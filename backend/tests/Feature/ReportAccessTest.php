<?php

namespace Tests\Feature;

use App\Jobs\GenerateYawareReport;
use App\Models\Employee;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Звіт — це чужий робочий день погодинно: о котрій прийшов, чим займався,
 * скільки простояв. Підмінений id у посиланні не має відкривати сусіда.
 */
class ReportAccessTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $email): Employee
    {
        $user = User::factory()->create(['email' => $email]);

        return Employee::create([
            'user_id' => $user->id,
            'name' => $email,
            'email' => $email,
            'active' => true,
        ]);
    }

    private function reportFor(Employee $employee): Report
    {
        return $employee->reports()->create([
            'report_date' => '2026-09-15',
            'status' => Report::STATUS_COMPLETED,
        ]);
    }

    public function test_employee_cannot_open_someone_elses_report(): void
    {
        $mine = $this->employee('a@example.com');
        $theirs = $this->employee('b@example.com');
        $report = $this->reportFor($theirs);

        Sanctum::actingAs($mine->user);

        $this->getJson("/api/reports/{$report->id}")->assertForbidden();
    }

    public function test_employee_cannot_download_someone_elses_report(): void
    {
        $mine = $this->employee('a@example.com');
        $theirs = $this->employee('b@example.com');
        $report = $this->reportFor($theirs);

        // Файл існує — щоб 403 прийшов саме з перевірки доступу, а не через
        // відсутній файл: інакше тест проходив би й з діркою.
        $directory = storage_path("app/reports/{$report->id}");
        File::ensureDirectoryExists($directory);
        File::put($directory.'/zvit.xlsx', 'xlsx');
        ReportFile::create([
            'report_id' => $report->id,
            'type' => 'combined_excel',
            'path' => "reports/{$report->id}/zvit.xlsx",
            'original_name' => 'zvit.xlsx',
        ]);

        Sanctum::actingAs($mine->user);

        $this->get("/api/reports/{$report->id}/download")->assertForbidden();

        File::deleteDirectory($directory);
    }

    public function test_employee_sees_only_own_reports_in_the_list(): void
    {
        $mine = $this->employee('a@example.com');
        $theirs = $this->employee('b@example.com');
        $ownReport = $this->reportFor($mine);
        $this->reportFor($theirs);

        Sanctum::actingAs($mine->user);

        $response = $this->getJson('/api/reports')->assertOk();

        $this->assertSame([$ownReport->id], array_column($response->json('data'), 'id'));
    }

    public function test_employee_cannot_queue_a_report_for_someone_else(): void
    {
        Queue::fake();

        $mine = $this->employee('a@example.com');
        $theirs = $this->employee('b@example.com');

        Sanctum::actingAs($mine->user);

        // employee_id читається лише в адмінів — у працівника він мовчки
        // ігнорується, і звіт має піти йому самому, а не вказаному сусіду.
        $this->postJson('/api/reports', [
            'employee_id' => $theirs->id,
            'report_date' => '2026-09-15',
        ])->assertCreated();

        $this->assertDatabaseMissing('reports', ['employee_id' => $theirs->id]);
        $this->assertDatabaseHas('reports', ['employee_id' => $mine->id]);
        Queue::assertPushed(GenerateYawareReport::class);
    }

    public function test_admin_opens_any_report(): void
    {
        $employee = $this->employee('a@example.com');
        $report = $this->reportFor($employee);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->getJson("/api/reports/{$report->id}")->assertOk();
    }

    public function test_admin_only_routes_are_closed_for_employees(): void
    {
        $employee = $this->employee('a@example.com');

        Sanctum::actingAs($employee->user);

        $this->getJson('/api/timesheet?month=2026-09')->assertForbidden();
        $this->getJson('/api/employees')->assertForbidden();
        $this->getJson('/api/analysis?employee_id='.$employee->id.'&date=2026-09-15')->assertForbidden();
    }

    public function test_guests_get_nothing(): void
    {
        $employee = $this->employee('a@example.com');
        $report = $this->reportFor($employee);

        $this->getJson("/api/reports/{$report->id}")->assertUnauthorized();
        $this->getJson('/api/employees')->assertUnauthorized();
    }
}
