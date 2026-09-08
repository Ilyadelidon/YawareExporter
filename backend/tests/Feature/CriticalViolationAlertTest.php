<?php

namespace Tests\Feature;

use App\Jobs\GenerateDailyAnalysis;
use App\Mail\CriticalViolationMail;
use App\Models\ActivityEntry;
use App\Models\DailyAnalysis;
use App\Models\Employee;
use App\Models\Report;
use App\Models\User;
use App\Services\Ai\AnalysisPrompt;
use App\Services\ViolationAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Листи керівнику про критичні порушення: кому йдуть, коли не йдуть і чому
 * не повторюються. Ціна помилки тут висока в обидва боки — і пропущене
 * порушення, і лист про кожну перегенерацію дня однаково знецінюють функцію.
 */
class CriticalViolationAlertTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        return Employee::create([
            'name' => 'Іван',
            'position' => 'Frontend-розробник',
            'email' => 'ivan@example.com',
        ]);
    }

    /**
     * @param  list<string>  $alertEmails
     */
    private function admin(array $alertEmails = ['boss@example.com']): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'alert_emails' => $alertEmails,
        ]);
    }

    /**
     * @param  list<array<string, string>>  $violations
     */
    private function analysis(Employee $employee, array $violations): DailyAnalysis
    {
        return DailyAnalysis::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-20',
            'status' => DailyAnalysis::STATUS_COMPLETED,
            'result' => [
                'summary' => 'Половина дня пішла не на роботу.',
                'violations' => $violations,
            ],
            'generated_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function violation(string $severity = AnalysisPrompt::SEVERITY_CRITICAL): array
    {
        return [
            'type' => 'personal_time',
            'severity' => $severity,
            'details' => '2 год 40 хв на YouTube.',
            'evidence' => 'youtube.com — 9600 с',
            'question' => 'Що це був за перегляд у робочий час?',
        ];
    }

    public function test_critical_violation_reaches_admins_who_asked_for_letters(): void
    {
        Mail::fake();

        // Один керівник читає такі листи у двох скриньках — має прийти в обидві.
        $this->admin(['boss@example.com', 'boss.personal@example.com']);
        $this->admin(['ANOTHER@Example.com', 'boss@example.com']);
        // Адміністратор без пошт сповіщень і звичайний працівник листів не отримують.
        $this->admin([]);
        User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'alert_emails' => ['ivan@example.com']]);

        $analysis = $this->analysis($this->employee(), [$this->violation()]);

        app(ViolationAlertService::class)->notify($analysis);

        // Три різні адреси на трьох адміністраторів: спільна boss@ повторюється
        // у двох, але лист по ній має бути один.
        Mail::assertSent(CriticalViolationMail::class, 3);
        Mail::assertSent(
            CriticalViolationMail::class,
            fn (CriticalViolationMail $mail) => $mail->hasTo('boss@example.com')
                && $mail->employeeName === 'Іван'
                && $mail->date === '2026-07-20'
                && $mail->violations[0]['question'] === 'Що це був за перегляд у робочий час?',
        );
        Mail::assertSent(
            CriticalViolationMail::class,
            fn (CriticalViolationMail $mail) => $mail->hasTo('boss.personal@example.com'),
        );
        // Пошта порівнюється в нижньому регістрі — інакше та сама адреса з
        // різним написанням у двох адміністраторів дала б два листи.
        Mail::assertSent(
            CriticalViolationMail::class,
            fn (CriticalViolationMail $mail) => $mail->hasTo('another@example.com'),
        );

        $this->assertNotNull($analysis->fresh()->alerted_at);
    }

    public function test_minor_violations_do_not_trigger_letters(): void
    {
        Mail::fake();

        $this->admin();
        $analysis = $this->analysis($this->employee(), [$this->violation('minor')]);

        app(ViolationAlertService::class)->notify($analysis);

        Mail::assertNothingSent();
        $this->assertNull($analysis->fresh()->alerted_at);
    }

    public function test_same_day_is_not_sent_twice(): void
    {
        Mail::fake();

        $this->admin();
        $analysis = $this->analysis($this->employee(), [$this->violation()]);
        $analysis->update(['alerted_at' => now()]);

        // Перегенерація розбору не має слати керівнику той самий день ще раз.
        app(ViolationAlertService::class)->notify($analysis);

        Mail::assertNothingSent();
    }

    public function test_nothing_is_sent_when_no_admin_left_an_email(): void
    {
        Mail::fake();

        $this->admin([]);
        $analysis = $this->analysis($this->employee(), [$this->violation()]);

        app(ViolationAlertService::class)->notify($analysis);

        Mail::assertNothingSent();
        // Позначки немає навмисно: щойно керівник впише пошту, наступний розбір
        // цього дня має до нього дійти.
        $this->assertNull($analysis->fresh()->alerted_at);
    }

    public function test_unknown_severity_from_model_is_treated_as_minor(): void
    {
        Mail::fake();

        $this->admin();
        $analysis = $this->analysis($this->employee(), [$this->violation('CRITICAL!!!')]);

        app(ViolationAlertService::class)->notify($analysis);

        Mail::assertNothingSent();
    }

    public function test_analysis_job_sends_letter_after_finding_violation(): void
    {
        Mail::fake();
        config()->set('services.deepseek.key', 'test-key');

        $employee = $this->employee();

        ActivityEntry::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-20',
            'name' => 'youtube.com',
            'productivity' => 'unproductive',
            'category' => null,
            'duration_seconds' => 9600,
        ]);

        $report = Report::create([
            'employee_id' => $employee->id,
            'report_date' => '2026-07-20',
            'status' => Report::STATUS_COMPLETED,
            'generated_at' => now(),
        ]);

        $this->admin(['boss@example.com']);

        Http::fake(['api.deepseek.com/*' => Http::response([
            'model' => 'deepseek-chat',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => json_encode([
                    'summary' => 'Більшу частину дня зайняв YouTube.',
                    'focus_assessment' => 'Низька зосередженість.',
                    'task_coverage' => [],
                    'unclear_activities' => [[
                        'name' => 'youtube.com',
                        'duration_seconds' => 9600,
                        'verdict' => 'personal',
                        'reasoning' => 'Розважальний контент поза посадою.',
                    ]],
                    'violations' => [$this->violation()],
                    'recommendations' => [],
                ], JSON_UNESCAPED_UNICODE)],
            ]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 340],
        ])]);

        GenerateDailyAnalysis::dispatchSync($report, 'deepseek');

        Mail::assertSent(
            CriticalViolationMail::class,
            fn (CriticalViolationMail $mail) => $mail->hasTo('boss@example.com'),
        );

        $analysis = DailyAnalysis::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(DailyAnalysis::STATUS_COMPLETED, $analysis->status);
        $this->assertNotNull($analysis->alerted_at);
    }

    public function test_admin_keeps_several_alert_emails(): void
    {
        $admin = $this->admin([]);
        // Другий керівник зі своєю адресою: поточний має бачити, що листи
        // дійдуть навіть без нього.
        $this->admin(['other@example.com']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/alerts/emails')
            ->assertOk()
            ->assertJsonPath('alert_emails', [])
            ->assertJsonPath('others_count', 1);

        $this->putJson('/api/alerts/emails', [
            'alert_emails' => ['Boss@example.com', ' second@example.com ', 'boss@EXAMPLE.com'],
        ])
            ->assertOk()
            // Однакові адреси зводяться в одну, регістр і пробіли не рахуються.
            ->assertJsonPath('alert_emails', ['boss@example.com', 'second@example.com']);

        $this->assertSame(['boss@example.com', 'second@example.com'], $admin->fresh()->alertEmails());

        // Одна зіпсована адреса не дає зберегти список цілком: інакше керівник
        // вирішить, що зберіг усі три, а лист піде лише на дві.
        $this->putJson('/api/alerts/emails', ['alert_emails' => ['boss@example.com', 'не пошта']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('alert_emails.1');

        $this->assertSame(['boss@example.com', 'second@example.com'], $admin->fresh()->alertEmails());

        // Порожній список — це «вимкнути», а не помилка валідації.
        $this->putJson('/api/alerts/emails', ['alert_emails' => []])
            ->assertOk()
            ->assertJsonPath('alert_emails', []);

        $this->assertSame([], $admin->fresh()->alertEmails());
    }

    public function test_alert_email_list_has_a_limit(): void
    {
        Sanctum::actingAs($this->admin([]));

        $emails = [];

        for ($i = 0; $i < 11; $i++) {
            $emails[] = "boss{$i}@example.com";
        }

        $this->putJson('/api/alerts/emails', ['alert_emails' => $emails])
            ->assertStatus(422)
            ->assertJsonValidationErrors('alert_emails');
    }

    public function test_model_answer_is_normalised_before_it_can_trigger_a_letter(): void
    {
        $result = AnalysisPrompt::normalise([
            'summary' => 'День.',
            'violations' => [
                // Невідомий рівень і тип не мають перетворитись на лист.
                ['type' => 'нове', 'severity' => 'CRITICAL', 'details' => 'Щось було.'],
                // Порушення без опису показувати нічого — його прибираємо.
                ['type' => 'schedule', 'severity' => 'critical', 'details' => ''],
                'зовсім не обʼєкт',
                [
                    'type' => 'side_work',
                    'severity' => 'critical',
                    'details' => 'Дві години на сайті вакансій.',
                    'evidence' => 'work.ua — 7200 с',
                    'question' => 'Це був робочий пошук підрядника?',
                ],
            ],
        ]);

        $this->assertCount(2, $result['violations']);
        $this->assertSame('other', $result['violations'][0]['type']);
        $this->assertSame('minor', $result['violations'][0]['severity']);
        // Пропущені поля добираються порожніми, а не ламають розбір.
        $this->assertSame('', $result['violations'][0]['question']);
        $this->assertSame('critical', $result['violations'][1]['severity']);
        $this->assertSame('side_work', $result['violations'][1]['type']);
    }

    public function test_employee_cannot_touch_alert_emails(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]));

        $this->getJson('/api/alerts/emails')->assertForbidden();
        $this->putJson('/api/alerts/emails', ['alert_emails' => ['ivan@example.com']])->assertForbidden();
    }
}
