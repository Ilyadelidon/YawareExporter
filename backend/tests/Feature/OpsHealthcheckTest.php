<?php

namespace Tests\Feature;

use App\Models\DailyAnalysis;
use App\Models\Employee;
use App\Models\Report;
use App\Models\User;
use App\Services\OpsMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Активний контроль стану: сервіс має сам повідомити про аварію, а не лишати
 * її помітною тільки за відсутністю ранкового підсумку.
 */
class OpsHealthcheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.bot_token', 'bot-token');
        config()->set('services.telegram.bot_username', 'TeamReporter_Bot');
        config()->set('services.telegram.ops_email', 'ilya.dev2715@gmail.com');
        config()->set('services.ops.heartbeat_url', null);

        // Стан монітора живе у файлі, щоб переживати cache:clear на деплої —
        // між тестами його треба прибирати руками.
        File::delete(storage_path('app/ops-state.json'));

        $user = User::factory()->create(['email' => 'ilya.dev2715@gmail.com']);
        $user->forceFill(['telegram_chat_id' => '555000'])->save();
    }

    protected function tearDown(): void
    {
        File::delete(storage_path('app/ops-state.json'));

        parent::tearDown();
    }

    private function employee(): Employee
    {
        return Employee::create(['name' => 'Іван', 'email' => 'ivan@example.com']);
    }

    private function failedJob(string $uuid, \DateTimeInterface $failedAt): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Jobs\GenerateReportJob']),
            'exception' => 'boom',
            'failed_at' => $failedAt,
        ]);
    }

    private function alerts(): array
    {
        $texts = [];

        Http::recorded(function (Request $request) use (&$texts) {
            if (str_contains($request->url(), '/sendMessage')) {
                $texts[] = $request->data()['text'];
            }

            return true;
        });

        return $texts;
    }

    public function test_healthy_service_stays_quiet(): void
    {
        Http::fake();
        // Робочий день уже почався, але прогін сьогодні зафіксовано.
        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00', 'Europe/Kyiv'));
        app(OpsMonitor::class)->recordDailyRun();

        $this->artisan('ops:healthcheck')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_missed_morning_run_raises_alarm(): void
    {
        Http::fake();
        // Понеділок, 09:00 — прогін мав бути о 07:00, позначки немає.
        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00', 'Europe/Kyiv'));

        $this->artisan('ops:healthcheck')->assertSuccessful();

        $this->assertStringContainsString('Ранкової автогенерації сьогодні не було', $this->alerts()[0]);
    }

    public function test_no_alarm_before_the_grace_hour(): void
    {
        Http::fake();
        // 07:30 — команда ще могла не відпрацювати, панікувати рано.
        $this->travelTo(CarbonImmutable::parse('2026-07-20 07:30', 'Europe/Kyiv'));

        $this->artisan('ops:healthcheck')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_no_alarm_on_weekend(): void
    {
        Http::fake();
        // Субота: автогенерація у вихідні не планується.
        $this->travelTo(CarbonImmutable::parse('2026-07-18 12:00', 'Europe/Kyiv'));

        $this->artisan('ops:healthcheck')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_failed_report_raises_alarm(): void
    {
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00', 'Europe/Kyiv'));
        app(OpsMonitor::class)->recordDailyRun();

        Report::create([
            'employee_id' => $this->employee()->id,
            'report_date' => '2026-07-19',
            'status' => Report::STATUS_FAILED,
        ]);

        $this->artisan('ops:healthcheck')->assertSuccessful();

        $alert = $this->alerts()[0];
        $this->assertStringContainsString('Звітів упало сьогодні: 1', $alert);
        $this->assertStringContainsString('Іван', $alert);
    }

    public function test_report_stuck_in_processing_raises_alarm(): void
    {
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00', 'Europe/Kyiv'));
        app(OpsMonitor::class)->recordDailyRun();

        $report = Report::create([
            'employee_id' => $this->employee()->id,
            'report_date' => '2026-07-19',
            'status' => Report::STATUS_PROCESSING,
        ]);
        // Воркер узяв джобу годину тому й помер — статус більше ніхто не змінить.
        $report->forceFill(['updated_at' => now()->subHour()])->save();

        $this->artisan('ops:healthcheck')->assertSuccessful();

        $this->assertStringContainsString('зависло в статусі processing', $this->alerts()[0]);
    }

    public function test_stalled_queue_raises_alarm(): void
    {
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00', 'Europe/Kyiv'));
        app(OpsMonitor::class)->recordDailyRun();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subHour()->timestamp,
            'created_at' => now()->subHour()->timestamp,
        ]);

        $this->artisan('ops:healthcheck')->assertSuccessful();

        $this->assertStringContainsString('Черга не рухається', $this->alerts()[0]);
    }

    public function test_failed_analysis_and_failed_jobs_are_reported(): void
    {
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00', 'Europe/Kyiv'));
        app(OpsMonitor::class)->recordDailyRun();

        DailyAnalysis::create([
            'employee_id' => $this->employee()->id,
            'date' => '2026-07-19',
            'status' => DailyAnalysis::STATUS_FAILED,
        ]);
        $this->failedJob('abc-123', now());

        $this->artisan('ops:healthcheck')->assertSuccessful();

        $alert = $this->alerts()[0];
        $this->assertStringContainsString('AI-розборів упало сьогодні: 1', $alert);
        $this->assertStringContainsString('Джоб упало за добу: 1', $alert);
        // Ім'я джоби видно одразу — не треба заходити на сервер, щоб зрозуміти,
        // що саме падає.
        $this->assertStringContainsString('GenerateReportJob', $alert);
    }

    public function test_old_failed_job_does_not_alert_forever(): void
    {
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00', 'Europe/Kyiv'));
        app(OpsMonitor::class)->recordDailyRun();

        // Таблиця failed_jobs не самоочищується: позавчорашнє падіння, яке вже
        // відзвітували, не має тримати сповіщення вічно.
        $this->failedJob('old-1', now()->subDays(2));

        $this->artisan('ops:healthcheck')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_same_problem_is_not_repeated_every_run(): void
    {
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00', 'Europe/Kyiv'));

        $this->artisan('ops:healthcheck')->assertSuccessful();
        $this->artisan('ops:healthcheck')->assertSuccessful();
        $this->artisan('ops:healthcheck')->assertSuccessful();

        // Проблема та сама — людину турбуємо один раз, інакше сповіщення
        // щопівгодини швидко почнуть ігнорувати.
        $this->assertCount(1, $this->alerts());
    }

    public function test_repeat_is_forced_by_flag(): void
    {
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00', 'Europe/Kyiv'));

        $this->artisan('ops:healthcheck')->assertSuccessful();
        $this->artisan('ops:healthcheck', ['--force' => true])->assertSuccessful();

        $this->assertCount(2, $this->alerts());
    }

    public function test_new_problem_breaks_through_the_hold(): void
    {
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00', 'Europe/Kyiv'));

        $this->artisan('ops:healthcheck')->assertSuccessful();

        // З'явилась ДРУГА проблема — набір змінився, тож мовчати вже не можна.
        Report::create([
            'employee_id' => $this->employee()->id,
            'report_date' => '2026-07-19',
            'status' => Report::STATUS_FAILED,
        ]);

        $this->artisan('ops:healthcheck')->assertSuccessful();

        $this->assertCount(2, $this->alerts());
    }

    public function test_external_heartbeat_is_pinged_on_success_and_failure(): void
    {
        Http::fake();
        config()->set('services.ops.heartbeat_url', 'https://hc-ping.test/uuid');
        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00', 'Europe/Kyiv'));

        app(OpsMonitor::class)->recordDailyRun();
        $this->artisan('ops:healthcheck')->assertSuccessful();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc-ping.test/uuid');

        // Аварія — окремий суфікс /fail, щоб зовнішній сервіс не чекав таймауту.
        Report::create([
            'employee_id' => $this->employee()->id,
            'report_date' => '2026-07-19',
            'status' => Report::STATUS_FAILED,
        ]);
        $this->artisan('ops:healthcheck')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hc-ping.test/uuid/fail');
    }
}
