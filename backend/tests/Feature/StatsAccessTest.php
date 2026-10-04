<?php

namespace Tests\Feature;

use App\Models\ActivityEntry;
use App\Models\DailyStat;
use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanTask;
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

    public function test_stats_include_plan_for_period(): void
    {
        $mine = $this->employee('a@example.com');
        $theirs = $this->employee('b@example.com');
        $project = PlanProject::create(['name' => 'TumTum']);
        $archived = PlanProject::create(['name' => 'Старий', 'archived_at' => now()]);
        $planTask = fn (PlanProject $project, Employee $employee, string $title, string $status) => PlanTask::create([
            'plan_project_id' => $project->id,
            'employee_id' => $employee->id,
            'title' => $title,
            'status' => $status,
        ]);

        $planTask($project, $mine, 'Інтеграція', PlanTask::STATUS_IN_PROGRESS);
        // Над «Інтеграцією» в періоді не працювали — у топ вона не потрапляє.
        $marked = $planTask($project, $mine, 'Дизайн', PlanTask::STATUS_DONE);
        $planTask($project, $mine, 'Бекапи', PlanTask::STATUS_PENDING);
        // Архівний проект не входить у стан плану, але робота над ним у періоді видна.
        $old = $planTask($archived, $mine, 'Старе', PlanTask::STATUS_PAUSED);
        $planTask($project, $theirs, 'Чуже', PlanTask::STATUS_PENDING);

        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
            $marked->days()->create(['date' => $date]);
        }
        $old->days()->create(['date' => '2026-09-04']);
        // Відмітка поза періодом не рахується.
        $marked->days()->create(['date' => '2026-08-20']);

        Sanctum::actingAs($mine->user);

        $this->getJson('/api/stats?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('plan.total', 3)
            ->assertJsonPath('plan.statuses', [
                ['status' => PlanTask::STATUS_PENDING, 'label' => 'Очікує виконання', 'count' => 1],
                ['status' => PlanTask::STATUS_IN_PROGRESS, 'label' => 'В роботі', 'count' => 1],
                ['status' => PlanTask::STATUS_DONE, 'label' => 'Виконано', 'count' => 1],
            ])
            ->assertJsonPath('plan.worked_tasks', 2)
            ->assertJsonPath('plan.worked_days', 4)
            ->assertJsonCount(2, 'plan.top')
            ->assertJsonPath('plan.top.0.title', 'Дизайн')
            ->assertJsonPath('plan.top.0.days', 3)
            ->assertJsonPath('plan.top.0.project', 'TumTum')
            ->assertJsonPath('plan.top.1.title', 'Старе');
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
