<?php

namespace App\Services\Plans;

use App\Jobs\PushPlanTaskToTrello;
use App\Jobs\RemovePlanLabelInTrello;
use App\Models\PlanProject;
use App\Models\PlanTask;
use App\Models\User;
use App\Services\TrelloService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Задачі «Планів» ↔ картки Trello з міткою «План» — той самий підхід, що й у
 * [[PlanBitrixSync]]: проект — друга мітка з назвою проекту, спільні поля
 * порівнюються зі зліпком, при конфлікті виграє сервіс, видалення не
 * поширюється.
 *
 * Відмінність — власність: спільного порталу немає, у кожного працівника своя
 * дошка й особистий токен. Тож виконавець картки — власник дошки (окремого
 * поля не синхронізуємо), писати в дошку можна лише його токеном, а статус —
 * це список, у якому лежить картка (зіставляємо за назвою списку).
 */
class PlanTrelloSync
{
    use SyncsWithTracker;

    private const LOG_PREFIX = 'Плани ↔ Trello';

    /**
     * Power-Up «Duck Epics — Sub-tasks & Progress»: у спільних даних картки-
     * батька він тримає {"children": [id карток]}.
     */
    public const EPICS_PLUGIN_ID = '6a6dbb0953912e0f415de989';

    private const FIELDS = ['title', 'description', 'list'];

    private const PLAN_LABEL_COLOR = 'green';

    private const PROJECT_LABEL_COLOR = 'sky';

    /**
     * Назва списку (без регістру) → статус плану. Дошки в команди різні, тож
     * перелічуємо назви, що вже трапляються. Статусу без свого списку
     * (пауза, регулярна…) картка не рухає.
     */
    private const LIST_STATUSES = [
        'план' => PlanTask::STATUS_PENDING,
        'список задач' => PlanTask::STATUS_PENDING,
        'уточнити' => PlanTask::STATUS_PENDING,
        'очікує виконання' => PlanTask::STATUS_PENDING,
        'нужно сделать' => PlanTask::STATUS_PENDING,
        'to do' => PlanTask::STATUS_PENDING,
        'todo' => PlanTask::STATUS_PENDING,
        'backlog' => PlanTask::STATUS_PENDING,
        'в роботі' => PlanTask::STATUS_IN_PROGRESS,
        'в процесі' => PlanTask::STATUS_IN_PROGRESS,
        'в работе' => PlanTask::STATUS_IN_PROGRESS,
        'в процессе' => PlanTask::STATUS_IN_PROGRESS,
        'doing' => PlanTask::STATUS_IN_PROGRESS,
        'in progress' => PlanTask::STATUS_IN_PROGRESS,
        'на перевірці' => PlanTask::STATUS_REVIEW,
        'на проверке' => PlanTask::STATUS_REVIEW,
        'review' => PlanTask::STATUS_REVIEW,
        'пауза' => PlanTask::STATUS_PAUSED,
        'виконано' => PlanTask::STATUS_DONE,
        'зроблено' => PlanTask::STATUS_DONE,
        'готово' => PlanTask::STATUS_DONE,
        'done' => PlanTask::STATUS_DONE,
    ];

    /** Є з чим синхронізувати: хоч хтось підключив Trello і вибрав дошку. */
    public static function isAvailable(): bool
    {
        return filled(config('services.trello.key')) && self::connectedUsers()->exists();
    }

    /** Картку можна створити лише на власній дошці виконавця. */
    public static function hasBoard(?User $user): bool
    {
        return filled(config('services.trello.key')) && $user?->hasTrelloConnected() && filled($user->trello_board_id);
    }

    public static function queuePush(PlanTask $task): void
    {
        if ($task->trello_unlinked_at !== null) {
            return;
        }

        $task->forceFill(['trello_pending' => true])->save();

        PushPlanTaskToTrello::dispatchAfterResponse($task->id);
    }

    /** Задачу видаляють із плану: картка лишається, лише без мітки «План». */
    public static function queueLabelRemoval(PlanTask $task): void
    {
        if ($task->trello_card_id === null || $task->trello_unlinked_at !== null) {
            return;
        }

        RemovePlanLabelInTrello::dispatchAfterResponse($task->trello_card_id, $task->trello_board_id);
    }

    /**
     * Задачу передали іншому: картка лишається на дошці попереднього
     * виконавця без мітки «План», а задача далі йде в трекер нового.
     */
    public static function detachFromForeignBoard(PlanTask $task): void
    {
        if ($task->trello_card_id === null || $task->trello_board_id === $task->employee?->user?->trello_board_id) {
            return;
        }

        self::queueLabelRemoval($task);

        $task->forceFill([
            'trello_card_id' => null,
            'trello_board_id' => null,
            'trello_snapshot' => null,
            'trello_pending' => false,
            'trello_unlinked_at' => null,
            'subtasks' => null,
        ])->save();
    }

    public static function cardUrl(string $cardId): string
    {
        return "https://trello.com/c/{$cardId}";
    }

    /**
     * Прогін: обійти дошки всіх підключених, підтягнути нові й змінені картки
     * з міткою, розвʼязати зниклі, дотиснути зміни сервісу, які не вдалося
     * надіслати раніше.
     */
    public function sync(): array
    {
        $startedAt = now();
        $projects = PlanProject::whereNull('archived_at')->get();
        $readBoards = [];
        $seenCards = [];

        foreach (self::connectedUsers()->get()->unique('trello_board_id') as $owner) {
            $trello = TrelloService::forUser($owner);

            try {
                $board = $this->readBoard($trello, withCards: true);
            } catch (Throwable $exception) {
                $this->warn("Не вдалося прочитати дошку {$owner->name}: {$exception->getMessage()}");

                continue;
            }

            $readBoards[] = $board['id'];

            foreach ($board['cards'] as $card) {
                if (! $this->hasLabel($card, $board, PlanTrackers::TAG)) {
                    continue;
                }

                $seenCards[] = $card['id'];

                try {
                    $this->pull($card, $board, $trello, $owner, $projects);
                } catch (Throwable $exception) {
                    $this->warn("Картка «{$card['name']}»: {$exception->getMessage()}");
                }
            }

            $this->syncSubtasks($board);
        }

        // Звʼязані картки, яких на прочитаній дошці більше не видно з міткою, —
        // архівовані, видалені або мітку зняли. Задачі, змінені вже під час
        // прогону (щойно створені картки), не чіпаємо.
        PlanTask::whereNotNull('trello_card_id')
            ->whereNull('trello_unlinked_at')
            ->whereIn('trello_board_id', $readBoards)
            ->whereNotIn('trello_card_id', $seenCards)
            ->where('updated_at', '<', $startedAt)
            ->each(function (PlanTask $task) {
                $task->forceFill(['trello_unlinked_at' => now(), 'trello_pending' => false])->save();
                $this->summary['unlinked']++;
            });

        PlanTask::where('trello_pending', true)
            ->whereNull('trello_unlinked_at')
            ->each(fn (PlanTask $task) => $this->push($task));

        return $this->summary;
    }

    /**
     * Доносить до Trello поточний стан задачі: нову створює на дошці
     * виконавця з мітками «План» і проекту, у звʼязаній оновлює змінені поля.
     */
    public function push(PlanTask $task): bool
    {
        if ($task->trello_unlinked_at !== null) {
            $task->forceFill(['trello_pending' => false])->save();

            return true;
        }

        try {
            $owner = $task->trello_board_id !== null
                ? self::boardOwner($task->trello_board_id)
                : $task->employee?->user;

            if (! self::hasBoard($owner)) {
                throw new RuntimeException($task->trello_board_id !== null
                    ? 'дошку картки більше ніхто не підключив.'
                    : 'виконавець не підключив Trello.');
            }

            $trello = TrelloService::forUser($owner);
            $board = $this->readBoard($trello, withCards: false);

            if ($task->trello_card_id === null) {
                $this->create($task, $trello, $board);
            } else {
                $snapshot = $task->trello_snapshot ?? [];
                $changes = $this->changes($this->localFields($task, $board, $snapshot), $snapshot, self::FIELDS);

                if ($changes !== []) {
                    $trello->updateCard($task->trello_card_id, $this->cardFields($changes));
                }

                $task->forceFill([
                    'trello_snapshot' => array_replace($snapshot, $changes, ['status' => $task->status]),
                    'trello_pending' => false,
                ])->save();
            }
        } catch (Throwable $exception) {
            $this->warn("Задачу «{$task->title}» не вдалося передати в Trello: {$exception->getMessage()}");

            return false;
        }

        $this->summary['pushed']++;

        return true;
    }

    /** Знімає мітку «План» із картки задачі, видаленої з плану; решту міток лишає. */
    public function removeLabel(string $cardId, ?string $boardId): void
    {
        $owner = self::boardOwner($boardId);

        if (! self::hasBoard($owner)) {
            throw new RuntimeException('дошку картки ніхто не підключив.');
        }

        $trello = TrelloService::forUser($owner);
        $board = $this->readBoard($trello, withCards: false);

        foreach ($trello->card($cardId)['idLabels'] ?? [] as $labelId) {
            if ($this->key($board['labels'][$labelId] ?? '') === $this->key(PlanTrackers::TAG)) {
                $trello->removeCardLabel($cardId, $labelId);
            }
        }
    }

    /**
     * @param  Collection<int, PlanProject>  $projects
     */
    private function pull(array $card, array $board, TrelloService $trello, User $owner, Collection $projects): void
    {
        $tagged = $projects->filter(fn (PlanProject $project) => $this->hasLabel($card, $board, $project->name))->values();
        $project = $tagged->count() === 1 ? $tagged->first() : null;

        if ($tagged->count() > 1) {
            $this->warn("Картка «{$card['name']}» має мітки кількох проектів ({$tagged->pluck('name')->implode(', ')}) — проект не змінюємо.");
        }

        $remote = [
            'title' => (string) ($card['name'] ?? ''),
            'description' => $this->text((string) ($card['desc'] ?? '')),
            'list' => (string) ($card['idList'] ?? ''),
            'project' => $project ? (string) $project->id : '',
        ];

        $local = PlanTask::where('trello_card_id', $card['id'])->first();

        if ($local) {
            $this->reconcile($local, $remote, $project, $board, $trello);
        } elseif ($project) {
            $this->adopt($card['id'], $remote, $project, $board, $owner);
        }
    }

    private function create(PlanTask $task, TrelloService $trello, array $board): void
    {
        $local = $this->localFields($task, $board, []);
        // Статусу без свого списку (пауза…) — перший список дошки.
        $listId = $local['list'] ?? array_key_first($board['lists'])
            ?? throw new RuntimeException('на дошці немає жодного списку.');

        $labels = [
            $this->ensureLabel($trello, $board, PlanTrackers::TAG, self::PLAN_LABEL_COLOR),
            $this->ensureLabel($trello, $board, $task->project->name, self::PROJECT_LABEL_COLOR),
        ];

        $card = $trello->addCard([
            'idList' => $listId,
            'name' => $local['title'],
            'desc' => $local['description'],
            'idLabels' => implode(',', $labels),
            'pos' => 'bottom',
        ]);

        $task->forceFill([
            'trello_card_id' => (string) $card['id'],
            'trello_board_id' => $board['id'],
            'trello_snapshot' => ['list' => $listId, 'project' => (string) $task->plan_project_id, 'status' => $task->status] + $local,
            'trello_pending' => false,
        ])->save();
    }

    /** Нова картка з міткою — зʼявляється в плані проекту; виконавець — власник дошки. */
    private function adopt(string $cardId, array $remote, PlanProject $project, array $board, User $owner): void
    {
        $employee = $owner->employee;

        if (! $employee) {
            $this->warn("Картку «{$remote['title']}» пропущено: власник дошки не повʼязаний із працівником.");

            return;
        }

        $status = $this->statusOfList($board, $remote['list']) ?? PlanTask::STATUS_PENDING;

        DB::transaction(function () use ($cardId, $remote, $project, $board, $employee, $status) {
            $project->members()->syncWithoutDetaching([$employee->id]);

            $project->tasks()->create([
                'employee_id' => $employee->id,
                ...$this->textAttributes($remote),
                'status' => $status,
                'position' => $project->nextTaskPosition(),
                'trello_card_id' => $cardId,
                'trello_board_id' => $board['id'],
                'trello_snapshot' => $remote + ['status' => $status],
            ]);
        });

        $this->summary['created']++;
    }

    /**
     * Поле, змінене лише в Trello, переносимо в план; змінене в сервісі —
     * відправляємо в Trello (сервіс виграє й тоді, коли змінили обидві сторони).
     */
    private function reconcile(PlanTask $task, array $remote, ?PlanProject $project, array $board, TrelloService $trello): void
    {
        $snapshot = $task->trello_snapshot ?? $remote;
        $local = $this->localFields($task, $board, $snapshot);
        [$apply, $push] = $this->merge($local, $remote, $snapshot, self::FIELDS);

        // Мітку проекту змінили в Trello — задача переїжджає в інший план.
        $moveTo = $project && $remote['project'] !== ($snapshot['project'] ?? null) && $project->id !== $task->plan_project_id
            ? $project
            : null;

        $newSnapshot = $remote;
        $pushFailed = false;

        if ($push !== []) {
            try {
                $trello->updateCard($task->trello_card_id, $this->cardFields($push));
                $newSnapshot = array_replace($newSnapshot, $push);
                $this->summary['pushed']++;
            } catch (Throwable $exception) {
                $pushFailed = true;

                // Лишаємо старе значення в зліпку — наступний прогін спробує ще раз.
                foreach (array_keys($push) as $field) {
                    $newSnapshot[$field] = $snapshot[$field] ?? null;
                }

                $this->warn("Картку «{$task->title}» не вдалося оновити в Trello: {$exception->getMessage()}");
            }
        }

        $changed = $apply !== [] || $moveTo !== null || $task->trello_unlinked_at !== null;

        DB::transaction(function () use ($task, $apply, $moveTo, $newSnapshot, $pushFailed, $board) {
            $attributes = $this->textAttributes($apply);

            // Список без відомого статусу (власний список працівника) статус не змінює.
            if (array_key_exists('list', $apply) && ($status = $this->statusOfList($board, $apply['list']))) {
                $attributes['status'] = $status;
            }

            if ($moveTo) {
                $attributes['plan_project_id'] = $moveTo->id;
                $attributes['plan_section_id'] = null;
            }

            $task->forceFill($attributes + [
                // Статус, на якому зійшлися, — щоб наступного разу відрізнити
                // зміну статусу в сервісі від картки, яку просто переклали.
                'trello_snapshot' => $newSnapshot + ['status' => $attributes['status'] ?? $task->status],
                'trello_unlinked_at' => null,
                'trello_pending' => $pushFailed,
            ])->save();

            $this->afterPull($task, $task->employee_id);
        });

        if ($changed) {
            $this->summary['updated']++;
        }
    }

    /**
     * Підзадачі Duck Epics для звʼязаних карток цієї дошки. Дочірні картки
     * шукаємо серед відкритих карток дошки: архівовані в план не потрапляють.
     */
    private function syncSubtasks(array $board): void
    {
        $cards = collect($board['cards'])->keyBy('id');

        PlanTask::where('trello_board_id', $board['id'])
            ->whereNotNull('trello_card_id')
            ->whereNull('trello_unlinked_at')
            ->each(function (PlanTask $task) use ($cards) {
                $card = $cards[$task->trello_card_id] ?? null;

                if (! $card) {
                    return;
                }

                $this->storeSubtasks($task, collect($this->epicChildren($card))
                    ->map(fn (string $id) => $cards[$id] ?? null)
                    ->filter()
                    ->map(fn (array $child) => [
                        'id' => (string) $child['id'],
                        'title' => mb_substr((string) ($child['name'] ?? ''), 0, 1000),
                        'url' => (string) ($child['shortUrl'] ?? self::cardUrl($child['id'])),
                    ])
                    ->values()
                    ->all());
            });
    }

    /** @return list<string> */
    private function epicChildren(array $card): array
    {
        foreach ($card['pluginData'] ?? [] as $data) {
            if (($data['idPlugin'] ?? null) === self::EPICS_PLUGIN_ID) {
                $children = json_decode((string) ($data['value'] ?? ''), true)['children'] ?? [];

                return array_values(array_filter($children, 'is_string'));
            }
        }

        return [];
    }

    /**
     * Дошка в зручному вигляді: списки й мітки — id => назва (списки в порядку
     * на дошці), картки — лише коли потрібні.
     *
     * @return array{id: string, lists: array<string, string>, labels: array<string, string>, cards: list<array<string, mixed>>}
     */
    private function readBoard(TrelloService $trello, bool $withCards): array
    {
        return [
            'id' => (string) $trello->boardId(),
            'lists' => collect($trello->lists())->mapWithKeys(fn (array $list) => [$list['id'] => (string) $list['name']])->all(),
            'labels' => collect($trello->labels())->mapWithKeys(fn (array $label) => [$label['id'] => (string) ($label['name'] ?? '')])->all(),
            'cards' => $withCards ? $trello->openCards() : [],
        ];
    }

    private function hasLabel(array $card, array $board, string $name): bool
    {
        foreach ($card['idLabels'] ?? [] as $labelId) {
            if ($this->key($board['labels'][$labelId] ?? '') === $this->key($name)) {
                return true;
            }
        }

        return false;
    }

    /** Мітка з назвою; немає на дошці — створюємо. */
    private function ensureLabel(TrelloService $trello, array &$board, string $name, string $color): string
    {
        foreach ($board['labels'] as $id => $label) {
            if ($this->key($label) === $this->key($name)) {
                return $id;
            }
        }

        $id = $trello->createLabel($name, $color);
        $board['labels'][$id] = $name;

        return $id;
    }

    /**
     * Спільні поля задачі в термінах Trello. Список сервіс змінює, лише коли
     * в ньому змінили статус: інакше це узгоджений список, хоч би який (у
     * працівника бувають власні списки без статусу). Новому статусу — той
     * самий список, якщо підходить (на один статус їх буває кілька), або
     * перший підхожий; null — підхожого немає, і картку не рухаємо.
     *
     * @return array{title: string, description: string, list: ?string}
     */
    private function localFields(PlanTask $task, array $board, array $snapshot): array
    {
        $agreed = $snapshot['list'] ?? null;

        $list = match (true) {
            $agreed !== null && $task->status === ($snapshot['status'] ?? null) => $agreed,
            $agreed !== null && $this->statusOfList($board, $agreed) === $task->status => $agreed,
            default => collect($board['lists'])->keys()->first(fn (string $id) => $this->statusOfList($board, $id) === $task->status),
        };

        return [
            'title' => $task->title,
            'description' => $this->text((string) $task->note),
            'list' => $list,
        ];
    }

    private function statusOfList(array $board, string $listId): ?string
    {
        return self::LIST_STATUSES[$this->key($board['lists'][$listId] ?? '')] ?? null;
    }

    private function cardFields(array $fields): array
    {
        $names = ['title' => 'name', 'description' => 'desc', 'list' => 'idList'];

        return collect($fields)->mapWithKeys(fn ($value, $field) => [$names[$field] => $value])->all();
    }

    /** @return Builder<User> */
    private static function connectedUsers(): Builder
    {
        return User::whereNotNull('trello_token')->whereNotNull('trello_board_id');
    }

    /** Писати в дошку можна лише токеном її власника. */
    private static function boardOwner(?string $boardId): ?User
    {
        return $boardId === null ? null : self::connectedUsers()->where('trello_board_id', $boardId)->first();
    }
}
