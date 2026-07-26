<?php

namespace App\Console\Commands;

use App\Jobs\GenerateYawareReport;
use App\Models\Employee;
use App\Models\Report;
use App\Services\GoogleSheetsService;
use App\Services\TrelloService;
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

        foreach (Employee::with('user')->where('active', true)->get() as $employee) {
            if ($reason = $this->skipReason($employee)) {
                $this->warn("{$employee->name} (#{$employee->id}): пропущено — {$reason}");

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

        return self::SUCCESS;
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

        if (! TrelloService::forUser($employee->user)->isConfigured()) {
            return 'не підключено Trello (токен або дошка відсутні).';
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
