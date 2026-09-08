<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrelloAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.trello.key', 'app-key');
        config()->set('services.trello.template_board_id', 'tpl123');
        config()->set('services.trello.timezone', 'Europe/Kyiv');
    }

    private function connectedUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'trello_token' => 'user-token',
            'trello_member_username' => 'ivan',
            'trello_board_id' => 'board42',
        ])->save();

        return $user;
    }

    public function test_store_token_validates_via_members_me_and_saves_encrypted(): void
    {
        Http::fake([
            'https://api.trello.com/1/members/me*' => Http::response(['username' => 'ivan', 'fullName' => 'Ivan']),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/trello/token', ['token' => 'fresh-token'])
            ->assertOk()
            ->assertJsonPath('username', 'ivan');

        $user->refresh();
        $this->assertSame('fresh-token', $user->trello_token);
        $this->assertSame('ivan', $user->trello_member_username);

        // У БД токен має лежати зашифрованим, а не відкритим текстом.
        $raw = DB::table('users')->where('id', $user->id)->value('trello_token');
        $this->assertNotSame('fresh-token', $raw);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'members/me')
            && str_contains($request->url(), 'token=fresh-token'));
    }

    public function test_store_token_rejects_token_trello_declined(): void
    {
        Http::fake([
            'https://api.trello.com/1/members/me*' => Http::response('invalid token', 401),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/trello/token', ['token' => 'bad-token'])->assertStatus(422);

        $this->assertFalse($user->refresh()->hasTrelloConnected());
    }

    public function test_create_board_clones_template_and_becomes_active(): void
    {
        // tpl123 — короткий id з URL дошки; сервіс має розгорнути його у повний
        // 24-символьний, бо лише такий приймає idBoardSource.
        Http::fake([
            'https://api.trello.com/1/boards/tpl123*' => Http::response([
                'id' => '5f4e3d2c1b0a998877665544',
            ]),
            'https://api.trello.com/1/boards/*' => Http::response([
                'id' => 'newBoard1',
                'name' => 'Моя дошка',
                'shortUrl' => 'https://trello.com/b/newBoard1',
            ]),
        ]);

        $user = $this->connectedUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/trello/boards', ['name' => 'Моя дошка'])
            ->assertCreated()
            ->assertJsonPath('board.id', 'newBoard1');

        $this->assertSame('newBoard1', $user->refresh()->trello_board_id);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), 'idBoardSource=5f4e3d2c1b0a998877665544')
            && str_contains($request->url(), 'keepFromSource=none')
            && str_contains($request->url(), 'token=user-token'));
    }

    public function test_create_board_uses_full_template_id_without_extra_request(): void
    {
        config()->set('services.trello.template_board_id', '5f4e3d2c1b0a998877665544');

        Http::fake([
            'https://api.trello.com/1/boards/*' => Http::response([
                'id' => 'newBoard2',
                'name' => 'Друга дошка',
            ]),
        ]);

        Sanctum::actingAs($this->connectedUser());

        $this->postJson('/api/trello/boards', ['name' => 'Друга дошка'])->assertCreated();

        // Повний id іде в idBoardSource одразу — без GET-запиту на розгортання.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), 'idBoardSource=5f4e3d2c1b0a998877665544'));
    }

    public function test_select_board_accepts_only_own_boards(): void
    {
        Http::fake([
            'https://api.trello.com/1/members/me/boards*' => Http::response([
                ['id' => 'b1', 'name' => 'Перша'],
                ['id' => 'b2', 'name' => 'Друга'],
            ]),
        ]);

        $user = $this->connectedUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/trello/board', ['board_id' => 'foreign'])->assertStatus(422);
        $this->assertSame('board42', $user->refresh()->trello_board_id);

        $this->putJson('/api/trello/board', ['board_id' => 'b2'])
            ->assertOk()
            ->assertJsonPath('board.name', 'Друга');
        $this->assertSame('b2', $user->refresh()->trello_board_id);
    }

    public function test_disconnect_revokes_token_and_clears_fields(): void
    {
        Http::fake([
            'https://api.trello.com/1/tokens/*' => Http::response([], 200),
        ]);

        $user = $this->connectedUser();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/trello/token')->assertOk();

        $user->refresh();
        $this->assertFalse($user->hasTrelloConnected());
        $this->assertNull($user->trello_member_username);
        $this->assertNull($user->trello_board_id);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), 'tokens/user-token'));
    }

    public function test_tasks_use_users_token_and_board(): void
    {
        Http::fake([
            'https://api.trello.com/1/boards/board42/lists*' => Http::response([
                ['id' => 'l1', 'name' => 'Doing'],
            ]),
            'https://api.trello.com/1/boards/board42/cards*' => Http::response([
                [
                    'id' => 'c1',
                    'name' => 'Таска дня',
                    'desc' => 'коментар',
                    'idList' => 'l1',
                    'start' => '2026-07-10T06:00:00.000Z',
                    'due' => '2026-07-10T09:00:00.000Z',
                    'dueComplete' => false,
                    'labels' => [],
                ],
            ]),
        ]);

        Sanctum::actingAs($this->connectedUser());

        $this->getJson('/api/tasks?date=2026-07-10')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Таска дня');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'boards/board42/cards')
            && str_contains($request->url(), 'token=user-token'));
    }

    public function test_tasks_report_not_connected_when_user_has_no_token(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/tasks?date=2026-07-10')
            ->assertStatus(503)
            ->assertJsonPath('not_connected', true);

        Http::assertNothingSent();
    }

    public function test_completed_report_exposes_tasks_snapshot(): void
    {
        $user = User::factory()->create();
        $employee = Employee::create([
            'user_id' => $user->id,
            'name' => 'Іван',
            'email' => $user->email,
            'active' => true,
        ]);

        $snapshot = [
            ['id' => 'c1', 'name' => 'Таска зі знімка', 'comment' => '', 'labels' => []],
        ];

        $report = Report::create([
            'employee_id' => $employee->id,
            'report_date' => '2026-07-10',
            'status' => Report::STATUS_COMPLETED,
            'tasks' => $snapshot,
        ]);

        Sanctum::actingAs($user);

        // Знімок доступний і в списку (його читає сторінка звіту), і в show.
        $this->getJson('/api/reports?date_from=2026-07-10&date_to=2026-07-10')
            ->assertOk()
            ->assertJsonPath('data.0.tasks.0.name', 'Таска зі знімка');

        $this->getJson("/api/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.tasks.0.name', 'Таска зі знімка');
    }

    public function test_status_reports_connection_and_api_key(): void
    {
        Http::fake([
            'https://api.trello.com/1/boards/board42*' => Http::response([
                'name' => 'ReportExporter',
                'shortUrl' => 'https://trello.com/b/board42',
            ]),
        ]);

        Sanctum::actingAs($this->connectedUser());

        $this->getJson('/api/trello/status')
            ->assertOk()
            ->assertJson([
                'connected' => true,
                'username' => 'ivan',
                'board_id' => 'board42',
                'board_name' => 'ReportExporter',
                'board_url' => 'https://trello.com/b/board42',
                'api_key' => 'app-key',
            ]);
    }
}
