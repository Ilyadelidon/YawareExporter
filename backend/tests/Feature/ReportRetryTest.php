<?php

namespace Tests\Feature;

use App\Jobs\GenerateYawareReport;
use App\Models\Employee;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Один мережевий збій о 07:00 не має губити звіт до наступного ранку, а
 * неправильний пароль не має ганяти браузер удруге і мовчати про відмову.
 */
class ReportRetryTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/report-retry-'.uniqid();
        File::ensureDirectoryExists($this->sandbox);
        $this->app->useStoragePath($this->sandbox);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    /**
     * Замість Playwright — PHP-скрипт, що падає так само, як воркер: останній
     * рядок stdout — JSON помилки, код виходу 1.
     */
    private function workerFailingWith(?string $code): void
    {
        $script = $this->sandbox.'/worker.php';
        $payload = var_export(json_encode([
            'status' => 'error',
            'message' => 'Збій воркера',
            'code' => $code,
        ]), true);

        File::put($script, "<?php echo {$payload}, PHP_EOL; exit(1);");

        config([
            'yaware.node_binary' => PHP_BINARY,
            'yaware.worker_script' => $script,
            'yaware.worker_cwd' => $this->sandbox,
        ]);
    }

    private function report(): Report
    {
        $user = User::factory()->create(['email' => 'a@example.com']);
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

    private function runJob(Report $report, int $attempt): GenerateYawareReport
    {
        $job = (new GenerateYawareReport($report))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        $job->handle();

        return $job;
    }

    public function test_transient_failure_is_retried_later(): void
    {
        $this->workerFailingWith(null);
        $report = $this->report();

        $this->runJob($report, 1)->assertReleased(GenerateYawareReport::RETRY_DELAY_SECONDS);

        $this->assertSame(Report::STATUS_PENDING, $report->fresh()->status);
    }

    public function test_last_attempt_marks_report_failed(): void
    {
        $this->workerFailingWith(null);
        $report = $this->report();

        $this->runJob($report, 2)->assertNotReleased();

        $this->assertSame(Report::STATUS_FAILED, $report->fresh()->status);
        $this->assertSame('Збій воркера', $report->fresh()->error_message);
    }

    public function test_wrong_password_is_not_retried(): void
    {
        $this->workerFailingWith('INVALID_CREDENTIALS');
        $report = $this->report();

        $this->runJob($report, 1)->assertNotReleased();

        $this->assertSame(Report::STATUS_FAILED, $report->fresh()->status);
    }

    public function test_empty_day_is_not_retried(): void
    {
        $this->workerFailingWith('EMPTY_DAY');
        $report = $this->report();

        $this->runJob($report, 1)->assertNotReleased();

        $this->assertSame(Report::STATUS_FAILED, $report->fresh()->status);
    }
}
