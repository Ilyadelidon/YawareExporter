<?php

namespace App\Services;

use App\Models\BitrixWorkspace;
use App\Models\User;
use App\Services\Tasks\TaskProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Таски з Бітрікс24 через вхідний вебхук порталу. На відміну від Trello,
 * робоча область одна на команду: вебхук підключає адміністратор, а кожен
 * працівник лише вказує свій акаунт на порталі — його таски й потрапляють
 * у звіт (фільтр за RESPONSIBLE_ID).
 */
class BitrixService implements TaskProvider
{
    /**
     * Ліміт порталу — 2 запити/сек з буфером 50; при його вичерпанні Бітрікс
     * віддає 503 QUERY_LIMIT_EXCEEDED (або 429 OPERATION_TIME_LIMIT).
     * Паузи між повторами, мс.
     */
    private const RETRY_DELAYS_MS = [700, 1500, 3000];

    /** Запобіжник від нескінченного посторінкового обходу (сторінка = 50 записів). */
    private const MAX_PAGES = 20;

    /** Статус завершеної таски — аналог dueComplete у Trello. */
    private const STATUS_COMPLETED = 5;

    /** Статуси тасок — найближчий аналог назви списку дошки Trello. */
    private const STATUS_LABELS = [
        1 => 'Нова',
        2 => 'Чекає на виконання',
        3 => 'Виконується',
        4 => 'Очікує контролю',
        5 => 'Завершена',
        6 => 'Відкладена',
        7 => 'Відхилена',
    ];

    public function __construct(
        private readonly ?string $webhookUrl = null,
        private readonly ?string $portalUrl = null,
        private readonly ?string $bitrixUserId = null,
    ) {}

    /**
     * Інстанс у контексті користувача: командний портал + його акаунт на ньому.
     */
    public static function forUser(?User $user): self
    {
        $workspace = BitrixWorkspace::active();

        if (! $workspace) {
            return new self;
        }

        return new self($workspace->webhook_url, $workspace->portal_url, $user?->bitrix_user_id);
    }

    /**
     * Інстанс командного порталу без прив'язки до працівника — для списку
     * користувачів порталу і перевірки самої робочої області.
     */
    public static function forWorkspace(?BitrixWorkspace $workspace = null): self
    {
        $workspace ??= BitrixWorkspace::active();

        return $workspace
            ? new self($workspace->webhook_url, $workspace->portal_url)
            : new self;
    }

    /**
     * Інстанс для перевірки щойно введеного вебхука (у БД його ще немає).
     */
    public static function withWebhook(string $webhookUrl): self
    {
        return new self($webhookUrl, self::portalUrlFromWebhook($webhookUrl));
    }

    /**
     * Схема + хост із вебхука (https://team.bitrix24.ua/rest/1/код/ → https://team.bitrix24.ua).
     */
    public static function portalUrlFromWebhook(string $webhookUrl): string
    {
        $parts = parse_url($webhookUrl);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
    }

    public function providerKey(): string
    {
        return User::TASK_PROVIDER_BITRIX;
    }

    public function providerLabel(): string
    {
        return 'Бітрікс24';
    }

    /** Портал команди підключено (незалежно від того, чи вибрав акаунт цей працівник). */
    public function hasWorkspace(): bool
    {
        return $this->webhookUrl !== null;
    }

    public function isConfigured(): bool
    {
        return $this->hasWorkspace() && $this->bitrixUserId !== null;
    }

    /**
     * Профіль власника вебхука — від його імені йдуть усі запити.
     * Слугує перевіркою вебхука перед збереженням.
     */
    public function profile(): array
    {
        $result = $this->call('profile')['result'] ?? [];

        return [
            'id' => isset($result['ID']) ? (string) $result['ID'] : null,
            'name' => $this->fullName($result['NAME'] ?? null, $result['LAST_NAME'] ?? null)
                ?: 'Власник вебхука',
        ];
    }

    /**
     * Активні користувачі порталу — з цього списку працівник обирає свій акаунт.
     *
     * @return array<int, array<string, ?string>>
     */
    public function users(): array
    {
        $users = [];
        $start = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->call('user.get', ['FILTER' => ['ACTIVE' => true], 'start' => $start]);

            foreach ($body['result'] ?? [] as $portalUser) {
                $id = (string) ($portalUser['ID'] ?? '');

                if ($id === '') {
                    continue;
                }

                $users[] = [
                    'id' => $id,
                    'name' => $this->fullName($portalUser['NAME'] ?? null, $portalUser['LAST_NAME'] ?? null)
                        ?: ($portalUser['EMAIL'] ?? "Користувач #{$id}"),
                    'email' => $portalUser['EMAIL'] ?? null,
                    'position' => $portalUser['WORK_POSITION'] ?? null,
                ];
            }

            if (! isset($body['next'])) {
                break;
            }

            $start = (int) $body['next'];
        }

        return $users;
    }

    /**
     * Таски працівника, що потрапляють у вибраний день за плановими датами
     * (START_DATE_PLAN–END_DATE_PLAN — прямий аналог Start/Due у Trello).
     *
     * @return array<int, array<string, mixed>>
     */
    public function tasksForDate(string $date): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException($this->hasWorkspace()
                ? 'Бітрікс24: не вибрано ваш акаунт на порталі — зробіть це на сторінці інтеграцій.'
                : 'Бітрікс24 не підключено: портал команди має підключити адміністратор.');
        }

        $timezone = config('services.bitrix.timezone');
        $dayStart = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $dayEnd = $dayStart->endOfDay();

        return collect($this->fetchDayTasks($dayStart, $dayEnd))
            ->filter(fn (array $task) => $this->taskMatchesDay($task, $dayStart, $dayEnd))
            ->map(fn (array $task) => $this->mapTask($task, $timezone))
            ->sortBy([['start', 'asc'], ['due', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Бітрікс не вміє OR у фільтрі, тож той самий набір, що дає одна умова
     * Trello, збираємо трьома вибірками і зливаємо за id:
     * інтервал перетинає добу; заданий лише початок; заданий лише дедлайн.
     */
    private function fetchDayTasks(CarbonImmutable $dayStart, CarbonImmutable $dayEnd): array
    {
        $from = $dayStart->format('Y-m-d H:i:s');
        $to = $dayEnd->format('Y-m-d H:i:s');

        $filters = [
            ['<=START_DATE_PLAN' => $to, '>=END_DATE_PLAN' => $from],
            ['>=START_DATE_PLAN' => $from, '<=START_DATE_PLAN' => $to],
            ['>=END_DATE_PLAN' => $from, '<=END_DATE_PLAN' => $to],
        ];

        $tasks = [];

        foreach ($filters as $filter) {
            foreach ($this->fetchTasks($filter) as $task) {
                $tasks[(string) ($task['id'] ?? '')] = $task;
            }
        }

        unset($tasks['']);

        return array_values($tasks);
    }

    private function fetchTasks(array $filter): array
    {
        $tasks = [];
        $start = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->cachedCall('tasks.task.list', [
                'filter' => $filter + ['RESPONSIBLE_ID' => $this->bitrixUserId],
                // select у верхньому регістрі, а от у відповіді Бітрікс віддає
                // ті самі поля в camelCase — звідси різні написання нижче.
                'select' => [
                    'ID', 'TITLE', 'DESCRIPTION', 'START_DATE_PLAN', 'END_DATE_PLAN',
                    'STATUS', 'RESPONSIBLE_ID',
                ],
                'order' => ['START_DATE_PLAN' => 'asc'],
                'start' => $start,
            ]);

            foreach ($body['result']['tasks'] ?? [] as $task) {
                $tasks[] = $task;
            }

            if (! isset($body['next'])) {
                break;
            }

            $start = (int) $body['next'];
        }

        return $tasks;
    }

    private function taskMatchesDay(array $task, CarbonImmutable $dayStart, CarbonImmutable $dayEnd): bool
    {
        $start = $this->parseDate($task['startDatePlan'] ?? null);
        $due = $this->parseDate($task['endDatePlan'] ?? null);

        if ($start && $due) {
            return $start->lte($dayEnd) && $due->gte($dayStart);
        }

        $single = $start ?? $due;

        return $single !== null && $single->between($dayStart, $dayEnd);
    }

    private function mapTask(array $task, string $timezone): array
    {
        $id = (string) ($task['id'] ?? '');
        $status = (int) ($task['status'] ?? 0);

        return [
            'id' => $id,
            'name' => (string) ($task['title'] ?? ''),
            'comment' => $this->plainText((string) ($task['description'] ?? '')),
            'url' => $this->taskUrl($id, $task['responsibleId'] ?? null),
            'list' => self::STATUS_LABELS[$status] ?? null,
            'start' => $this->toLocal($task['startDatePlan'] ?? null, $timezone),
            'due' => $this->toLocal($task['endDatePlan'] ?? null, $timezone),
            'due_complete' => $status === self::STATUS_COMPLETED,
            // Міток у тасок Бітрікса немає — поле лишається для спільної форми таски.
            'labels' => [],
        ];
    }

    private function taskUrl(string $id, mixed $responsibleId): ?string
    {
        if ($id === '' || ! $this->portalUrl) {
            return null;
        }

        $userId = (string) ($responsibleId ?: $this->bitrixUserId);

        return "{$this->portalUrl}/company/personal/user/{$userId}/tasks/task/view/{$id}/";
    }

    /**
     * Опис таски приходить у BB-коді (або HTML, якщо портал так налаштований) —
     * у звіті потрібен звичайний текст.
     */
    private function plainText(string $value): string
    {
        $value = preg_replace('/\[url=([^\]]+)\](.*?)\[\/url\]/is', '$2 ($1)', $value) ?? $value;
        $value = preg_replace('/\[\/?[a-z][^\]]*\]/i', '', $value) ?? $value;
        $value = preg_replace('#<br\s*/?>|</p>#i', "\n", $value) ?? $value;

        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        // Порожні дати Бітрікс віддає порожнім рядком, а не null.
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    private function toLocal(mixed $value, string $timezone): ?string
    {
        return $this->parseDate($value)?->setTimezone($timezone)->format('Y-m-d H:i');
    }

    private function fullName(?string $first, ?string $last): string
    {
        return trim(trim((string) $first).' '.trim((string) $last));
    }

    /**
     * Той самий кеш на 60 с, що й у Trello: сторінка звіту опитує таски
     * полінгом, а ліміт запитів порталу жорсткий.
     */
    private function cachedCall(string $method, array $params): array
    {
        $key = 'bitrix.'.$method.'.'.md5($this->portalUrl.json_encode($params));

        return Cache::remember($key, now()->addSeconds(60), fn () => $this->call($method, $params));
    }

    private function call(string $method, array $params = []): array
    {
        if (! $this->webhookUrl) {
            throw new RuntimeException('Портал Бітрікс24 не підключено.');
        }

        $url = rtrim($this->webhookUrl, '/')."/{$method}";
        $attempts = count(self::RETRY_DELAYS_MS) + 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = Http::timeout(25)->asJson()->post($url, $params);

            // Ліміт запитів — єдина помилка, яку має сенс перечекати.
            if ($attempt < $attempts && in_array($response->status(), [429, 503], true)) {
                usleep(self::RETRY_DELAYS_MS[$attempt - 1] * 1000);

                continue;
            }

            return $this->result($response);
        }

        throw new RuntimeException('Бітрікс24 не відповідає — вичерпано ліміт запитів порталу.');
    }

    private function result(Response $response): array
    {
        // 401/404 (невірний вебхук), 5xx — кидаємо RequestException, як у TrelloService.
        $response->throw();

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Бітрікс24 повернув неочікувану відповідь.');
        }

        if (isset($body['error'])) {
            throw new RuntimeException(trim($body['error_description'] ?? '') ?: (string) $body['error']);
        }

        return $body;
    }
}
