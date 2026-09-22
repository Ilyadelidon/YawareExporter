<?php

namespace Tests\Feature;

use App\Jobs\GenerateYawareReport;
use App\Models\ActivityEntry;
use App\Models\DailyStat;
use App\Models\Employee;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * День, у якому лишився час поза тасками, звітом не стає: такий звіт однаково
 * довелось би переробляти. Працівник дізнається про це з Telegram і формує
 * звіт заново, поправивши таски в трекері.
 */
class TaskCoverageBlockTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/task-coverage-'.uniqid();
        File::ensureDirectoryExists($this->sandbox);
        $this->app->useStoragePath($this->sandbox);

        config([
            'services.trello.key' => 'trello-key',
            'services.telegram.bot_token' => 'bot-token',
            'services.telegram.bot_username' => 'TestBot',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    /**
     * Замість Playwright — PHP-скрипт, що друкує такий самий підсумковий JSON:
     * шлях до Excel, шлях до history-data і час поза тасками.
     */
    private function workerReturning(?int $outsideSeconds, array $history): void
    {
        $excelPath = $this->sandbox.'/yaware-report.xlsx';
        $historyPath = $this->sandbox.'/history-data.json';

        File::put($excelPath, 'xlsx');
        File::put($historyPath, json_encode($history, JSON_UNESCAPED_UNICODE));

        $payload = var_export(json_encode([
            'status' => 'ok',
            'file' => $excelPath,
            'dataFile' => $historyPath,
            'outsideSeconds' => $outsideSeconds,
            'summary' => ['Загальний час' => '08:00:00'],
            'warnings' => [],
        ], JSON_UNESCAPED_UNICODE), true);

        File::put($this->sandbox.'/worker.php', "<?php echo {$payload}, PHP_EOL;");

        config([
            'yaware.node_binary' => PHP_BINARY,
            'yaware.worker_script' => $this->sandbox.'/worker.php',
            'yaware.worker_cwd' => $this->sandbox,
        ]);
    }

    /** День із активністю: такий не вважається порожнім. */
    private function historyWithActivity(): array
    {
        return [
            'stats' => ['total_seconds' => 28800, 'productive_seconds' => 28800],
            'activities' => [
                ['name' => 'PhpStorm', 'productivity' => 'productive', 'duration_seconds' => 28800],
            ],
            'idle_activities' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     */
    private function fakeTrello(array $cards): void
    {
        Http::fake([
            'api.trello.com/*/lists*' => Http::response([['id' => 'list-1', 'name' => 'Done']]),
            'api.trello.com/*/cards*' => Http::response($cards),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
    }

    private function report(): Report
    {
        $user = User::factory()->create([
            'email' => 'a@example.com',
            'trello_token' => 'token',
            'trello_board_id' => 'board',
            'telegram_chat_id' => '42',
        ]);

        $employee = Employee::create([
            'user_id' => $user->id,
            'name' => 'A',
            'email' => 'a@example.com',
            'yaware_password' => 'secret',
            'active' => true,
        ]);

        return $employee->reports()->create([
            'report_date' => '2026-09-15',
            'status' => Report::STATUS_PENDING,
        ]);
    }

    private function card(): array
    {
        return [
            'id' => 'card-1',
            'name' => 'Таска',
            'desc' => '',
            'start' => '2026-09-15T06:00:00.000Z',
            'due' => '2026-09-15T14:00:00.000Z',
            'dueComplete' => true,
            'idList' => 'list-1',
            'shortUrl' => 'https://trello.com/c/card-1',
            'labels' => [],
        ];
    }

    public function test_time_outside_tasks_blocks_the_report(): void
    {
        $this->workerReturning(4800, $this->historyWithActivity());
        $this->fakeTrello([$this->card()]);

        $report = $this->report();
        (new GenerateYawareReport($report))->handle();

        $report->refresh();

        $this->assertSame(Report::STATUS_BLOCKED, $report->status);
        $this->assertStringContainsString('1 год 20 хв', (string) $report->error_message);
        $this->assertNull($report->generated_at);
        $this->assertNull($report->summary);

        // Звіту немає: ні файлу для завантаження, ні дня в історичних таблицях.
        $this->assertSame(0, $report->files()->count());
        $this->assertSame(0, DailyStat::count());
        $this->assertSame(0, ActivityEntry::count());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request['text'], 'не сформовано')
            && str_contains($request['text'], '1 год 20 хв'));
    }

    public function test_day_without_tasks_blocks_the_report(): void
    {
        $this->workerReturning(null, $this->historyWithActivity());
        $this->fakeTrello([]);

        $report = $this->report();
        (new GenerateYawareReport($report))->handle();

        $report->refresh();

        $this->assertSame(Report::STATUS_BLOCKED, $report->status);
        $this->assertStringContainsString('жодної таски', (string) $report->error_message);
        $this->assertSame(0, $report->files()->count());
        $this->assertSame(0, DailyStat::count());
    }

    public function test_day_fully_covered_by_tasks_produces_a_report(): void
    {
        $this->workerReturning(0, $this->historyWithActivity());
        $this->fakeTrello([$this->card()]);

        $report = $this->report();
        (new GenerateYawareReport($report))->handle();

        $report->refresh();

        $this->assertSame(Report::STATUS_COMPLETED, $report->status);
        $this->assertSame(1, $report->files()->count());
        $this->assertSame(1, DailyStat::count());
    }

    /**
     * Трекер не відповів — тасок не отримано взагалі. Це не провина працівника,
     * тож звіт іде звичайним шляхом із попередженням, як і до блокування.
     */
    public function test_tracker_failure_does_not_block_the_report(): void
    {
        $this->workerReturning(null, $this->historyWithActivity());
        Http::fake([
            'api.trello.com/*' => Http::response([], 500),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $report = $this->report();
        (new GenerateYawareReport($report))->handle();

        $report->refresh();

        $this->assertSame(Report::STATUS_COMPLETED, $report->status);
        $this->assertNull($report->tasks);
    }
}
