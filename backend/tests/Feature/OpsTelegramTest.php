<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Технічний Telegram адміністратора: підключається з «Налаштувань» і живе
 * окремо від особистих сповіщень про звіти.
 */
class OpsTelegramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.bot_token', 'bot-token');
        config()->set('services.telegram.bot_username', 'TeamReporter_Bot');
        config()->set('services.telegram.webhook_secret', 'hook-secret');
        config()->set('services.telegram.ops_email', null);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function codeFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['start'];
    }

    private function start(string $code, string $chatId): void
    {
        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => $chatId], 'text' => "/start {$code}"],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
    }

    /** @return list<array<string, mixed>> */
    private function sentMessages(): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/sendMessage'))
            ->map(fn (array $pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    public function test_admin_links_ops_chat_separately_from_personal_one(): void
    {
        Http::fake();
        $admin = $this->admin();
        $admin->forceFill(['telegram_chat_id' => '111'])->save();
        Sanctum::actingAs($admin);

        $this->getJson('/api/alerts/telegram')->assertJson(['configured' => true, 'connected' => false]);

        $url = $this->postJson('/api/alerts/telegram/link')->assertOk()->json('url');
        $this->start($this->codeFrom($url), '-100200');

        $admin->refresh();
        $this->assertSame('-100200', $admin->ops_telegram_chat_id);
        $this->assertSame('111', $admin->telegram_chat_id);
        $this->getJson('/api/alerts/telegram')->assertJson(['connected' => true]);
    }

    public function test_ops_link_does_not_work_for_someone_no_longer_admin(): void
    {
        Http::fake();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $url = $this->postJson('/api/alerts/telegram/link')->json('url');
        $admin->update(['role' => User::ROLE_EMPLOYEE]);
        $this->start($this->codeFrom($url), '777');

        $this->assertNull($admin->fresh()->ops_telegram_chat_id);
    }

    public function test_employees_cannot_touch_ops_telegram(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]));

        $this->getJson('/api/alerts/telegram')->assertForbidden();
        $this->postJson('/api/alerts/telegram/link')->assertForbidden();
    }

    public function test_ops_notification_reaches_every_connected_admin_once(): void
    {
        Http::fake();
        config()->set('services.telegram.ops_email', 'dev@example.com');

        $this->admin()->forceFill(['ops_telegram_chat_id' => '500'])->save();
        // Розробник з OPS_TELEGRAM_EMAIL — він же адмін з тим самим чатом:
        // дубля бути не повинно.
        User::factory()->create(['email' => 'dev@example.com', 'role' => User::ROLE_ADMIN])
            ->forceFill(['telegram_chat_id' => '500', 'ops_telegram_chat_id' => '500'])->save();
        $this->admin()->forceFill(['ops_telegram_chat_id' => '600'])->save();
        // Колишній адмін зі старим чатом тривог не отримує.
        User::factory()->create(['role' => User::ROLE_EMPLOYEE])->forceFill(['ops_telegram_chat_id' => '700'])->save();

        app(TelegramService::class)->notifyOps('збій');

        $recipients = array_column($this->sentMessages(), 'chat_id');
        sort($recipients);
        $this->assertSame(['500', '600'], $recipients);
    }

    public function test_test_message_goes_to_ops_chat_and_unlink_stops_it(): void
    {
        Http::fake();
        $admin = $this->admin();
        $admin->forceFill(['ops_telegram_chat_id' => '500'])->save();
        Sanctum::actingAs($admin);

        $this->postJson('/api/alerts/telegram/test')->assertOk();
        $this->assertSame(['500'], array_column($this->sentMessages(), 'chat_id'));

        $this->deleteJson('/api/alerts/telegram')->assertOk();
        $this->assertNull($admin->fresh()->ops_telegram_chat_id);
        $this->postJson('/api/alerts/telegram/test')->assertStatus(422);
    }

    public function test_personal_link_still_works(): void
    {
        Http::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $url = $this->postJson('/api/telegram/link')->json('url');
        $this->start($this->codeFrom($url), '321');

        $this->assertSame('321', $user->fresh()->telegram_chat_id);
        $this->assertNull($user->fresh()->ops_telegram_chat_id);
    }
}
