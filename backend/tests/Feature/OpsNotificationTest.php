<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\DailyRunSummary;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Технічні сповіщення розробнику: підсумок ранкової автогенерації і сигнал
 * про падіння планувальника. Тут — адресат з OPS_TELEGRAM_EMAIL; технічний
 * чат адміністраторів перевіряє OpsTelegramTest.
 */
class OpsNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.bot_token', 'bot-token');
        config()->set('services.telegram.bot_username', 'TeamReporter_Bot');
        config()->set('services.telegram.ops_email', 'Ilya.dev2715@gmail.com');
    }

    private function ops(string $email = 'ilya.dev2715@gmail.com'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->forceFill(['telegram_chat_id' => '555000'])->save();

        return $user;
    }

    private function sentMessages(): array
    {
        $messages = [];

        Http::recorded(function (Request $request) use (&$messages) {
            if (str_contains($request->url(), '/sendMessage')) {
                $messages[] = $request->data();
            }

            return true;
        });

        return $messages;
    }

    public function test_ops_notification_finds_recipient_regardless_of_email_case(): void
    {
        Http::fake();
        // У базі пошта малими літерами, у конфізі — з великої: збігтися має все одно.
        $this->ops();

        app(TelegramService::class)->notifyOps('перевірка');

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('555000', $messages[0]['chat_id']);
        $this->assertSame('перевірка', $messages[0]['text']);
    }

    public function test_nothing_is_sent_when_ops_email_is_empty(): void
    {
        Http::fake();
        config()->set('services.telegram.ops_email', null);
        $this->ops();

        app(TelegramService::class)->notifyOps('перевірка');

        Http::assertNothingSent();
    }

    public function test_missing_recipient_is_logged_not_swallowed(): void
    {
        Http::fake();
        Log::spy();

        app(TelegramService::class)->notifyOps('перевірка');

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_recipient_without_linked_telegram_is_logged(): void
    {
        Http::fake();
        Log::spy();
        User::factory()->create(['email' => 'ilya.dev2715@gmail.com']);

        app(TelegramService::class)->notifyOps('перевірка');

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_employees_never_receive_ops_notifications(): void
    {
        Http::fake();
        $this->ops();

        $employeeUser = User::factory()->create(['email' => 'petro@example.com']);
        $employeeUser->forceFill(['telegram_chat_id' => '999111'])->save();

        app(TelegramService::class)->notifyOps('службове');

        $recipients = array_column($this->sentMessages(), 'chat_id');
        $this->assertSame(['555000'], $recipients);
    }

    public function test_daily_run_reports_queued_and_skipped_employees(): void
    {
        Http::fake();
        $this->ops();

        // Працівник без кредів Yaware — єдина причина пропуску, яку видно
        // без налаштованих Trello і Google.
        Employee::create([
            'name' => 'Петро',
            'email' => 'petro@example.com',
            'active' => true,
        ]);

        $this->artisan('reports:generate-daily', ['--date' => '2026-07-20'])
            ->assertSuccessful();

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);

        $text = $messages[0]['text'];
        $this->assertStringContainsString('20.07.2026', $text);
        $this->assertStringNotContainsString('Сформовано', $text);
        $this->assertStringContainsString('❌ Не сформовано: 1', $text);
        $this->assertStringContainsString('Петро', $text);
        $this->assertStringContainsString('кредів Yaware', $text);
    }

    public function test_daily_run_says_when_there_are_no_active_employees(): void
    {
        Http::fake();
        $this->ops();

        $this->artisan('reports:generate-daily', ['--date' => '2026-07-20'])
            ->assertSuccessful();

        $this->assertStringContainsString(
            'Активних працівників не знайдено',
            $this->sentMessages()[0]['text'],
        );
    }

    public function test_daily_summary_lists_who_got_a_report_and_who_did_not(): void
    {
        $report = function (string $name, array $attributes) {
            $employee = Employee::create(['name' => $name, 'email' => mb_strtolower($name).'@example.com', 'active' => true]);

            return Report::create(['employee_id' => $employee->id, 'report_date' => '2026-09-30'] + $attributes)->id;
        };

        $ids = [
            $report('Анна', [
                'status' => Report::STATUS_COMPLETED,
                'tasks' => [['name' => 'Таска']],
                'summary' => [Report::SUMMARY_GOOGLE_SHEET => 'https://docs.google.com/x'],
            ]),
            $report('Борис', ['status' => Report::STATUS_FAILED, 'error_message' => 'Yaware не відповідає.']),
            $report('Віра', ['status' => Report::STATUS_BLOCKED, 'error_message' => 'У дні є 40 хв робочого часу поза тасками.']),
            $report('Гліб', ['status' => Report::STATUS_COMPLETED, 'tasks' => [], 'summary' => []]),
            $report('Дана', [
                'status' => Report::STATUS_COMPLETED,
                'tasks' => [],
                'summary' => [Report::SUMMARY_RESULT => Report::EMPTY_DAY_RESULT],
            ]),
        ];

        $petro = Employee::create(['name' => 'Петро', 'email' => 'petro@example.com', 'active' => true]);

        $text = app(DailyRunSummary::class)->text('2026-09-30', $ids, [$petro->id => 'немає кредів Yaware.']);

        $this->assertStringContainsString('30.09.2026', $text);
        $this->assertStringContainsString("✅ Сформовано: 2\n• Анна\n• Дана — день без активності", $text);
        $this->assertStringNotContainsString('#', $text);
        $this->assertStringContainsString('❌ Не сформовано: 4', $text);
        $this->assertStringContainsString('Yaware не відповідає.', $text);
        $this->assertStringContainsString('поза тасками', $text);
        $this->assertStringContainsString('тасок за день немає', $text);
        $this->assertStringContainsString('• Петро — немає кредів Yaware.', $text);
    }

    public function test_daily_summary_flags_a_report_the_queue_left_unfinished(): void
    {
        $employee = Employee::create(['name' => 'Анна', 'email' => 'anna@example.com', 'active' => true]);
        $id = Report::create([
            'employee_id' => $employee->id,
            'report_date' => '2026-09-30',
            'status' => Report::STATUS_PROCESSING,
        ])->id;

        $text = app(DailyRunSummary::class)->text('2026-09-30', [$id], []);

        $this->assertStringContainsString('❌ Не сформовано: 1', $text);
        $this->assertStringContainsString('генерація обірвалась', $text);
    }
}
