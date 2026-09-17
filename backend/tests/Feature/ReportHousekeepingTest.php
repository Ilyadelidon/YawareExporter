<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportHousekeepingTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/report-housekeeping-'.uniqid();
        File::ensureDirectoryExists($this->sandbox);
        $this->app->useStoragePath($this->sandbox);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    private function employee(bool $admin = false): Employee
    {
        $user = User::factory()->create(['role' => $admin ? User::ROLE_ADMIN : User::ROLE_EMPLOYEE]);

        return Employee::create([
            'user_id' => $user->id,
            'name' => $user->email,
            'email' => $user->email,
            'active' => true,
        ]);
    }

    private function reportWithFile(Employee $employee, string $date, string $updatedAt): Report
    {
        $report = $employee->reports()->create([
            'report_date' => $date,
            'status' => Report::STATUS_COMPLETED,
        ]);
        Report::whereKey($report->id)->update(['updated_at' => $updatedAt]);

        File::ensureDirectoryExists(storage_path("app/reports/{$report->id}"));
        File::put(storage_path("app/reports/{$report->id}/zvit.xlsx"), 'xlsx');

        return $report;
    }

    public function test_guest_without_json_accept_header_gets_401_not_500(): void
    {
        $this->get('/api/auth/me')->assertUnauthorized();
    }

    public function test_employee_cannot_flood_the_report_queue(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->employee()->user);

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/reports', ['report_date' => '2026-09-15'])->assertCreated();
        }

        $this->postJson('/api/reports', ['report_date' => '2026-09-15'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Забагато запитів на генерацію звітів. Зачекайте трохи й спробуйте ще раз.');
    }

    public function test_old_report_files_are_pruned_and_fresh_ones_kept(): void
    {
        $this->travelTo('2026-09-17 03:30:00');
        $employee = $this->employee();

        $old = $this->reportWithFile($employee, '2026-01-10', '2026-01-10 08:00:00');
        // Давня дата, але перегенерований учора — файл свіжий.
        $regenerated = $this->reportWithFile($employee, '2026-01-11', '2026-09-16 08:00:00');
        File::ensureDirectoryExists(storage_path('app/reports/999999'));

        $this->artisan('reports:prune-files', ['--days' => 180])->assertSuccessful();

        $this->assertDirectoryDoesNotExist(storage_path("app/reports/{$old->id}"));
        $this->assertDirectoryDoesNotExist(storage_path('app/reports/999999'));
        $this->assertFileExists(storage_path("app/reports/{$regenerated->id}/zvit.xlsx"));
        $this->assertModelExists($old);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->travelTo('2026-09-17 03:30:00');
        $old = $this->reportWithFile($this->employee(), '2026-01-10', '2026-01-10 08:00:00');

        $this->artisan('reports:prune-files', ['--days' => 180, '--dry-run' => true])->assertSuccessful();

        $this->assertFileExists(storage_path("app/reports/{$old->id}/zvit.xlsx"));
    }
}
