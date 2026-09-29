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
 * Готовність до формування звіту: активний трекер і Google Таблиця.
 * Відповідь збирається з бази, без запитів до Trello, Бітрікса чи Google.
 */
class IntegrationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_connected(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/integrations/status')
            ->assertOk()
            ->assertExactJson([
                'tracker' => ['provider' => 'trello', 'connected' => false],
                'sheets' => ['connected' => false],
            ]);

        Http::assertNothingSent();
    }

    public function test_readiness_follows_active_tracker(): void
    {
        Http::fake();
        BitrixWorkspace::connect([
            'portal_url' => 'https://team.bitrix24.ua',
            'client_id' => 'local.app',
            'client_secret' => 'secret',
        ]);

        $user = User::factory()->create();
        $user->connectTrello('user-token', 'ivan');
        Sanctum::actingAs($user);

        $this->getJson('/api/integrations/status')
            ->assertJsonPath('tracker.provider', 'trello')
            ->assertJsonPath('tracker.connected', true);

        // Бітрікс активний, але працівник ще не авторизувався на порталі.
        $user->forceFill(['task_provider' => User::TASK_PROVIDER_BITRIX])->save();

        $this->getJson('/api/integrations/status')
            ->assertJsonPath('tracker.provider', 'bitrix')
            ->assertJsonPath('tracker.connected', false);

        BitrixAccount::create([
            'user_id' => $user->id,
            'bitrix_user_id' => '7',
            'client_endpoint' => 'https://team.bitrix24.ua/rest/',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        // actingAs тримає той самий екземпляр — скидаємо закешований зв'язок.
        $user->unsetRelation('bitrixAccount');

        $this->getJson('/api/integrations/status')->assertJsonPath('tracker.connected', true);

        Http::assertNothingSent();
    }

    public function test_spreadsheet_counts_only_with_service_google_account(): void
    {
        config()->set('services.google.token_path', null);

        $user = User::factory()->create();
        $user->attachSpreadsheet('1AbCdEfGhIjKlMnOpQrStUvWxYz');
        Sanctum::actingAs($user);

        $this->getJson('/api/integrations/status')->assertJsonPath('sheets.connected', false);
    }
}
