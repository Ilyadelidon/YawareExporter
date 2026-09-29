<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Особисті Telegram-сповіщення: працівник бере одноразове посилання на
 * бота, тисне Start — і чат прив'язується до його акаунта.
 */
class TelegramLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.bot_token', 'bot-token');
        config()->set('services.telegram.bot_username', 'TeamReporter_Bot');
        config()->set('services.telegram.webhook_secret', 'hook-secret');
    }

    private function start(string $text, string $chatId = '555'): void
    {
        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => $chatId], 'text' => $text],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
    }

    public function test_start_with_code_links_chat_once(): void
    {
        Http::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/telegram/status')->assertJson(['configured' => true, 'connected' => false]);

        $url = $this->postJson('/api/telegram/link')->assertOk()->json('url');
        $this->assertStringStartsWith('https://t.me/TeamReporter_Bot?start=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->start("/start {$query['start']}");
        $this->assertSame('555', $user->refresh()->telegram_chat_id);
        $this->getJson('/api/telegram/status')->assertJson(['connected' => true]);

        // Код одноразовий: повторний Start з іншого чату нічого не змінює.
        $this->start("/start {$query['start']}", '999');
        $this->assertSame('555', $user->refresh()->telegram_chat_id);
    }

    public function test_link_is_refused_until_bot_is_configured(): void
    {
        config()->set('services.telegram.bot_token', null);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/telegram/status')->assertJson(['configured' => false]);
        $this->postJson('/api/telegram/link')->assertStatus(503);
    }

    public function test_webhook_requires_secret(): void
    {
        $this->postJson('/api/telegram/webhook', ['message' => []], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])
            ->assertForbidden();
    }

    public function test_unlink_stops_notifications(): void
    {
        $user = User::factory()->create();
        $user->linkTelegram('555');
        Sanctum::actingAs($user);

        $this->deleteJson('/api/telegram/link')->assertOk();

        $this->assertFalse($user->refresh()->hasTelegramConnected());
    }
}
