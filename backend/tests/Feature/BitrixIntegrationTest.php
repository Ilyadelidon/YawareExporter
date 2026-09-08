<?php

namespace Tests\Feature;

use App\Models\BitrixWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BitrixIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://team.bitrix24.ua/rest/1/secret/';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bitrix.timezone', 'Europe/Kyiv');
    }

    private function workspace(): BitrixWorkspace
    {
        return BitrixWorkspace::connect([
            'portal_url' => 'https://team.bitrix24.ua',
            'webhook_url' => self::WEBHOOK,
            'owner_name' => 'Адмін Порталу',
        ]);
    }

    /** Працівник із вибраним Бітріксом і прив'язаним акаунтом на порталі. */
    private function connectedUser(): User
    {
        $this->workspace();

        $user = User::factory()->create();
        $user->forceFill([
            'task_provider' => User::TASK_PROVIDER_BITRIX,
            'bitrix_user_id' => '7',
            'bitrix_user_name' => 'Іван Петренко',
        ])->save();

        return $user;
    }

    private function fakePortalUsers(): void
    {
        Http::fake([
            self::WEBHOOK.'user.get' => Http::response([
                'result' => [
                    ['ID' => '7', 'NAME' => 'Іван', 'LAST_NAME' => 'Петренко', 'EMAIL' => 'ivan@team.ua', 'WORK_POSITION' => 'Розробник'],
                    ['ID' => '9', 'NAME' => 'Олена', 'LAST_NAME' => 'Коваль', 'EMAIL' => 'olena@team.ua'],
                ],
                'total' => 2,
            ]),
        ]);
    }

    public function test_admin_connects_portal_and_webhook_is_stored_encrypted(): void
    {
        Http::fake([
            self::WEBHOOK.'profile' => Http::response([
                'result' => ['ID' => '1', 'NAME' => 'Адмін', 'LAST_NAME' => 'Порталу'],
            ]),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->postJson('/api/bitrix/workspace', ['webhook' => self::WEBHOOK])
            ->assertCreated()
            ->assertJsonPath('portal_url', 'https://team.bitrix24.ua')
            ->assertJsonPath('owner_name', 'Адмін Порталу');

        $workspace = BitrixWorkspace::active();
        $this->assertSame(self::WEBHOOK, $workspace->webhook_url);

        // Вебхук — ключ доступу до всього порталу, у БД має лежати зашифрованим.
        $raw = DB::table('bitrix_workspaces')->where('id', $workspace->id)->value('webhook_url');
        $this->assertNotSame(self::WEBHOOK, $raw);

        Http::assertSent(fn (Request $request) => $request->url() === self::WEBHOOK.'profile');
    }

    public function test_portal_connect_is_admin_only(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]));

        $this->postJson('/api/bitrix/workspace', ['webhook' => self::WEBHOOK])->assertForbidden();

        $this->assertNull(BitrixWorkspace::active());
        Http::assertNothingSent();
    }

    public function test_malformed_webhook_is_rejected_before_any_request(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->postJson('/api/bitrix/workspace', ['webhook' => 'https://team.bitrix24.ua/'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_portal_rejecting_webhook_is_not_saved(): void
    {
        Http::fake([
            self::WEBHOOK.'profile' => Http::response(['error' => 'INVALID_CREDENTIALS'], 401),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->postJson('/api/bitrix/workspace', ['webhook' => self::WEBHOOK])->assertStatus(502);

        $this->assertNull(BitrixWorkspace::active());
    }

    public function test_disconnecting_portal_clears_bound_accounts(): void
    {
        $user = $this->connectedUser();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->deleteJson('/api/bitrix/workspace')->assertOk();

        $this->assertNull(BitrixWorkspace::active());
        $user->refresh();
        $this->assertNull($user->bitrix_user_id);
        $this->assertFalse($user->hasBitrixConnected());
    }

    public function test_user_selects_own_account_from_portal_users(): void
    {
        $this->workspace();
        $this->fakePortalUsers();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/bitrix/users')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Іван Петренко');

        $this->putJson('/api/bitrix/user', ['bitrix_user_id' => '9'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Олена Коваль');

        $user->refresh();
        $this->assertSame('9', $user->bitrix_user_id);
        $this->assertSame('Олена Коваль', $user->bitrix_user_name);
    }

    public function test_user_cannot_select_account_missing_on_portal(): void
    {
        $this->workspace();
        $this->fakePortalUsers();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/bitrix/user', ['bitrix_user_id' => '404'])->assertStatus(422);

        $this->assertNull($user->refresh()->bitrix_user_id);
    }

    public function test_tasks_come_from_portal_filtered_by_users_account(): void
    {
        Http::fake([
            self::WEBHOOK.'tasks.task.list' => Http::response([
                'result' => [
                    'tasks' => [
                        [
                            'id' => '42',
                            'title' => 'Таска дня',
                            'description' => '[b]Коментар[/b] і [url=https://team.ua]посилання[/url]',
                            'startDatePlan' => '2026-09-03T09:00:00+03:00',
                            'endDatePlan' => '2026-09-03T12:00:00+03:00',
                            'status' => '5',
                            'responsibleId' => '7',
                        ],
                    ],
                ],
                'total' => 1,
            ]),
        ]);

        Sanctum::actingAs($this->connectedUser());

        $response = $this->getJson('/api/tasks?date=2026-09-03')
            ->assertOk()
            ->assertJsonPath('provider', 'bitrix')
            // Три вибірки за різними полями дат мають злитись в одну таску.
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Таска дня')
            ->assertJsonPath('data.0.start', '2026-09-03 09:00')
            ->assertJsonPath('data.0.due', '2026-09-03 12:00')
            ->assertJsonPath('data.0.due_complete', true)
            ->assertJsonPath('data.0.list', 'Завершена');

        // BB-код в описі має перетворитись на звичайний текст.
        $this->assertSame(
            'Коментар і посилання (https://team.ua)',
            $response->json('data.0.comment'),
        );

        $this->assertSame(
            'https://team.bitrix24.ua/company/personal/user/7/tasks/task/view/42/',
            $response->json('data.0.url'),
        );

        Http::assertSent(fn (Request $request) => $request->url() === self::WEBHOOK.'tasks.task.list'
            && $request['filter']['RESPONSIBLE_ID'] === '7');
    }

    public function test_tasks_outside_the_day_are_dropped(): void
    {
        Http::fake([
            self::WEBHOOK.'tasks.task.list' => Http::response([
                'result' => [
                    'tasks' => [
                        [
                            'id' => '1',
                            'title' => 'Учорашня',
                            'description' => '',
                            'startDatePlan' => '2026-09-02T09:00:00+03:00',
                            'endDatePlan' => '2026-09-02T18:00:00+03:00',
                            'status' => '3',
                            'responsibleId' => '7',
                        ],
                        [
                            'id' => '2',
                            'title' => 'Лише дедлайн сьогодні',
                            'description' => '',
                            'startDatePlan' => '',
                            'endDatePlan' => '2026-09-03T15:00:00+03:00',
                            'status' => '3',
                            'responsibleId' => '7',
                        ],
                    ],
                ],
            ]),
        ]);

        Sanctum::actingAs($this->connectedUser());

        $this->getJson('/api/tasks?date=2026-09-03')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Лише дедлайн сьогодні')
            ->assertJsonPath('data.0.start', null);
    }

    public function test_tasks_report_not_connected_without_portal_account(): void
    {
        $this->workspace();

        $user = User::factory()->create();
        $user->forceFill(['task_provider' => User::TASK_PROVIDER_BITRIX])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/tasks?date=2026-09-03')
            ->assertStatus(503)
            ->assertJsonPath('not_connected', true)
            ->assertJsonPath('provider', 'bitrix');

        Http::assertNothingSent();
    }

    public function test_status_shows_team_portal_and_own_binding(): void
    {
        Sanctum::actingAs($this->connectedUser());

        $this->getJson('/api/bitrix/status')
            ->assertOk()
            ->assertJson([
                'workspace_connected' => true,
                'portal_url' => 'https://team.bitrix24.ua',
                'user_id' => '7',
                'user_name' => 'Іван Петренко',
                'connected' => true,
                'can_manage' => false,
            ]);
    }

    public function test_provider_switch_keeps_both_integrations_configured(): void
    {
        $user = $this->connectedUser();
        $user->forceFill([
            'trello_token' => 'user-token',
            'trello_board_id' => 'board42',
        ])->save();

        Sanctum::actingAs($user);

        $this->putJson('/api/tasks/provider', ['provider' => User::TASK_PROVIDER_TRELLO])
            ->assertOk()
            ->assertJsonPath('provider', 'trello');

        $user->refresh();
        $this->assertSame(User::TASK_PROVIDER_TRELLO, $user->taskProvider());
        // Перемикання нічого не відв'язує — Бітрікс лишається готовим до повернення.
        $this->assertSame('7', $user->bitrix_user_id);

        $this->putJson('/api/tasks/provider', ['provider' => 'jira'])->assertStatus(422);
    }

    public function test_rate_limited_call_is_retried(): void
    {
        // Перший запит упирається в ліміт запитів порталу — сервіс має
        // перечекати і повторити, а не завалити звіт.
        Http::fakeSequence()
            ->push(['error' => 'QUERY_LIMIT_EXCEEDED'], 503)
            ->whenEmpty(Http::response(['result' => ['tasks' => []]]));

        Sanctum::actingAs($this->connectedUser());

        $this->getJson('/api/tasks?date=2026-09-03')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // 503 + повтор + дві решта вибірок за датами.
        Http::assertSentCount(4);
    }
}
