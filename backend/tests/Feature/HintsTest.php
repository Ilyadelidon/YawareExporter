<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanTaskDay;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Підказки в боковому меню: пропущені звіти за минулі будні й будні без
 * відміченої задачі в «Планах» — за останні 7 днів або від першого входу.
 */
class HintsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Середа; сім днів назад — четвер 17.09, будні: 17, 18, 21, 22, 23.
        Carbon::setTestNow('2026-09-23 12:00:00');
    }

    private function employee(string $joinedAt = '2026-09-01 09:00:00'): Employee
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'created_at' => $joinedAt]);

        return Employee::create(['user_id' => $user->id, 'name' => 'Іван', 'email' => $user->email, 'active' => true]);
    }

    public function test_lists_weekdays_without_ready_report_except_today(): void
    {
        $employee = $this->employee();
        Report::create([
            'employee_id' => $employee->id, 'report_date' => '2026-09-22', 'status' => Report::STATUS_COMPLETED,
            'summary' => ['Google Таблиця' => 'https://docs.google.com/spreadsheets/d/x/edit'], 'tasks' => [['name' => 'Задача']],
        ]);
        Report::create(['employee_id' => $employee->id, 'report_date' => '2026-09-21', 'status' => Report::STATUS_BLOCKED]);
        Report::create(['employee_id' => $employee->id, 'report_date' => '2026-09-18', 'status' => Report::STATUS_PROCESSING]);
        Report::create(['employee_id' => $employee->id, 'report_date' => '2026-09-16', 'status' => Report::STATUS_FAILED]);

        Sanctum::actingAs($employee->user);

        $this->getJson('/api/hints')
            ->assertOk()
            ->assertJsonPath('today', '2026-09-23')
            ->assertJsonPath('reports', [
                ['date' => '2026-09-21', 'status' => 'blocked'],
                ['date' => '2026-09-17', 'status' => 'none'],
            ]);
    }

    public function test_completed_report_without_tasks_or_google_counts_as_missing(): void
    {
        $employee = $this->employee();
        $sheet = ['Google Таблиця' => 'https://docs.google.com/spreadsheets/d/x/edit'];
        $task = [['name' => 'Задача']];

        // Повний: таски є і вивантажено в Google.
        Report::create(['employee_id' => $employee->id, 'report_date' => '2026-09-22', 'status' => Report::STATUS_COMPLETED, 'summary' => $sheet, 'tasks' => $task]);
        // Тасок немає — ні файлу, ні вкладки.
        Report::create(['employee_id' => $employee->id, 'report_date' => '2026-09-21', 'status' => Report::STATUS_COMPLETED, 'summary' => ['Попередження' => 'тасок немає'], 'tasks' => []]);
        // Таски є, але Google не прийняв.
        Report::create(['employee_id' => $employee->id, 'report_date' => '2026-09-18', 'status' => Report::STATUS_COMPLETED, 'summary' => ['Попередження' => 'не вивантажено'], 'tasks' => $task]);
        // День без активності (відпустка) — не пропуск.
        Report::create(['employee_id' => $employee->id, 'report_date' => '2026-09-17', 'status' => Report::STATUS_COMPLETED, 'summary' => ['Результат' => 'День без активності в Yaware — історія і Google Таблиця не оновлювались.'], 'tasks' => []]);

        Sanctum::actingAs($employee->user);

        $this->getJson('/api/hints')
            ->assertOk()
            ->assertJsonPath('reports', [
                ['date' => '2026-09-21', 'status' => 'incomplete'],
                ['date' => '2026-09-18', 'status' => 'incomplete'],
            ]);
    }

    public function test_lists_weekdays_without_marked_plan_task_including_today(): void
    {
        $employee = $this->employee();
        $project = PlanProject::create(['name' => 'TumTum']);
        $project->members()->sync([$employee->id]);
        $task = $project->tasks()->create(['employee_id' => $employee->id, 'title' => 'Задача']);
        PlanTaskDay::create(['plan_task_id' => $task->id, 'date' => '2026-09-22']);
        PlanTaskDay::create(['plan_task_id' => $task->id, 'date' => '2026-09-18']);

        Sanctum::actingAs($employee->user);

        $this->getJson('/api/hints')
            ->assertOk()
            ->assertJsonPath('plans', ['2026-09-23', '2026-09-21', '2026-09-17']);
    }

    public function test_plan_hint_for_today_waits_until_seven_in_kyiv(): void
    {
        $employee = $this->employee();
        PlanProject::create(['name' => 'TumTum'])->members()->sync([$employee->id]);
        Sanctum::actingAs($employee->user);

        // 06:59 за Києвом (UTC+3) — сьогодні ще не нагадуємо.
        Carbon::setTestNow('2026-09-23 03:59:00');
        $this->getJson('/api/hints')->assertOk()->assertJsonPath('plans.0', '2026-09-22');

        // 07:00 — уже нагадуємо.
        Carbon::setTestNow('2026-09-23 04:00:00');
        $this->getJson('/api/hints')->assertOk()->assertJsonPath('plans.0', '2026-09-23');
    }

    public function test_no_plan_hints_without_active_project(): void
    {
        $employee = $this->employee();
        PlanProject::create(['name' => 'Архів', 'archived_at' => now()])->members()->sync([$employee->id]);

        Sanctum::actingAs($employee->user);

        $this->getJson('/api/hints')->assertOk()->assertJsonPath('plans', []);
    }

    public function test_newcomer_is_not_reminded_about_days_before_first_login(): void
    {
        $employee = $this->employee('2026-09-22 10:00:00');
        PlanProject::create(['name' => 'TumTum'])->members()->sync([$employee->id]);

        Sanctum::actingAs($employee->user);

        $this->getJson('/api/hints')
            ->assertOk()
            ->assertJsonPath('reports', [['date' => '2026-09-22', 'status' => 'none']])
            ->assertJsonPath('plans', ['2026-09-23', '2026-09-22']);
    }

    public function test_admin_gets_no_hints(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->getJson('/api/hints')->assertOk()->assertJson(['today' => null, 'reports' => [], 'plans' => []]);
    }
}
