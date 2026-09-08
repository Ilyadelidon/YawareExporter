<?php

namespace App\Services;

use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Вивантаження готового звіту в Google Таблицю користувача: денна вкладка +
 * рознесення тасок по місячному аркушу.
 *
 * Винесено з джоби генерації окремо, щоб той самий шлях міг повторити
 * команда reports:sync-google — день, який не потрапив у таблицю через
 * тимчасову помилку Google, доливається без перегенерації всього звіту.
 */
class ReportSheetPublisher
{
    /**
     * Маркер у попередженні звіту: день не потрапив у таблицю саме через
     * помилку (а не через невимкнену інтеграцію). За ним OpsMonitor знаходить
     * звіти, які треба долити, тому текст попередження має його містити.
     */
    public const FAILED_MARKER = 'не вивантажено в Google Таблицю';

    /**
     * Готовий звіт, день якого не потрапив у таблицю через помилку. Критерій
     * спільний для монітора (сповістити) і команди reports:sync-google
     * (долити), тому живе тут, а не дублюється в обох.
     */
    public static function uploadFailed(Report $report): bool
    {
        $warnings = ($report->summary ?? [])['Попередження'] ?? '';

        return is_string($warnings) && str_contains($warnings, self::FAILED_MARKER);
    }

    /**
     * Помилки не блокують звіт — лише повертаються попередженнями.
     *
     * @return array{0: ?string, 1: array<int, string>} [URL вкладки, попередження]
     */
    public function publish(Report $report, string $excelPath): array
    {
        // Персональна таблиця користувача, за яким закріплений працівник звіту.
        $sheets = GoogleSheetsService::forUser($report->employee?->user);

        if (! $sheets->isConfigured()) {
            return [null, ['Google Таблицю не підключено (авторизація на /google/auth + персональна таблиця на сторінці «Інтеграції») — звіт не вивантажено.']];
        }

        $dayTitle = $report->report_date->format('d.m.Y');
        $reportDate = CarbonImmutable::parse($report->report_date->toDateString());

        try {
            $url = $sheets->uploadReportSheet($excelPath, $dayTitle, $reportDate);
        } catch (Throwable $exception) {
            Log::warning("Звіт #{$report->id} ".self::FAILED_MARKER.": {$exception->getMessage()}");

            return [null, ['звіт '.self::FAILED_MARKER.': '.mb_substr($exception->getMessage(), 0, 300)]];
        }

        try {
            $sheets->syncMonthSheet($dayTitle, $reportDate);

            return [$url, []];
        } catch (Throwable $exception) {
            Log::warning("Таски звіту #{$report->id} не рознесено по місячному аркушу: {$exception->getMessage()}");

            return [$url, ['таски не записано в аркуш «Звіт за місяць»: '.mb_substr($exception->getMessage(), 0, 300)]];
        }
    }
}
