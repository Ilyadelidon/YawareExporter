<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanTask;
use App\Models\User;
use App\Services\PlanTrelloSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «Плани» ↔ Trello: задача плану — картка з міткою «План» і міткою проекту
 * на дошці виконавця. Trello підмінено сховищем у памʼяті; писати в дошку
 * пускає лише токен її власника, як і справжній.
 */
class PlanTrelloSyncTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.trello.com/1/';

    /** @var array<string, array{token: string, lists: array<string, string>, labels: array<string, string>}> */
    private array $boards = [];

    /** @var array<string, array<string, mixed>> картки за id */
    private array $cards = [];

    /** @var list<array{method: string, path: string, data: array}> */
    private array $writes = [];

    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-29 12:00:00');
        config()->set('services.trello.key', 'app-key');

        Http::fake(fn (Request $request) => $this->trello($request));
    }

    private function trello(Request $request)
    {
        $path = strtok(substr($request->url(), strlen(self::API)), '?');
        // Сервіс шле параметри в рядку запиту (так робить і Trello-клієнт звітів).
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $data = $query + (array) $request->data();
        $token = $data['token'] ?? null;
        $segments = explode('/', $path);

        if ($request->method() !== 'GET') {
            $this->writes[] = ['method' => $request->method(), 'path' => $path, 'data' => $data];
        }

        if ($segments[0] === 'boards') {
            $board = $this->boards[$segments[1]] ?? null;

            if (! $board || $board['token'] !== $token) {
                return Http::response('unauthorized permission requested', 401);
            }

            return match ($segments[2]) {
                'lists' => Http::response(collect($board['lists'])->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all()),
                'labels' => Http::response(collect($board['labels'])->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all()),
                'cards' => Http::response(array_values(array_map(
                    fn (array $card) => array_diff_key($card, ['board' => 1, 'closed' => 1]),
                    array_filter($this->cards, fn (array $card) => $card['board'] === $segments[1] && ! $card['closed']),
                ))),
            };
        }

        if ($segments[0] === 'labels') {
            if (($this->boards[$data['idBoard']]['token'] ?? null) !== $token) {
                return Http::response('unauthorized', 401);
            }

            $id = 'label-'.$this->nextId++;
            $this->boards[$data['idBoard']]['labels'][$id] = $data['name'];

            return Http::response(['id' => $id]);
        }

        if ($segments[0] === 'cards' && ! isset($segments[1])) {
            $board = $this->boardOfList($data['idList']);

            if ($this->boards[$board]['token'] !== $token) {
                return Http::response('unauthorized', 401);
            }

            $id = 'card-'.$this->nextId++;
            $this->cards[$id] = [
                'id' => $id, 'board' => $board, 'closed' => false, 'name' => $data['name'], 'desc' => $data['desc'] ?? '',
                'idList' => $data['idList'], 'idLabels' => array_filter(explode(',', $data['idLabels'] ?? '')),
                'shortUrl' => "https://trello.com/c/short-{$id}", 'pluginData' => [],
            ];

            return Http::response(['id' => $id, 'shortUrl' => $this->cards[$id]['shortUrl']]);
        }

        $card = &$this->cards[$segments[1]];

        if ($this->boards[$card['board']]['token'] !== $token) {
            return Http::response('unauthorized', 401);
        }

        switch ($request->method()) {
            case 'GET':
                return Http::response($card);

            case 'PUT':
                foreach ($data as $field => $value) {
                    if (in_array($field, ['name', 'desc', 'idList'], true)) {
                        $card[$field] = $value;
                    }
                }

                return Http::response($card);

            case 'DELETE':
                $card['idLabels'] = array_values(array_diff($card['idLabels'], [$segments[3]]));

                return Http::response([]);
        }

        return Http::response('not found', 404);
    }

    private function boardOfList(string $listId): string
    {
        foreach ($this->boards as $id => $board) {
            if (isset($board['lists'][$listId])) {
                return $id;
            }
        }

        throw new \RuntimeException("Невідомий список {$listId}");
    }

    /** Працівник зі своєю дошкою: списки як на справжній дошці команди. */
    private function employee(string $name, string $board, array $lists, string $provider = User::TASK_PROVIDER_TRELLO): Employee
    {
        $email = 'e'.md5($name).'@example.com';
        $user = User::factory()->create([
            'email' => $email,
            'trello_token' => "token-{$board}",
            'trello_board_id' => $board,
            'task_provider' => $provider,
        ]);

        $this->boards[$board] = [
            'token' => "token-{$board}",
            'lists' => collect($lists)->mapWithKeys(fn ($list) => ["{$board}-".mb_strtolower(str_replace(' ', '-', $list)) => $list])->all(),
            'labels' => [],
        ];

        return Employee::create(['user_id' => $user->id, 'name' => $name, 'email' => $email, 'active' => true]);
    }

    private function label(string $board, string $name): string
    {
        $id = "{$board}-label-".count($this->boards[$board]['labels']);
        $this->boards[$board]['labels'][$id] = $name;

        return $id;
    }

    private function remoteCard(string $id, string $board, string $list, array $labels, array $attributes = []): void
    {
        $this->cards[$id] = $attributes + [
            'id' => $id, 'board' => $board, 'closed' => false, 'name' => 'Картка', 'desc' => '',
            'idList' => "{$board}-".mb_strtolower(str_replace(' ', '-', $list)),
            'idLabels' => array_map(fn ($name) => array_search($name, $this->boards[$board]['labels'], true) ?: $this->label($board, $name), $labels),
            'shortUrl' => "https://trello.com/c/short-{$id}", 'pluginData' => [],
        ];
    }

    private function epic(string $cardId, array $children): void
    {
        $this->cards[$cardId]['pluginData'][] = [
            'idPlugin' => PlanTrelloSync::EPICS_PLUGIN_ID,
            'value' => json_encode(['meta' => ['role' => 'subscription'], 'children' => $children]),
        ];
    }

    private function project(string $name, Employee ...$members): PlanProject
    {
        $project = PlanProject::create(['name' => $name]);
        $project->members()->sync(array_map(fn (Employee $e) => $e->id, $members));

        return $project;
    }

    private function cardLabels(string $cardId): array
    {
        $card = $this->cards[$cardId];

        return array_values(array_map(fn ($id) => $this->boards[$card['board']]['labels'][$id], $card['idLabels']));
    }

    private function listName(string $cardId): string
    {
        $card = $this->cards[$cardId];

        return $this->boards[$card['board']]['lists'][$card['idList']];
    }

    private function sync(): void
    {
        $this->artisan('plans:sync-trello')->assertSuccessful();
    }

    public function test_card_labelled_in_trello_is_pulled_into_plan_with_epic_subtasks(): void
    {
        $ivan = $this->employee('Іван Петренко', 'board-ivan', ['План', 'Список задач', 'В роботі', 'На перевірці', 'Виконано']);
        $brok = $this->project('Brok', $ivan);

        $this->remoteCard('c1', 'board-ivan', 'На перевірці', ['План', 'brok'], ['name' => 'Інтеграція з MDoffice', 'desc' => 'Деталі']);
        $this->remoteCard('c2', 'board-ivan', 'В роботі', [], ['name' => 'Імпорт довідників']);
        $this->remoteCard('c3', 'board-ivan', 'Виконано', [], ['name' => 'Авторизація']);
        $this->remoteCard('c4', 'board-ivan', 'Список задач', [], ['name' => 'Архівна', 'closed' => true]);
        $this->epic('c1', ['c2', 'c3', 'c4', 'зникла']);
        // Без мітки «План», без мітки проекту чи з проектом, якого в сервісі немає, — не чіпаємо.
        $this->remoteCard('c5', 'board-ivan', 'План', ['Brok']);
        $this->remoteCard('c6', 'board-ivan', 'План', ['План']);
        $this->remoteCard('c7', 'board-ivan', 'План', ['План', 'Сторонній']);

        $this->sync();

        $this->assertSame(1, PlanTask::count());

        $task = PlanTask::first();
        $this->assertSame($brok->id, $task->plan_project_id);
        $this->assertSame($ivan->id, $task->employee_id);
        $this->assertSame('Інтеграція з MDoffice', $task->title);
        $this->assertSame('Деталі', $task->note);
        $this->assertSame(PlanTask::STATUS_REVIEW, $task->status);
        $this->assertSame([], $this->writes);

        Sanctum::actingAs($ivan->user);

        $this->getJson("/api/plans/projects/{$brok->id}")
            ->assertJsonPath('tasks.0.tracker', 'trello')
            ->assertJsonPath('tasks.0.tracker_state', 'linked')
            ->assertJsonPath('tasks.0.tracker_url', 'https://trello.com/c/c1')
            // Архівна й зникла підзадачі в план не потрапляють.
            ->assertJsonPath('tasks.0.subtasks', [
                ['id' => 'c2', 'title' => 'Імпорт довідників', 'url' => 'https://trello.com/c/short-c2'],
                ['id' => 'c3', 'title' => 'Авторизація', 'url' => 'https://trello.com/c/short-c3'],
            ]);
    }

    public function test_task_created_in_plan_becomes_card_on_executor_board(): void
    {
        $ivan = $this->employee('Іван Петренко', 'board-ivan', ['План', 'Список задач', 'В роботі', 'Виконано']);
        $this->label('board-ivan', 'Brok');
        $project = $this->project('Brok', $ivan);

        Sanctum::actingAs($ivan->user);

        $id = $this->postJson("/api/plans/projects/{$project->id}/tasks", [
            'title' => 'Звіт по лідах',
            'note' => 'Деталі в документі',
        ])->assertCreated()->json('data.id');

        $task = PlanTask::find($id);
        $this->assertSame('card-2', $task->trello_card_id);
        $this->assertSame('board-ivan', $task->trello_board_id);
        $this->assertFalse($task->trello_pending);

        // «Очікує» — перший підхожий список; мітку «План» створено, «Brok» узято наявну.
        $this->assertSame('План', $this->listName('card-2'));
        $this->assertSame(['План', 'Brok'], $this->cardLabels('card-2'));
        $this->assertSame('Деталі в документі', $this->cards['card-2']['desc']);

        // Статус у сервісі — картка переїжджає у відповідний список.
        $this->patchJson("/api/plans/tasks/{$id}", ['status' => PlanTask::STATUS_IN_PROGRESS])->assertOk();
        $this->assertSame('В роботі', $this->listName('card-2'));

        // Пауза свого списку не має — картка лишається, де була.
        $this->patchJson("/api/plans/tasks/{$id}", ['status' => PlanTask::STATUS_PAUSED])->assertOk();
        $this->assertSame('В роботі', $this->listName('card-2'));

        // Наступний прогін бачить ту саму картку — нічого не дублює й не змінює.
        $this->sync();
        $this->assertSame(1, PlanTask::count());
        $this->assertSame(PlanTask::STATUS_PAUSED, $task->fresh()->status);
    }

    public function test_changes_flow_both_ways_and_service_wins_on_conflict(): void
    {
        $ivan = $this->employee('Іван Петренко', 'board-ivan', ['План', 'Список задач', 'В роботі', 'Виконано']);
        $this->project('Brok', $ivan);
        $this->remoteCard('c1', 'board-ivan', 'Список задач', ['План', 'Brok'], ['name' => 'Стара назва', 'desc' => 'Опис']);
        $this->sync();
        $task = PlanTask::first();
        $this->assertSame(PlanTask::STATUS_PENDING, $task->status);

        // Змінили лише в Trello — переноситься в план.
        $this->cards['c1']['name'] = 'Нова назва';
        $this->cards['c1']['idList'] = 'board-ivan-виконано';
        $this->sync();
        $task->refresh();
        $this->assertSame('Нова назва', $task->title);
        $this->assertSame(PlanTask::STATUS_DONE, $task->status);
        $this->assertSame([], $this->writes);

        // Змінили з обох боків — виграє сервіс, і Trello отримує його значення.
        $this->travel(1)->minutes();
        $task->update(['title' => 'Назва з сервісу']);
        $this->cards['c1']['name'] = 'Назва з Trello';
        $this->cards['c1']['desc'] = 'Опис з Trello';
        $this->sync();
        $task->refresh();
        $this->assertSame('Назва з сервісу', $task->title);
        $this->assertSame('Назва з сервісу', $this->cards['c1']['name']);
        // Опис змінили лише в Trello — він і приходить.
        $this->assertSame('Опис з Trello', $task->note);

        // Власний список працівника без відомого статусу статус не змінює.
        $this->boards['board-ivan']['lists']['board-ivan-ідеї'] = 'Ідеї';
        $this->cards['c1']['idList'] = 'board-ivan-ідеї';
        $this->sync();
        $this->assertSame(PlanTask::STATUS_DONE, $task->fresh()->status);
        // …і сервіс не повертає картку назад у «Виконано».
        $this->sync();
        $this->assertSame('Ідеї', $this->listName('c1'));

        // Статус змінили в сервісі — картка переїжджає у відповідний список.
        $this->travel(1)->minutes();
        $task->refresh()->update(['status' => PlanTask::STATUS_IN_PROGRESS]);
        $this->sync();
        $this->assertSame('В роботі', $this->listName('c1'));
    }

    public function test_deleted_task_loses_plan_label_and_archived_card_unlinks_task(): void
    {
        $ivan = $this->employee('Іван Петренко', 'board-ivan', ['План', 'Виконано']);
        $project = $this->project('Brok', $ivan);
        $this->remoteCard('c1', 'board-ivan', 'План', ['План', 'Brok', 'Баг'], ['name' => 'Видалимо']);
        $this->remoteCard('c2', 'board-ivan', 'План', ['План', 'Brok'], ['name' => 'Заархівують']);
        $this->sync();

        Sanctum::actingAs($ivan->user);

        $this->deleteJson('/api/plans/tasks/'.PlanTask::where('trello_card_id', 'c1')->value('id'))->assertOk();
        $this->assertSame(['Brok', 'Баг'], $this->cardLabels('c1'));

        $this->cards['c2']['closed'] = true;
        $this->travel(1)->minutes();
        $this->sync();

        $this->getJson("/api/plans/projects/{$project->id}")->assertJsonPath('tasks.0.tracker_state', 'unlinked');
        $this->assertSame(1, PlanTask::count());
    }

    public function test_reassigned_task_moves_to_new_executor_board(): void
    {
        $ivan = $this->employee('Іван Петренко', 'board-ivan', ['План', 'Виконано']);
        $olena = $this->employee('Олена Коваль', 'board-olena', ['Нужно сделать', 'В процессе', 'Готово']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = $this->project('Brok', $ivan, $olena);
        $this->remoteCard('c1', 'board-ivan', 'План', ['План', 'Brok'], ['name' => 'Передамо']);
        $this->sync();
        $task = PlanTask::first();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/plans/tasks/{$task->id}", ['employee_id' => $olena->id])->assertOk();

        // Стара картка лишається в Івана без «План», нова — в Олени.
        $this->assertSame(['Brok'], $this->cardLabels('c1'));
        $task->refresh();
        $this->assertSame('board-olena', $task->trello_board_id);
        $this->assertNotSame('c1', $task->trello_card_id);
        $this->assertSame('Нужно сделать', $this->listName($task->trello_card_id));
        $this->assertSame(['План', 'Brok'], $this->cardLabels($task->trello_card_id));
        $this->assertSame('Передамо', $this->cards[$task->trello_card_id]['name']);

        // Наступний прогін стару картку вже не підтягує.
        $this->sync();
        $this->assertSame(1, PlanTask::count());
        $this->assertTrue($project->hasMember($olena));
    }

    public function test_task_stays_local_when_executor_has_no_tracker(): void
    {
        // Виконавець вибрав Trello, але дошки не підключив — задачу нема де створити.
        $user = User::factory()->create(['task_provider' => User::TASK_PROVIDER_TRELLO]);
        $employee = Employee::create(['user_id' => $user->id, 'name' => 'Без дошки', 'email' => $user->email, 'active' => true]);
        $project = $this->project('Brok', $employee);

        Sanctum::actingAs($user);

        $this->postJson("/api/plans/projects/{$project->id}/tasks", ['title' => 'Локальна'])->assertCreated();

        $task = PlanTask::first();
        $this->assertNull($task->trello_card_id);
        $this->assertFalse($task->trello_pending);
        $this->assertSame([], $this->writes);
        $this->getJson("/api/plans/projects/{$project->id}")->assertJsonPath('tasks.0.tracker', null);
    }
}
