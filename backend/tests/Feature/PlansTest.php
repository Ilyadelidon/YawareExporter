<?php

namespace Tests\Feature;

use App\Jobs\ExportPlansToGoogle;
use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanTask;
use App\Models\PlanTaskDay;
use App\Models\User;
use App\Services\GoogleSheetsService;
use App\Services\PlanSheetExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * «Плани»: адміністратор керує проектами, працівник бачить лише свої
 * проекти й веде лише власні задачі.
 */
class PlansTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-17 12:00:00');
    }

    private function employee(string $name): Employee
    {
        $email = 'e'.md5($name).'@example.com';
        $user = User::factory()->create(['email' => $email, 'role' => User::ROLE_EMPLOYEE]);

        return Employee::create(['user_id' => $user->id, 'name' => $name, 'email' => $email, 'active' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function project(string $name, Employee ...$members): PlanProject
    {
        $project = PlanProject::create(['name' => $name]);
        $project->members()->sync(array_map(fn (Employee $e) => $e->id, $members));

        return $project;
    }

    private function task(PlanProject $project, Employee $employee, string $title = 'Задача'): PlanTask
    {
        return $project->tasks()->create(['employee_id' => $employee->id, 'title' => $title]);
    }

    public function test_employee_sees_only_projects_where_they_are_member(): void
    {
        $ivan = $this->employee('Іван Петренко');
        $olena = $this->employee('Олена Коваль');
        $mine = $this->project('TumTum', $ivan);
        $foreign = $this->project('Brok', $olena);

        Sanctum::actingAs($ivan->user);

        $this->getJson('/api/plans/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'TumTum');

        $this->getJson("/api/plans/projects/{$mine->id}")->assertOk()->assertJsonPath('can_manage', false);
        $this->getJson("/api/plans/projects/{$foreign->id}")->assertForbidden();
    }

    public function test_project_management_is_admin_only(): void
    {
        $ivan = $this->employee('Іван Петренко');
        $project = $this->project('TumTum', $ivan);

        Sanctum::actingAs($ivan->user);

        $this->postJson('/api/plans/projects', ['name' => 'Новий'])->assertForbidden();
        $this->putJson("/api/plans/projects/{$project->id}/members", ['employee_ids' => []])->assertForbidden();
        $this->deleteJson("/api/plans/projects/{$project->id}")->assertForbidden();

        Sanctum::actingAs($this->admin());

        $this->postJson('/api/plans/projects', ['name' => 'Delivery', 'employee_ids' => [$ivan->id]])
            ->assertCreated()
            ->assertJsonPath('data.members.0.id', $ivan->id);
    }

    public function test_employee_task_is_always_assigned_to_themselves(): void
    {
        $ivan = $this->employee('Іван Петренко');
        $olena = $this->employee('Олена Коваль');
        $project = $this->project('TumTum', $ivan, $olena);

        Sanctum::actingAs($ivan->user);

        $this->postJson("/api/plans/projects/{$project->id}/tasks", [
            'title' => 'Правки кошика',
            'employee_id' => $olena->id,
        ])->assertCreated()->assertJsonPath('data.employee_id', $ivan->id);
    }

    public function test_employee_cannot_touch_someone_elses_task(): void
    {
        $ivan = $this->employee('Іван Петренко');
        $olena = $this->employee('Олена Коваль');
        $project = $this->project('TumTum', $ivan, $olena);
        $task = $this->task($project, $olena);

        Sanctum::actingAs($ivan->user);

        $this->patchJson("/api/plans/tasks/{$task->id}", ['status' => 'done'])->assertForbidden();
        $this->putJson("/api/plans/tasks/{$task->id}/days/2026-09-16")->assertForbidden();
        $this->putJson("/api/plans/tasks/{$task->id}/current")->assertForbidden();
        $this->deleteJson("/api/plans/tasks/{$task->id}")->assertForbidden();
    }

    public function test_employee_cannot_reassign_own_task(): void
    {
        $ivan = $this->employee('Іван Петренко');
        $olena = $this->employee('Олена Коваль');
        $project = $this->project('TumTum', $ivan, $olena);
        $task = $this->task($project, $ivan);

        Sanctum::actingAs($ivan->user);

        $this->patchJson("/api/plans/tasks/{$task->id}", ['employee_id' => $olena->id])->assertUnprocessable();
        $this->assertSame($ivan->id, $task->fresh()->employee_id);
    }

    public function test_removed_member_loses_edit_access_to_own_task(): void
    {
        $ivan = $this->employee('Іван Петренко');
        $project = $this->project('TumTum', $ivan);
        $task = $this->task($project, $ivan);
        $project->members()->detach();

        Sanctum::actingAs($ivan->user);

        $this->patchJson("/api/plans/tasks/{$task->id}", ['status' => 'done'])->assertForbidden();
    }

    public function test_marking_a_day_stores_comment_and_rejects_future_dates(): void
    {
        $ivan = $this->employee('Іван Петренко');
        $project = $this->project('TumTum', $ivan);
        $task = $this->task($project, $ivan);

        Sanctum::actingAs($ivan->user);

        $this->putJson("/api/plans/tasks/{$task->id}/days/2026-09-16", ['comment' => ' валідація форми '])
            ->assertOk()
            ->assertJsonPath('data.comment', 'валідація форми');
        // Повторна відмітка оновлює коментар, а не дублює день.
        $this->putJson("/api/plans/tasks/{$task->id}/days/2026-09-16", ['comment' => 'тести'])->assertOk();
        $this->assertSame(1, PlanTaskDay::count());

        $this->putJson("/api/plans/tasks/{$task->id}/days/2026-09-18")->assertUnprocessable();
        $this->putJson("/api/plans/tasks/{$task->id}/days/2026-02-30")->assertUnprocessable();

        $this->getJson("/api/plans/projects/{$project->id}?month=2026-09")
            ->assertOk()
            ->assertJsonPath('tasks.0.days.2026-09-16', 'тести')
            ->assertJsonPath('tasks.0.last_worked_on', '2026-09-16');

        $this->deleteJson("/api/plans/tasks/{$task->id}/days/2026-09-16")->assertOk();
        $this->assertSame(0, PlanTaskDay::count());
    }

    public function test_current_task_is_one_per_person_and_marks_today(): void
    {
        $ivan = $this->employee('Іван Петренко');
        $tumtum = $this->project('TumTum', $ivan);
        $brok = $this->project('Brok', $ivan);
        $first = $this->task($tumtum, $ivan, 'Кошик');
        $second = $this->task($brok, $ivan, 'Калькулятор');

        Sanctum::actingAs($ivan->user);

        $this->putJson("/api/plans/tasks/{$first->id}/current")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->putJson("/api/plans/tasks/{$second->id}/current")->assertOk();

        $this->assertSame($second->id, $ivan->fresh()->current_plan_task_id);
        $this->assertTrue(PlanTaskDay::where('plan_task_id', $second->id)->where('date', '2026-09-17')->exists());

        // Закрита задача перестає бути поточною.
        $this->patchJson("/api/plans/tasks/{$second->id}", ['status' => 'done'])->assertOk();
        $this->assertNull($ivan->fresh()->current_plan_task_id);
    }

    public function test_admin_cannot_assign_task_to_non_member(): void
    {
        $ivan = $this->employee('Іван Петренко');
        $olena = $this->employee('Олена Коваль');
        $project = $this->project('TumTum', $ivan);

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/plans/projects/{$project->id}/tasks", ['title' => 'X', 'employee_id' => $olena->id])
            ->assertUnprocessable();
        $this->postJson("/api/plans/projects/{$project->id}/tasks", ['title' => 'X', 'employee_id' => $ivan->id])
            ->assertCreated();
    }

    public function test_section_from_another_project_is_rejected(): void
    {
        $ivan = $this->employee('Іван Петренко');
        $tumtum = $this->project('TumTum', $ivan);
        $brok = $this->project('Brok', $ivan);
        $foreignSection = $brok->sections()->create(['name' => 'Кабінет']);

        Sanctum::actingAs($ivan->user);

        $this->postJson("/api/plans/projects/{$tumtum->id}/tasks", ['title' => 'X', 'section_id' => $foreignSection->id])
            ->assertUnprocessable();
    }

    public function test_import_command_splits_projects_and_matches_names_loosely(): void
    {
        $illia = $this->employee('Ілля Делідон');
        $oleksii = $this->employee('Олексій Посохов');

        $path = tempnam(sys_get_temp_dir(), 'plans');
        file_put_contents($path, json_encode(['projects' => [
            [
                'name' => 'TumTum',
                'sections' => [['name' => 'Правки', 'note' => 'https://docs.google.com/document/d/x']],
                'tasks' => [[
                    'assignee' => 'Посохов Олексый', 'section' => 'Правки', 'title' => 'Кабінет', 'note' => null,
                    'status' => 'review', 'current' => false,
                    'days' => [['date' => '2026-09-15', 'comment' => 'верстка'], ['date' => '2026-09-16', 'comment' => null]],
                ]],
            ],
            [
                'name' => 'Brok',
                'sections' => [],
                'tasks' => [[
                    'assignee' => 'Делідон Ілля', 'section' => null, 'title' => 'API', 'note' => 'Для MPS',
                    'status' => 'in_progress', 'current' => true, 'days' => [['date' => '2026-09-17', 'comment' => null]],
                ]],
            ],
        ]]));

        $this->artisan('plans:import', ['file' => $path])->assertSuccessful();

        $tumtum = PlanProject::where('name', 'TumTum')->sole();
        $task = $tumtum->tasks()->sole();

        $this->assertSame($oleksii->id, $task->employee_id);
        $this->assertSame('Правки', $task->section->name);
        $this->assertSame(2, $task->days()->count());
        $this->assertEquals([$oleksii->id], $tumtum->members()->pluck('employees.id')->all());

        $apiTask = PlanProject::where('name', 'Brok')->sole()->tasks()->sole();
        $this->assertSame($apiTask->id, $illia->fresh()->current_plan_task_id);

        // Повторний запуск не дублює задачі.
        $this->artisan('plans:import', ['file' => $path])->assertFailed();
        $this->assertSame(2, PlanTask::count());
    }

    public function test_export_grid_mirrors_plan_sections_and_days(): void
    {
        $ivan = $this->employee('Іван Петренко');
        $project = $this->project('TumTum', $ivan);
        $section = $project->sections()->create(['name' => 'Правки', 'note' => 'див. https://docs.google.com/document/d/x', 'position' => 0]);
        $project->sections()->create(['name' => 'Порожній', 'position' => 1]);
        $loose = $this->task($project, $ivan, 'Без розділу задача');
        $task = $this->task($project, $ivan, '=HYPERLINK("x")');
        $task->update(['plan_section_id' => $section->id, 'note' => 'ТЗ: https://example.com/spec', 'status' => 'done']);
        $task->days()->create(['date' => '2026-09-12', 'comment' => 'субота']);
        $task->days()->create(['date' => '2026-09-17']);
        $ivan->update(['current_plan_task_id' => $task->id]);

        [$rows, , $snapshot] = app(PlanSheetExporter::class)->grid($project);

        // Колонки: службова прихована з ID, далі як у сервісі — задача,
        // виконавець, коментарі, статус, далі дні з 12.09 (перша відмітка) по 17.09.
        $this->assertSame(['id', 'Задача', 'Виконавець', 'Коментарі', 'Статус'], array_map(fn ($c) => $c['userEnteredValue']['stringValue'], array_slice($rows[0], 0, 5)));
        $this->assertSame('12.09', $rows[0][5]['userEnteredValue']['stringValue']);
        $this->assertCount(5 + 6, $rows[0]);

        // Рядки: «Без розділу», його задача, розділ, його задача, порожній розділ.
        $titles = array_map(fn ($row) => $row[1]['userEnteredValue']['stringValue'], array_slice($rows, 1));
        $this->assertSame(['Без розділу', $loose->title, 'Правки', '=HYPERLINK("x")', 'Порожній'], $titles);
        // Службова колонка: псевдорозділ «Без розділу» — s:0, задачі — t:<id>.
        $this->assertSame(
            ['s:0', "t:{$loose->id}", "s:{$section->id}", "t:{$task->id}"],
            array_map(fn ($row) => $row[0]['userEnteredValue']['stringValue'], array_slice($rows, 1, 4)),
        );
        // Примітка розділу — у колонці «Коментарі», посилання з неї там же.
        $this->assertSame('див. https://docs.google.com/document/d/x', $rows[3][3]['userEnteredValue']['stringValue']);
        $this->assertSame('https://docs.google.com/document/d/x', $rows[3][3]['userEnteredFormat']['textFormat']['link']['uri']);
        $this->assertCount(5 + 6, $rows[3]);

        $row = $rows[4];
        // Текст із «=» лишається текстом, примітка задачі — в колонці коментарів.
        $this->assertSame(['stringValue' => '=HYPERLINK("x")'], $row[1]['userEnteredValue']);
        $this->assertArrayNotHasKey('note', $row[1]);
        $this->assertSame('ТЗ: https://example.com/spec', $row[3]['userEnteredValue']['stringValue']);
        $this->assertSame('https://example.com/spec', $row[3]['userEnteredFormat']['textFormat']['link']['uri']);
        $this->assertSame('Виконано', $row[4]['userEnteredValue']['stringValue']);
        // Відмітка дня — бірюзова, коментар у нотатці.
        $this->assertSame('субота', $row[5]['note']);
        $this->assertEquals(['red' => 0.561, 'green' => 0.816, 'blue' => 0.78], $row[5]['userEnteredFormat']['backgroundColor']);
        // Неділя 13.09 без відмітки — сіра.
        $this->assertEquals(['red' => 0.965, 'green' => 0.969, 'blue' => 0.973], $row[6]['userEnteredFormat']['backgroundColor']);
        // Понеділок 14.09 — порожня клітинка.
        $this->assertEquals((object) [], $row[7]);
        // Сьогодні й «працює зараз» — акцентна.
        $this->assertEquals(['red' => 0.078, 'green' => 0.616, 'blue' => 0.553], $row[10]['userEnteredFormat']['backgroundColor']);

        // Зліпок описує те саме, що пішло в аркуш, — з нього наступний прогін
        // і зрозуміє, що в таблиці змінила людина.
        $this->assertSame(['2026-09-12', '2026-09-13', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17'], $snapshot['dates']);
        $this->assertSame(['name' => 'Правки', 'note' => 'див. https://docs.google.com/document/d/x'], $snapshot['sections'][(string) $section->id]);
        $this->assertSame([
            'section' => $section->id,
            'title' => '=HYPERLINK("x")',
            'employee' => 'Іван Петренко',
            'note' => 'ТЗ: https://example.com/spec',
            'status' => 'Виконано',
            'days' => ['2026-09-12' => 'субота', '2026-09-17' => ''],
        ], $snapshot['tasks'][(string) $task->id]);
    }

    public function test_admin_links_shared_plans_spreadsheet(): void
    {
        $this->mock(GoogleSheetsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('hasGoogleAccount')->andReturn(true);
            $mock->shouldReceive('spreadsheetTitle')->with('1C87ihnHOyGwjWCRVCt_plans')->once()->andReturn('Загальний план');
        });

        Sanctum::actingAs($this->admin());

        $this->putJson('/api/plans/google', ['spreadsheet' => 'https://docs.google.com/spreadsheets/d/1C87ihnHOyGwjWCRVCt_plans/edit?usp=sharing'])
            ->assertOk()
            ->assertJsonPath('data.spreadsheet_title', 'Загальний план');

        $this->assertSame('1C87ihnHOyGwjWCRVCt_plans', AppSetting::get(AppSetting::PLANS_SPREADSHEET_ID));

        $this->deleteJson('/api/plans/google')->assertOk();
        $this->assertNull(AppSetting::get(AppSetting::PLANS_SPREADSHEET_ID));
    }

    public function test_export_is_queued_and_reported_as_queued(): void
    {
        Queue::fake();
        AppSetting::put(AppSetting::PLANS_SPREADSHEET_ID, '1C87ihnHOyGwjWCRVCt_plans');
        $this->mock(GoogleSheetsService::class, fn (MockInterface $mock) => $mock->shouldReceive('hasGoogleAccount')->andReturn(true));

        Sanctum::actingAs($this->admin());

        $this->postJson('/api/plans/export')
            ->assertStatus(202)
            ->assertJsonPath('data.export.status', 'queued');

        Queue::assertPushed(ExportPlansToGoogle::class, fn (ExportPlansToGoogle $job) => $job->spreadsheetId === '1C87ihnHOyGwjWCRVCt_plans');
    }

    public function test_export_job_writes_every_active_project_to_its_own_sheet(): void
    {
        $ivan = $this->employee('Іван Петренко');
        $this->task($this->project('TumTum', $ivan), $ivan);
        $this->task($this->project('Brok', $ivan), $ivan);
        PlanProject::create(['name' => 'Старий', 'archived_at' => now()]);

        $written = [];
        $this->mock(GoogleSheetsService::class, function (MockInterface $mock) use (&$written) {
            $mock->shouldReceive('replaceSheet')->twice()
                ->andReturnUsing(function (string $spreadsheetId, string $title) use (&$written) {
                    $written[] = [$spreadsheetId, $title];
                });
        });

        ExportPlansToGoogle::dispatchSync('1C87ihnHOyGwjWCRVCt_plans');

        $this->assertSame([['1C87ihnHOyGwjWCRVCt_plans', 'План — Brok'], ['1C87ihnHOyGwjWCRVCt_plans', 'План — TumTum']], $written);
        $this->assertSame('done', ExportPlansToGoogle::status()['status']);
    }

    public function test_scheduled_command_queues_the_same_export(): void
    {
        Queue::fake();
        AppSetting::put(AppSetting::PLANS_SPREADSHEET_ID, '1C87ihnHOyGwjWCRVCt_plans');
        $this->mock(GoogleSheetsService::class, fn (MockInterface $mock) => $mock->shouldReceive('hasGoogleAccount')->andReturn(true));

        $this->artisan('plans:sync-google')->assertSuccessful();

        Queue::assertPushed(ExportPlansToGoogle::class, fn (ExportPlansToGoogle $job) => $job->spreadsheetId === '1C87ihnHOyGwjWCRVCt_plans');
        $this->assertSame('queued', ExportPlansToGoogle::status()['status']);
    }

    public function test_scheduled_command_is_silent_without_linked_spreadsheet(): void
    {
        Queue::fake();
        $this->mock(GoogleSheetsService::class, fn (MockInterface $mock) => $mock->shouldReceive('hasGoogleAccount')->andReturn(true));

        // Успіх, а не помилка: підключеної таблиці може просто не бути, і
        // планувальник не повинен щодня звітувати про це як про аварію.
        $this->artisan('plans:sync-google')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_export_needs_linked_spreadsheet_and_admin(): void
    {
        $this->mock(GoogleSheetsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('hasGoogleAccount')->andReturn(true);
        });
        Queue::fake();

        $ivan = $this->employee('Іван Петренко');
        Sanctum::actingAs($ivan->user);
        $this->postJson('/api/plans/export')->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/plans/export')->assertStatus(409);
        Queue::assertNothingPushed();
    }
}
