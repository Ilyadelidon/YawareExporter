<?php

namespace App\Services;

use App\Models\User;
use App\Services\Tasks\TaskProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TrelloService implements TaskProvider
{
    private const API_BASE = 'https://api.trello.com/1';

    public function __construct(
        private readonly ?string $token = null,
        private readonly ?string $boardId = null,
    ) {}

    /**
     * Інстанс у контексті користувача: його токен і дошка. Без підключеного
     * Trello інстанс лишається несконфігурованим — глобального fallback немає.
     */
    public static function forUser(?User $user): self
    {
        if ($user && $user->hasTrelloConnected()) {
            return new self($user->trello_token, $user->trello_board_id);
        }

        return new self;
    }

    /**
     * Інстанс для валідації щойно отриманого токена (дошки ще немає).
     */
    public static function withToken(string $token): self
    {
        return new self($token);
    }

    public function providerKey(): string
    {
        return User::TASK_PROVIDER_TRELLO;
    }

    public function providerLabel(): string
    {
        return 'Trello';
    }

    public function isConfigured(): bool
    {
        return config('services.trello.key') && $this->token && $this->boardId;
    }

    /**
     * Профіль власника токена. Кидає RequestException для невалідного токена —
     * це і є перевірка токена перед збереженням.
     */
    public function member(): array
    {
        return $this->request()
            ->get(self::API_BASE.'/members/me', ['fields' => 'username,fullName'])
            ->throw()
            ->json();
    }

    /**
     * Відкриті дошки власника токена — для вибору існуючої дошки замість створення.
     */
    public function boards(): array
    {
        return $this->request()
            ->get(self::API_BASE.'/members/me/boards', [
                'fields' => 'name,shortUrl',
                'filter' => 'open',
            ])
            ->throw()
            ->json();
    }

    public function board(string $boardId): array
    {
        return $this->request()
            ->get(self::API_BASE."/boards/{$boardId}", ['fields' => 'name,shortUrl'])
            ->throw()
            ->json();
    }

    /**
     * Створює дошку під акаунтом власника токена. Якщо задано TRELLO_TEMPLATE_BOARD_ID,
     * нова дошка клонує списки й мітки шаблону (без карток — keepFromSource=none).
     */
    public function createBoard(string $name): array
    {
        $params = ['name' => $name];

        if ($templateId = config('services.trello.template_board_id')) {
            // idBoardSource приймає лише повний 24-символьний id; у .env зручніше тримати
            // короткий id з URL дошки, тому розгортаємо його запитом до Trello.
            if (! preg_match('/^[0-9a-fA-F]{24}$/', $templateId)) {
                $templateId = $this->request()
                    ->get(self::API_BASE."/boards/{$templateId}", ['fields' => 'id'])
                    ->throw()
                    ->json('id');
            }

            $params['idBoardSource'] = $templateId;
            $params['keepFromSource'] = 'none';
        } else {
            $params['defaultLists'] = 'true';
        }

        return $this->request($params)
            ->post(self::API_BASE.'/boards/')
            ->throw()
            ->json();
    }

    /**
     * Відкликає токен на боці Trello (при відключенні інтеграції).
     */
    public function revokeToken(): void
    {
        $this->request()
            ->delete(self::API_BASE."/tokens/{$this->token}")
            ->throw();
    }

    public function boardId(): ?string
    {
        return $this->boardId;
    }

    /**
     * Відкриті списки дошки в порядку на дошці. Без кешу, як і решта методів
     * для синхронізації планів: їй потрібен свіжий стан.
     *
     * @return list<array{id: string, name: string}>
     */
    public function lists(): array
    {
        return $this->boardGet('lists', ['fields' => 'name', 'filter' => 'open']);
    }

    /** @return list<array{id: string, name: string}> */
    public function labels(): array
    {
        return $this->boardGet('labels', ['fields' => 'name', 'limit' => 1000]);
    }

    /**
     * Відкриті картки дошки разом із даними Power-Up-ів — у них Duck Epics
     * тримає підзадачі.
     *
     * @return list<array<string, mixed>>
     */
    public function openCards(): array
    {
        return $this->boardGet('cards', [
            'fields' => 'name,desc,idList,idLabels,shortUrl',
            'pluginData' => 'true',
            'filter' => 'open',
        ]);
    }

    public function card(string $id): array
    {
        return $this->request()
            ->get(self::API_BASE."/cards/{$id}", ['fields' => 'idLabels'])
            ->throw()
            ->json();
    }

    /** Створює мітку на дошці й повертає її id. */
    public function createLabel(string $name, string $color): string
    {
        return (string) $this->request(['name' => $name, 'color' => $color, 'idBoard' => $this->boardId])
            ->post(self::API_BASE.'/labels')
            ->throw()
            ->json('id');
    }

    /** Створює картку й повертає її (id, shortUrl). */
    public function addCard(array $fields): array
    {
        return $this->request($fields)
            ->post(self::API_BASE.'/cards')
            ->throw()
            ->json();
    }

    public function updateCard(string $id, array $fields): void
    {
        $this->request($fields)
            ->put(self::API_BASE."/cards/{$id}")
            ->throw();
    }

    public function removeCardLabel(string $cardId, string $labelId): void
    {
        $this->request()
            ->delete(self::API_BASE."/cards/{$cardId}/idLabels/{$labelId}")
            ->throw();
    }

    private function boardGet(string $resource, array $query): array
    {
        return $this->request()
            ->get(self::API_BASE."/boards/{$this->boardId}/{$resource}", $query)
            ->throw()
            ->json();
    }

    /**
     * Таски дошки, що потрапляють у вибраний день за інтервалом Start–Due (Log Work).
     *
     * @return array<int, array<string, mixed>>
     */
    public function tasksForDate(string $date): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Trello не підключено: підключіть акаунт на сторінці інтеграцій.');
        }

        $timezone = config('services.trello.timezone');
        $dayStart = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $dayEnd = $dayStart->endOfDay();

        $lists = collect($this->fetchBoardLists())->pluck('name', 'id');

        $tasks = collect($this->fetchBoardCards())
            ->filter(fn (array $card) => $this->cardMatchesDay($card, $dayStart, $dayEnd))
            ->map(fn (array $card) => [
                'id' => $card['id'],
                'name' => $card['name'],
                'comment' => trim($card['desc'] ?? ''),
                'url' => $card['shortUrl'] ?? $card['url'] ?? null,
                'list' => $lists->get($card['idList']),
                'start' => $this->toLocal($card['start'] ?? null, $timezone),
                'due' => $this->toLocal($card['due'] ?? null, $timezone),
                'due_complete' => (bool) ($card['dueComplete'] ?? false),
                'labels' => array_values(array_map(
                    fn (array $label) => ['name' => $label['name'], 'color' => $label['color']],
                    $card['labels'] ?? [],
                )),
            ])
            ->sortBy([['start', 'asc'], ['due', 'asc']])
            ->values();

        return $tasks->all();
    }

    private function cardMatchesDay(array $card, CarbonImmutable $dayStart, CarbonImmutable $dayEnd): bool
    {
        $start = $this->parseDate($card['start'] ?? null);
        $due = $this->parseDate($card['due'] ?? null);

        if ($start && $due) {
            return $start->lte($dayEnd) && $due->gte($dayStart);
        }

        $single = $start ?? $due;

        return $single !== null && $single->between($dayStart, $dayEnd);
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value) : null;
    }

    private function toLocal(?string $value, string $timezone): ?string
    {
        return $value
            ? CarbonImmutable::parse($value)->setTimezone($timezone)->format('Y-m-d H:i')
            : null;
    }

    private function fetchBoardCards(): array
    {
        return $this->cachedGet('cards', [
            'fields' => 'name,desc,start,due,dueComplete,idList,shortUrl,labels',
            // 'all' — включно з архівованими картками, щоб історичні звіти не втрачали таски.
            'filter' => 'all',
        ]);
    }

    private function fetchBoardLists(): array
    {
        return $this->cachedGet('lists', [
            'fields' => 'name',
            'filter' => 'all',
        ]);
    }

    private function cachedGet(string $resource, array $query): array
    {
        return Cache::remember(
            "trello.board.{$this->boardId}.{$resource}",
            now()->addSeconds(60),
            fn () => $this->boardGet($resource, $query),
        );
    }

    private function request(array $query = []): PendingRequest
    {
        return Http::withQueryParameters($query + [
            'key' => config('services.trello.key'),
            'token' => $this->token,
        ]);
    }
}
