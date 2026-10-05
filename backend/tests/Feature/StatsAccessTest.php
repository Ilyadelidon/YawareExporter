<?php

namespace Tests\Feature;

use App\Models\ActivityEntry;
use App\Models\DailyStat;
use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanTask;
use App\Models\PlanTaskDay;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Статистика — чужий робочий день по хвилинах. Працівник бачить лише свою,
 * навіть якщо підставить у запит чужий employee_id.
 */
class StatsAccessTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $email): Employee
    {
        $user = User::factory()->create(['email' => $email]);

        return Employee::create([
            'user_id' => $user->id,
            'name' => $email,
            'email' => $email,
            'active' => true,
        ]);
    }

    private function stat(Employee $employee): DailyStat
    {
        return DailyStat::create([
            'employee_id' => $employee->id,
            'date' => '2026-09-15',
            'total_seconds' => 3600,
            'productive_seconds' => 3600,
        ]);
    }

    public function test_employee_sees_only_own_stats_even_with_foreign_employee_id(): void
    {
        $mine = $this->employee('a@example.com');
        $theirs = $this->employee('b@example.com');
        $this->stat($mine);
        $this->stat($theirs);

        Sanctum::actingAs($mine->user);

        $this->getJson("/api/stats?date_from=2026-09-01&date_to=2026-09-30&employee_id={$theirs->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $mine->id)
            ->assertJsonPath('totals.days', 1);
    }

    public function test_admin_can_filter_stats_by_employee(): void
    {
        $first = $this->employee('a@example.com');
        $second = $this->employee('b@example.com');
        $this->stat($first);
        $this->stat($second);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->getJson('/api/stats?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('totals.days', 2);

        $this->getJson("/api/stats?date_from=2026-09-01&date_to=2026-09-30&employee_id={$second->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $second->id);
    }

    public function test_stats_include_top_activities(): void
    {
        $mine = $this->employee('a@example.com');
        $theirs = $this->employee('b@example.com');
        $entry = fn (Employee $employee, string $date, string $name, int $seconds) => ActivityEntry::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'name' => $name,
            'productivity' => 'productive',
            'duration_seconds' => $seconds,
        ]);
        $entry($mine, '2026-09-01', 'VS Code', 3000);
        $entry($mine, '2026-09-02', 'VS Code', 2000);
        $entry($mine, '2026-09-01', 'Figma', 600);
        $entry($theirs, '2026-09-01', 'Excel', 9000);

        Sanctum::actingAs($mine->user);

        // Чужий Excel не потрапляє.
        $this->getJson('/api/stats?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(2, 'activities')
            ->assertJsonPath('activities.0.name', 'VS Code')
            ->assertJsonPath('activities.0.seconds', 5000);
    }

    public function test_stats_include_report_tasks_for_period(): void
    {
        $mine = $this->employee('a@example.com');
        $theirs = $this->employee('b@example.com');
        $report = fn (Employee $employee, string $date, array $tasks, string $status = Report::STATUS_COMPLETED) => Report::create([
            'employee_id' => $employee->id,
            'report_date' => $date,
            'status' => $status,
            'tasks' => $tasks,
        ]);
        $task = fn (string $name, string $start, string $due, ?string $url = null) => compact('name', 'start', 'due', 'url');

        $report($mine, '2026-09-01', [
            $task('Інтеграція', '2026-09-01 09:00', '2026-09-01 11:00', 'https://trello.com/c/a'),
            $task('Мітинг', '2026-09-01 11:00', '2026-09-01 11:30'),
        ]);
        // Картку перейменували — це та сама таска (те саме посилання), назва береться остання.
        $report($mine, '2026-09-02', [
            $task('Інтеграція з CRM', '2026-09-02 09:00', '2026-09-02 10:00', 'https://trello.com/c/a'),
            $task('Мітинг', '2026-09-02 10:00', '2026-09-02 10:45'),
            // Таска без часу рахується в кількості, але не в топі за часом.
            $task('Без часу', '', ''),
        ]);
        // Не готовий звіт, звіт поза періодом і чужий звіт не рахуються.
        $report($mine, '2026-09-03', [$task('Збій', '2026-09-03 09:00', '2026-09-03 18:00')], Report::STATUS_FAILED);
        $report($mine, '2026-08-31', [$task('Серпень', '2026-08-31 09:00', '2026-08-31 18:00')]);
        $report($theirs, '2026-09-01', [$task('Чуже', '2026-09-01 09:00', '2026-09-01 18:00')]);

        Sanctum::actingAs($mine->user);

        $this->getJson('/api/stats?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('tasks.total', 3)
            ->assertJsonPath('tasks.seconds', 3 * 3600 + 75 * 60)
            ->assertJsonCount(2, 'tasks.top')
            ->assertJsonPath('tasks.top.0', [
                'name' => 'Інтеграція з CRM',
                'url' => 'https://trello.com/c/a',
                'seconds' => 3 * 3600,
                'days' => 2,
                'employees' => ['a@example.com'],
            ])
            ->assertJsonPath('tasks.top.1.name', 'Мітинг')
            ->assertJsonPath('tasks.top.1.seconds', 75 * 60);
    }

    public function test_stats_include_plan_tasks_worked_in_period(): void
    {
        $mine = $this->employee('a@example.com');
        $theirs = $this->employee('b@example.com');
        $project = PlanProject::create(['name' => 'TumTum']);
        $task = function (Employee $employee, string $title, array $dates, string $status = PlanTask::STATUS_IN_PROGRESS) use ($project) {
            $task = $project->tasks()->create(['employee_id' => $employee->id, 'title' => $title, 'status' => $status]);
            foreach ($dates as $date) {
                PlanTaskDay::create(['plan_task_id' => $task->id, 'date' => $date]);
            }
        };

        $task($mine, 'Інтеграція', ['2026-09-01', '2026-09-02', '2026-09-03']);
        // Відмітка поза періодом не рахується.
        $task($mine, 'Звіт', ['2026-09-10', '2026-08-31'], PlanTask::STATUS_DONE);
        $task($mine, 'Дизайн', ['2026-09-11'], PlanTask::STATUS_REVIEW);
        // Задача без відміток у періоді і чужа задача не потрапляють.
        $task($mine, 'Серпень', ['2026-08-20'], PlanTask::STATUS_DONE);
        $task($theirs, 'Чуже', ['2026-09-05']);

        Sanctum::actingAs($mine->user);

        $this->getJson('/api/stats?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('plan_tasks.total', 3)
            ->assertJsonPath('plan_tasks.done', 1)
            ->assertJsonPath('plan_tasks.review', 1)
            ->assertJsonCount(3, 'plan_tasks.top')
            ->assertJsonPath('plan_tasks.top.0', [
                'name' => 'Інтеграція',
                'project' => 'TumTum',
                'status' => 'В роботі',
                'days' => 3,
                'employee' => 'a@example.com',
            ])
            ->assertJsonPath('plan_tasks.top.1.name', 'Звіт')
            ->assertJsonPath('plan_tasks.top.1.days', 1);
    }

    public function test_employee_cannot_open_someone_elses_activities(): void
    {
        $mine = $this->employee('a@example.com');
        $theirs = $this->employee('b@example.com');
        ActivityEntry::create([
            'employee_id' => $theirs->id,
            'date' => '2026-09-15',
            'productivity' => 'productive',
            'name' => 'phpstorm',
            'duration_seconds' => 600,
        ]);

        Sanctum::actingAs($mine->user);

        $this->getJson("/api/stats/activities?employee_id={$theirs->id}&date=2026-09-15")->assertForbidden();
        $this->getJson("/api/stats/activities?employee_id={$mine->id}&date=2026-09-15")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
