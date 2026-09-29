<?php

namespace Tests\Feature;

use App\Models\ActivityEntry;
use App\Models\DailyStat;
use App\Models\Employee;
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
