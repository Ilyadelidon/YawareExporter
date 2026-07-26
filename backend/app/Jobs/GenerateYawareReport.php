<?php

namespace App\Jobs;

use App\Models\Report;
use App\Models\ReportFile;
use App\Services\GoogleSheetsService;
use App\Services\ReportHistoryService;
use App\Services\TelegramService;
use App\Services\TrelloService;
use Carbon\CarbonImmutable;
use App\Support\WorkerEnvironment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class GenerateYawareReport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 660;

    public int $tries = 1;

    public function __construct(public Report $report)
    {
    }

    public function handle(): void
    {
        $report = $this->report->fresh(['employee']);

        if (! $report || ! $report->employee) {
            return;
        }

        // Звіт генерується лише кредами самого працівника — сервісного акаунта
        // немає, щоб дані різних користувачів не змішувались через один логін.
        if (! $report->employee->yaware_password) {
            $report->update([
                'status' => Report::STATUS_FAILED,
                'error_message' => 'Немає збережених кредів Yaware для цього працівника — він має хоча б раз увійти в сервіс своїми email і паролем Yaware.',
            ]);
            $this->notifyFailure($report);

            return;
        }

        $report->update([
            'status' => Report::STATUS_PROCESSING,
            'error_message' => null,
        ]);

        $outputDirectory = storage_path("app/reports/{$report->id}");
        File::ensureDirectoryExists($outputDirectory);

        $loginEmail = $report->employee->email;
        $loginPassword = $report->employee->yaware_password;

        [$trelloTasks, $trelloWarning] = $this->fetchTrelloTasks($report);

        $process = new Process(
            [config('yaware.node_binary'), config('yaware.worker_script')],
            config('yaware.worker_cwd'),
            WorkerEnvironment::base() + [
                'YAWARE_WORKER' => 'true',
                'YAWARE_EMAIL' => $loginEmail,
                'YAWARE_PASSWORD' => $loginPassword,
                'YAWARE_DATE' => $report->report_date->format('d.m.Y'),
                'YAWARE_TARGET_EMAIL' => $report->employee->email,
                'YAWARE_DOWNLOAD_DIR' => $outputDirectory,
                'YAWARE_HEADLESS' => config('yaware.headless') ? 'true' : 'false',
                'YAWARE_TRELLO_TASKS' => json_encode($this->workerTasks($trelloTasks ?? []), JSON_UNESCAPED_UNICODE),
            ],
            null,
            (float) config('yaware.timeout'),
        );

        try {
            $process->mustRun();
            $result = $this->parseWorkerResult($process->getOutput());
        } catch (Throwable $exception) {
            $report->update([
                'status' => Report::STATUS_FAILED,
                'error_message' => $this->workerErrorMessage($report, $process) ?? $this->buildErrorMessage($exception, $process),
            ]);
            $this->notifyFailure($report);

            return;
        }

        ReportFile::create([
            'report_id' => $report->id,
            'type' => 'combined_excel',
            'path' => 'reports/'.$report->id.'/'.basename($result['file']),
            'original_name' => basename($result['file']),
        ]);

        $history = $this->loadHistory($report, $result);

        // День без активності в Yaware (відпустка, лікарняний): в історію і
        // Google Таблицю нічого не пишемо, Telegram мовчить — інакше кожен
        // такий день дає порожню вкладку, нульовий рядок у Табелі і спам
        // «додайте таски Trello». Excel-файл лишається як підтвердження,
        // що день перевірено.
        if ($this->isEmptyDay($history)) {
            app(ReportHistoryService::class)->forgetDay($report);

            $report->update([
                'status' => Report::STATUS_COMPLETED,
                'summary' => ['Результат' => 'День без активності в Yaware — історія і Google Таблиця не оновлювались.'],
                'trello_tasks' => $trelloTasks,
                'generated_at' => now(),
            ]);

            return;
        }

        $summary = $result['summary'] ?? null;

        if ($historyWarning = $this->storeHistory($report, $history)) {
            $result['warnings'][] = $historyWarning;
        }

        [$googleSheetUrl, $googleWarnings] = $this->uploadToGoogleSheets($report, $result['file']);

        if ($googleSheetUrl) {
            $summary = ($summary ?? []) + ['Google Таблиця' => $googleSheetUrl];
        }

        if ($trelloWarning) {
            $result['warnings'][] = $trelloWarning;
        }

        foreach ($googleWarnings as $googleWarning) {
            $result['warnings'][] = $googleWarning;
        }

        if (! empty($result['warnings'])) {
            $summary = ($summary ?? []) + ['Попередження' => implode("\n", $result['warnings'])];
        }

        $report->update([
            'status' => Report::STATUS_COMPLETED,
            'summary' => $summary,
            // Знімок тасок на момент генерації — готовий звіт показує їх незалежно
            // від дошки, вибраної пізніше; null = знімка немає (Trello був недоступний).
            'trello_tasks' => $trelloTasks,
            'generated_at' => now(),
        ]);

        $this->notifySuccess($report, $trelloTasks, $googleSheetUrl);
    }

    /**
     * Telegram-сповіщення працівнику про готовий звіт; якщо тасок у Trello за
     * день немає — просить заповнити їх і перегенерувати звіт.
     */
    private function notifySuccess(Report $report, ?array $trelloTasks, ?string $googleSheetUrl): void
    {
        $lines = ["✅ Звіт за {$report->report_date->format('d.m.Y')} згенеровано."];

        if ($googleSheetUrl) {
            $lines[] = 'Вкладка у <a href="'.e($googleSheetUrl).'">Google Таблиці</a>.';
        }

        if (empty($trelloTasks)) {
            $lines[] = '⚠️ Тасок у Trello за цей день немає. Зайдіть на дошку, додайте виконані таски з часом (Start/Due) і перегенеруйте звіт на '.config('app.url').', щоб вони потрапили у звіт.';
        }

        app(TelegramService::class)->notify($report->employee?->user, implode("\n", $lines), 'HTML');
    }

    private function notifyFailure(Report $report): void
    {
        $reason = mb_substr((string) $report->error_message, 0, 500);

        app(TelegramService::class)->notify(
            $report->employee?->user,
            "❌ Звіт за {$report->report_date->format('d.m.Y')} не згенерувався.\n{$reason}\nСпробуйте ще раз: ".config('app.url'),
        );
    }

    /**
     * Таски Trello за дату звіту — повний формат для знімка у звіті.
     * null замість масиву = таски не отримано (Trello не налаштовано або помилка);
     * помилка Trello не блокує генерацію звіту — лише додає попередження.
     *
     * @return array{0: ?array<int, array<string, mixed>>, 1: ?string}
     */
    private function fetchTrelloTasks(Report $report): array
    {
        // Trello-акаунт користувача, за яким закріплений працівник звіту; fallback на .env.
        $trello = TrelloService::forUser($report->employee?->user);

        if (! $trello->isConfigured()) {
            return [null, null];
        }

        try {
            return [$trello->tasksForDate($report->report_date->format('Y-m-d')), null];
        } catch (Throwable $exception) {
            Log::warning("Trello-таски для звіту #{$report->id} не отримано: {$exception->getMessage()}");

            return [null, 'таски Trello не отримано — звіт згенеровано без розподілу часу по тасках.'];
        }
    }

    /**
     * Спрощений формат тасок для Excel-воркера (колонка «Завдання» і табличка тасок).
     *
     * @return array<int, array<string, ?string>>
     */
    private function workerTasks(array $tasks): array
    {
        return array_map(fn (array $task) => [
            'name' => $task['name'],
            'comment' => $task['comment'],
            'start' => $task['start'],
            'due' => $task['due'],
        ], $tasks);
    }

    /**
     * Структуровані дані воркера (history-data-*.json); null — файл відсутній
     * або нечитабельний.
     */
    private function loadHistory(Report $report, array $result): ?array
    {
        $dataFile = $result['dataFile'] ?? null;

        if (! $dataFile || ! is_file($dataFile)) {
            return null;
        }

        try {
            $history = json_decode(file_get_contents($dataFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            Log::warning("Історичні дані звіту #{$report->id} не прочитано: {$exception->getMessage()}");

            return null;
        }

        return is_array($history) ? $history : null;
    }

    /**
     * День без жодної активності в Yaware — ні секунди часу, ні онлайн-, ні
     * офлайн-активностей. Без історії (null) день порожнім не вважається:
     * краще пройти звичайний шлях і отримати попередження, ніж мовчки
     * пропустити день з даними.
     */
    private function isEmptyDay(?array $history): bool
    {
        if ($history === null) {
            return false;
        }

        $hasActivities = collect($history['activities'] ?? [])
            ->contains(fn ($activity) => is_array($activity) && trim((string) ($activity['name'] ?? '')) !== '');

        return (int) (($history['stats'] ?? [])['total_seconds'] ?? 0) === 0
            && ! $hasActivities
            && empty($history['idle_activities']);
    }

    /**
     * Складає структуровані дані воркера в історичні таблиці (daily_stats +
     * activity_entries). Помилка не блокує звіт — повертає текст попередження.
     */
    private function storeHistory(Report $report, ?array $history): ?string
    {
        if ($history === null) {
            return 'воркер не передав структуровані дані — день не збережено в історію.';
        }

        try {
            app(ReportHistoryService::class)->store($report, $history);

            return null;
        } catch (Throwable $exception) {
            Log::warning("Історичні дані звіту #{$report->id} не збережено: {$exception->getMessage()}");

            return 'день не збережено в історичну БД: '.mb_substr($exception->getMessage(), 0, 300);
        }
    }

    /**
     * Вивантажує згенерований Excel вкладкою в Google Таблицю і розносить
     * таски по місячному аркушу. Помилки не блокують звіт — лише додають
     * попередження.
     *
     * @return array{0: ?string, 1: array<int, string>} [URL вкладки, попередження]
     */
    private function uploadToGoogleSheets(Report $report, string $filePath): array
    {
        // Персональна таблиця користувача, за яким закріплений працівник звіту.
        $sheets = GoogleSheetsService::forUser($report->employee?->user);

        if (! $sheets->isConfigured()) {
            return [null, ['Google Таблицю не підключено (авторизація на /google/auth + персональна таблиця на сторінці «Інтеграції») — звіт не вивантажено.']];
        }

        $dayTitle = $report->report_date->format('d.m.Y');
        $reportDate = CarbonImmutable::parse($report->report_date->toDateString());

        try {
            $url = $sheets->uploadReportSheet($filePath, $dayTitle, $reportDate);
        } catch (Throwable $exception) {
            Log::warning("Звіт #{$report->id} не вивантажено в Google Таблицю: {$exception->getMessage()}");

            return [null, ['звіт не вивантажено в Google Таблицю: '.mb_substr($exception->getMessage(), 0, 300)]];
        }

        try {
            $sheets->syncMonthSheet($dayTitle, $reportDate);

            return [$url, []];
        } catch (Throwable $exception) {
            Log::warning("Таски звіту #{$report->id} не рознесено по місячному аркушу: {$exception->getMessage()}");

            return [$url, ['таски не записано в аркуш «Звіт за місяць»: '.mb_substr($exception->getMessage(), 0, 300)]];
        }
    }

    public function failed(Throwable $exception): void
    {
        $report = $this->report->fresh(['employee']);

        $report?->update([
            'status' => Report::STATUS_FAILED,
            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
        ]);

        if ($report) {
            $this->notifyFailure($report);
        }
    }

    private function parseWorkerResult(string $stdout): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));
        $lastLine = end($lines);

        $result = $lastLine ? json_decode($lastLine, true) : null;

        if (! is_array($result) || ($result['status'] ?? null) !== 'ok' || empty($result['file'])) {
            throw new \RuntimeException('Воркер завершився без валідного JSON-результату. Stdout: '.mb_substr($stdout, -500));
        }

        if (! is_file($result['file'])) {
            throw new \RuntimeException("Воркер повідомив про файл, якого не існує: {$result['file']}");
        }

        return $result;
    }

    /**
     * Людське повідомлення від самого воркера: при падінні він віддає останнім
     * рядком stdout JSON {status: 'error', message, screenshot}; скріншот
     * сторінки лишається на диску в теці звіту і в UI не показується.
     * null — воркер упав без структурованої помилки (fallback на stderr).
     */
    private function workerErrorMessage(Report $report, Process $process): ?string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $process->getOutput()))));
        $result = $lines ? json_decode((string) end($lines), true) : null;

        if (! is_array($result) || ($result['status'] ?? null) !== 'error' || empty($result['message'])) {
            return null;
        }

        Log::warning("Воркер звіту #{$report->id} завершився з помилкою: {$result['message']}", [
            'screenshot' => $result['screenshot'] ?? null,
            'stderr' => mb_substr(trim($process->getErrorOutput()), -1500),
        ]);

        return $result['message'];
    }

    private function buildErrorMessage(Throwable $exception, Process $process): string
    {
        $stderrTail = mb_substr(trim($process->getErrorOutput()), -1500);

        return mb_substr(trim($exception->getMessage()."\n\n".$stderrTail), 0, 2000);
    }
}
