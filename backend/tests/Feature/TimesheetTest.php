<?php

namespace Tests\Feature;

use App\Models\DailyStat;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TimesheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
    }

    private function employee(string $name, bool $active = true): Employee
    {
        return Employee::create([
            'name' => $name,
            'email' => mb_strtolower($name).'@example.com',
            'active' => $active,
        ]);
    }

    public function test_timesheet_excludes_unproductive_time(): void
    {
        $employee = $this->employee('Іван');
        DailyStat::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-10',
            'productive_seconds' => 3000,
            'neutral_seconds' => 600,
            'unproductive_seconds' => 900,
            'total_seconds' => 4500,
        ]);
        // День, коли був лише непродуктивний час, відпрацьованим не рахується.
        DailyStat::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-11',
            'unproductive_seconds' => 1200,
            'total_seconds' => 1200,
        ]);

        $this->getJson('/api/timesheet?month=2026-07')
            ->assertOk()
            ->assertJsonPath('data.0.days.2026-07-10', 3600)
            ->assertJsonPath('data.0.total_seconds', 3600)
            ->assertJsonPath('data.0.days_worked', 1);
    }

    public function test_short_month_is_not_shifted_by_todays_day(): void
    {
        // 30 числа «2026-02» без обнулення дня перетворювалось на 2 березня.
        $this->travelTo('2026-09-30 10:00');

        $this->getJson('/api/timesheet?month=2026-02')
            ->assertOk()
            ->assertJsonPath('month', '2026-02')
            ->assertJsonPath('days_in_month', 28);
    }

    public function test_current_month_by_default(): void
    {
        $this->travelTo('2026-09-30 10:00');

        $this->getJson('/api/timesheet')
            ->assertOk()
            ->assertJsonPath('month', '2026-09')
            ->assertJsonPath('days_in_month', 30);
    }

    public function test_inactive_employee_appears_only_with_data_for_the_month(): void
    {
        $this->employee('Богдан');
        $withData = $this->employee('Андрій', active: false);
        $this->employee('Василь', active: false);
        DailyStat::create([
            'employee_id' => $withData->id,
            'date' => '2026-07-10',
            'productive_seconds' => 3600,
            'total_seconds' => 3600,
        ]);

        $this->getJson('/api/timesheet?month=2026-07')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Андрій')
            ->assertJsonPath('data.1.name', 'Богдан')
            ->assertJsonPath('data.1.total_seconds', 0);
    }

    public function test_invalid_month_is_rejected(): void
    {
        $this->getJson('/api/timesheet?month=2026-13')->assertUnprocessable();
    }
}
