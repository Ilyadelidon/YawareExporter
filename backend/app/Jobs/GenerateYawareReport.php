<?php

namespace App\Jobs;

use App\Models\Report;
use App\Models\ReportFile;
use App\Services\AiAnalysisService;
use App\Services\ReportHistoryService;
use App\Services\ReportSheetPublisher;
use App\Services\Tasks\TaskProviders;
use App\Services\TelegramService;
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

    // Друга спроба — лише для збоїв, які повтор може виправити (мережа, Yaware
    // лежить, Chromium впав). Без неї один такий збій о 07:00 губив звіт до
    // наступного ранку. Решту відмов handle() фіксує одразу, не чекаючи.
    public int $tries = 2;

    // Пауза перед повтором: короткий збій Yaware встигає минути, а решта
    // ранкової черги тим часом іде далі.
    public const RETRY_DELAY_SECONDS = 600;

    // Коди воркера, за яких повтор дасть той самий результат.
    private const PERMANENT_WORKER_ERRORS = [
        'INVALID_CREDENTIALS',
        'REPORTS_PAGE_UNAVAILABLE',
        'EMPTY_DAY',
    ];

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

        [$tasks, $tasksWarning] = $this->fetchTasks($report);

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
                // Історична назва змінної воркера: сюди йдуть таски будь-якого
                // трекера — форма однакова, і Excel-скрипт про різницю не знає.
                'YAWARE_TRELLO_TASKS' => json_encode($this->workerTasks($tasks ?? []), JSON_UNESCAPED_UNICODE),
            ],
            null,
            (float) config('yaware.timeout'),
        );

        try {
            $process->mustRun();
            $result = $this->parseWorkerResult($process->getOutput());
        } catch (Throwable $exception) {
            $workerError = $this->workerError($report, $process);
            $errorMessage = $workerError['message'] ?? $this->buildErrorMessage($exception, $process);

            if ($this->shouldRetry($workerError['code'] ?? null)) {
                Log::warning("Звіт #{$report->id} не згенерувався з {$this->attempts()}-ї спроби, повтор через ".self::RETRY_DELAY_SECONDS." с: {$errorMessage}");

                // Працівнику поки не пишемо: звіт ще може вийти з другої спроби.
                $report->update([
                    'status' => Report::STATUS_PENDING,
                    'error_message' => null,
                ]);
                $this->release(self::RETRY_DELAY_SECONDS);

                return;
            }

            $report->update([
                'status' => Report::STATUS_FAILED,
                'error_message' => $errorMessage,
            ]);
            $this->notifyFailure($report);

            return;
        }

        $history = $this->loadHistory($report, $result);

        // Час, який не належить жодній тасці, — привід не віддавати звіт
        // узагалі: такий звіт однаково довелось би переробляти, а поки він
        // лежить «готовий», ніхто цього не помічає. Працівник дізнається
        // причину з Telegram і формує звіт заново, поправивши таски.
        if ($blockReason = $this->coverageBlockReason($tasks, $result, $history)) {
            $this->blockReport($report, $tasks, $blockReason);

            return;
        }

        ReportFile::create([
            'report_id' => $report->id,
            'type' => 'combined_excel',
            'path' => 'reports/'.$report->id.'/'.basename($result['file']),
            'original_name' => basename($result['file']),
        ]);

        // День без активності в Yaware (відпустка, лікарняний): в історію і
        // Google Таблицю нічого не пишемо, Telegram мовчить — інакше кожен
        // такий день дає порожню вкладку, нульовий рядок у Табелі і спам
        // «додайте таски». Excel-файл лишається як підтвердження,
        // що день перевірено.
        if ($this->isEmptyDay($history)) {
            app(ReportHistoryService::class)->forgetDay($report);

            $report->update([
                'status' => Report::STATUS_COMPLETED,
                'summary' => ['Результат' => 'День без активності в Yaware — історія і Google Таблиця не оновлювались.'],
                'tasks' => $tasks,
                'generated_at' => now(),
            ]);

            return;
        }

        $summary = $result['summary'] ?? null;

        if ($historyWarning = $this->storeHistory($report, $history)) {
            $result['warnings'][] = $historyWarning;
        }

        // Знімок тасок потрібен публікатору ще до збереження звіту: день, у
        // якому тасок немає взагалі, в Google Таблицю не вивантажується.
        $report->tasks = $tasks;

        [$googleSheetUrl, $googleWarnings] = app(ReportSheetPublisher::class)->publish($report, $result['file']);

        if ($googleSheetUrl) {
            $summary = ($summary ?? []) + ['Google Таблиця' => $googleSheetUrl];
        }

        if ($tasksWarning) {
            $result['warnings'][] = $tasksWarning;
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
            // від того, що змінилося в трекері пізніше; null = знімка немає
            // (таск-трекер був недоступний).
            'tasks' => $tasks,
            'generated_at' => now(),
        ]);

        $this->notifySuccess($report, $tasks, $googleSheetUrl);

        // AI-розбір дня для адміністратора — окремою джобою в черзі analysis,
        // щоб довгий запит до моделі не тримав чергу звітів і не зривав
        // готовий звіт при помилці.
        if (app(AiAnalysisService::class)->isConfigured()) {
            GenerateDailyAnalysis::dispatch($report);
        }
    }

    /**
     * Telegram-сповіщення працівнику про готовий звіт; якщо тасок у трекері за
     * день немає — просить заповнити їх і перегенерувати звіт.
     */
    private function notifySuccess(Report $report, ?array $tasks, ?string $googleSheetUrl): void
    {
        $lines = ["✅ Звіт за {$report->report_date->format('d.m.Y')} згенеровано."];

        if ($googleSheetUrl) {
            $lines[] = 'Вкладка у <a href="'.e($googleSheetUrl).'">Google Таблиці</a>.';
        }

        if (empty($tasks)) {
            $tracker = TaskProviders::forUser($report->employee?->user)->providerLabel();
            $lines[] = "⚠️ Тасок у {$tracker} за цей день немає. Додайте виконані таски з проставленим часом початку й завершення і перегенеруйте звіт на ".config('app.url').', щоб вони потрапили у звіт.';
        }

        app(TelegramService::class)->notify($report->employee?->user, implode("\n", $lines), 'HTML');
    }

    /**
     * Причина не віддавати звіт: у дні є час поза тасками. Текст готовий до
     * показу працівнику; null — звіт іде звичайним шляхом.
     *
     * Рахує розподіл воркер (колонка «Поза тасками» в Excel), тож короткі
     * залишки між тасками сюди не доходять — вони вже приклеєні до сусідньої
     * таски тим самим порогом, що й у файлі.
     */
    private function coverageBlockReason(?array $tasks, array $result, ?array $history): ?string
    {
        // Тасок не отримано взагалі (трекер не налаштований або впав) — це не
        // провина працівника: звіт іде далі з попередженням, як і раніше.
        if ($tasks === null) {
            return null;
        }

        $outsideSeconds = $result['outsideSeconds'] ?? null;

        if (is_numeric($outsideSeconds) && (int) $outsideSeconds > 0) {
            return 'У дні є '.$this->formatDuration((int) $outsideSeconds).' робочого часу поза тасками.';
        }

        // Тасок за день немає зовсім — весь активний час дня поза тасками.
        // День без активності (відпустка, лікарняний) сюди не потрапляє: його
        // окремо обробляє isEmptyDay(). Без історії стану дня ми не знаємо,
        // тож мовчимо і лишаємо звичайне попередження про порожній трекер.
        if ($tasks === [] && $history !== null && ! $this->isEmptyDay($history)) {
            return 'За цей день у трекері немає жодної таски, тож увесь робочий час — поза тасками.';
        }

        return null;
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

        $tracker = TaskProviders::forUser($report->employee?->user)->providerLabel();

        app(TelegramService::class)->notify($report->employee?->user, implode("\n", [
            "⚠️ Звіт за {$report->report_date->format('d.m.Y')} не сформовано.",
            $reason,
            "Додайте у {$tracker} таски з проставленим часом початку й завершення так, щоб вони покрили весь день, і сформуйте звіт заново: ".config('app.url'),
        ]));
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

    private function notifyFailure(Report $report): void
    {
        $reason = mb_substr((string) $report->error_message, 0, 500);

        app(TelegramService::class)->notify(
            $report->employee?->user,
            "❌ Звіт за {$report->report_date->format('d.m.Y')} не згенерувався.\n{$reason}\nСпробуйте ще раз: ".config('app.url'),
        );
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
     * Спрощений формат тасок для Excel-воркера (колонка «Завдання» і табличка тасок).
     * Форма однакова для обох трекерів, тож воркер про різницю не знає.
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
     * Повтор має сенс, лише поки є спроби і воркер не повідомив про відмову,
     * яку повтор не змінить. Падіння без коду (таймаут, крах процесу, битий
     * вивід) вважаємо тимчасовим.
     */
    private function shouldRetry(?string $workerErrorCode): bool
    {
        return $this->attempts() < $this->tries
            && ! in_array($workerErrorCode, self::PERMANENT_WORKER_ERRORS, true);
    }

    /**
     * Помилка від самого воркера: при падінні він віддає останнім рядком
     * stdout JSON {status: 'error', message, code, screenshot}; скріншот
     * сторінки лишається на диску в теці звіту і в UI не показується.
     * null — воркер упав без структурованої помилки (fallback на stderr).
     *
     * @return array{message: string, code: ?string}|null
     */
    private function workerError(Report $report, Process $process): ?array
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

        return [
            'message' => $result['message'],
            'code' => is_string($result['code'] ?? null) ? $result['code'] : null,
        ];
    }

    private function buildErrorMessage(Throwable $exception, Process $process): string
    {
        $stderrTail = mb_substr(trim($process->getErrorOutput()), -1500);

        return mb_substr(trim($exception->getMessage()."\n\n".$stderrTail), 0, 2000);
    }
}
