<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanTask;
use App\Models\PlanTaskDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Вивантаження планів у спільну Google Таблицю, яку підключив адміністратор.
 * Кожен проект — свій аркуш «План — <назва>», інші аркуші таблиці не чіпаються.
 *
 * Аркуш не лише для перегляду: правки, зроблені просто в таблиці, перед
 * наступним записом підтягує назад PlanSheetImporter, а звіряється він зі
 * зліпком (`plan_projects.sheet_snapshot`), який лишає по собі цей клас.
 *
 * Аркуш повторює вигляд плану в сервісі: задача / виконавець / коментарі /
 * статус, розділи сірими рядками, праворуч дні датами. Текст усюди чорний —
 * сірі й кольорові відтінки на аркуші не читаються. Відпрацьований день —
 * бірюзовий, «працює зараз» — акцентний; коментар дня лишається нотаткою
 * клітинки, як підказка при наведенні в сервісі.
 *
 * Примітки розділів і задач у сервісі — підказки, а в аркуші їх видно
 * окремою колонкою «Коментарі»: у таблиці нотатку клітинки ще треба знайти.
 */
class PlanSheetExporter
{
    /**
     * Перша колонка службова й прихована: у ній ID розділу («s:12») або
     * задачі («t:184»). Без неї перейменовану в таблиці задачу не відрізнити
     * від нової, а скопійований рядок — від того, з якого копіювали.
     */
    public const ID_COLUMN = 0;

    public const TITLE_COLUMN = 1;

    public const EMPLOYEE_COLUMN = 2;

    public const COMMENT_COLUMN = 3;

    public const STATUS_COLUMN = 4;

    private const FIXED_COLUMNS = ['id', 'Задача', 'Виконавець', 'Коментарі', 'Статус'];

    /** Скільки колонок ліворуч від таймлайну; з неї ж починаються дні. */
    public const FIRST_DAY_COLUMN = 5;

    // Кольори — ті самі, що в PlansView.vue.
    public const WORKED = '#8fd0c7';

    public const CURRENT = '#149d8d';

    public const WEEKEND = '#f6f7f8';

    public const SECTION = '#f4f6f7';

    public const HEADER = '#f0f4f7';

    public const WHITE = '#ffffff';

    public function __construct(private readonly GoogleSheetsService $sheets) {}

    public static function sheetTitle(PlanProject $project): string
    {
        // Google обмежує назву аркуша 100 символами.
        return mb_substr("План — {$project->name}", 0, 100);
    }

    /**
     * Записує аркуш проекту, перезаписуючи його повністю.
     *
     * @param  list<list<array<string, mixed>|object>>  $rows
     * @param  array<int, int>  $widths
     */
    public function write(string $spreadsheetId, PlanProject $project, array $rows, array $widths): void
    {
        $this->sheets->replaceSheet(
            $spreadsheetId,
            self::sheetTitle($project),
            $rows,
            1,
            count(self::FIXED_COLUMNS),
            $widths,
            [self::ID_COLUMN],
        );
    }

    /**
     * Сітка аркуша, ширини колонок і зліпок того, що в цю сітку пішло.
     *
     * @return array{0: list<list<array<string, mixed>|object>>, 1: array<int, int>, 2: array<string, mixed>}
     */
    public function grid(PlanProject $project): array
    {
        $today = CarbonImmutable::today();
        $tasks = $project->tasks()->with('employee:id,name,current_plan_task_id')->get();

        $days = PlanTaskDay::whereIn('plan_task_id', $tasks->pluck('id'))->get()->groupBy('plan_task_id');

        $firstDate = $days->flatten()->min('date');
        $start = CarbonImmutable::parse($firstDate ?? $project->created_at ?? $today)->startOfDay();
        $dates = collect(CarbonPeriod::create($start, $today->max($start)))->map(fn ($d) => CarbonImmutable::parse($d));
        $width = count(self::FIXED_COLUMNS) + $dates->count();

        $header = array_map(fn (string $title) => $this->cell($title, ['background' => self::HEADER, 'bold' => true]), self::FIXED_COLUMNS);

        foreach ($dates as $date) {
            $header[] = $this->cell($date->format('d.m'), [
                'background' => $date->isWeekend() ? self::WEEKEND : self::HEADER,
                'bold' => true,
                'align' => 'CENTER',
            ]);
        }

        $rows = [$header];
        $snapshot = [
            'dates' => $dates->map(fn (CarbonImmutable $date) => $date->toDateString())->all(),
            'sections' => [],
            'tasks' => [],
        ];

        // Як у сервісі: спершу задачі без розділу, далі розділи за порядком —
        // зокрема порожні, щоб структура плану була видна повністю.
        $bySection = $tasks->groupBy(fn (PlanTask $task) => $task->plan_section_id ?? 0);
        $groups = [];

        if ($bySection->has(0)) {
            $groups[] = [0, 'Без розділу', null, $bySection[0]];
        }

        foreach ($project->sections()->get() as $section) {
            $groups[] = [$section->id, $section->name, $section->note, $bySection[$section->id] ?? collect()];
        }

        foreach ($groups as [$sectionId, $name, $note, $sectionTasks]) {
            $rows[] = $this->sectionRow($sectionId, $name, $note, $width);

            // Псевдорозділ «Без розділу» в базі не існує — у зліпку його немає.
            if ($sectionId !== 0) {
                $snapshot['sections'][(string) $sectionId] = ['name' => $name, 'note' => (string) $note];
            }

            foreach ($sectionTasks as $task) {
                $marks = $days[$task->id] ?? collect();

                $rows[] = $this->taskRow($task, $marks, $dates, $today);
                $snapshot['tasks'][(string) $task->id] = [
                    'section' => $sectionId,
                    'title' => $task->title,
                    'employee' => (string) $task->employee?->name,
                    'note' => (string) $task->note,
                    'status' => PlanTask::STATUS_LABELS[$task->status] ?? $task->status,
                    'days' => $marks
                        ->mapWithKeys(fn (PlanTaskDay $day) => [$day->date->toDateString() => (string) $day->comment])
                        ->all(),
                ];
            }
        }

        $widths = [self::ID_COLUMN => 60, self::TITLE_COLUMN => 380, self::EMPLOYEE_COLUMN => 170, self::COMMENT_COLUMN => 300, self::STATUS_COLUMN => 130]
            + array_fill(self::FIRST_DAY_COLUMN, $dates->count(), 58);

        return [$rows, $widths, $snapshot];
    }

    /**
     * @return list<array<string, mixed>|object>
     */
    private function sectionRow(int $sectionId, string $name, ?string $note, int $width): array
    {
        $row = array_pad([], $width, $this->cell('', ['background' => self::SECTION]));

        $row[self::ID_COLUMN] = $this->cell("s:{$sectionId}", ['background' => self::SECTION]);
        $row[self::TITLE_COLUMN] = $this->cell($name, ['background' => self::SECTION, 'bold' => true, 'overflow' => true]);
        $row[self::COMMENT_COLUMN] = $this->comment($note, self::SECTION);

        return $row;
    }

    /**
     * Клітинка колонки «Коментарі»: текст примітки, а знайдений у ній URL —
     * посиланням на цій же клітинці.
     *
     * @return array<string, mixed>|object
     */
    private function comment(?string $note, ?string $background = null): array|object
    {
        return $this->cell((string) $note, ['background' => $background, 'link' => $this->link($note)]);
    }

    /**
     * @param  Collection<int, PlanTaskDay>  $marks
     * @param  Collection<int, CarbonImmutable>  $dates
     * @return list<array<string, mixed>|object>
     */
    private function taskRow(PlanTask $task, Collection $marks, Collection $dates, CarbonImmutable $today): array
    {
        $marks = $marks->keyBy(fn (PlanTaskDay $day) => $day->date->toDateString());
        $isCurrent = $task->employee instanceof Employee && $task->employee->current_plan_task_id === $task->id;

        $row = [
            $this->cell("t:{$task->id}"),
            $this->cell($task->title),
            $this->cell((string) $task->employee?->name),
            $this->comment($task->note),
            $this->cell(PlanTask::STATUS_LABELS[$task->status] ?? $task->status),
        ];

        foreach ($dates as $date) {
            $mark = $marks[$date->toDateString()] ?? null;

            $row[] = $this->cell('', [
                'background' => match (true) {
                    $isCurrent && $date->isSameDay($today) => self::CURRENT,
                    $mark !== null => self::WORKED,
                    $date->isWeekend() => self::WEEKEND,
                    default => null,
                },
                'note' => $mark?->comment,
            ]);
        }

        return $row;
    }

    private function link(?string $note): ?string
    {
        return $note !== null && preg_match('~https?://\S+~', $note, $match) ? $match[0] : null;
    }

    /**
     * Клітинка у форматі CellData. Порожня — порожнім обʼєктом: дні займають
     * більшість сітки, і зайвий формат у кожній роздуває запит у рази.
     *
     * @param  array{background?: ?string, bold?: bool, link?: ?string, note?: ?string, align?: string, overflow?: bool}  $style
     * @return array<string, mixed>|object
     */
    private function cell(string $text, array $style = []): array|object
    {
        $cell = [];
        $format = [];
        $textFormat = [];

        if ($text !== '') {
            // stringValue, а не formulaValue: текст на кшталт «=…» з задачі не
            // має стати формулою в таблиці.
            $cell['userEnteredValue'] = ['stringValue' => $text];
            $format['wrapStrategy'] = ($style['overflow'] ?? false) ? 'OVERFLOW_CELL' : 'CLIP';
        }

        if (($style['background'] ?? null) !== null) {
            $format['backgroundColor'] = $this->rgb($style['background']);
        }

        if (isset($style['align'])) {
            $format['horizontalAlignment'] = $style['align'];
        }

        if ($style['bold'] ?? false) {
            $textFormat['bold'] = true;
        }

        if (($style['link'] ?? null) !== null && $text !== '') {
            $textFormat['link'] = ['uri' => $style['link']];
        }

        if ($textFormat !== []) {
            $format['textFormat'] = $textFormat;
        }

        if ($format !== []) {
            $cell['userEnteredFormat'] = $format;
        }

        if (($style['note'] ?? '') !== '') {
            $cell['note'] = $style['note'];
        }

        return $cell === [] ? (object) [] : $cell;
    }

    /**
     * @return array{red: float, green: float, blue: float}
     */
    private function rgb(string $hex): array
    {
        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x');

        return ['red' => round($red / 255, 3), 'green' => round($green / 255, 3), 'blue' => round($blue / 255, 3)];
    }
}
