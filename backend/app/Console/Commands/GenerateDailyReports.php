<?php

namespace App\Console\Commands;

use App\Jobs\GenerateYawareReport;
use App\Models\Employee;
use App\Models\Report;
use App\Services\GoogleSheetsService;
use App\Services\OpsMonitor;
use App\Services\Tasks\TaskProviders;
use App\Services\TelegramService;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;

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

        $queued = 0;
        $skipped = [];

        // whereNull поруч із active: `active` міг би підняти вхід у Yaware,
        // а звільненому звіти не робимо в жодному разі.
        $employees = Employee::with('user')
            ->where('active', true)
            ->whereNull('dismissed_at')
            ->get();

        foreach ($employees as $employee) {
            if ($reason = $this->skipReason($employee)) {
                $this->warn("{$employee->name} (#{$employee->id}): пропущено — {$reason}");
                $skipped[] = "{$employee->name} (#{$employee->id}): {$reason}";

                continue;
            }

            $report = Report::firstOrCreate(
                ['employee_id' => $employee->id, 'report_date' => $date],
                ['status' => Report::STATUS_PENDING],
            );

            if (! $report->wasRecentlyCreated && $report->status !== Report::STATUS_FAILED) {
                $this->line("{$employee->name} (#{$employee->id}): звіт #{$report->id} уже {$report->status} — пропущено.");

                continue;
            }

            // Failed-звіт перезапускаємо: наступного ранку причина (Yaware/мережа)
            // могла зникнути; повторна генерація ідемпотентна.
            $report->update(['status' => Report::STATUS_PENDING, 'error_message' => null]);

            GenerateYawareReport::dispatch($report);
            $this->line("{$employee->name} (#{$employee->id}): звіт #{$report->id} поставлено в чергу.");
            $queued++;
        }

        $this->info("У чергу поставлено звітів: {$queued}.");

        // Позначка для ops:healthcheck: без неї він за годину вирішить, що
        // ранкової автогенерації сьогодні не було.
        app(OpsMonitor::class)->recordDailyRun();

        $this->notifyOps($date, $queued, $skipped);

        return self::SUCCESS;
    }

    /**
     * Підсумок прогону розробнику в Telegram. Це водночас сигнал живості
     * планувальника: повідомлення приходить щобудня, тож його відсутність
     * і є ознакою, що cron або воркер лягли — інакше про це дізнаєшся
     * від працівників, у яких не з'явився звіт.
     *
     * @param  list<string>  $skipped
     */
    private function notifyOps(string $date, int $queued, array $skipped): void
    {
        $day = CarbonImmutable::parse($date)->format('d.m.Y');

        $lines = ["🗓 Автогенерація звітів за {$day}", "У чергу поставлено: {$queued}"];

        if ($skipped !== []) {
            $lines[] = 'Пропущено: '.count($skipped);

            foreach ($skipped as $reason) {
                $lines[] = "• {$reason}";
            }
        }

        if ($queued === 0 && $skipped === []) {
            $lines[] = 'Активних працівників не знайдено — перевірте список працівників.';
        }

        app(TelegramService::class)->notifyOps(implode("\n", $lines));
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
