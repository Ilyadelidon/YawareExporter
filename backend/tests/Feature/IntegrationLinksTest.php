<?php

namespace Tests\Feature;

use App\Models\BitrixAccount;
use App\Models\BitrixWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Посилання для кнопок переходу в боковому меню: показуємо лише те,
 * що справді підключено, і лише активний таск-трекер.
 */
class IntegrationLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_nothing_when_nothing_connected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/integrations/links')
            ->assertOk()
            ->assertJson(['google' => null, 'tracker' => null]);
    }

    public function test_returns_spreadsheet_url_for_linked_sheet(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['google_spreadsheet_id' => 'sheet-1'])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/integrations/links')
            ->assertOk()
            ->assertJsonPath('google.url', 'https://docs.google.com/spreadsheets/d/sheet-1/edit');
    }

    public function test_returns_active_board_url_from_trello(): void
    {
        Http::fake([
            'https://api.trello.com/1/boards/*' => Http::response([
                'name' => 'Звіти Івана',
                'shortUrl' => 'https://trello.com/b/abc123',
            ]),
        ]);

        $user = User::factory()->create();
        $user->forceFill([
            'trello_token' => 'user-token',
            'trello_board_id' => 'board42',
        ])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/integrations/links')
            ->assertOk()
            ->assertJsonPath('tracker.provider', 'trello')
            ->assertJsonPath('tracker.name', 'Звіти Івана')
            ->assertJsonPath('tracker.url', 'https://trello.com/b/abc123');
    }

    public function test_trello_without_board_gives_no_button(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['trello_token' => 'user-token'])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/integrations/links')
            ->assertOk()
            ->assertJsonPath('tracker', null);
    }

    public function test_falls_back_to_short_link_when_trello_is_unavailable(): void
    {
        Http::fake(['https://api.trello.com/*' => Http::response(status: 401)]);

        $user = User::factory()->create();
        $user->forceFill([
            'trello_token' => 'user-token',
            'trello_board_id' => 'board42',
        ])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/integrations/links')
            ->assertOk()
            ->assertJsonPath('tracker.url', 'https://trello.com/b/board42');
    }

    public function test_returns_personal_tasks_url_when_bitrix_is_active(): void
    {
        BitrixWorkspace::connect([
            'portal_url' => 'https://team.bitrix24.ua',
            'client_id' => 'local.app',
            'client_secret' => 'secret',
        ]);

        $user = User::factory()->create();
        $user->forceFill([
            'task_provider' => User::TASK_PROVIDER_BITRIX,
            // Trello лишається підключеним, але кнопка має бути від активного трекера.
            'trello_token' => 'user-token',
            'trello_board_id' => 'board42',
        ])->save();

        BitrixAccount::create([
            'user_id' => $user->id,
            'bitrix_user_id' => '7',
            'bitrix_user_name' => 'Іван Петренко',
            'client_endpoint' => 'https://team.bitrix24.ua/rest/',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        Sanctum::actingAs($user->refresh());

        $this->getJson('/api/integrations/links')
            ->assertOk()
            ->assertJsonPath('tracker.provider', 'bitrix')
            ->assertJsonPath('tracker.url', 'https://team.bitrix24.ua/company/personal/user/7/tasks/');
    }
}
