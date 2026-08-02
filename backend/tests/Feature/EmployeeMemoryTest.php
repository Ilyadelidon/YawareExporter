<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMemory;
use App\Models\User;
use App\Services\Ai\EmployeeMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeMemoryTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        return Employee::create([
            'name' => 'Іван',
            'position' => 'Frontend-розробник',
            'email' => 'ivan@example.com',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $activities
     * @return array<string, mixed>
     */
    private function analysisResult(array $activities): array
    {
        return ['summary' => 'День робочий', 'unclear_activities' => $activities];
    }

    private function service(): EmployeeMemoryService
    {
        return app(EmployeeMemoryService::class);
    }

    public function test_remember_stores_and_counts_repeats(): void
    {
        $employee = $this->employee();

        $activity = [
            'name' => 'Windows Terminal Host',
            'duration_seconds' => 9500,
            'verdict' => 'work_related',
            'reasoning' => 'Термінал розробника.',
        ];

        $this->service()->remember($employee->id, $this->analysisResult([$activity]), '2026-07-20');
        $this->service()->remember($employee->id, $this->analysisResult([$activity]), '2026-07-21');

        $memory = EmployeeMemory::where('employee_id', $employee->id)->sole();

        $this->assertSame('work_related', $memory->verdict);
        $this->assertSame(EmployeeMemory::SOURCE_AI, $memory->source);
        // Один рядок на назву, а не по рядку на день.
        $this->assertSame(2, $memory->occurrences);
        $this->assertSame('2026-07-21', $memory->last_seen_at->toDateString());
    }

    public function test_remember_keeps_older_last_seen_when_backfilling_out_of_order(): void
    {
        $employee = $this->employee();

        $activity = ['name' => 'example.com', 'verdict' => 'unknown', 'reasoning' => 'Невідомо.'];

        $this->service()->remember($employee->id, $this->analysisResult([$activity]), '2026-07-21');
        // Перегенерація давнішого дня не має відкочувати дату назад.
        $this->service()->remember($employee->id, $this->analysisResult([$activity]), '2026-07-15');

        $this->assertSame(
            '2026-07-21',
            EmployeeMemory::where('name', 'example.com')->sole()->last_seen_at->toDateString(),
        );
    }

    public function test_admin_verdict_survives_further_analyses(): void
    {
        $employee = $this->employee();

        EmployeeMemory::create([
            'employee_id' => $employee->id,
            'kind' => EmployeeMemory::KIND_ACTIVITY,
            'name' => 'brok.tumi-tum.com',
            'verdict' => 'work_related',
            'note' => 'Внутрішній сервіс компанії.',
            'source' => EmployeeMemory::SOURCE_ADMIN,
            'checked_at' => now(),
        ]);

        $this->service()->remember($employee->id, $this->analysisResult([[
            'name' => 'brok.tumi-tum.com',
            'verdict' => 'personal',
            'reasoning' => 'Схоже на сайт доставки.',
        ]]), '2026-07-22');

        $memory = EmployeeMemory::where('name', 'brok.tumi-tum.com')->sole();

        $this->assertSame('work_related', $memory->verdict);
        $this->assertSame('Внутрішній сервіс компанії.', $memory->note);
        // Статистика появи оновлюється й для адмінського рядка.
        $this->assertSame(2, $memory->occurrences);
    }

    public function test_prompt_skips_stale_ai_verdicts_but_keeps_admin_ones(): void
    {
        $employee = $this->employee();

        EmployeeMemory::create([
            'employee_id' => $employee->id,
            'kind' => EmployeeMemory::KIND_ACTIVITY,
            'name' => 'old-domain.com',
            'verdict' => 'unknown',
            'source' => EmployeeMemory::SOURCE_AI,
            'checked_at' => now()->subDays(EmployeeMemoryService::RECHECK_DAYS + 1),
        ]);

        EmployeeMemory::create([
            'employee_id' => $employee->id,
            'kind' => EmployeeMemory::KIND_ACTIVITY,
            'name' => 'confirmed.com',
            'verdict' => 'work_related',
            'source' => EmployeeMemory::SOURCE_ADMIN,
            'checked_at' => now()->subYears(2),
        ]);

        EmployeeMemory::create([
            'employee_id' => $employee->id,
            'kind' => EmployeeMemory::KIND_FACT,
            'name' => 'Графік',
            'note' => 'Обід 13:00–14:00.',
            'source' => EmployeeMemory::SOURCE_ADMIN,
            'checked_at' => now(),
        ]);

        $prompt = $this->service()->forPrompt($employee->id);

        $this->assertSame(['confirmed.com'], array_column($prompt['activities'], 'name'));
        $this->assertTrue($prompt['activities'][0]['confirmed_by_admin']);
        $this->assertSame(['Графік'], array_column($prompt['facts'], 'name'));
    }

    public function test_employee_cannot_read_memory(): void
    {
        $employee = $this->employee();
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $employee->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->getJson("/api/employees/{$employee->id}/memory")->assertForbidden();
    }

    public function test_admin_edits_and_deletes_memory(): void
    {
        $employee = $this->employee();

        $memory = EmployeeMemory::create([
            'employee_id' => $employee->id,
            'kind' => EmployeeMemory::KIND_ACTIVITY,
            'name' => 'chatday.ai',
            'verdict' => 'unknown',
            'source' => EmployeeMemory::SOURCE_AI,
            'checked_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->getJson("/api/employees/{$employee->id}/memory")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'chatday.ai')
            ->assertJsonPath('recheck_days', EmployeeMemoryService::RECHECK_DAYS);

        // Правка переводить рядок у ручний режим — модель його більше не чіпає.
        $this->patchJson("/api/employees/{$employee->id}/memory/{$memory->id}", [
            'verdict' => 'personal',
            'note' => 'Розважальний AI-чат.',
        ])
            ->assertOk()
            ->assertJsonPath('data.verdict', 'personal')
            ->assertJsonPath('data.source', EmployeeMemory::SOURCE_ADMIN);

        $this->deleteJson("/api/employees/{$employee->id}/memory/{$memory->id}")->assertNoContent();

        $this->assertDatabaseCount('employee_memories', 0);
    }

    public function test_memory_of_another_employee_is_not_reachable(): void
    {
        $employee = $this->employee();
        $other = Employee::create(['name' => 'Петро', 'email' => 'petro@example.com']);

        $memory = EmployeeMemory::create([
            'employee_id' => $other->id,
            'kind' => EmployeeMemory::KIND_ACTIVITY,
            'name' => 'secret.com',
            'verdict' => 'personal',
            'source' => EmployeeMemory::SOURCE_AI,
            'checked_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->patchJson("/api/employees/{$employee->id}/memory/{$memory->id}", ['verdict' => 'work_related'])
            ->assertNotFound();
    }

    public function test_admin_adds_fact(): void
    {
        $employee = $this->employee();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->postJson("/api/employees/{$employee->id}/memory", [
            'kind' => 'fact',
            'name' => 'Робочі інструменти',
            'note' => 'PhpStorm, DBeaver, локальний сервер.',
        ])->assertCreated();

        // Активність без вердикту не приймається — інакше пам'ять безкорисна.
        $this->postJson("/api/employees/{$employee->id}/memory", [
            'kind' => 'activity',
            'name' => 'example.com',
        ])->assertStatus(422);
    }
}
