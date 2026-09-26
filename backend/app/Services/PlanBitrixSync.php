<?php

namespace App\Services;

use App\Jobs\PushPlanTaskToBitrix;
use App\Jobs\RemovePlanTagInBitrix;
use App\Models\BitrixAccount;
use App\Models\BitrixWorkspace;
use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanTask;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Задачі «Планів» ↔ задачі Бітрікс24 з тегом «План».
 *
 * Проект плану — другий тег задачі з назвою проекту («План» + «Brok»): так
 * не потрібні проекти Бітрікса, недоступні на безкоштовному тарифі.
 * Спільні поля — назва, опис (= примітка), статус і виконавець; відмітки днів,
 * «працюю зараз» і розділи живуть лише в сервісі. Зі сервісу в Бітрікс зміни
 * йдуть одразу після запиту, з Бітрікса — прогоном раз на 5 хвилин.
 *
 * Хто змінив поле, видно лише проти зліпка — стану, на якому обидві сторони
 * востаннє зійшлися. Змінилось з обох боків — виграє сервіс, як і з Google
 * Таблицею. Видалення не поширюється: зникла з Бітрікса (або без тегу) задача
 * лишається в плані розвʼязаною, а видалена в плані — лишається в Бітріксі
 * без тегу «План».
 */
class PlanBitrixSync
{
    public const TAG = 'План';

    private const FIELDS = ['title', 'description', 'status', 'responsible'];

    /** Скільки акаунтів пробувати для одного запису, поки хтось не матиме прав. */
    private const MAX_ACCOUNT_ATTEMPTS = 3;

    /**
     * Статуси Бітрікса: 2 — чекає виконання, 3 — виконується, 4 — чекає
     * контролю, 5 — завершена, 6 — відкладена, 7 — відхилена.
     * «Регулярна» й «Поки не актуально» власних відповідників не мають.
     */
    private const TO_BITRIX = [
        PlanTask::STATUS_PENDING => 2,
        PlanTask::STATUS_IN_PROGRESS => 3,
        PlanTask::STATUS_REVIEW => 4,
        PlanTask::STATUS_PAUSED => 6,
        PlanTask::STATUS_RECURRING => 3,
        PlanTask::STATUS_DONE => 5,
        PlanTask::STATUS_NOT_RELEVANT => 6,
    ];

    private const FROM_BITRIX = [
        2 => PlanTask::STATUS_PENDING,
        3 => PlanTask::STATUS_IN_PROGRESS,
        4 => PlanTask::STATUS_REVIEW,
        5 => PlanTask::STATUS_DONE,
        6 => PlanTask::STATUS_PAUSED,
        7 => PlanTask::STATUS_NOT_RELEVANT,
    ];

    /** @var array{created: int, updated: int, pushed: int, unlinked: int, warnings: list<string>} */
    private array $summary = ['created' => 0, 'updated' => 0, 'pushed' => 0, 'unlinked' => 0, 'warnings' => []];

    /** Є куди синхронізувати: портал підключено і хоч один працівник авторизований. */
    public static function isAvailable(): bool
    {
        return BitrixWorkspace::active() !== null && BitrixAccount::query()->exists();
    }

    /**
     * Зміна в сервісі: позначаємо задачу й доносимо її до Бітрікса одразу
     * після відповіді. Не вдалося — підхопить наступний прогін.
     */
    public static function queuePush(PlanTask $task, ?User $actor): void
    {
        if ($task->bitrix_unlinked_at !== null || ! self::isAvailable()) {
            return;
        }

        $task->forceFill(['bitrix_pending' => true])->save();

        PushPlanTaskToBitrix::dispatchAfterResponse($task->id, $actor?->id);
    }

    /** Задачу видаляють із плану: у Бітріксі вона лишається, лише без тегу. */
    public static function queueTagRemoval(PlanTask $task, ?User $actor): void
    {
        if ($task->bitrix_task_id === null || $task->bitrix_unlinked_at !== null || ! self::isAvailable()) {
            return;
        }

        RemovePlanTagInBitrix::dispatchAfterResponse($task->bitrix_task_id, $actor?->id, $task->employee_id);
    }

    public static function statusToBitrix(string $status): int
    {
        return self::TO_BITRIX[$status] ?? 2;
    }

    /**
     * @return array{created: int, updated: int, pushed: int, unlinked: int, warnings: list<string>}
     */
    public function summary(): array
    {
        return $this->summary;
    }

    /**
     * Прогін: підтягнути з Бітрікса нові й змінені задачі з тегом, розвʼязати
     * зниклі, дотиснути зміни сервісу, які не вдалося надіслати раніше.
     */
    public function sync(): array
    {
        $startedAt = now();
        $accounts = $this->orderedAccounts();

        if (BitrixWorkspace::active() === null || $accounts->isEmpty()) {
            return $this->summary;
        }

        // Токени особисті: кожен бачить лише доступні йому задачі, тож повний
        // набір — обʼєднання того, що бачать усі підключені працівники.
        $remote = [];
        $reader = null;
        $complete = true;
        $projects = PlanProject::whereNull('archived_at')->get();
        // id задачі Бітрікса => id проектів, чиї теги на ній стоять. Теги
        // задач у списку Бітрікс не віддає, тож питаємо окремо по кожному.
        $projectTags = [];

        foreach ($accounts as $account) {
            $bitrix = BitrixService::forAccount($account);

            try {
                foreach ($bitrix->tasksWithTag(self::TAG) as $task) {
                    $remote[(string) ($task['id'] ?? '')] ??= ['task' => $task, 'via' => $bitrix];
                }

                foreach ($projects as $project) {
                    foreach ($bitrix->tasksWithTag($project->name, ['ID']) as $task) {
                        $projectTags[(string) ($task['id'] ?? '')][$project->id] = $project;
                    }
                }

                $reader ??= $bitrix;
            } catch (Throwable $exception) {
                $complete = false;
                $this->warn("Не вдалося прочитати задачі від імені {$account->bitrix_user_name}: {$exception->getMessage()}");
            }
        }

        unset($remote['']);

        if ($reader === null) {
            return $this->summary;
        }

        foreach ($remote as $id => ['task' => $task, 'via' => $bitrix]) {
            $tagged = array_values($projectTags[(string) $id] ?? []);
            $project = count($tagged) === 1 ? $tagged[0] : null;

            if (count($tagged) > 1) {
                $names = implode(', ', array_map(fn (PlanProject $p) => $p->name, $tagged));
                $this->warn("Задача Бітрікса #{$id} має теги кількох проектів ({$names}) — проект не змінюємо.");
            }

            $fields = $this->remoteFields($task) + ['project' => $project ? (string) $project->id : ''];

            try {
                $local = PlanTask::where('bitrix_task_id', (string) $id)->first();

                if ($local) {
                    $this->reconcile($local, $fields, $project, $bitrix);
                } elseif ($project) {
                    $this->adopt((string) $id, $fields, $project, $bitrix);
                }
            } catch (Throwable $exception) {
                $this->warn("Задача Бітрікса #{$id}: {$exception->getMessage()}");
            }
        }

        // Звʼязані задачі, яких більше не видно з тегом, — видалені в Бітріксі
        // або тег зняли. Лише якщо прочитати вдалося від імені всіх: інакше
        // «не видно» може означати «не зміг прочитати». Задачі, змінені вже
        // під час прогону (щойно створені в Бітріксі), не чіпаємо.
        if ($complete) {
            PlanTask::whereNotNull('bitrix_task_id')
                ->whereNull('bitrix_unlinked_at')
                ->whereNotIn('bitrix_task_id', array_map('strval', array_keys($remote)))
                ->where('updated_at', '<', $startedAt)
                ->each(function (PlanTask $task) {
                    $task->forceFill(['bitrix_unlinked_at' => now(), 'bitrix_pending' => false])->save();
                    $this->summary['unlinked']++;
                });
        }

        PlanTask::where('bitrix_pending', true)
            ->whereNull('bitrix_unlinked_at')
            ->each(fn (PlanTask $task) => $this->push($task, null));

        return $this->summary;
    }

    /**
     * Доносить до Бітрікса поточний стан задачі сервісу: нову створює з тегом
     * «План» у проекті з тією ж назвою, у звʼязаній оновлює змінені поля.
     */
    public function push(PlanTask $task, ?User $actor): bool
    {
        if ($task->bitrix_unlinked_at !== null) {
            $task->forceFill(['bitrix_pending' => false])->save();

            return true;
        }

        try {
            $this->withAccounts($this->accountsFor($actor, $task), function (BitrixService $bitrix) use ($task) {
                $local = $this->localFields($task, $bitrix);

                if ($task->bitrix_task_id === null) {
                    $this->create($task, $local, $bitrix);

                    return;
                }

                $snapshot = $task->bitrix_snapshot ?? [];
                $changes = [];

                foreach (self::FIELDS as $field) {
                    if ($local[$field] !== null && $local[$field] !== ($snapshot[$field] ?? null)) {
                        $changes[$field] = $local[$field];
                    }
                }

                if ($changes !== []) {
                    $bitrix->updateTask($task->bitrix_task_id, $this->bitrixFields($changes));
                }

                $task->forceFill([
                    'bitrix_snapshot' => array_replace($snapshot, $changes),
                    'bitrix_pending' => false,
                ])->save();
            });
        } catch (Throwable $exception) {
            $this->warn("Задачу «{$task->title}» не вдалося передати в Бітрікс24: {$exception->getMessage()}");

            return false;
        }

        $this->summary['pushed']++;

        return true;
    }

    /** Знімає тег «План» із задачі, видаленої з плану; решту тегів лишає. */
    public function removeTag(string $bitrixTaskId, ?User $actor, ?int $employeeId): void
    {
        $executor = $employeeId ? Employee::find($employeeId)?->user : null;

        $this->withAccounts($this->accountsFor($actor, null, $executor), function (BitrixService $bitrix) use ($bitrixTaskId) {
            $tags = $this->tagTitles($bitrix->task($bitrixTaskId)['tags'] ?? null);

            if ($tags === null) {
                throw new RuntimeException('Бітрікс24 не віддав теги задачі.');
            }

            $rest = array_values(array_filter($tags, fn (string $tag) => $this->key($tag) !== $this->key(self::TAG)));

            if (count($rest) !== count($tags)) {
                // Порожній масив Бітрікс сприймає як «не змінювати», а порожній
                // рядок — як «прибрати всі».
                $bitrix->updateTask($bitrixTaskId, ['TAGS' => $rest === [] ? '' : $rest]);
            }
        });
    }

    private function create(PlanTask $task, array $local, BitrixService $bitrix): void
    {
        if ($local['responsible'] === null) {
            $name = $task->employee?->name ?? '—';

            throw new RuntimeException("виконавця «{$name}» не знайдено серед користувачів порталу — він має підключити Бітрікс24 або мати ту саму пошту.");
        }

        $fields = [
            'TITLE' => $local['title'],
            'DESCRIPTION' => $local['description'],
            'RESPONSIBLE_ID' => $local['responsible'],
            'TAGS' => [self::TAG, $task->project->name],
        ];

        $id = $bitrix->addTask($fields);

        // Id зберігаємо одразу: якщо далі щось впаде, повтор оновить цю ж
        // задачу, а не створить у Бітріксі ще одну.
        $snapshot = ['status' => 2, 'project' => (string) $task->plan_project_id] + $local;
        $task->forceFill(['bitrix_task_id' => $id, 'bitrix_snapshot' => $snapshot])->save();

        // Нова задача в Бітріксі завжди «чекає виконання»; інший статус — окремим оновленням.
        if ($local['status'] !== 2) {
            $bitrix->updateTask($id, ['STATUS' => $local['status']]);
        }

        $task->forceFill(['bitrix_snapshot' => $local + $snapshot, 'bitrix_pending' => false])->save();
    }

    /** Нова задача з тегом у Бітріксі — з'являється в плані відповідного проекту. */
    private function adopt(string $id, array $remote, PlanProject $project, BitrixService $bitrix): void
    {
        $employee = $this->employeeFor($remote['responsible'], $bitrix);

        if (! $employee) {
            $this->warn("Задачу Бітрікса #{$id} «{$remote['title']}» пропущено: її виконавця немає серед працівників сервісу.");

            return;
        }

        DB::transaction(function () use ($id, $remote, $project, $employee) {
            // Виконавцю задачі проекту потрібен доступ до плану цього проекту.
            $project->members()->syncWithoutDetaching([$employee->id]);

            $project->tasks()->create([
                'employee_id' => $employee->id,
                'title' => mb_substr($remote['title'], 0, 1000),
                'note' => $remote['description'] === '' ? null : $remote['description'],
                'status' => self::FROM_BITRIX[$remote['status']] ?? PlanTask::STATUS_PENDING,
                'position' => (int) PlanTask::where('plan_project_id', $project->id)->max('position') + 1,
                'bitrix_task_id' => $id,
                'bitrix_snapshot' => $remote,
            ]);
        });

        $this->summary['created']++;
    }

    /**
     * Звʼязана задача: поле, змінене лише в Бітріксі, переносимо в план;
     * змінене в сервісі — відправляємо в Бітрікс (сервіс виграє й тоді, коли
     * змінили обидві сторони).
     */
    private function reconcile(PlanTask $task, array $remote, ?PlanProject $project, BitrixService $bitrix): void
    {
        $snapshot = $task->bitrix_snapshot ?? $remote;
        $local = $this->localFields($task, $bitrix);
        $apply = [];
        $push = [];

        foreach (self::FIELDS as $field) {
            $mine = $local[$field];
            $theirs = $remote[$field];
            $base = $snapshot[$field] ?? null;

            if ($mine !== null && $mine !== $base && $mine !== $theirs) {
                $push[$field] = $mine;
            } elseif ($theirs !== $base && $theirs !== $mine) {
                $apply[$field] = $theirs;
            }
        }

        // Тег проекту змінили в Бітріксі — задача переїжджає в інший план.
        $moveTo = $project && $remote['project'] !== ($snapshot['project'] ?? null) && $project->id !== $task->plan_project_id
            ? $project
            : null;

        $newSnapshot = $remote;
        $pushFailed = false;

        if ($push !== []) {
            try {
                $this->withAccounts($this->accountsFor(null, $task), fn (BitrixService $writer) => $writer->updateTask($task->bitrix_task_id, $this->bitrixFields($push)));
                $newSnapshot = array_replace($newSnapshot, $push);
                $this->summary['pushed']++;
            } catch (Throwable $exception) {
                $pushFailed = true;

                // Лишаємо старе значення в зліпку — наступний прогін спробує ще раз.
                foreach (array_keys($push) as $field) {
                    $newSnapshot[$field] = $snapshot[$field] ?? null;
                }

                $this->warn("Задачу «{$task->title}» не вдалося оновити в Бітріксі: {$exception->getMessage()}");
            }
        }

        $changed = $apply !== [] || $moveTo !== null || $task->bitrix_unlinked_at !== null;

        DB::transaction(function () use ($task, $apply, $moveTo, $newSnapshot, $pushFailed, $bitrix) {
            $previousEmployeeId = $task->employee_id;
            $attributes = [];

            if (array_key_exists('title', $apply)) {
                $attributes['title'] = mb_substr($apply['title'], 0, 1000);
            }

            if (array_key_exists('description', $apply)) {
                $attributes['note'] = $apply['description'] === '' ? null : $apply['description'];
            }

            if (array_key_exists('status', $apply)) {
                $attributes['status'] = self::FROM_BITRIX[$apply['status']] ?? $task->status;
            }

            if (array_key_exists('responsible', $apply)) {
                $employee = $this->employeeFor($apply['responsible'], $bitrix);

                if ($employee) {
                    $attributes['employee_id'] = $employee->id;
                } else {
                    $this->warn("Новий виконавець задачі «{$task->title}» у Бітріксі не знайдений серед працівників — лишаємо попереднього.");
                }
            }

            if ($moveTo) {
                $attributes['plan_project_id'] = $moveTo->id;
                $attributes['plan_section_id'] = null;
            }

            $task->forceFill($attributes + [
                'bitrix_snapshot' => $newSnapshot,
                'bitrix_unlinked_at' => null,
                'bitrix_pending' => $pushFailed,
            ])->save();

            // Виконавцю задачі потрібен доступ до плану її проекту.
            $task->unsetRelation('project');
            $task->project->members()->syncWithoutDetaching([$task->employee_id]);

            if (in_array($task->status, PlanTask::INACTIVE_STATUSES, true) || $task->employee_id !== $previousEmployeeId) {
                Employee::whereKey($previousEmployeeId)
                    ->where('current_plan_task_id', $task->id)
                    ->update(['current_plan_task_id' => null]);
            }
        });

        if ($changed) {
            $this->summary['updated']++;
        }
    }

    /**
     * Спільні поля задачі сервісу в термінах Бітрікса. Виконавець — null,
     * якщо його не знайти на порталі: тоді це поле ми не нав'язуємо.
     *
     * @return array{title: string, description: string, status: int, responsible: ?string}
     */
    private function localFields(PlanTask $task, BitrixService $bitrix): array
    {
        return [
            'title' => $task->title,
            'description' => $this->text((string) $task->note),
            'status' => self::statusToBitrix($task->status),
            'responsible' => $task->employee ? $this->bitrixUserIdFor($task->employee, $bitrix) : null,
        ];
    }

    /**
     * @return array{title: string, description: string, status: int, responsible: string}
     */
    private function remoteFields(array $task): array
    {
        $status = (int) ($task['status'] ?? 2);

        return [
            'title' => (string) ($task['title'] ?? ''),
            'description' => $this->text(BitrixService::plainText((string) ($task['description'] ?? ''))),
            // «Нова» (1) — застарілий статус, по суті те саме, що «чекає виконання».
            'status' => $status === 1 ? 2 : $status,
            'responsible' => (string) ($task['responsibleId'] ?? ''),
        ];
    }

    private function bitrixFields(array $fields): array
    {
        $names = ['title' => 'TITLE', 'description' => 'DESCRIPTION', 'status' => 'STATUS', 'responsible' => 'RESPONSIBLE_ID'];

        return collect($fields)->mapWithKeys(fn ($value, $field) => [$names[$field] => $value])->all();
    }

    private function bitrixUserIdFor(Employee $employee, BitrixService $bitrix): ?string
    {
        $own = $employee->user?->bitrixAccount?->bitrix_user_id;

        if ($own) {
            return (string) $own;
        }

        foreach (array_unique(array_filter([$employee->email, $employee->user?->email])) as $email) {
            try {
                $id = $bitrix->userIdByEmail($email);
            } catch (Throwable) {
                $id = null;
            }

            if ($id) {
                return $id;
            }
        }

        return null;
    }

    private function employeeFor(string $bitrixUserId, BitrixService $bitrix): ?Employee
    {
        if ($bitrixUserId === '') {
            return null;
        }

        $userId = BitrixAccount::where('bitrix_user_id', $bitrixUserId)->value('user_id');

        if ($userId) {
            return Employee::where('user_id', $userId)->first();
        }

        try {
            $email = $bitrix->userEmail($bitrixUserId);
        } catch (Throwable) {
            $email = null;
        }

        if (! $email) {
            return null;
        }

        $email = mb_strtolower($email);

        return Employee::whereRaw('lower(email) = ?', [$email])->first()
            ?? Employee::whereHas('user', fn ($users) => $users->whereRaw('lower(email) = ?', [$email]))->first();
    }

    /**
     * Від чийого імені писати: того, хто змінив, виконавця, адміністраторів,
     * решти. У Бітріксі назву й опис чужої задачі міняє лише постановник чи
     * адміністратор порталу — тож на відмову пробуємо наступний акаунт.
     *
     * @return Collection<int, BitrixAccount>
     */
    private function accountsFor(?User $actor, ?PlanTask $task, ?User $executor = null): Collection
    {
        $executor ??= $task?->employee?->user;

        return collect([$actor?->bitrixAccount, $executor?->bitrixAccount])
            ->merge($this->orderedAccounts())
            ->filter()
            ->unique('id')
            ->values()
            ->take(self::MAX_ACCOUNT_ATTEMPTS);
    }

    /** Спершу адміністратори — у них на порталі зазвичай найширші права. */
    private function orderedAccounts(): Collection
    {
        return BitrixAccount::with('user')->get()
            ->sortBy(fn (BitrixAccount $account) => $account->user?->isAdmin() ? 0 : 1)
            ->values();
    }

    private function withAccounts(Collection $accounts, callable $callback): mixed
    {
        if ($accounts->isEmpty()) {
            throw new RuntimeException('ніхто з працівників не підключив Бітрікс24.');
        }

        $last = null;

        foreach ($accounts as $account) {
            try {
                return $callback(BitrixService::forAccount($account));
            } catch (Throwable $exception) {
                $last = $exception;
            }
        }

        throw $last;
    }

    /** Теги з відповіді tasks.task.get — Бітрікс віддає їх то списком, то мапою. */
    private function tagTitles(mixed $tags): ?array
    {
        if (! is_array($tags)) {
            return null;
        }

        return collect($tags)
            ->map(fn ($tag) => is_array($tag) ? ($tag['title'] ?? $tag['name'] ?? $tag['NAME'] ?? $tag['TITLE'] ?? null) : $tag)
            ->filter(fn ($tag) => is_string($tag) && $tag !== '')
            ->values()
            ->all();
    }

    private function text(string $value): string
    {
        return trim(str_replace("\r\n", "\n", $value));
    }

    private function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function warn(string $message): void
    {
        $this->summary['warnings'][] = $message;
        Log::warning('Плани ↔ Бітрікс24: '.$message);
    }
}
