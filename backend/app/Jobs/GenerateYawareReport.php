<?php

namespace App\Jobs;

use App\Models\Report;
use App\Models\ReportFile;
use App\Services\AiAnalysisService;
use App\Services\ReportHistoryService;
use App\Services\Reports\ReportNotifier;
use App\Services\Reports\YawareWorker;
use App\Services\Reports\YawareWorkerException;
use App\Services\ReportSheetPublisher;
use App\Services\Tasks\TaskProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateYawareReport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 660;

    // Друга спроба — лише для збоїв, які повтор може виправити (мережа, Yaware
    // лежить, Chromium впав). Без неї один такий збій о 07:00 губив звіт до
    // наступного ранку. Решту відмов handle() фіксує одразу, не чекаючи.
    public int $tries = 2;

    // Пауза перед повтором: короткий збій Yaware встигає минути, а решта
    // ранкової черги тим часом іде далі.
    public const RETRY_DELAY_SECONDS = 600;

    public function __construct(public Report $report) {}

    public function handle(): void
    {
        $report = $this->report->fresh(['employee']);

        if (! $report || ! $report->employee) {
            return;
        }

        // Звіт генерується лише кредами самого працівника — сервісного акаунта
        // немає, щоб дані різних користувачів не змішувались через один логін.
        if (! $report->employee->yaware_password) {
            $this->markFailed($report, 'Немає збережених кредів Yaware для цього працівника — він має хоча б раз увійти в сервіс своїми email і паролем Yaware.');

            return;
        }

        $report->update([
            'status' => Report::STATUS_PROCESSING,
            'error_message' => null,
        ]);

        [$tasks, $tasksWarning] = $this->fetchTasks($report);

        try {
            $result = app(YawareWorker::class)->run($report, $tasks ?? []);
        } catch (YawareWorkerException $exception) {
            $this->handleWorkerFailure($report, $exception);

            return;
        }

        $history = $this->loadHistory($report, $result);

        // Час, який не належить жодній тасці, — привід не віддавати звіт
        // узагалі: такий звіт однаково довелось би переробляти, а поки він
        // лежить «готовий», ніхто цього не помічає. Працівник дізнається
        // причину з Telegram і формує звіт заново, поправивши таски.
        if ($blockReason = $this->coverageBlockReason($tasks, $result)) {
            $this->blockReport($report, $tasks, $blockReason);

            return;
        }

        $isEmptyDay = $this->isEmptyDay($history);

        // Тасок за день немає зовсім: у сервісі показуємо лише статистику дня,
        // а файл звіту і Google Таблиця чекають на таски — після перегенерації
        // з тасками звіт піде звичайним шляхом.
        if ($tasks === [] && ! $isEmptyDay) {
            $this->completeWithoutTasks($report, $result, $history, $tasksWarning);

            return;
        }

        ReportFile::create([
            'report_id' => $report->id,
            'type' => ReportFile::TYPE_EXCEL,
            'path' => $report->filesDirectory().'/'.basename($result['file']),
            'original_name' => basename($result['file']),
        ]);

        // День без активності в Yaware (відпустка, лікарняний): в історію і
        // Google Таблицю нічого не пишемо, Telegram мовчить — інакше кожен
        // такий день дає порожню вкладку, нульовий рядок у Табелі і спам
        // «додайте таски». Excel-файл лишається як підтвердження,
        // що день перевірено.
        if ($isEmptyDay) {
            app(ReportHistoryService::class)->forgetDay($report);
            $this->complete($report, [Report::SUMMARY_RESULT => Report::EMPTY_DAY_RESULT], [], $tasks);

            return;
        }

        $this->completeWithSheet($report, $result, $history, $tasks, $tasksWarning);
    }

    public function failed(Throwable $exception): void
    {
        $report = $this->report->fresh(['employee']);

        if ($report) {
            $this->markFailed($report, mb_substr($exception->getMessage(), 0, 2000));
        }
    }

    /**
     * Повний звіт: історія, вкладка в Google Таблиці, сповіщення і AI-розбір.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $history
     */
    private function completeWithSheet(Report $report, array $result, ?array $history, ?array $tasks, ?string $tasksWarning): void
    {
        $summary = $result['summary'] ?? null;
        $warnings = $result['warnings'] ?? [];

        if ($historyWarning = $this->storeHistory($report, $history)) {
            $warnings[] = $historyWarning;
        }

        [$googleSheetUrl, $googleWarnings] = app(ReportSheetPublisher::class)->publish($report, $result['file']);

        if ($googleSheetUrl) {
            $summary = ($summary ?? []) + [Report::SUMMARY_GOOGLE_SHEET => $googleSheetUrl];
        }

        if ($tasksWarning) {
            $warnings[] = $tasksWarning;
        }

        // Попередження Google — останніми: reports:sync-google відрізає
        // невдале вивантаження від маркера до кінця тексту.
        array_push($warnings, ...$googleWarnings);

        // Знімок тасок на момент генерації — готовий звіт показує їх незалежно
        // від того, що змінилося в трекері пізніше; null = знімка немає
        // (таск-трекер був недоступний).
        $this->complete($report, $summary, $warnings, $tasks);

        app(ReportNotifier::class)->completed($report, $tasks, $googleSheetUrl);

        // AI-розбір дня для адміністратора — окремою джобою в черзі analysis,
        // щоб довгий запит до моделі не тримав чергу звітів і не зривав
        // готовий звіт при помилці.
        if (app(AiAnalysisService::class)->isConfigured()) {
            GenerateDailyAnalysis::dispatch($report);
        }
    }

    /**
     * Звіт лише зі статистикою дня: історія (Табель, таблиці активності)
     * оновлюється, а файлу для завантаження і вкладки в Google Таблиці немає —
     * без тасок вони неповні. Файл від попередньої генерації теж прибираємо,
     * щоб не завантажили застарілий.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $history
     */
    private function completeWithoutTasks(Report $report, array $result, ?array $history, ?string $tasksWarning): void
    {
        $report->files()->delete();

        $warnings = $result['warnings'] ?? [];

        if ($historyWarning = $this->storeHistory($report, $history)) {
            $warnings[] = $historyWarning;
        }

        if ($tasksWarning) {
            $warnings[] = $tasksWarning;
        }

        $warnings[] = 'тасок за день немає — файл звіту не сформовано і в Google Таблицю не вивантажено.';

        $this->complete($report, $result['summary'] ?? [], $warnings, []);

        app(ReportNotifier::class)->completed($report, [], null);
    }

    /**
     * @param  array<string, mixed>|null  $summary
     * @param  array<int, string>  $warnings
     */
    private function complete(Report $report, ?array $summary, array $warnings, ?array $tasks): void
    {
        if ($warnings !== []) {
            $summary = ($summary ?? []) + [Report::SUMMARY_WARNINGS => implode("\n", $warnings)];
        }

        $report->update([
            'status' => Report::STATUS_COMPLETED,
            'summary' => $summary,
            'tasks' => $tasks,
            'generated_at' => now(),
        ]);
    }

    /**
     * Звіт зі часом поза тасками не зберігається: файлів у ньому немає, історія
     * і Google Таблиця не оновлюються. Сам xlsx лишається в теці звіту на диску
     * (по ньому видно, який саме час випав), а теку згодом прибере
     * reports:prune-files.
     */
    private function blockReport(Report $report, ?array $tasks, string $reason): void
    {
        $report->files()->delete();

        $report->update([
            'status' => Report::STATUS_BLOCKED,
            'summary' => null,
            'tasks' => $tasks,
            'error_message' => $reason.' Поки він не розподілений по тасках, звіт не формується.',
            'generated_at' => null,
        ]);

        app(ReportNotifier::class)->blocked($report, $reason);
    }

    private function markFailed(Report $report, string $message): void
    {
        $report->update([
            'status' => Report::STATUS_FAILED,
            'error_message' => $message,
        ]);

        app(ReportNotifier::class)->failed($report);
    }

    /**
     * Повтор має сенс, лише поки є спроби і воркер не повідомив про відмову,
     * яку повтор не змінить.
     */
    private function handleWorkerFailure(Report $report, YawareWorkerException $exception): void
    {
        if ($this->attempts() >= $this->tries || ! $exception->isRetryable()) {
            $this->markFailed($report, $exception->getMessage());

            return;
        }

        Log::warning("Звіт #{$report->id} не згенерувався з {$this->attempts()}-ї спроби, повтор через ".self::RETRY_DELAY_SECONDS." с: {$exception->getMessage()}");

        // Працівнику поки не пишемо: звіт ще може вийти з другої спроби.
        $report->markPending();
        $this->release(self::RETRY_DELAY_SECONDS);
    }

    /**
     * Причина не віддавати звіт: у дні є час поза тасками. Текст готовий до
     * показу працівнику; null — звіт іде звичайним шляхом.
     *
     * Рахує розподіл воркер (колонка «Поза тасками» в Excel), тож короткі
     * залишки між тасками сюди не доходять — вони вже приклеєні до сусідньої
     * таски тим самим порогом, що й у файлі.
     */
    private function coverageBlockReason(?array $tasks, array $result): ?string
    {
        // Тасок не отримано взагалі (трекер не налаштований або впав) — це не
        // провина працівника: звіт іде далі з попередженням. День зовсім без
        // тасок теж не блокується — він стає звітом лише зі статистикою
        // (див. completeWithoutTasks()).
        if (empty($tasks)) {
            return null;
        }

        $outsideSeconds = $result['outsideSeconds'] ?? null;

        if (is_numeric($outsideSeconds) && (int) $outsideSeconds > 0) {
            return 'У дні є '.$this->formatDuration((int) $outsideSeconds).' робочого часу поза тасками.';
        }

        return null;
    }

    /**
     * Тривалість для людини: «1 год 20 хв», «40 хв», «30 с».
     */
    private function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = array_filter([
            $hours ? "{$hours} год" : null,
            $minutes ? "{$minutes} хв" : null,
        ]);

        return $parts ? implode(' ', $parts) : "{$seconds} с";
    }

    /**
     * Таски за дату звіту з трекера, вибраного працівником (Trello або Бітрікс24),
     * — повний формат для знімка у звіті. null замість масиву = таски не отримано
     * (трекер не налаштовано або помилка); помилка трекера не блокує генерацію
     * звіту — лише додає попередження.
     *
     * @return array{0: ?array<int, array<string, mixed>>, 1: ?string}
     */
    private function fetchTasks(Report $report): array
    {
        // Трекер користувача, за яким закріплений працівник звіту.
        $provider = TaskProviders::forUser($report->employee?->user);

        if (! $provider->isConfigured()) {
            return [null, null];
        }

        try {
            return [$provider->tasksForDate($report->report_date->format('Y-m-d')), null];
        } catch (Throwable $exception) {
            Log::warning("Таски {$provider->providerLabel()} для звіту #{$report->id} не отримано: {$exception->getMessage()}");

            return [null, "таски {$provider->providerLabel()} не отримано — звіт згенеровано без розподілу часу по тасках."];
        }
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
}
