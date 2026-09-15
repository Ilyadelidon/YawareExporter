<?php

namespace App\Services;

use App\Models\BitrixAccount;
use App\Models\BitrixWorkspace;
use App\Models\User;
use App\Services\Tasks\TaskProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Таски з Бітрікс24 від імені самого працівника. Портал один на команду
 * (адміністратор реєструє на ньому локальний застосунок — [[BitrixWorkspace]]),
 * але токен у кожного свій: працівник авторизується в Бітріксі особисто, і
 * REST віддає рівно ті таски, які він і так бачить на порталі. Ставити
 * фільтр за чужим RESPONSIBLE_ID безглуздо — токен цього не дозволить.
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
        private ?BitrixAccount $account = null,
        private readonly ?string $portalUrl = null,
    ) {}

    /**
     * Інстанс у контексті працівника: його особистий токен на порталі команди.
     */
    public static function forUser(?User $user): self
    {
        $workspace = BitrixWorkspace::active();
        $account = $user?->bitrixAccount;

        return $workspace && $account
            ? new self($account, $workspace->portal_url)
            : new self;
    }

    /**
     * Профіль власника щойно виданого токена — ним і визначається, чий це
     * акаунт. Викликається в OAuth-callback, коли акаунта в БД ще немає.
     *
     * @return array{id: ?string, name: string, email: ?string}
     */
    public static function profileWithToken(string $clientEndpoint, string $accessToken): array
    {
        $response = Http::timeout(25)->asJson()
            ->post(rtrim($clientEndpoint, '/').'/profile', ['auth' => $accessToken]);

        $result = (new self)->result($response)['result'] ?? [];

        return [
            'id' => isset($result['ID']) ? (string) $result['ID'] : null,
            'name' => self::fullName($result),
            'email' => $result['EMAIL'] ?? null,
        ];
    }

    /**
     * Картка користувача порталу: profile віддає лише базові поля і часто без
     * пошти й імені, а саме пошта впізнавана для працівника в інтерфейсі.
     * Права на user.get у застосунку може й не бути — тоді порожня картка,
     * і підключення все одно відбувається.
     *
     * @return array{name: ?string, email: ?string}
     */
    public static function userCardWithToken(string $clientEndpoint, string $accessToken, string $bitrixUserId): array
    {
        $response = Http::timeout(25)->asJson()->post(rtrim($clientEndpoint, '/').'/user.get', [
            'auth' => $accessToken,
            'ID' => $bitrixUserId,
        ]);

        $result = (new self)->result($response)['result'][0] ?? [];

        return [
            'name' => self::fullName($result) ?: null,
            'email' => $result['EMAIL'] ?? null,
        ];
    }

    /** Ім'я з полів картки Бітрікса; порожнє, якщо профіль не заповнений. */
    private static function fullName(array $fields): string
    {
        return trim(trim((string) ($fields['NAME'] ?? '')).' '.trim((string) ($fields['LAST_NAME'] ?? '')));
    }

    public function providerKey(): string
    {
        return User::TASK_PROVIDER_BITRIX;
    }

    public function providerLabel(): string
    {
        return 'Бітрікс24';
    }

    public function isConfigured(): bool
    {
        return $this->account !== null;
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
            throw new RuntimeException(BitrixWorkspace::active()
                ? 'Бітрікс24 не підключено: авторизуйтесь на порталі команди на сторінці інтеграцій.'
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
                'filter' => $filter + ['RESPONSIBLE_ID' => $this->account->bitrix_user_id],
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

        $userId = (string) ($responsibleId ?: $this->account?->bitrix_user_id);

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

    /**
     * Той самий кеш на 60 с, що й у Trello: сторінка звіту опитує таски
     * полінгом, а ліміт запитів порталу жорсткий. Ключ включає акаунт —
     * токени особисті, тож і відповіді в різних працівників різні.
     */
    private function cachedCall(string $method, array $params): array
    {
        $key = 'bitrix.'.$method.'.'.md5($this->account->bitrix_user_id.'@'.$this->portalUrl.json_encode($params));

        return Cache::remember($key, now()->addSeconds(60), fn () => $this->call($method, $params));
    }

    /**
     * Виклик REST від імені працівника. Прострочений access_token — робоча
     * ситуація (він живе годину), тож 401 один раз відпрацьовуємо оновленням
     * токена, а не помилкою у звіті.
     */
    private function call(string $method, array $params = []): array
    {
        if (! $this->account) {
            throw new RuntimeException('Бітрікс24 не підключено.');
        }

        $this->ensureFreshToken();

        $attempts = count(self::RETRY_DELAYS_MS) + 1;
        $refreshed = false;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $url = rtrim($this->account->client_endpoint, '/')."/{$method}";
            $response = Http::timeout(25)->asJson()
                ->post($url, $params + ['auth' => $this->account->access_token]);

            // Токен міг протухнути раніше строку (наприклад, після зміни прав) —
            // оновлюємо і повторюємо рівно один раз.
            if (! $refreshed && $response->status() === 401) {
                $refreshed = true;
                $this->refreshToken();

                continue;
            }

            // Ліміт запитів — єдина помилка, яку має сенс перечекати.
            if ($attempt < $attempts && in_array($response->status(), [429, 503], true)) {
                usleep(self::RETRY_DELAYS_MS[$attempt - 1] * 1000);

                continue;
            }

            return $this->result($response);
        }

        throw new RuntimeException('Бітрікс24 не відповідає — вичерпано ліміт запитів порталу.');
    }

    private function ensureFreshToken(): void
    {
        if ($this->account->needsRefresh()) {
            $this->refreshToken();
        }
    }

    /**
     * Оновлення токена під локом: звіти всієї команди генеруються паралельно
     * трьома воркерами, а refresh_token одноразовий — два одночасні оновлення
     * зробили б доступ недійсним.
     */
    private function refreshToken(): void
    {
        $lock = Cache::lock("bitrix.refresh.{$this->account->id}", 20);

        $lock->block(15, function () {
            $this->account->refresh();

            // Поки чекали лок, сусідній процес міг уже все оновити.
            if (! $this->account->needsRefresh()) {
                return;
            }

            $oauth = BitrixOAuth::forWorkspace();

            if (! $oauth) {
                throw new RuntimeException('Бітрікс24 не підключено: портал команди має підключити адміністратор.');
            }

            try {
                $tokens = $oauth->refresh($this->account->refresh_token);
            } catch (RuntimeException $exception) {
                // Refresh-токен живий 30 днів і згорає після відкликання доступу
                // або перевстановлення застосунку — далі потрібна нова авторизація.
                $this->account->delete();

                throw new RuntimeException('Бітрікс24 відкликав доступ — підключіть портал заново на сторінці інтеграцій.', 0, $exception);
            }

            $this->account->forceFill([
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'client_endpoint' => $tokens['client_endpoint'],
                'expires_at' => now()->addSeconds($tokens['expires_in']),
            ])->save();
        });
    }

    private function result(Response $response): array
    {
        // 401 (протухлий чи відкликаний токен), 5xx — кидаємо RequestException,
        // як у TrelloService.
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
