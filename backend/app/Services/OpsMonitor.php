<?php

namespace App\Services;

use App\Models\DailyAnalysis;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Активний контроль стану сервісу: шукає ознаки того, що щось лягло, і дає
 * список проблем людською мовою.
 *
 * Свідомо тримає стан у файлі, а не в кеші: cache:clear на кожному деплої
 * стер би позначку про останній ранковий прогін, і монітор одразу після
 * деплою кричав би про неіснуючу аварію.
 *
 * Межа методу: усе це виконується на тому ж сервері. Якщо ляже сам сервер,
 * cron або PHP — доповісти буде нікому, і аварію побачить лише зовнішній
 * пінг (OPS_HEARTBEAT_URL, див. HeartbeatService).
 */
class OpsMonitor
{
    /** Звіт, що висить у processing довше за це, — покинутий воркером. */
    private const STUCK_REPORT_MINUTES = 25;

    /** Скільки чекати після планового 07:00, перш ніж бити на сполох. */
    private const MORNING_GRACE_HOUR = 8;

    /** Джоба, що стоїть у черзі довше, — ознака мертвого воркера. */
    private const QUEUE_STALL_MINUTES = 30;

    /** Менше вільного місця — і звіти перестануть зберігатись. */
    private const MIN_FREE_DISK_PERCENT = 10;

    /** Падіння, старіше за це, — рядок у журналі, а не свіжа аварія. */
    private const FAILED_JOB_WINDOW_HOURS = 24;

    /** За скільки днів назад шукаємо дні, що не потрапили в Google Таблицю. */
    private const GOOGLE_UPLOAD_WINDOW_DAYS = 3;

    /** Та сама проблема не повторюється частіше, ніж раз на стільки годин. */
    private const REPEAT_ALERT_HOURS = 6;

    /** Бекап, старіший за це, — ознака, що щоденний cron більше не працює. */
    private const BACKUP_STALE_HOURS = 36;

    private string $statePath;

    private string $backupMarkerPath;

    public function __construct()
    {
        $this->statePath = storage_path('app/ops-state.json');
        $this->backupMarkerPath = storage_path('app/ops-backup.json');
    }

    /**
     * Позначка успішного ранкового прогону — за нею монітор розуміє, що
     * автогенерація сьогодні взагалі відбувалась.
     */
    public function recordDailyRun(?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now('Europe/Kyiv');

        $this->writeState(['last_daily_run' => $at->toDateString()] + $this->state());
    }

    /**
     * Усе, що зараз виглядає як аварія. Порожній масив — усе гаразд.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $now = CarbonImmutable::now('Europe/Kyiv');

        return array_values(array_filter([
            $this->missedMorningRun($now),
            $this->failedReports($now),
            $this->missingGoogleUploads($now),
            $this->stuckReports($now),
            $this->failedAnalyses($now),
            $this->failedJobs($now),
            $this->stalledQueue($now),
            $this->staleBackup($now),
            $this->lowDisk(),
        ]));
    }

    /**
     * Чи слід зараз турбувати людину цим набором проблем. Та сама проблема,
     * що тримається днями, інакше слала б повідомлення щопівгодини — і її
     * швидко почали б ігнорувати разом з усіма іншими.
     *
     * @param  list<string>  $problems
     */
    public function shouldAlert(array $problems): bool
    {
        if ($problems === []) {
            return false;
        }

        $state = $this->state();
        $signature = md5(implode('|', $problems));

        if (($state['last_alert_signature'] ?? null) !== $signature) {
            return true;
        }

        $sentAt = $state['last_alert_at'] ?? null;

        return ! $sentAt || CarbonImmutable::parse($sentAt)->addHours(self::REPEAT_ALERT_HOURS)->isPast();
    }

    /**
     * @param  list<string>  $problems
     */
    public function markAlerted(array $problems): void
    {
        // Нові значення ліворуч: при збігу ключів масивний «+» лишає саме їх.
        $this->writeState([
            'last_alert_signature' => md5(implode('|', $problems)),
            'last_alert_at' => CarbonImmutable::now()->toIso8601String(),
        ] + $this->state());
    }

    /**
     * Ранковий прогін не відбувся: будній день, минула година запасу, а
     * позначки за сьогодні немає. Це ловить мертвий cron і падіння команди
     * до того, як вона встигла щось повідомити.
     */
    private function missedMorningRun(CarbonImmutable $now): ?string
    {
        if ($now->isWeekend() || $now->hour < self::MORNING_GRACE_HOUR) {
            return null;
        }

        $lastRun = $this->state()['last_daily_run'] ?? null;

        if ($lastRun === $now->toDateString()) {
            return null;
        }

        $seen = $lastRun ? "останній прогін: {$lastRun}" : 'жодного прогону не зафіксовано';

        return "Ранкової автогенерації сьогодні не було ({$seen}). Перевірте cron під www-data і schedule:run.";
    }

    private function failedReports(CarbonImmutable $now): ?string
    {
        $failed = Report::where('status', Report::STATUS_FAILED)
            ->whereBetween('updated_at', $this->kyivDayInUtc($now))
            ->with('employee')
            ->get();

        if ($failed->isEmpty()) {
            return null;
        }

        $names = $failed->map(fn (Report $report) => $report->employee?->name ?? "звіт #{$report->id}")
            ->unique()
            ->implode(', ');

        return "Звітів упало сьогодні: {$failed->count()} ({$names}).";
    }

    /**
     * Звіт згенерувався, але його день не потрапив у Google Таблицю (частіше
     * за все — тимчасова 500 від Google). Сам звіт при цьому «успішний», тож
     * без окремої перевірки день просто тихо зникає з таблиці, і помічають це
     * вже наприкінці місяця.
     */
    private function missingGoogleUploads(CarbonImmutable $now): ?string
    {
        $missing = Report::where('status', Report::STATUS_COMPLETED)
            ->where('updated_at', '>=', $now->subDays(self::GOOGLE_UPLOAD_WINDOW_DAYS)->utc()->toDateTimeString())
            ->get()
            ->filter(fn (Report $report) => ReportSheetPublisher::uploadFailed($report));

        if ($missing->isEmpty()) {
            return null;
        }

        $days = $missing->map(fn (Report $report) => $report->report_date->format('d.m.Y'))->unique()->implode(', ');
        $ids = $missing->pluck('id')->implode(' ');

        return "Днів не потрапило в Google Таблицю: {$missing->count()} ({$days}). Долити: php artisan reports:sync-google {$ids}.";
    }

    /**
     * Звіт застряг у processing: воркер узяв джобу й помер, тому статус уже
     * ніхто не змінить — сам по собі такий звіт не «розсмокчеться».
     */
    private function stuckReports(CarbonImmutable $now): ?string
    {
        $stuck = Report::where('status', Report::STATUS_PROCESSING)
            ->where('updated_at', '<', $now->subMinutes(self::STUCK_REPORT_MINUTES)->utc())
            ->count();

        return $stuck > 0
            ? "Звітів зависло в статусі processing: {$stuck} (довше за ".self::STUCK_REPORT_MINUTES.' хв). Схоже, воркер помер посеред роботи.'
            : null;
    }

    private function failedAnalyses(CarbonImmutable $now): ?string
    {
        $failed = DailyAnalysis::where('status', DailyAnalysis::STATUS_FAILED)
            ->whereBetween('updated_at', $this->kyivDayInUtc($now))
            ->count();

        return $failed > 0
            ? "AI-розборів упало сьогодні: {$failed}. Найчастіша причина — вичерпані кредити або невалідний ключ."
            : null;
    }

    /**
     * Свіжі падіння джоб. Таблиця failed_jobs не самоочищується, тож без
     * вікна пара давніх записів тримала б сповіщення вічно — монітор
     * перетворився б на фоновий шум, який перестають читати.
     */
    private function failedJobs(CarbonImmutable $now): ?string
    {
        // failed_at пишеться в таймзоні застосунку (UTC), тож межу вікна
        // приводимо туди ж, інакше отримали б зсув на київські +3.
        $recent = DB::table('failed_jobs')
            ->where('failed_at', '>=', $now->subHours(self::FAILED_JOB_WINDOW_HOURS)->utc()->toDateTimeString())
            ->pluck('payload');

        if ($recent->isEmpty()) {
            return null;
        }

        $names = $recent->map(fn (?string $payload) => $this->jobName($payload))->unique()->implode(', ');

        return "Джоб упало за добу: {$recent->count()} ({$names}). Деталі — php artisan queue:failed.";
    }

    /**
     * Ім'я джоби з payload — щоб зрозуміти, що саме падає, не заходячи на сервер.
     */
    private function jobName(?string $payload): string
    {
        $decoded = json_decode((string) $payload, true);

        return is_array($decoded) && is_string($decoded['displayName'] ?? null)
            ? class_basename($decoded['displayName'])
            : 'невідома джоба';
    }

    /**
     * Черга не рухається: джоба лежить довше, ніж триває найдовша робота.
     * Ловить зупинений або впалий queue-воркер, навіть коли решта сервісу жива.
     */
    private function stalledQueue(CarbonImmutable $now): ?string
    {
        $oldest = DB::table('jobs')->min('available_at');

        if (! $oldest) {
            return null;
        }

        $waiting = (int) round(CarbonImmutable::createFromTimestamp($oldest)->diffInMinutes($now));

        return $waiting >= self::QUEUE_STALL_MINUTES
            ? "Черга не рухається: найстаріша джоба чекає {$waiting} хв. Перевірте systemctl status yaware-queue-*."
            : null;
    }

    private function lowDisk(): ?string
    {
        try {
            $free = disk_free_space(base_path());
            $total = disk_total_space(base_path());
        } catch (Throwable) {
            return null;
        }

        if (! $free || ! $total) {
            return null;
        }

        $percent = (int) round($free / $total * 100);

        return $percent < self::MIN_FREE_DISK_PERCENT
            ? "На диску лишилось {$percent}% вільного місця — звіти скоро перестануть зберігатись."
            : null;
    }

    /**
     * Бекап бази робить cron (ops/backup-db.sh), а не Laravel, — звідси файл із
     * позначкою замість запису в базі: позначку, що лежить у самій базі, разом
     * із нею і втрачають.
     *
     * Мовчазна відмова тут найдорожча з усіх: бекап, що перестав робитись,
     * ніяк себе не проявляє, поки не знадобиться.
     */
    private function staleBackup(CarbonImmutable $now): ?string
    {
        if (! is_file($this->backupMarkerPath)) {
            return 'Бекапу бази ще не було: cron ops/backup-db.sh не налаштований (див. DEPLOY.md, розділ 5б).';
        }

        try {
            $marker = json_decode((string) file_get_contents($this->backupMarkerPath), true, 512, JSON_THROW_ON_ERROR);
            $at = CarbonImmutable::parse($marker['at']);
        } catch (Throwable) {
            return 'Позначка бекапу (storage/app/ops-backup.json) нечитабельна — перевірте ops/backup-db.sh.';
        }

        $hours = (int) round($at->diffInHours($now));

        if ($hours >= self::BACKUP_STALE_HOURS) {
            return "Свіжого бекапу бази немає {$hours} год. Перевірте cron під www-data і вивід ops/backup-db.sh.";
        }

        // Копії на тому ж диску рятують від помилкової міграції, але не від
        // втрати самого VPS — заради чого бекап і робиться.
        return ($marker['remote'] ?? false) === false
            ? 'Бекап бази робиться, але лишається на цьому ж сервері: BACKUP_REMOTE не задано.'
            : null;
    }

    /**
     * Київська доба в межах UTC. Timestamps у базі лежать в UTC (APP_TIMEZONE),
     * а «сьогодні» для людини — київське; без переведення межі поїхали б на +3
     * години, і вечірні падіння рахувались би вже завтрашніми.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function kyivDayInUtc(CarbonImmutable $now): array
    {
        return [$now->startOfDay()->utc(), $now->endOfDay()->utc()];
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        if (! is_file($this->statePath)) {
            return [];
        }

        try {
            $state = json_decode((string) file_get_contents($this->statePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($state) ? $state : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(array $state): void
    {
        File::ensureDirectoryExists(dirname($this->statePath));
        File::put($this->statePath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
