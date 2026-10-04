<?php

namespace App\Console\Commands;

use App\Jobs\GenerateYawareReport;
use App\Models\Employee;
use App\Models\Report;
use App\Services\GoogleSheetsService;
use App\Services\OpsMonitor;
use App\Services\Reports\DailyRunSummary;
use App\Services\Tasks\TaskProviders;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class GenerateDailyReports extends Command
{
    protected $signature = 'reports:generate-daily
        {--date= : Дата звіту Y-m-d (за замовчуванням — попередній будній день за Києвом)}';

    protected $description = 'Ставить у чергу звіти за попередній будній день для всіх активних працівників з повними інтеграціями';

    public function handle(): int
    {
        $date = $this->reportDate();

        if (! $date) {
            $this->error('Невалідна дата: очікується формат Y-m-d.');

            return self::FAILURE;
        }

        $this->info("Автогенерація звітів за {$date}.");

        $jobs = [];
        $reportIds = [];
        $skipped = [];

        // whereNull поруч із active: `active` міг би підняти вхід у Yaware,
        // а звільненому звіти не робимо в жодному разі.
        $employees = Employee::with('user')
            ->where('active', true)
            ->whereNull('dismissed_at')
            ->get();

        foreach ($employees as $employee) {
            if ($reason = $this->skipReason($employee)) {
                $this->warn("{$employee->label()}: пропущено — {$reason}");
                $skipped[$employee->id] = $reason;

                continue;
            }

            $report = Report::firstOrCreate(
                ['employee_id' => $employee->id, 'report_date' => $date],
                ['status' => Report::STATUS_PENDING],
            );

            // У підсумок іде й звіт, який цей прогін не перезапускав: адміну
            // потрібна повна картина дня, а не лише свіжі генерації.
            $reportIds[] = $report->id;

            if (! $report->wasRecentlyCreated && $report->status !== Report::STATUS_FAILED) {
                $this->line("{$employee->label()}: звіт #{$report->id} уже {$report->status} — пропущено.");

                continue;
            }

            // Failed-звіт перезапускаємо: наступного ранку причина (Yaware/мережа)
            // могла зникнути.
            $jobs[] = $report->generationJob();
            $this->line("{$employee->label()}: звіт #{$report->id} поставлено в чергу.");
        }

        $this->info('У чергу поставлено звітів: '.count($jobs).'.');

        // Позначка для ops:healthcheck: без неї він за годину вирішить, що
        // ранкової автогенерації сьогодні не було.
        app(OpsMonitor::class)->recordDailyRun();

        $this->queueWithSummary($date, $jobs, $reportIds, $skipped);

        return self::SUCCESS;
    }

    /**
     * Звіти йдуть у чергу одним пакетом: коли воркер відпрацює останній,
     * розробник отримає в Telegram підсумок, для кого звіт сформувався, а
     * для кого ні (див. DailyRunSummary). allowFailures — щоб упалий звіт
     * одного працівника не скасовував решту. Ставити нічого — підсумок одразу.
     *
     * @param  list<GenerateYawareReport>  $jobs
     * @param  list<int>  $reportIds
     * @param  array<int, string>  $skipped  id працівника => причина пропуску
     */
    private function queueWithSummary(string $date, array $jobs, array $reportIds, array $skipped): void
    {
        if ($jobs === []) {
            app(DailyRunSummary::class)->send($date, $reportIds, $skipped);

            return;
        }

        Bus::batch($jobs)
            ->name("reports:generate-daily {$date}")
            ->allowFailures()
            ->finally(static fn () => app(DailyRunSummary::class)->send($date, $reportIds, $skipped))
            ->dispatch();
    }

    /**
     * Той самий критерій, що блокує кнопку «Сформувати звіт» в UI: креди Yaware
     * плюс обидві активні інтеграції. Без них звіт або впаде, або вийде
     * неповним — такого працівника пропускаємо з поясненням у лог.
     */
    private function skipReason(Employee $employee): ?string
    {
        if (! $employee->yaware_password) {
            return 'немає збережених кредів Yaware (працівник має раз увійти в сервіс своїми email і паролем Yaware).';
        }

        if (! $employee->user) {
            return 'до працівника не привʼязано користувача сервісу.';
        }

        $provider = TaskProviders::forUser($employee->user);

        if (! $provider->isConfigured()) {
            return "не налаштовано таск-трекер {$provider->providerLabel()}.";
        }

        if (! GoogleSheetsService::forUser($employee->user)->isConfigured()) {
            return 'не налаштовано персональну Google-таблицю.';
        }

        return null;
    }

    /**
     * Дата звіту: --date або попередній будній день за Києвом
     * (у понеділок вранці — пʼятниця, вихідні не звітуються).
     */
    private function reportDate(): ?string
    {
        if ($option = $this->option('date')) {
            try {
                $parsed = CarbonImmutable::createFromFormat('Y-m-d', $option);
            } catch (InvalidFormatException) {
                return null;
            }

            return $parsed && $parsed->format('Y-m-d') === $option ? $option : null;
        }

        return CarbonImmutable::now('Europe/Kyiv')->previousWeekday()->format('Y-m-d');
    }
}
