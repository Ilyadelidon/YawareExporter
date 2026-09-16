<?php

namespace Tests\Feature;

use App\Jobs\CheckYawareLogin;
use App\Models\Employee;
use App\Models\User;
use App\Services\YawareAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Доступ у сервіс дає Yaware: є людина там — заходить і сюди. Звільнення —
 * єдиний виняток із цього правила, і воно має тримати двері зачиненими, навіть
 * поки людину ще не прибрали з Yaware.
 */
class EmployeeDismissalTest extends TestCase
{
    use RefreshDatabase;

    private function employee(array $attributes = []): Employee
    {
        $user = User::factory()->create([
            'email' => $attributes['email'] ?? 'pratsivnyk@example.com',
            'password' => 'yaware-pass',
        ]);

        return Employee::create([
            'user_id' => $user->id,
            'name' => 'Працівник',
            'email' => $user->email,
            'yaware_password' => 'yaware-pass',
            'active' => true,
        ] + $attributes);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_dismissal_revokes_tokens_and_wipes_yaware_password(): void
    {
        $employee = $this->employee();
        $employee->user->createToken('spa');
        $this->assertSame(1, $employee->user->tokens()->count());

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/employees/{$employee->id}/dismissal")->assertOk();

        $employee->refresh();
        $this->assertNotNull($employee->dismissed_at);
        $this->assertFalse($employee->active);
        $this->assertNull($employee->yaware_password);
        $this->assertSame(0, $employee->user->tokens()->count());
    }

    public function test_dismissal_keeps_the_history(): void
    {
        $employee = $this->employee();
        $report = $employee->reports()->create(['report_date' => '2026-09-15', 'status' => 'completed']);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/employees/{$employee->id}/dismissal")->assertOk();

        // Звільнення закриває доступ, а не стирає роботу: Табель за минулі
        // місяці має лишитись на місці.
        $this->assertDatabaseHas('reports', ['id' => $report->id]);
    }

    public function test_dismissed_employee_cannot_log_in_with_local_password(): void
    {
        Queue::fake();

        $employee = $this->employee();
        $employee->dismiss();

        $this->postJson('/api/auth/login', ['email' => $employee->email, 'password' => 'yaware-pass'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Доступ до сервісу закрито. Зверніться до адміністратора.');

        // Перевірку в Yaware навіть не запускаємо: двері зачинені раніше.
        Queue::assertNothingPushed();
    }

    public function test_yaware_login_does_not_reinstate_a_dismissed_employee(): void
    {
        $employee = $this->employee();
        $employee->dismiss();

        // Yaware людину ще знає — саме той випадок, заради якого кнопка й
        // потрібна: звільнили в нас раніше, ніж прибрали там.
        $this->mock(YawareAuthService::class, function ($mock) {
            $mock->shouldReceive('attemptLogin')->andReturn(['employee' => ['id' => '77', 'name' => 'Працівник']]);
        });

        $checkId = (string) Str::uuid();
        CheckYawareLogin::storeResult($checkId, ['status' => 'pending']);
        app()->call([new CheckYawareLogin($checkId, $employee->email, 'yaware-pass'), 'handle']);

        $this->getJson("/api/auth/login/pending/{$checkId}")
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $employee->refresh();
        $this->assertNotNull($employee->dismissed_at);
        $this->assertFalse($employee->active);
    }

    public function test_dismissal_logs_the_employee_out_of_a_live_session(): void
    {
        $employee = $this->employee();
        $token = $employee->user->createToken('spa')->plainTextToken;

        $this->withToken($token)->getJson('/api/reports')->assertOk();

        $employee->dismiss();

        // У тестах усі запити йдуть через один контейнер, і гард лишає в собі
        // вже впізнаного користувача. На бойовому кожен запит — окремий процес,
        // тож скидаємо гард руками, інакше перевірятимемо не той стан.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/reports')->assertUnauthorized();
    }

    public function test_token_that_outlived_dismissal_opens_nothing(): void
    {
        $employee = $this->employee();
        $token = $employee->user->createToken('spa')->plainTextToken;

        // Токен видано за мить до звільнення й тому вцілів — саме те вікно,
        // заради якого перевірка стоїть ще й на кожному запиті.
        $employee->update(['dismissed_at' => now()]);

        $this->withToken($token)->getJson('/api/reports')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/stats')->assertUnauthorized();

        // І сам токен більше не існує: наступний запит уже нічого не шукає.
        $this->assertSame(0, $employee->user->tokens()->count());
    }

    public function test_reinstatement_opens_the_door_again(): void
    {
        $employee = $this->employee();
        $employee->dismiss();

        Sanctum::actingAs($this->admin());
        $this->deleteJson("/api/employees/{$employee->id}/dismissal")->assertOk();

        $employee->refresh();
        $this->assertNull($employee->dismissed_at);

        // active навмисно лишається знятим: його підніме перший вдалий вхід
        // через Yaware, як і в усіх інших.
        $this->assertFalse($employee->active);
    }

    public function test_only_admin_can_dismiss(): void
    {
        $employee = $this->employee();
        $other = $this->employee(['email' => 'inshyi@example.com']);

        Sanctum::actingAs($other->user);

        $this->postJson("/api/employees/{$employee->id}/dismissal")->assertForbidden();
        $this->assertNull($employee->fresh()->dismissed_at);
    }

    public function test_daily_run_skips_the_dismissed(): void
    {
        Queue::fake();

        $employee = $this->employee();
        $employee->dismiss();

        // Прапорець active могло б підняти щось інше — звільненого пропускаємо
        // незалежно від нього.
        $employee->update(['active' => true]);

        $this->artisan('reports:generate-daily', ['--date' => '2026-09-15'])->assertSuccessful();

        $this->assertDatabaseMissing('reports', ['employee_id' => $employee->id]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
