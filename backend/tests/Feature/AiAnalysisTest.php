<?php

namespace Tests\Feature;

use App\Jobs\GenerateDailyAnalysis;
use App\Models\DailyAnalysis;
use App\Models\Employee;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.ai.provider', 'anthropic');
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.deepseek.key', null);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'name' => 'Іван',
            'position' => 'Frontend-розробник',
            'email' => 'ivan@example.com',
        ]);
    }

    private function completedReport(Employee $employee, string $date = '2026-07-20'): Report
    {
        return Report::create([
            'employee_id' => $employee->id,
            'report_date' => $date,
            'status' => Report::STATUS_COMPLETED,
            'generated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_employee_cannot_read_analysis(): void
    {
        $employee = $this->employee();
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $employee->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        // Навіть власний розбір працівникові недоступний — це матеріал для керівника.
        $this->getJson("/api/analysis?employee_id={$employee->id}&date=2026-07-20")
            ->assertForbidden();

        $this->postJson('/api/analysis', ['employee_id' => $employee->id, 'date' => '2026-07-20'])
            ->assertForbidden();
    }

    public function test_admin_reads_stored_analysis(): void
    {
        $employee = $this->employee();

        DailyAnalysis::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-20',
            'status' => DailyAnalysis::STATUS_COMPLETED,
            'result' => ['summary' => 'День робочий', 'unclear_activities' => []],
            'model' => 'claude-sonnet-5',
            'generated_at' => now(),
        ]);

        Sanctum::actingAs($this->admin());

        $this->getJson("/api/analysis?employee_id={$employee->id}&date=2026-07-20")
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('data.status', DailyAnalysis::STATUS_COMPLETED)
            ->assertJsonPath('data.result.summary', 'День робочий');
    }

    public function test_admin_queues_analysis_for_completed_report(): void
    {
        Queue::fake();

        $employee = $this->employee();
        $this->completedReport($employee);

        Sanctum::actingAs($this->admin());

        $this->postJson('/api/analysis', ['employee_id' => $employee->id, 'date' => '2026-07-20'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', DailyAnalysis::STATUS_PENDING);

        Queue::assertPushed(GenerateDailyAnalysis::class);
    }

    public function test_analysis_requires_completed_report(): void
    {
        Queue::fake();

        $employee = $this->employee();

        Sanctum::actingAs($this->admin());

        $this->postJson('/api/analysis', ['employee_id' => $employee->id, 'date' => '2026-07-20'])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_analysis_requires_api_key(): void
    {
        Queue::fake();
        config()->set('services.anthropic.key', null);

        $employee = $this->employee();
        $this->completedReport($employee);

        Sanctum::actingAs($this->admin());

        $this->postJson('/api/analysis', ['employee_id' => $employee->id, 'date' => '2026-07-20'])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_show_lists_providers_with_configuration_state(): void
    {
        $employee = $this->employee();

        Sanctum::actingAs($this->admin());

        $this->getJson("/api/analysis?employee_id={$employee->id}&date=2026-07-20")
            ->assertOk()
            ->assertJsonPath('providers.0.name', 'anthropic')
            ->assertJsonPath('providers.0.configured', true)
            ->assertJsonPath('providers.0.default', true)
            ->assertJsonPath('providers.1.name', 'deepseek')
            ->assertJsonPath('providers.1.configured', false);
    }

    public function test_admin_can_pick_deepseek(): void
    {
        Queue::fake();
        config()->set('services.deepseek.key', 'test-key');

        $employee = $this->employee();
        $this->completedReport($employee);

        Sanctum::actingAs($this->admin());

        $this->postJson('/api/analysis', [
            'employee_id' => $employee->id,
            'date' => '2026-07-20',
            'provider' => 'deepseek',
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.provider', 'deepseek');

        Queue::assertPushed(
            GenerateDailyAnalysis::class,
            fn (GenerateDailyAnalysis $job) => $job->provider === 'deepseek',
        );
    }

    public function test_chosen_provider_needs_its_own_key(): void
    {
        Queue::fake();

        $employee = $this->employee();
        $this->completedReport($employee);

        Sanctum::actingAs($this->admin());

        // Ключ Claude заповнений, але просять DeepSeek — запускати нічого.
        $this->postJson('/api/analysis', [
            'employee_id' => $employee->id,
            'date' => '2026-07-20',
            'provider' => 'deepseek',
        ])->assertStatus(422);

        $this->postJson('/api/analysis', [
            'employee_id' => $employee->id,
            'date' => '2026-07-20',
            'provider' => 'gemini',
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }
}
