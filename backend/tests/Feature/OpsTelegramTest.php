<?php

namespace Tests\Feature;

use App\Models\OpsTelegramChat;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Технічний Telegram адміністратора: підключається з «Налаштувань», живе
 * окремо від особистих сповіщень про звіти, і чатів може бути кілька.
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

    private function opsChat(User $user, string $chatId, ?string $title = null): OpsTelegramChat
    {
        return $user->opsTelegramChats()->create(['chat_id' => $chatId, 'title' => $title]);
    }

    private function codeFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['start'];
    }

    private function start(string $code, string $chatId, array $chat = []): void
    {
        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => $chatId] + $chat, 'text' => "/start {$code}"],
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

        $this->getJson('/api/alerts/telegram')->assertJson(['configured' => true, 'chats' => []]);

        $url = $this->postJson('/api/alerts/telegram/link')->assertOk()->json('url');
        $this->start($this->codeFrom($url), '-100200', ['title' => 'Чергування']);

        $this->assertSame('-100200', $admin->opsTelegramChats()->value('chat_id'));
        $this->assertSame('111', $admin->fresh()->telegram_chat_id);
        $this->getJson('/api/alerts/telegram')->assertJsonPath('chats.0.title', 'Чергування');
    }

    public function test_admin_keeps_several_ops_chats_and_removes_one(): void
    {
        Http::fake();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        foreach ([['-100200', ['title' => 'Чергування']], ['300', ['first_name' => 'Ілля', 'username' => 'delidon']]] as [$chatId, $chat]) {
            $url = $this->postJson('/api/alerts/telegram/link')->json('url');
            $this->start($this->codeFrom($url), $chatId, $chat);
        }

        $this->getJson('/api/alerts/telegram')
            ->assertJsonCount(2, 'chats')
            ->assertJsonPath('chats.0.title', 'Чергування')
            ->assertJsonPath('chats.0.kind', 'group')
            ->assertJsonPath('chats.1.title', 'Ілля (@delidon)')
            ->assertJsonPath('chats.1.kind', 'private');

        $first = $admin->opsTelegramChats()->first();
        $this->deleteJson("/api/alerts/telegram/{$first->id}")->assertOk();

        $this->assertSame(['300'], $admin->opsTelegramChats()->pluck('chat_id')->all());
    }

    public function test_group_links_with_the_command_addressed_to_the_bot(): void
    {
        // У групі команда приходить разом з іменем бота — код усе одно має
        // прочитатись, інакше групу не підключити взагалі.
        Http::fake();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $url = $this->postJson('/api/alerts/telegram/link')->json('url');
        $code = $this->codeFrom($url);
        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => '-1001234567', 'title' => 'TeamReporter — чергування', 'type' => 'supergroup'],
                'text' => "/start@TeamReporter_Bot {$code}",
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();

        $this->assertSame('TeamReporter — чергування', $admin->opsTelegramChats()->value('title'));
    }

    public function test_repeated_start_in_linked_chat_refreshes_title_without_duplicating(): void
    {
        Http::fake();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $url = $this->postJson('/api/alerts/telegram/link')->json('url');
        $this->start($this->codeFrom($url), '-100200', ['title' => 'Чергування']);

        $url = $this->postJson('/api/alerts/telegram/link')->json('url');
        $this->start($this->codeFrom($url), '-100200', ['title' => 'Чергування 24/7']);

        $this->assertCount(1, $admin->opsTelegramChats()->get());
        $this->assertSame('Чергування 24/7', $admin->opsTelegramChats()->value('title'));
    }

    public function test_chat_without_a_remembered_title_asks_telegram_for_it(): void
    {
        // Чати, підключені до появи списку, переїхали з users без назви —
        // у списку має зʼявитись нік із Telegram, а не номер чату.
        Http::fake([
            '*/getChat' => Http::response(['ok' => true, 'result' => [
                'id' => 868276169, 'first_name' => 'Ілля', 'username' => 'delidon', 'type' => 'private',
            ]]),
        ]);
        $admin = $this->admin();
        $chat = $this->opsChat($admin, '868276169');
        Sanctum::actingAs($admin);

        $this->getJson('/api/alerts/telegram')->assertJsonPath('chats.0.title', 'Ілля (@delidon)');

        // Запитуємо один раз: далі назва вже збережена.
        $this->assertSame('Ілля (@delidon)', $chat->fresh()->title);
        $this->getJson('/api/alerts/telegram')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_settings_open_even_if_telegram_does_not_answer(): void
    {
        Http::fake(['*/getChat' => Http::response(status: 400)]);
        $admin = $this->admin();
        $this->opsChat($admin, '500');
        Sanctum::actingAs($admin);

        $this->getJson('/api/alerts/telegram')->assertOk()->assertJsonPath('chats.0.title', 'Чат 500');
    }

    public function test_link_refuses_beyond_the_limit(): void
    {
        Http::fake();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        for ($i = 0; $i < OpsTelegramChat::MAX_PER_USER; $i++) {
            $this->opsChat($admin, "10{$i}");
        }

        $this->postJson('/api/alerts/telegram/link')->assertStatus(422);
    }

    public function test_ops_link_does_not_work_for_someone_no_longer_admin(): void
    {
        Http::fake();
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $url = $this->postJson('/api/alerts/telegram/link')->json('url');
        $admin->update(['role' => User::ROLE_EMPLOYEE]);
        $this->start($this->codeFrom($url), '777');

        $this->assertCount(0, $admin->opsTelegramChats()->get());
    }

    public function test_employees_cannot_touch_ops_telegram(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]));

        $this->getJson('/api/alerts/telegram')->assertForbidden();
        $this->postJson('/api/alerts/telegram/link')->assertForbidden();
    }

    public function test_admin_cannot_touch_someone_elses_chat(): void
    {
        Http::fake();
        $chat = $this->opsChat($this->admin(), '500');
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/alerts/telegram/{$chat->id}")->assertNotFound();
        $this->postJson("/api/alerts/telegram/{$chat->id}/test")->assertNotFound();
        $this->assertSame(1, OpsTelegramChat::count());
    }

    public function test_ops_notification_reaches_every_connected_chat_once(): void
    {
        Http::fake();
        config()->set('services.telegram.ops_email', 'dev@example.com');

        // Один адміністратор із двома чатами — тривога має прийти в обидва.
        $admin = $this->admin();
        $this->opsChat($admin, '500');
        $this->opsChat($admin, '600');
        // Розробник з OPS_TELEGRAM_EMAIL — він же адмін з тим самим чатом:
        // дубля бути не повинно.
        $developer = User::factory()->create(['email' => 'dev@example.com', 'role' => User::ROLE_ADMIN]);
        $developer->forceFill(['telegram_chat_id' => '500'])->save();
        $this->opsChat($developer, '500');
        // Колишній адмін зі старим чатом тривог не отримує.
        $this->opsChat(User::factory()->create(['role' => User::ROLE_EMPLOYEE]), '700');

        app(TelegramService::class)->notifyOps('збій');

        $recipients = array_column($this->sentMessages(), 'chat_id');
        sort($recipients);
        $this->assertSame(['500', '600'], $recipients);
    }

    public function test_test_message_goes_to_the_chosen_chat_and_unlink_stops_it(): void
    {
        Http::fake();
        $admin = $this->admin();
        $chat = $this->opsChat($admin, '500');
        $this->opsChat($admin, '600');
        Sanctum::actingAs($admin);

        $this->postJson("/api/alerts/telegram/{$chat->id}/test")->assertOk();
        $this->assertSame(['500'], array_column($this->sentMessages(), 'chat_id'));

        $this->deleteJson("/api/alerts/telegram/{$chat->id}")->assertOk();
        $this->assertSame(['600'], $admin->opsTelegramChats()->pluck('chat_id')->all());
        $this->postJson("/api/alerts/telegram/{$chat->id}/test")->assertNotFound();
    }

    public function test_personal_link_still_works(): void
    {
        Http::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $url = $this->postJson('/api/telegram/link')->json('url');
        $this->start($this->codeFrom($url), '321');

        $this->assertSame('321', $user->fresh()->telegram_chat_id);
        $this->assertCount(0, $user->opsTelegramChats()->get());
    }
}
