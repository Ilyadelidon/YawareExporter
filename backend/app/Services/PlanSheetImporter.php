<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanSection;
use App\Models\PlanTask;
use App\Models\PlanTaskDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Зворотний бік вивантаження: правки, зроблені людиною просто в Google
 * Таблиці, підтягуються в систему перед тим, як експорт перезапише аркуш.
 * Без цього кроку все, що дописали в таблиці, зникало б щоночі.
 *
 * Звіряння тристороннє — аркуш проти зліпка останнього вивантаження
 * (`plan_projects.sheet_snapshot`) проти поточної бази. Тільки так видно
 * різницю між «людина змінила в таблиці» і «ми самі це туди записали».
 * При розбіжності з обох боків виграє сервіс (рішення користувача): правка
 * з таблиці не застосовується, а потрапляє в попередження.
 *
 * Рядки зіставляються за прихованою колонкою з ID. Порожній ID — новий
 * рядок; ID, що вже траплявся вище, — скопійований рядок, тобто теж новий:
 * скопіювати рядок розділу і виправити назву — звичний спосіб додати розділ
 * у таблиці.
 *
 * Чого імпорт не робить навмисно: не видаляє. Прибраний у таблиці рядок
 * повернеться наступним вивантаженням, а в журнал піде рядок про розбіжність —
 * помилковий рух мишею в таблиці не має стирати історію днів у системі.
 */
class PlanSheetImporter
{
    /** Довжини з валідації PlanTaskController — тут ті самі межі. */
    private const MAX_TITLE = 1000;

    private const MAX_NOTE = 5000;

    private const MAX_DAY_COMMENT = 2000;

    /**
     * Google зберігає колір із похибкою: наш акцент #149d8d повертається як
     * #139d8d (частку 0.078 він обрізає до 19/255, а не округлює до 20).
     * Тому кольори звіряються з допуском, а не порівнюються як рядки.
     */
    private const COLOR_TOLERANCE = 2;

    /** @var array<string, mixed> */
    private array $snapshot = [];

    /** @var list<string> */
    private array $warnings = [];

    private int $created = 0;

    private int $updated = 0;

    private int $days = 0;

    /**
     * Зливає аркуш у проект.
     *
     * @param  list<list<array{text: string, note: ?string, background: ?string}>>  $cells  аркуш як його прочитали
     * @param  array<string, mixed>  $snapshot  зліпок останнього вивантаження
     * @return array{created: int, updated: int, days: int, warnings: list<string>}
     */
    public function pull(PlanProject $project, array $cells, array $snapshot): array
    {
        $this->snapshot = $snapshot;
        $this->warnings = [];
        $this->created = 0;
        $this->updated = 0;
        $this->days = 0;

        $dates = array_values($snapshot['dates'] ?? []);

        if (! $this->headerMatches($cells[0] ?? [], $dates)) {
            $this->warnings[] = 'колонки аркуша не збігаються з тим, що ми вивантажували (додали чи прибрали колонку?) — з аркуша нічого не підтягнуто';

            return $this->report();
        }

        DB::transaction(function () use ($project, $cells, $snapshot, $dates) {
            $this->walk($project, $cells, $snapshot, $dates);
        });

        return $this->report();
    }

    /**
     * @param  list<list<array{text: string, note: ?string, background: ?string}>>  $cells
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $dates
     */
    private function walk(PlanProject $project, array $cells, array $snapshot, array $dates): void
    {
        $tasks = $project->tasks()->with('employee:id,name')->get()->keyBy('id');
        $sections = $project->sections()->get()->keyBy('id');
        $marks = PlanTaskDay::whereIn('plan_task_id', $tasks->keys())->get()->groupBy('plan_task_id');
        $members = $project->members()->get();

        $seen = [];
        $handledTasks = [];
        $sectionId = null;
        $position = (int) $tasks->max('position');

        foreach ($cells as $index => $row) {
            if ($index === 0) {
                continue;
            }

            $id = $this->text($row, PlanSheetExporter::ID_COLUMN);
            $title = $this->text($row, PlanSheetExporter::TITLE_COLUMN);

            if ($id === '' && $title === '') {
                continue;
            }

            // Людина бачить номер рядка, а не індекс масиву.
            $line = $index + 1;
            [$kind, $key] = $this->parseId($id);
            $copy = $id !== '' && isset($seen[$id]);
            $seen[$id] = true;

            if ($kind === 's') {
                $sectionId = $this->section($project, $sections, $row, $title, $key, $copy, $line);

                continue;
            }

            $task = $kind === 't' && ! $copy ? $tasks->get($key) : null;

            if ($task instanceof PlanTask) {
                $handledTasks[$task->id] = true;
                $snap = $snapshot['tasks'][(string) $task->id] ?? null;

                if ($snap === null) {
                    // Рядок із ID, якого ми не вивантажували: ID вписали вручну.
                    // Застосувати нічого не можемо — порівнювати немає з чим.
                    $this->warnings[] = "рядок {$line}: ID «{$id}» не з нашого вивантаження — рядок пропущено";

                    continue;
                }

                $this->mergeTask($task, $snap, $row, $members, $line);
                $this->mergeDays($task, $snap, $row, $marks->get($task->id, collect()), $dates, $line);

                continue;
            }

            $created = $this->createTask($project, $row, $title, $sectionId, $members, $position + 1, $line);

            if ($created instanceof PlanTask) {
                $position++;
                // Дні щойно створеної задачі — усе, що в її рядку відмічено.
                $this->mergeDays($created, ['days' => []], $row, collect(), $dates, $line);
            }
        }

        $missing = array_diff_key($snapshot['tasks'] ?? [], array_flip(array_map('strval', array_keys($handledTasks))));

        if ($missing !== []) {
            $count = count($missing);
            $this->warnings[] = "рядків задач прибрано з аркуша: {$count} — у системі вони лишились і повернуться наступним вивантаженням";
        }
    }

    /**
     * Рядок розділу: або зливає назву й примітку наявного, або створює новий
     * (скопійований чи незнайомий ID). Повертає ID розділу, до якого тепер
     * належать наступні рядки задач; null — «Без розділу».
     *
     * @param  Collection<int, PlanSection>  $sections
     * @param  list<array{text: string, note: ?string, background: ?string}>  $row
     */
    private function section(PlanProject $project, Collection $sections, array $row, string $title, ?int $key, bool $copy, int $line): ?int
    {
        // «s:0» — псевдорозділ «Без розділу», у базі його немає.
        if ($key === 0 && ! $copy) {
            return null;
        }

        $note = $this->text($row, PlanSheetExporter::COMMENT_COLUMN);
        $section = $copy ? null : $sections->get($key);

        if ($section instanceof PlanSection) {
            $snap = $this->snapshot['sections'][(string) $section->id] ?? null;

            if ($snap === null) {
                return $section->id;
            }

            $changes = array_filter([
                'name' => $this->pick($title, (string) $snap['name'], $section->name, "розділ «{$section->name}»", $line),
                'note' => $this->pick($note, (string) $snap['note'], (string) $section->note, "примітка розділу «{$section->name}»", $line),
            ], fn (?string $value) => $value !== null);

            if ($changes !== []) {
                if (($changes['name'] ?? null) === '') {
                    unset($changes['name']);
                    $this->warnings[] = "рядок {$line}: назву розділу стерли — лишено попередню";
                }

                if ($changes !== []) {
                    $section->update($this->normalize($changes));
                    $this->updated++;
                }
            }

            return $section->id;
        }

        if ($title === '') {
            $this->warnings[] = "рядок {$line}: новий розділ без назви — пропущено";

            return null;
        }

        $section = $project->sections()->create([
            'name' => mb_substr($title, 0, 255),
            'note' => $note === '' ? null : mb_substr($note, 0, self::MAX_NOTE),
            'position' => (int) $sections->max('position') + 1,
        ]);

        $sections->put($section->id, $section);
        $this->created++;

        return $section->id;
    }

    /**
     * @param  array<string, mixed>  $snap
     * @param  list<array{text: string, note: ?string, background: ?string}>  $row
     * @param  Collection<int, Employee>  $members
     */
    private function mergeTask(PlanTask $task, array $snap, array $row, Collection $members, int $line): void
    {
        $changes = [];
        $label = "задача «{$task->title}»";

        $title = $this->pick($this->text($row, PlanSheetExporter::TITLE_COLUMN), (string) $snap['title'], $task->title, $label, $line);

        if ($title === '') {
            $this->warnings[] = "рядок {$line}: назву задачі стерли — лишено попередню";
        } elseif ($title !== null) {
            $changes['title'] = mb_substr($title, 0, self::MAX_TITLE);
        }

        $note = $this->pick($this->text($row, PlanSheetExporter::COMMENT_COLUMN), (string) $snap['note'], (string) $task->note, "коментар до {$label}", $line);

        if ($note !== null) {
            $changes['note'] = $note === '' ? null : mb_substr($note, 0, self::MAX_NOTE);
        }

        $statusLabel = $this->pick($this->text($row, PlanSheetExporter::STATUS_COLUMN), (string) $snap['status'], PlanTask::STATUS_LABELS[$task->status] ?? $task->status, "статус {$label}", $line);

        if ($statusLabel !== null) {
            $status = array_search($statusLabel, PlanTask::STATUS_LABELS, true);

            if ($status === false) {
                $this->warnings[] = "рядок {$line}: статус «{$statusLabel}» невідомий — лишено попередній";
            } else {
                $changes['status'] = $status;
            }
        }

        $name = $this->pick($this->text($row, PlanSheetExporter::EMPLOYEE_COLUMN), (string) $snap['employee'], (string) $task->employee?->name, "виконавець {$label}", $line);

        if ($name !== null) {
            $employee = $this->member($members, $name);

            if (! $employee instanceof Employee) {
                $this->warnings[] = "рядок {$line}: «{$name}» не учасник проекту — виконавця не змінено";
            } else {
                $changes['employee_id'] = $employee->id;
            }
        }

        if ($changes === []) {
            return;
        }

        $previousEmployeeId = $task->employee_id;
        $task->update($changes);
        $this->updated++;

        // Те саме, що робить сервіс при зміні статусу чи виконавця: закрита
        // або передана задача більше не «поточна» для попереднього виконавця.
        if (in_array($task->status, PlanTask::INACTIVE_STATUSES, true) || $task->employee_id !== $previousEmployeeId) {
            Employee::whereKey($previousEmployeeId)
                ->where('current_plan_task_id', $task->id)
                ->update(['current_plan_task_id' => null]);
        }
    }

    /**
     * Відмітки днів: у таблиці це залита клітинка, у системі — рядок у
     * plan_task_days. Текст або нотатка в клітинці стає коментарем дня.
     *
     * @param  array<string, mixed>  $snap
     * @param  list<array{text: string, note: ?string, background: ?string}>  $row
     * @param  Collection<int, PlanTaskDay>  $marks
     * @param  list<string>  $dates
     */
    private function mergeDays(PlanTask $task, array $snap, array $row, Collection $marks, array $dates, int $line): void
    {
        $sheet = [];
        $ours = [];

        foreach ($dates as $offset => $date) {
            $cell = $row[PlanSheetExporter::FIRST_DAY_COLUMN + $offset] ?? [];
            $comment = trim((string) ($cell['note'] ?? '')) ?: trim((string) ($cell['text'] ?? ''));

            // Акцентну клітинку «працює зараз» малюємо ми самі й поверх
            // відмітки дня — з неї не видно, чи день відмічено, тож такий
            // день лишаємо як у сервісі. Порожній текст навмисно: якщо в цю
            // ж клітинку щось вписали, це вже правка людини.
            if ($this->sameColor($cell['background'] ?? null, PlanSheetExporter::CURRENT) && $comment === '') {
                $ours[$date] = true;

                continue;
            }

            if ($this->isMarked($cell) || $comment !== '') {
                $sheet[$date] = mb_substr($comment, 0, self::MAX_DAY_COMMENT);
            }
        }

        $before = array_map('strval', $snap['days'] ?? []);
        $now = $marks->mapWithKeys(fn (PlanTaskDay $day) => [$day->date->toDateString() => (string) $day->comment]);

        foreach ($sheet as $date => $comment) {
            $stored = $now->get($date);

            if ($stored === null) {
                // Дня немає в системі. Якщо його не було й у зліпку — його
                // відмітили в таблиці; якщо був — його зняли в сервісі, і
                // тоді виграє сервіс.
                if (! array_key_exists($date, $before)) {
                    PlanTaskDay::create(['plan_task_id' => $task->id, 'date' => $date, 'comment' => $comment === '' ? null : $comment]);
                    $this->days++;
                }

                continue;
            }

            $applied = $this->pick($comment, (string) ($before[$date] ?? ''), $stored, "коментар дня {$this->day($date)}", $line);

            if ($applied !== null) {
                PlanTaskDay::where('plan_task_id', $task->id)->where('date', $date)
                    ->update(['comment' => $applied === '' ? null : $applied]);
                $this->days++;
            }
        }

        foreach ($before as $date => $comment) {
            if (isset($sheet[$date]) || isset($ours[$date]) || ! $now->has($date)) {
                continue;
            }

            // Відмітку зняли в таблиці. Якщо в сервісі до дня теж торкались
            // (змінили коментар), лишаємо як у сервісі.
            if ($now->get($date) !== $comment) {
                $this->warnings[] = "рядок {$line}: день {$this->day($date)} зняли в таблиці, але змінили в сервісі — лишено як у сервісі";

                continue;
            }

            PlanTaskDay::where('plan_task_id', $task->id)->where('date', $date)->delete();
            $this->days++;
        }
    }

    /**
     * Новий рядок у таблиці — нова задача. Виконавець обовʼязковий: без нього
     * задачу нікуди подіти, тож такий рядок лишається в аркуші до наступного
     * вивантаження і зникає — про це й попередження.
     *
     * @param  list<array{text: string, note: ?string, background: ?string}>  $row
     * @param  Collection<int, Employee>  $members
     */
    private function createTask(PlanProject $project, array $row, string $title, ?int $sectionId, Collection $members, int $position, int $line): ?PlanTask
    {
        if ($title === '') {
            $this->warnings[] = "рядок {$line}: нова задача без назви — пропущено";

            return null;
        }

        $name = $this->text($row, PlanSheetExporter::EMPLOYEE_COLUMN);
        $employee = $this->member($members, $name);

        if (! $employee instanceof Employee) {
            $reason = $name === '' ? 'не вказано виконавця' : "«{$name}» не учасник проекту";
            $this->warnings[] = "рядок {$line}: нову задачу «{$title}» пропущено — {$reason}";

            return null;
        }

        $note = $this->text($row, PlanSheetExporter::COMMENT_COLUMN);
        $statusLabel = $this->text($row, PlanSheetExporter::STATUS_COLUMN);
        $status = $statusLabel === '' ? PlanTask::STATUS_PENDING : array_search($statusLabel, PlanTask::STATUS_LABELS, true);

        if ($status === false) {
            $this->warnings[] = "рядок {$line}: статус «{$statusLabel}» невідомий — нова задача створена як «".PlanTask::STATUS_LABELS[PlanTask::STATUS_PENDING].'»';
            $status = PlanTask::STATUS_PENDING;
        }

        $task = $project->tasks()->create([
            'plan_section_id' => $sectionId,
            'employee_id' => $employee->id,
            'title' => mb_substr($title, 0, self::MAX_TITLE),
            'note' => $note === '' ? null : mb_substr($note, 0, self::MAX_NOTE),
            'status' => $status,
            'position' => $position,
        ]);

        $this->created++;

        return $task;
    }

    /**
     * Що взяти з аркуша. null — не чіпати: або в таблиці не міняли, або
     * міняли і там, і в сервісі (тоді виграє сервіс, і це попередження).
     */
    private function pick(string $sheet, string $snapshot, string $service, string $what, int $line): ?string
    {
        if ($sheet === $snapshot) {
            return null;
        }

        if ($service !== $snapshot) {
            $this->warnings[] = "рядок {$line}: {$what} змінили і в таблиці, і в сервісі — лишено як у сервісі";

            return null;
        }

        return $sheet;
    }

    /**
     * Клітинку дня вважаємо відміченою, якщо її чимось залили. Сірий фон
     * вихідних і білий — не відмітка: саме так ми їх і вивантажуємо.
     *
     * @param  array{text?: string, note?: ?string, background?: ?string}  $cell
     */
    private function isMarked(array $cell): bool
    {
        $background = $cell['background'] ?? null;

        return $background !== null
            && ! $this->sameColor($background, PlanSheetExporter::WHITE)
            && ! $this->sameColor($background, PlanSheetExporter::WEEKEND);
    }

    /**
     * Чи це той самий колір із точністю до похибки Google (див. COLOR_TOLERANCE).
     */
    private function sameColor(?string $background, string $reference): bool
    {
        if ($background === null) {
            return false;
        }

        foreach (array_map(null, (array) sscanf($background, '#%02x%02x%02x'), (array) sscanf($reference, '#%02x%02x%02x')) as [$left, $right]) {
            if (abs((int) $left - (int) $right) > self::COLOR_TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Заголовок аркуша має збігатися з тим, що ми вивантажували: ті самі
     * колонки й ті самі дні. Інакше зсув на одну колонку тихо переписав би
     * виконавців коментарями — тому при розбіжності не чіпаємо нічого.
     *
     * @param  list<array{text: string, note: ?string, background: ?string}>  $header
     * @param  list<string>  $dates
     */
    private function headerMatches(array $header, array $dates): bool
    {
        if ($dates === []) {
            return false;
        }

        if ($this->text($header, PlanSheetExporter::TITLE_COLUMN) !== 'Задача'
            || $this->text($header, PlanSheetExporter::STATUS_COLUMN) !== 'Статус') {
            return false;
        }

        foreach ($dates as $offset => $date) {
            if ($this->text($header, PlanSheetExporter::FIRST_DAY_COLUMN + $offset) !== CarbonImmutable::parse($date)->format('d.m')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: ?string, 1: ?int} вид рядка (s/t) та ID; [null, null] — ID немає
     */
    private function parseId(string $id): array
    {
        return preg_match('~^([st]):(\d+)$~', $id, $match) ? [$match[1], (int) $match[2]] : [null, null];
    }

    /**
     * @param  Collection<int, Employee>  $members
     */
    private function member(Collection $members, string $name): ?Employee
    {
        $name = mb_strtolower(trim($name));

        return $members->first(fn (Employee $employee) => mb_strtolower($employee->name) === $name);
    }

    /**
     * @param  list<array{text?: string, note?: ?string, background?: ?string}>  $row
     */
    private function text(array $row, int $column): string
    {
        // Google не повертає хвостові порожні клітинки — звідси ?? ''.
        return trim((string) ($row[$column]['text'] ?? ''));
    }

    private function day(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d.m.Y');
    }

    /**
     * @param  array<string, string|null>  $changes
     * @return array<string, string|null>
     */
    private function normalize(array $changes): array
    {
        if (array_key_exists('note', $changes) && $changes['note'] === '') {
            $changes['note'] = null;
        }

        return $changes;
    }

    /**
     * @return array{created: int, updated: int, days: int, warnings: list<string>}
     */
    private function report(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'days' => $this->days,
            'warnings' => $this->warnings,
        ];
    }
}
