<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanSection;
use App\Models\PlanTask;
use App\Models\PlanTaskDay;
use App\Models\User;
use App\Services\GoogleSheetsService;
use App\Services\PlanSheetExporter;
use App\Services\PlanSheetImporter;
use App\Services\PlanSheetSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Зворотний бік обміну: людина правила план просто в Google Таблиці, і перед
 * тим, як перезаписати аркуш, ці правки треба підтягнути в систему.
 *
 * Аркуш у тестах не вигадується руками, а будується з нашого ж вивантаження
 * і потім «правиться», як це зробила б людина, — інакше тест перевіряв би
 * власне уявлення про формат, а не той, що реально йде в Google.
 */
class PlanSheetSyncTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST_DAY = PlanSheetExporter::FIRST_DAY_COLUMN;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-17 12:00:00');
        // На проді зворотне злиття зараз вимкнене; тут воно і є предметом
        // перевірки — окремий тест нижче стежить за самим вимикачем.
        config(['plans.sheet_pull' => true]);
    }

    public function test_sheet_edits_are_pulled_back_into_the_plan(): void
    {
        [$project, $section, $task, $olena] = $this->plan();
        $sheet = $this->exported($project);

        // Що зробила людина в таблиці.
        $sheet[1][PlanSheetExporter::TITLE_COLUMN]['text'] = 'Правки сайту';
        $sheet[2][PlanSheetExporter::TITLE_COLUMN]['text'] = 'Нова назва';
        $sheet[2][PlanSheetExporter::STATUS_COLUMN]['text'] = 'В роботі';
        $sheet[2][PlanSheetExporter::COMMENT_COLUMN]['text'] = 'ТЗ тут';
        // 16.09 залили й підписали, з 15.09 відмітку зняли.
        $sheet[2][self::FIRST_DAY + 1] = ['text' => '', 'note' => 'доробив', 'background' => PlanSheetExporter::WORKED];
        $sheet[2][self::FIRST_DAY] = ['text' => '', 'note' => null, 'background' => null];
        $sheet[] = $this->row(['', 'Задача з таблиці', 'Олена Коваль', 'аркуш', 'Пауза']);

        $report = $this->pull($project, $sheet);

        $this->assertSame('Правки сайту', $section->fresh()->name);

        $task->refresh();
        $this->assertSame('Нова назва', $task->title);
        $this->assertSame(PlanTask::STATUS_IN_PROGRESS, $task->status);
        $this->assertSame('ТЗ тут', $task->note);

        $this->assertSame(['2026-09-16' => 'доробив'], $task->days()->pluck('comment', 'date')
            ->mapWithKeys(fn (?string $comment, string $date) => [substr($date, 0, 10) => $comment])->all());

        $added = PlanTask::where('title', 'Задача з таблиці')->sole();
        $this->assertSame($olena->id, $added->employee_id);
        $this->assertSame($section->id, $added->plan_section_id);
        $this->assertSame(PlanTask::STATUS_PAUSED, $added->status);
        $this->assertSame('аркуш', $added->note);

        $this->assertSame(['created' => 1, 'updated' => 2, 'days' => 2], array_diff_key($report, ['warnings' => null]));
        $this->assertSame([], $report['warnings']);
    }

    public function test_service_change_wins_when_the_same_row_was_edited_on_both_sides(): void
    {
        [$project, , $task] = $this->plan();
        $sheet = $this->exported($project);

        // Після вивантаження задачу перейменували в сервісі…
        $task->update(['title' => 'Назва з сервісу']);
        // …і того ж дня — інакше в таблиці.
        $sheet[2][PlanSheetExporter::TITLE_COLUMN]['text'] = 'Назва з таблиці';
        // А ось статус у сервісі не чіпали — його правка з таблиці доїде.
        $sheet[2][PlanSheetExporter::STATUS_COLUMN]['text'] = 'На перевірці';

        $report = $this->pull($project, $sheet);

        $task->refresh();
        $this->assertSame('Назва з сервісу', $task->title);
        $this->assertSame(PlanTask::STATUS_REVIEW, $task->status);
        $this->assertCount(1, $report['warnings']);
        $this->assertStringContainsString('і в таблиці, і в сервісі', $report['warnings'][0]);
    }

    public function test_copied_section_row_becomes_a_new_section_and_unknown_people_are_skipped(): void
    {
        [$project, $section, $task] = $this->plan();
        $sheet = $this->exported($project);

        // Рядок розділу скопіювали й перейменували — звичний спосіб додати
        // розділ у таблиці; під ним дописали задачу.
        $copied = $sheet[1];
        $copied[PlanSheetExporter::TITLE_COLUMN]['text'] = 'Новий розділ';
        $sheet[] = $copied;
        $sheet[] = $this->row(['', 'Задача нового розділу', 'Іван Петренко', '', '']);
        $sheet[] = $this->row(['', 'Задача чужому', 'Петро Сидоренко', '', '']);
        // Рядок наявної задачі прибрали з аркуша.
        unset($sheet[2]);
        $sheet = array_values($sheet);

        $report = $this->pull($project, $sheet);

        $created = PlanSection::where('name', 'Новий розділ')->sole();
        $this->assertNotSame($section->id, $created->id);
        $this->assertSame($created->id, PlanTask::where('title', 'Задача нового розділу')->sole()->plan_section_id);

        // Чужа людина в проект не потрапляє, а прибраний рядок нічого не видаляє.
        $this->assertSame(0, PlanTask::where('title', 'Задача чужому')->count());
        $this->assertNotNull($task->fresh());
        $this->assertSame(2, $report['created']);
        $this->assertSame([
            'рядок 5: нову задачу «Задача чужому» пропущено — «Петро Сидоренко» не учасник проекту',
            'рядків задач прибрано з аркуша: 1 — у системі вони лишились і повернуться наступним вивантаженням',
        ], $report['warnings']);
    }

    public function test_accent_cell_of_the_current_task_is_not_a_change(): void
    {
        [$project, , $task] = $this->plan();
        // «Працює зараз»: сьогоднішню клітинку ми заливаємо акцентом самі,
        // тож із неї не видно, відмічений день у системі чи ні. Ані нової
        // відмітки, ані знятої з такої клітинки бути не може.
        Employee::whereKey($task->employee_id)->update(['current_plan_task_id' => $task->id]);

        $sheet = $this->exported($project);
        // Саме так колір і повертається з Google: на одиницю «не той».
        $sheet[2][self::FIRST_DAY + 2]['background'] = '#139d8d';

        $report = $this->pull($project, $sheet);

        $this->assertSame(0, $report['days']);
        $this->assertSame([], $report['warnings']);
        $this->assertSame(['2026-09-15'], $task->days()->get()
            ->map(fn (PlanTaskDay $day) => $day->date->toDateString())->all());
    }

    public function test_shifted_columns_stop_the_pull_instead_of_writing_nonsense(): void
    {
        [$project, , $task] = $this->plan();
        $sheet = $this->exported($project);
        $sheet[2][PlanSheetExporter::TITLE_COLUMN]['text'] = 'Назва з таблиці';

        // Хтось вставив колонку — усе поїхало праворуч.
        $sheet = array_map(fn (array $row) => [['text' => '', 'note' => null, 'background' => null], ...$row], $sheet);

        $report = $this->pull($project, $sheet);

        $this->assertSame(['created' => 0, 'updated' => 0, 'days' => 0], array_diff_key($report, ['warnings' => null]));
        $this->assertStringContainsString('нічого не підтягнуто', $report['warnings'][0]);
        // Зсунутий аркуш не чіпає нічого: у колонці «Задача» тепер ID, і без
        // цієї перевірки він поїхав би в назву задачі.
        $this->assertSame('Правки сайту', $task->fresh()->title);
    }

    public function test_sync_pulls_before_it_overwrites_the_sheet(): void
    {
        [$project, , $task] = $this->plan();
        $sheet = $this->exported($project);
        $sheet[2][PlanSheetExporter::TITLE_COLUMN]['text'] = 'Назва з таблиці';

        $written = [];
        $this->mock(GoogleSheetsService::class, function (MockInterface $mock) use ($sheet, &$written) {
            $mock->shouldReceive('readSheet')->once()->with('sheet-id', 'План — TumTum')->andReturn($sheet);
            $mock->shouldReceive('replaceSheet')->once()
                ->andReturnUsing(function (string $spreadsheetId, string $title, array $rows) use (&$written) {
                    $written = $rows;
                });
        });

        $result = app(PlanSheetSync::class)->run('sheet-id');

        // Аркуш перезаписано вже з підтягнутою назвою — інакше правку з
        // таблиці стерло б те саме вивантаження, що її прочитало.
        $this->assertSame('Назва з таблиці', $task->fresh()->title);
        $this->assertSame('Назва з таблиці', $written[2][PlanSheetExporter::TITLE_COLUMN]['userEnteredValue']['stringValue']);
        $this->assertSame(['План — TumTum'], $result['titles']);
        $this->assertSame(['«TumTum»: з таблиці підтягнуто — правок у задачах і розділах: 1'], $result['lines']);

        // Новий зліпок описує щойно записаний аркуш.
        $project->refresh();
        $this->assertSame('Назва з таблиці', $project->sheet_snapshot['tasks'][(string) $task->id]['title']);
        $this->assertNotNull($project->sheet_synced_at);
    }

    public function test_disabled_pull_leaves_the_sheet_one_way(): void
    {
        config(['plans.sheet_pull' => false]);

        [$project, , $task] = $this->plan();
        $this->exported($project);

        $this->mock(GoogleSheetsService::class, function (MockInterface $mock) {
            // З вимкненим злиттям аркуш навіть не читається.
            $mock->shouldNotReceive('readSheet');
            $mock->shouldReceive('replaceSheet')->once();
        });

        $this->assertSame([], app(PlanSheetSync::class)->run('sheet-id')['lines']);
        $this->assertSame('Правки сайту', $task->fresh()->title);
        // Зліпок пишеться й далі — інакше вмикання злиття почалося б із того,
        // що всі рядки аркуша виглядають новими.
        $this->assertNotNull($project->fresh()->sheet_snapshot);
    }

    public function test_first_export_has_nothing_to_pull(): void
    {
        [$project] = $this->plan();

        $this->mock(GoogleSheetsService::class, function (MockInterface $mock) {
            // Аркуша ще не було — читати нічого, і питати Google теж.
            $mock->shouldNotReceive('readSheet');
            $mock->shouldReceive('replaceSheet')->once();
        });

        $this->assertSame([], app(PlanSheetSync::class)->run('sheet-id')['lines']);
        $this->assertNotNull($project->fresh()->sheet_snapshot);
    }

    /**
     * Проект із розділом, однією задачею і відміткою за 15.09.
     *
     * @return array{0: PlanProject, 1: PlanSection, 2: PlanTask, 3: Employee}
     */
    private function plan(): array
    {
        $ivan = $this->employee('Іван Петренко');
        $olena = $this->employee('Олена Коваль');

        $project = PlanProject::create(['name' => 'TumTum']);
        $project->members()->sync([$ivan->id, $olena->id]);

        $section = $project->sections()->create(['name' => 'Правки', 'position' => 0]);
        $task = $project->tasks()->create([
            'plan_section_id' => $section->id,
            'employee_id' => $ivan->id,
            'title' => 'Правки сайту',
            'status' => PlanTask::STATUS_PENDING,
        ]);
        PlanTaskDay::create(['plan_task_id' => $task->id, 'date' => '2026-09-15', 'comment' => 'вчора']);

        return [$project, $section, $task, $olena];
    }

    private function employee(string $name): Employee
    {
        $email = 'e'.md5($name).'@example.com';
        $user = User::factory()->create(['email' => $email, 'role' => User::ROLE_EMPLOYEE]);

        return Employee::create(['user_id' => $user->id, 'name' => $name, 'email' => $email, 'active' => true]);
    }

    /**
     * Вивантажує проект «у таблицю»: лишає зліпок і віддає аркуш таким, яким
     * його поверне Google.
     *
     * @return list<list<array{text: string, note: ?string, background: ?string}>>
     */
    private function exported(PlanProject $project): array
    {
        [$rows, , $snapshot] = app(PlanSheetExporter::class)->grid($project);

        $project->update(['sheet_snapshot' => $snapshot, 'sheet_synced_at' => now()]);

        return array_map(fn (array $row) => array_map(function (array|object $cell) {
            $cell = (array) $cell;
            $color = $cell['userEnteredFormat']['backgroundColor'] ?? null;

            return [
                'text' => (string) ($cell['userEnteredValue']['stringValue'] ?? ''),
                'note' => $cell['note'] ?? null,
                'background' => $color === null ? null : sprintf(
                    '#%02x%02x%02x',
                    (int) round(($color['red'] ?? 0) * 255),
                    (int) round(($color['green'] ?? 0) * 255),
                    (int) round(($color['blue'] ?? 0) * 255),
                ),
            ];
        }, $row), $rows);
    }

    /**
     * @param  array<int, string>  $fixed  текст службової колонки й чотирьох видимих
     * @return list<array{text: string, note: ?string, background: ?string}>
     */
    private function row(array $fixed): array
    {
        return array_map(fn (string $text) => ['text' => $text, 'note' => null, 'background' => null], $fixed);
    }

    /**
     * @param  list<list<array{text: string, note: ?string, background: ?string}>>  $sheet
     * @return array{created: int, updated: int, days: int, warnings: list<string>}
     */
    private function pull(PlanProject $project, array $sheet): array
    {
        return app(PlanSheetImporter::class)->pull($project, $sheet, $project->sheet_snapshot);
    }
}
