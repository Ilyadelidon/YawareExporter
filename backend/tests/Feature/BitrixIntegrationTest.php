<?php

namespace Tests\Feature;

use App\Models\BitrixAccount;
use App\Models\BitrixWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BitrixIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PORTAL = 'https://team.bitrix24.ua';

    private const REST = 'https://team.bitrix24.ua/rest/';

    private const OAUTH = 'https://oauth.bitrix.info/oauth/token/';

    private const SECRET = 'app-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bitrix.timezone', 'Europe/Kyiv');
        config()->set('services.bitrix.oauth_url', self::OAUTH);
        config()->set('services.bitrix.frontend_url', 'https://reporter.test');
    }

    private function workspace(): BitrixWorkspace
    {
        return BitrixWorkspace::connect([
            'portal_url' => self::PORTAL,
            'client_id' => 'local.app',
            'client_secret' => self::SECRET,
        ]);
    }

    /** Працівник із Бітріксом як активним трекером і власним токеном порталу. */
    private function connectedUser(array $accountAttributes = []): User
    {
        $this->workspace();

        $user = User::factory()->create();
        $user->forceFill(['task_provider' => User::TASK_PROVIDER_BITRIX])->save();

        BitrixAccount::create($accountAttributes + [
            'user_id' => $user->id,
            'bitrix_user_id' => '7',
            'bitrix_user_name' => 'Іван Петренко',
            'bitrix_email' => 'ivan@team.ua',
            'client_endpoint' => self::REST,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        return $user->refresh();
    }

    /** Відповіді OAuth і profile — те, що бачить callback після згоди на порталі. */
    private function fakeAuthorization(array $profile = [], array $tokens = []): void
    {
        Http::fake([
            self::OAUTH.'*' => Http::response($tokens + [
                'access_token' => 'fresh-access',
                'refresh_token' => 'fresh-refresh',
                'expires_in' => 3600,
                'member_id' => 'member-1',
                'client_endpoint' => self::REST,
                // Бітрікс віддає тут домен видавця токенів, а не портал команди —
                // портал визначається лише за client_endpoint.
                'domain' => 'oauth.bitrix.info',
            ]),
            self::REST.'profile' => Http::response([
                'result' => $profile + ['ID' => '7'],
            ]),
            self::REST.'user.get' => Http::response([
                'result' => [['ID' => '7', 'NAME' => 'Іван', 'LAST_NAME' => 'Петренко', 'EMAIL' => 'ivan@team.ua']],
            ]),
        ]);
    }

    /** Проходить авторизацію працівника до кінця: старт → згода → callback. */
    private function authorize(User $user): string
    {
        Sanctum::actingAs($user);

        $url = $this->postJson('/api/bitrix/oauth/start')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->get("/api/bitrix/oauth/callback?code=auth-code&state={$query['state']}");

        return $url;
    }

    public function test_admin_connects_portal_and_app_secret_is_stored_encrypted(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->postJson('/api/bitrix/workspace', [
            'portal_url' => self::PORTAL.'/',
            'client_id' => 'local.app',
            'client_secret' => self::SECRET,
        ])
            ->assertCreated()
            ->assertJsonPath('portal_url', self::PORTAL);

        $workspace = BitrixWorkspace::active();
        $this->assertSame(self::SECRET, $workspace->client_secret);

        // Ключем застосунку обмінюють коди на токени працівників — у БД він має
        // лежати зашифрованим.
        $raw = DB::table('bitrix_workspaces')->where('id', $workspace->id)->value('client_secret');
        $this->assertNotSame(self::SECRET, $raw);

        // Реквізити самі по собі нікуди не ходять — жодного запиту при збереженні.
        Http::assertNothingSent();
    }

    public function test_portal_connect_is_admin_only(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]));

        $this->postJson('/api/bitrix/workspace', [
            'portal_url' => self::PORTAL,
            'client_id' => 'local.app',
            'client_secret' => self::SECRET,
        ])->assertForbidden();

        $this->assertNull(BitrixWorkspace::active());
    }

    public function test_malformed_portal_url_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->postJson('/api/bitrix/workspace', [
            'portal_url' => self::PORTAL.'/rest/1/webhook/',
            'client_id' => 'local.app',
            'client_secret' => self::SECRET,
        ])->assertStatus(422);

        $this->assertNull(BitrixWorkspace::active());
    }

    public function test_disconnecting_portal_removes_employee_tokens(): void
    {
        $user = $this->connectedUser();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->deleteJson('/api/bitrix/workspace')->assertOk();

        $this->assertNull(BitrixWorkspace::active());
        $this->assertSame(0, BitrixAccount::query()->count());
        $this->assertFalse($user->refresh()->hasBitrixConnected());
    }

    public function test_employee_authorizes_and_bitrix_decides_whose_account_it_is(): void
    {
        $this->workspace();
        $this->fakeAuthorization();

        $user = User::factory()->create();
        $url = $this->authorize($user);

        // Сторінка згоди — на самому порталі команди, з нашим redirect_uri.
        $this->assertStringStartsWith(self::PORTAL.'/oauth/authorize/?', $url);
        $this->assertStringContainsString('redirect_uri='.urlencode(url('/api/bitrix/oauth/callback')), $url);

        $account = $user->refresh()->bitrixAccount;
        $this->assertSame('7', $account->bitrix_user_id);
        $this->assertSame('Іван Петренко', $account->bitrix_user_name);
        // Пошту показує інтерфейс — за нею працівник упізнає свій акаунт.
        $this->assertSame('ivan@team.ua', $account->bitrix_email);
        $this->assertSame('fresh-access', $account->access_token);
        $this->assertSame('fresh-refresh', $account->refresh_token);

        // Токени — ключі доступу до порталу, у БД лежать зашифрованими.
        $raw = DB::table('bitrix_accounts')->where('id', $account->id)->value('refresh_token');
        $this->assertNotSame('fresh-refresh', $raw);
    }

    public function test_authorization_survives_portal_without_user_read_access(): void
    {
        $this->workspace();
        Http::fake([
            self::OAUTH.'*' => Http::response([
                'access_token' => 'fresh-access',
                'refresh_token' => 'fresh-refresh',
                'expires_in' => 3600,
                'client_endpoint' => self::REST,
            ]),
            self::REST.'profile' => Http::response(['result' => ['ID' => '7', 'NAME' => 'Іван']]),
            // Права user_brief може не бути — картка недоступна.
            self::REST.'user.get' => Http::response(['error' => 'ACCESS_DENIED'], 403),
        ]);

        $user = User::factory()->create();
        $this->authorize($user);

        $account = $user->refresh()->bitrixAccount;
        $this->assertNotNull($account);
        $this->assertSame('Іван', $account->bitrix_user_name);
        $this->assertNull($account->bitrix_email);
    }

    public function test_callback_without_valid_state_stores_nothing(): void
    {
        $this->workspace();
        $this->fakeAuthorization();

        $this->get('/api/bitrix/oauth/callback?code=auth-code&state=підроблений')
            ->assertRedirectContains('bitrix=error');

        $this->assertSame(0, BitrixAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_state_belongs_to_the_user_who_started_authorization(): void
    {
        $this->workspace();
        $this->fakeAuthorization();

        $starter = User::factory()->create();
        Sanctum::actingAs($starter);
        $url = $this->postJson('/api/bitrix/oauth/start')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        // Навіть якщо посилання відкриє інший залогінений користувач, токен
        // дістанеться тому, хто почав авторизацію.
        Sanctum::actingAs(User::factory()->create());
        $this->get("/api/bitrix/oauth/callback?code=auth-code&state={$query['state']}");

        $this->assertNotNull($starter->refresh()->bitrixAccount);
        $this->assertSame(1, BitrixAccount::query()->count());
    }

    public function test_authorization_on_another_portal_is_rejected(): void
    {
        $this->workspace();
        $this->fakeAuthorization(tokens: ['client_endpoint' => 'https://stranger.bitrix24.ua/rest/']);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $url = $this->postJson('/api/bitrix/oauth/start')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->get("/api/bitrix/oauth/callback?code=auth-code&state={$query['state']}")
            ->assertRedirectContains(urlencode('Це інший портал'));

        $this->assertNull($user->refresh()->bitrixAccount);
    }

    public function test_account_already_taken_by_another_user_is_rejected(): void
    {
        $this->connectedUser();
        $this->fakeAuthorization();

        // Другий користувач сервісу приходить з тим самим акаунтом порталу.
        $intruder = User::factory()->create();
        $this->authorize($intruder);

        $this->assertNull($intruder->refresh()->bitrixAccount);
        $this->assertSame(1, BitrixAccount::query()->count());
    }

    public function test_tasks_are_requested_with_personal_token(): void
    {
        Http::fake([
            self::REST.'tasks.task.list' => Http::response([
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
            self::PORTAL.'/company/personal/user/7/tasks/task/view/42/',
            $response->json('data.0.url'),
        );

        // Запит іде особистим токеном працівника — саме тому чужі таски недосяжні.
        Http::assertSent(fn (Request $request) => $request->url() === self::REST.'tasks.task.list'
            && $request['auth'] === 'access-token'
            && $request['filter']['RESPONSIBLE_ID'] === '7');
    }

    public function test_tasks_outside_the_day_are_dropped(): void
    {
        Http::fake([
            self::REST.'tasks.task.list' => Http::response([
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

    public function test_expired_token_is_refreshed_before_the_call(): void
    {
        Http::fake([
            self::OAUTH.'*' => Http::response([
                'access_token' => 'second-access',
                'refresh_token' => 'second-refresh',
                'expires_in' => 3600,
                'client_endpoint' => self::REST,
            ]),
            self::REST.'tasks.task.list' => Http::response(['result' => ['tasks' => []]]),
        ]);

        $user = $this->connectedUser(['expires_at' => now()->subMinute()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/tasks?date=2026-09-03')->assertOk();

        $account = $user->refresh()->bitrixAccount;
        $this->assertSame('second-access', $account->access_token);
        // Refresh-токен одноразовий: зберігаємо саме новий, інакше наступне
        // оновлення відвалиться.
        $this->assertSame('second-refresh', $account->refresh_token);

        Http::assertSent(fn (Request $request) => $request->url() === self::REST.'tasks.task.list'
            && $request['auth'] === 'second-access');
    }

    public function test_revoked_access_drops_the_account_and_asks_to_reconnect(): void
    {
        Http::fake([
            self::OAUTH.'*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is expired'], 400),
        ]);

        $user = $this->connectedUser(['expires_at' => now()->subMinute()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/tasks?date=2026-09-03')->assertStatus(503);

        $this->assertNull($user->refresh()->bitrixAccount);
    }

    public function test_tasks_report_not_connected_without_authorization(): void
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

    public function test_start_is_refused_until_admin_connects_the_portal(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bitrix/oauth/start')->assertStatus(409);

        $this->assertSame(0, Cache::get('bitrix.oauth.state.any', 0));
    }

    public function test_unlinking_own_account_keeps_the_team_portal(): void
    {
        $user = $this->connectedUser();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/bitrix/user')->assertOk();

        $this->assertNull($user->refresh()->bitrixAccount);
        $this->assertNotNull(BitrixWorkspace::active());
    }

    public function test_status_shows_team_portal_and_own_account(): void
    {
        Sanctum::actingAs($this->connectedUser());

        $this->getJson('/api/bitrix/status')
            ->assertOk()
            ->assertJson([
                'workspace_connected' => true,
                'portal_url' => self::PORTAL,
                'user_id' => '7',
                'user_name' => 'Іван Петренко',
                'user_email' => 'ivan@team.ua',
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
        $this->assertNotNull($user->bitrixAccount);

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
