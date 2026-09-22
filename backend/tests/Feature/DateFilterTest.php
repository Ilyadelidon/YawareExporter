<?php

namespace Tests\Feature;

use App\Models\ActivityEntry;
use App\Models\DailyStat;
use App\Models\Employee;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Фільтри за датами на колонках типу date. Порівняння йде по самій колонці
 * (без DATE()/strftime(), щоб працювали індекси), тому межі діапазону мають
 * бути включними, а рядок Y-m-d — збігатися точно.
 */
class DateFilterTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $email = 'ivan@example.com'): Employee
    {
        return Employee::create([
            'name' => 'Іван',
            'email' => $email,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function report(Employee $employee, string $date): Report
    {
        return Report::create([
            'employee_id' => $employee->id,
            'report_date' => $date,
            'status' => Report::STATUS_COMPLETED,
            'generated_at' => now(),
        ]);
    }

    private function stat(Employee $employee, string $date, int $seconds = 3600): DailyStat
    {
        return DailyStat::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'total_seconds' => $seconds,
            'productive_seconds' => $seconds,
        ]);
    }

    public function test_report_range_includes_both_boundary_days(): void
    {
        $employee = $this->employee();
        $this->report($employee, '2026-07-05');
        $this->report($employee, '2026-07-10');
        $this->report($employee, '2026-07-15');
        $this->report($employee, '2026-07-20');

        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/reports?date_from=2026-07-10&date_to=2026-07-15')
            ->assertOk();

        $dates = collect($response->json('data'))->pluck('report_date')->map(
            fn (string $date) => substr($date, 0, 10),
        )->sort()->values()->all();

        $this->assertSame(['2026-07-10', '2026-07-15'], $dates);
    }

    public function test_stats_range_includes_both_boundary_days(): void
    {
        $employee = $this->employee();
        $this->stat($employee, '2026-07-09');
        $this->stat($employee, '2026-07-10');
        $this->stat($employee, '2026-07-15');
        $this->stat($employee, '2026-07-16');

        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/stats?date_from=2026-07-10&date_to=2026-07-15')
            ->assertOk()
            ->assertJsonPath('totals.days', 2);

        $dates = collect($response->json('data'))->pluck('date')->map(
            fn (string $date) => substr($date, 0, 10),
        )->sort()->values()->all();

        $this->assertSame(['2026-07-10', '2026-07-15'], $dates);
    }

    public function test_stats_totals_report_work_time_without_unproductive(): void
    {
        $employee = $this->employee();
        DailyStat::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-10',
            'productive_seconds' => 3000,
            'neutral_seconds' => 600,
            'unproductive_seconds' => 900,
            'total_seconds' => 4500,
        ]);
        // День лише з непродуктивним часом у робочий час не додає нічого.
        DailyStat::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-11',
            'unproductive_seconds' => 1200,
            'total_seconds' => 1200,
        ]);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/stats?date_from=2026-07-10&date_to=2026-07-11')
            ->assertOk()
            ->assertJsonPath('totals.total_seconds', 5700)
            ->assertJsonPath('totals.work_seconds', 3600);
    }

    public function test_activities_of_a_day_are_matched_exactly(): void
    {
        $employee = $this->employee();
        ActivityEntry::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-10',
            'productivity' => 'productive',
            'name' => 'phpstorm',
            'duration_seconds' => 600,
        ]);
        ActivityEntry::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-11',
            'productivity' => 'unproductive',
            'name' => 'youtube.com',
            'duration_seconds' => 300,
        ]);

        Sanctum::actingAs($this->admin());

        $this->getJson("/api/stats/activities?employee_id={$employee->id}&date=2026-07-10")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'phpstorm');
    }

    public function test_timesheet_covers_the_whole_month_and_nothing_beyond(): void
    {
        $employee = $this->employee();
        // Останній день липня і перший день серпня — межі, які найлегше загубити.
        $this->stat($employee, '2026-06-30', 1000);
        $this->stat($employee, '2026-07-01', 2000);
        $this->stat($employee, '2026-07-31', 3000);
        $this->stat($employee, '2026-08-01', 4000);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/timesheet?month=2026-07')
            ->assertOk()
            ->assertJsonPath('data.0.total_seconds', 5000)
            ->assertJsonPath('data.0.days_worked', 2);
    }

    public function test_timesheet_excludes_unproductive_time(): void
    {
        $employee = $this->employee();
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

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/timesheet?month=2026-07')
            ->assertOk()
            ->assertJsonPath('data.0.days.2026-07-10', 3600)
            ->assertJsonPath('data.0.total_seconds', 3600)
            ->assertJsonPath('data.0.days_worked', 1);
    }
}
