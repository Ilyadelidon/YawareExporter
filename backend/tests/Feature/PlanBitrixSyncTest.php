<?php

namespace Tests\Feature;

use App\Models\BitrixAccount;
use App\Models\BitrixWorkspace;
use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «Плани» ↔ Бітрікс24: задача плану — це задача Бітрікса з тегом «План» і
 * тегом із назвою проекту плану. Портал підмінено сховищем у памʼяті, яке
 * відповідає на ті самі REST-методи.
 */
class PlanBitrixSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PORTAL = 'https://team.bitrix24.ua';

    private const REST = 'https://team.bitrix24.ua/rest/';

    /** @var array<string, array<string, mixed>> задачі порталу за id */
    private array $portalTasks = [];

    /** @var array<string, string> користувачі порталу: id => пошта */
    private array $portalUsers = [];

    /** @var list<array{method: string, params: array}> */
    private array $writes = [];

    /** @var array<string, int> скільки разів методи мають відмовити */
    private array $failures = [];

    private int $nextId = 500;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-23 12:00:00');
        config()->set('services.bitrix.timezone', 'Europe/Kyiv');

        BitrixWorkspace::connect([
            'portal_url' => self::PORTAL,
            'client_id' => 'local.app',
            'client_secret' => 'secret',
        ]);

        Http::fake(fn (Request $request) => $this->portal($request));
    }

    private function portal(Request $request)
    {
        $method = substr($request->url(), strlen(self::REST));
        $params = $request->data();

        if (($this->failures[$method] ?? 0) > 0) {
            $this->failures[$method]--;

            return Http::response(['error' => 'ACCESS_DENIED', 'error_description' => 'Доступ заборонено'], 400);
        }

        switch ($method) {
            case 'tasks.task.list':
                $tag = $params['filter']['TAG'] ?? null;
                // Фільтр за тегом у Бітріксі не зважає на регістр.
                $tasks = array_values(array_filter($this->portalTasks, fn (array $task) => in_array(mb_strtolower($tag), array_map('mb_strtolower', $task['tags']), true)));

                // Як і справжній портал, теги в списку не віддаємо.
                return Http::response(['result' => ['tasks' => array_map(fn (array $task) => array_diff_key($task, ['tags' => 1]), $tasks)]]);

            case 'tasks.task.get':
                $task = $this->portalTasks[(string) $params['taskId']];

                return Http::response(['result' => ['task' => $task]]);

            case 'tasks.task.add':
                $this->writes[] = compact('method', 'params');
                $id = (string) $this->nextId++;
                $this->portalTasks[$id] = $this->applyFields(['id' => $id, 'status' => '2', 'description' => '', 'tags' => []], $params['fields']);

                return Http::response(['result' => ['task' => ['id' => $id]]]);

            case 'tasks.task.update':
                $this->writes[] = compact('method', 'params');
                $id = (string) $params['taskId'];
                $this->portalTasks[$id] = $this->applyFields($this->portalTasks[$id], $params['fields']);

                return Http::response(['result' => ['task' => ['id' => $id]]]);

            case 'user.get':
                if (isset($params['FILTER']['EMAIL'])) {
                    $id = array_search($params['FILTER']['EMAIL'], $this->portalUsers, true);

                    return Http::response(['result' => $id === false ? [] : [['ID' => (string) $id, 'EMAIL' => $params['FILTER']['EMAIL']]]]);
                }

                $email = $this->portalUsers[(string) $params['ID']] ?? null;

                return Http::response(['result' => $email ? [['ID' => (string) $params['ID'], 'EMAIL' => $email]] : []]);
        }

        return Http::response(['error' => 'ERROR_METHOD_NOT_FOUND'], 404);
    }

    private function applyFields(array $task, array $fields): array
    {
        $map = ['TITLE' => 'title', 'DESCRIPTION' => 'description', 'STATUS' => 'status', 'RESPONSIBLE_ID' => 'responsibleId'];

        foreach ($fields as $name => $value) {
            if ($name === 'TAGS') {
                $task['tags'] = $value === '' ? [] : $value;
            } else {
                $task[$map[$name]] = (string) $value;
            }
        }

        return $task;
    }

    private function remoteTask(string $id, array $attributes): void
    {
        $this->portalTasks[$id] = $attributes + [
            'id' => $id,
            'title' => 'Задача',
            'description' => '',
            'status' => '2',
            'responsibleId' => '7',
            'tags' => ['План', 'Brok'],
        ];
    }

    /** Працівник, що підключив Бітрікс під id порталу $bitrixId. */
    private function employee(string $name, ?string $bitrixId, string $role = User::ROLE_EMPLOYEE): Employee
    {
        $email = 'e'.md5($name).'@example.com';
        $user = User::factory()->create(['email' => $email, 'role' => $role]);

        if ($bitrixId !== null) {
            BitrixAccount::create([
                'user_id' => $user->id,
                'bitrix_user_id' => $bitrixId,
                'bitrix_user_name' => $name,
                'client_endpoint' => self::REST,
                'access_token' => 'token-'.$bitrixId,
                'refresh_token' => 'refresh-'.$bitrixId,
                'expires_at' => now()->addHour(),
            ]);
        }

        return Employee::create(['user_id' => $user->id, 'name' => $name, 'email' => $email, 'active' => true]);
    }

    private function project(string $name, Employee ...$members): PlanProject
    {
        $project = PlanProject::create(['name' => $name]);
        $project->members()->sync(array_map(fn (Employee $e) => $e->id, $members));

        return $project;
    }

    private function writesOf(string $method): array
    {
        return array_values(array_filter($this->writes, fn (array $write) => $write['method'] === $method));
    }

    private function sync(): void
    {
        $this->artisan('plans:sync-bitrix')->assertSuccessful();
    }

    public function test_task_created_in_plan_appears_in_bitrix_with_plan_and_project_tags(): void
    {
        $ivan = $this->employee('Іван Петренко', '7');
        $project = $this->project('Brok', $ivan);

        Sanctum::actingAs($ivan->user);

        $id = $this->postJson("/api/plans/projects/{$project->id}/tasks", [
            'title' => 'Звіт по лідах',
            'note' => 'Деталі в документі',
            'status' => PlanTask::STATUS_IN_PROGRESS,
        ])->assertCreated()->json('data.id');

        $add = $this->writesOf('tasks.task.add');
        $this->assertCount(1, $add);
        $this->assertSame([
            'TITLE' => 'Звіт по лідах',
            'DESCRIPTION' => 'Деталі в документі',
            'RESPONSIBLE_ID' => '7',
            'TAGS' => ['План', 'Brok'],
        ], $add[0]['params']['fields']);

        $task = PlanTask::find($id);
        $this->assertSame('500', $task->bitrix_task_id);
        $this->assertFalse($task->bitrix_pending);
        // «В роботі» дотискається окремим оновленням — нова задача завжди «чекає».
        $this->assertSame('3', $this->portalTasks['500']['status']);

        $this->getJson("/api/plans/projects/{$project->id}")
            ->assertJsonPath('tasks.0.bitrix_state', 'linked')
            ->assertJsonPath('tasks.0.bitrix_url', self::PORTAL.'/company/personal/user/7/tasks/task/view/500/');

        // Наступний прогін бачить ту саму задачу — нічого не дублює й не змінює.
        $this->sync();
        $this->assertSame(1, PlanTask::count());
        $this->assertSame(PlanTask::STATUS_IN_PROGRESS, $task->fresh()->status);
    }

    public function test_task_tagged_in_bitrix_is_pulled_into_plan_of_matching_project(): void
    {
        $ivan = $this->employee('Іван Петренко', '7');
        $olena = $this->employee('Олена Коваль', null);
        $this->portalUsers['9'] = $olena->email;
        $brok = $this->project('Brok', $ivan);

        $this->remoteTask('101', ['title' => 'Нова з Бітрікса', 'description' => '[b]Опис[/b]', 'status' => '4']);
        // Виконавець не підключав Бітрікс — упізнаємо за поштою й додаємо в учасники.
        $this->remoteTask('102', ['title' => 'Для Олени', 'responsibleId' => '9']);
        // Без тегу «План», без тегу проекту чи з проектом, якого в сервісі немає, — не чіпаємо.
        $this->remoteTask('103', ['tags' => ['Brok']]);
        $this->remoteTask('104', ['tags' => ['План']]);
        $this->remoteTask('105', ['tags' => ['План', 'Сторонній']]);

        $this->sync();

        $this->assertSame(2, PlanTask::count());

        $pulled = PlanTask::where('bitrix_task_id', '101')->first();
        $this->assertSame($brok->id, $pulled->plan_project_id);
        $this->assertSame($ivan->id, $pulled->employee_id);
        $this->assertSame('Нова з Бітрікса', $pulled->title);
        $this->assertSame('Опис', $pulled->note);
        $this->assertSame(PlanTask::STATUS_REVIEW, $pulled->status);

        $this->assertSame($olena->id, PlanTask::where('bitrix_task_id', '102')->value('employee_id'));
        $this->assertTrue($brok->hasMember($olena));

        // Підтягнуте не відправляється назад у Бітрікс.
        $this->assertSame([], $this->writes);
    }

    public function test_changes_flow_both_ways_and_service_wins_on_conflict(): void
    {
        $ivan = $this->employee('Іван Петренко', '7');
        $this->project('Brok', $ivan);
        $this->remoteTask('101', ['title' => 'Стара назва', 'description' => 'Опис']);
        $this->sync();
        $task = PlanTask::where('bitrix_task_id', '101')->first();

        // Змінили лише в Бітріксі — переноситься в план.
        $this->portalTasks['101']['title'] = 'Назва з Бітрікса';
        $this->portalTasks['101']['status'] = '5';
        $this->sync();
        $task->refresh();
        $this->assertSame('Назва з Бітрікса', $task->title);
        $this->assertSame(PlanTask::STATUS_DONE, $task->status);

        // Змінили з обох боків — лишається версія сервісу і йде в Бітрікс;
        // незачеплене сервісом поле все одно приходить з Бітрікса.
        Sanctum::actingAs($ivan->user);
        $this->patchJson("/api/plans/tasks/{$task->id}", ['title' => 'Назва з сервісу'])->assertOk();
        $this->assertSame('Назва з сервісу', $this->portalTasks['101']['title']);

        $task->forceFill(['title' => 'Ще раз із сервісу'])->save();
        $this->portalTasks['101']['title'] = 'Паралельно в Бітріксі';
        $this->portalTasks['101']['description'] = 'Новий опис';
        $this->sync();

        $task->refresh();
        $this->assertSame('Ще раз із сервісу', $task->title);
        $this->assertSame('Новий опис', $task->note);
        $this->assertSame('Ще раз із сервісу', $this->portalTasks['101']['title']);
    }

    public function test_changing_project_tag_in_bitrix_moves_task_to_that_plan(): void
    {
        $ivan = $this->employee('Іван Петренко', '7');
        $this->project('Brok', $ivan);
        $tumtum = $this->project('TumTum');
        $this->remoteTask('101', []);
        $this->sync();

        // Тег проекту пишуть як завгодно — регістр не важить.
        $this->portalTasks['101']['tags'] = ['План', 'tumtum'];
        $this->sync();

        $task = PlanTask::where('bitrix_task_id', '101')->first();
        $this->assertSame($tumtum->id, $task->plan_project_id);
        $this->assertTrue($tumtum->hasMember($ivan));

        // Два теги проектів одразу — незрозуміло куди, тож лишаємо як є.
        $this->portalTasks['101']['tags'] = ['План', 'Brok', 'TumTum'];
        $this->sync();
        $this->assertSame($tumtum->id, $task->fresh()->plan_project_id);
    }

    public function test_section_or_day_marks_do_not_touch_bitrix(): void
    {
        $ivan = $this->employee('Іван Петренко', '7');
        $project = $this->project('Brok', $ivan);
        $this->remoteTask('101', []);
        $this->sync();
        $task = PlanTask::where('bitrix_task_id', '101')->first();
        $section = $project->sections()->create(['name' => 'Розділ']);

        Sanctum::actingAs($ivan->user);
        $this->patchJson("/api/plans/tasks/{$task->id}", ['section_id' => $section->id])->assertOk();
        $this->putJson("/api/plans/tasks/{$task->id}/days/2026-09-23", ['comment' => 'зробив'])->assertOk();

        $this->assertSame([], $this->writes);
    }

    public function test_task_gone_from_bitrix_stays_in_plan_unlinked_and_relinks_when_tag_returns(): void
    {
        $ivan = $this->employee('Іван Петренко', '7');
        $project = $this->project('Brok', $ivan);
        $this->remoteTask('101', ['title' => 'Задача']);
        $this->sync();

        Carbon::setTestNow('2026-09-23 12:10:00');
        $this->portalTasks['101']['tags'] = [];
        $this->sync();

        $task = PlanTask::where('bitrix_task_id', '101')->first();
        $this->assertNotNull($task->bitrix_unlinked_at);

        Sanctum::actingAs($ivan->user);
        $this->getJson("/api/plans/projects/{$project->id}")->assertJsonPath('tasks.0.bitrix_state', 'unlinked');

        // Розвʼязану задачу правки в сервісі в Бітрікс не повертають.
        $this->patchJson("/api/plans/tasks/{$task->id}", ['title' => 'Змінено'])->assertOk();
        $this->assertSame([], $this->writes);

        $this->portalTasks['101']['tags'] = ['План'];
        $this->sync();
        $this->assertNull($task->fresh()->bitrix_unlinked_at);
        $this->assertSame(1, PlanTask::count());
    }

    public function test_deleting_plan_task_removes_only_plan_tag_in_bitrix(): void
    {
        $ivan = $this->employee('Іван Петренко', '7');
        $this->project('Brok', $ivan);
        $this->remoteTask('101', ['tags' => ['Терміново', 'План', 'Brok']]);
        $this->sync();
        $task = PlanTask::where('bitrix_task_id', '101')->first();

        Sanctum::actingAs($ivan->user);
        $this->deleteJson("/api/plans/tasks/{$task->id}")->assertOk();

        $this->assertArrayHasKey('101', $this->portalTasks);
        $this->assertSame(['Терміново', 'Brok'], $this->portalTasks['101']['tags']);

        // І наступний прогін її вже не притягне назад.
        $this->sync();
        $this->assertSame(0, PlanTask::count());
    }

    public function test_failed_push_is_retried_by_sync_with_another_account(): void
    {
        $admin = $this->employee('Адмін', '1', User::ROLE_ADMIN);
        $ivan = $this->employee('Іван Петренко', '7');
        $project = $this->project('Brok', $ivan);
        $this->remoteTask('101', ['title' => 'Поставив адмін']);
        $this->sync();
        $task = PlanTask::where('bitrix_task_id', '101')->first();

        // Портал взагалі не відповідає — зміна не губиться, а чекає прогону.
        $this->failures['tasks.task.update'] = 3;
        Sanctum::actingAs($ivan->user);
        $this->patchJson("/api/plans/tasks/{$task->id}", ['title' => 'Нова назва'])->assertOk();
        $this->assertTrue($task->fresh()->bitrix_pending);
        $this->assertSame('Поставив адмін', $this->portalTasks['101']['title']);

        $this->getJson("/api/plans/projects/{$project->id}")->assertJsonPath('tasks.0.bitrix_state', 'pending');

        // Виконавцю назву чужої задачі міняти не можна — прогін пробує від імені адміністратора.
        $this->failures['tasks.task.update'] = 1;
        $this->sync();

        $this->assertFalse($task->fresh()->bitrix_pending);
        $this->assertSame('Нова назва', $this->portalTasks['101']['title']);
        $this->assertNotNull($admin);
    }

    public function test_nothing_is_sent_when_bitrix_is_not_connected(): void
    {
        BitrixWorkspace::query()->delete();
        $ivan = $this->employee('Іван Петренко', null);
        $project = $this->project('Brok', $ivan);

        Sanctum::actingAs($ivan->user);
        $id = $this->postJson("/api/plans/projects/{$project->id}/tasks", ['title' => 'Задача'])->assertCreated()->json('data.id');

        $this->assertFalse(PlanTask::find($id)->bitrix_pending);
        $this->sync();
        Http::assertNothingSent();
    }
}
