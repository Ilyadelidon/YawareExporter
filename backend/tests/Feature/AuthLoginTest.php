<?php

namespace Tests\Feature;

use App\Jobs\CheckYawareLogin;
use App\Models\Employee;
use App\Models\User;
use App\Services\YawareAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_password_logs_in_without_queue(): void
    {
        Queue::fake();

        $user = User::factory()->create(['password' => 'secret-pass']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']]);

        Queue::assertNothingPushed();
    }

    public function test_admin_with_wrong_password_fails_without_queue(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'password' => 'right-pass']);

        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'wrong-pass'])
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_unknown_credentials_queue_yaware_check_and_return_pending(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'new.user@example.com',
            'password' => 'yaware-pass',
        ]);

        $response->assertStatus(202)->assertJsonPath('status', 'pending');
        $checkId = $response->json('check_id');
        $this->assertNotEmpty($checkId);

        Queue::assertPushedOn(CheckYawareLogin::QUEUE, CheckYawareLogin::class);

        // Поки джоба не виконалась — полінг віддає pending.
        $this->getJson("/api/auth/login/pending/{$checkId}")
            ->assertOk()
            ->assertJsonPath('status', 'pending');
    }

    public function test_successful_check_creates_user_and_token_is_issued_once(): void
    {
        $this->mock(YawareAuthService::class, function ($mock) {
            $mock->shouldReceive('attemptLogin')
                ->once()
                ->with('new.user@example.com', 'yaware-pass')
                ->andReturn(['employee' => ['id' => '77', 'name' => 'Новий Працівник']]);
        });

        $checkId = (string) Str::uuid();
        CheckYawareLogin::storeResult($checkId, ['status' => 'pending']);

        app()->call([new CheckYawareLogin($checkId, 'new.user@example.com', 'yaware-pass'), 'handle']);

        $user = User::where('email', 'new.user@example.com')->firstOrFail();
        $employee = Employee::where('email', 'new.user@example.com')->firstOrFail();
        $this->assertSame('Новий Працівник', $user->name);
        $this->assertSame($user->id, $employee->user_id);
        $this->assertSame('yaware-pass', $employee->yaware_password);

        $first = $this->getJson("/api/auth/login/pending/{$checkId}")->assertOk();
        $this->assertSame('ok', $first->json('status'));
        $this->assertNotEmpty($first->json('token'));
        $this->assertSame($user->id, $first->json('user.id'));

        // Токен одноразовий: повторний полінг — уже 404.
        $this->getJson("/api/auth/login/pending/{$checkId}")->assertNotFound();
    }

    public function test_failed_check_reports_error_and_creates_nobody(): void
    {
        $this->mock(YawareAuthService::class, function ($mock) {
            $mock->shouldReceive('attemptLogin')->once()->andReturn(null);
        });

        $checkId = (string) Str::uuid();
        CheckYawareLogin::storeResult($checkId, ['status' => 'pending']);

        app()->call([new CheckYawareLogin($checkId, 'bad@example.com', 'wrong'), 'handle']);

        $this->getJson("/api/auth/login/pending/{$checkId}")
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        $this->assertDatabaseMissing('users', ['email' => 'bad@example.com']);
    }

    public function test_unknown_check_id_returns_404(): void
    {
        $this->getJson('/api/auth/login/pending/'.Str::uuid())->assertNotFound();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
