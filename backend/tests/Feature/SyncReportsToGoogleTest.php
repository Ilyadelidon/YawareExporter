<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Долив дня, який не потрапив у Google Таблицю через помилку на боці Google:
 * Excel уже згенеровано, тож повторювати весь прогін воркера не треба.
 */
class SyncReportsToGoogleTest extends TestCase
{
    use RefreshDatabase;

    private const COPIED_SHEET_ID = 777;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'client-id');
        config()->set('services.google.client_secret', 'client-secret');
        config()->set('services.google.token_path', storage_path('app/test-google-token.json'));
        File::put(config('services.google.token_path'), json_encode(['refresh_token' => 'refresh']));

        Cache::put('google.oauth.access_token', 'test-access-token', now()->addHour());
    }

    protected function tearDown(): void
    {
        File::delete(config('services.google.token_path'));

        parent::tearDown();
    }

    private function reportWithFailedUpload(): Report
    {
        $user = User::factory()->create();
        $user->forceFill(['google_spreadsheet_id' => 'sheet-abc'])->save();

        $employee = Employee::create([
            'user_id' => $user->id,
            'name' => 'Іван',
            'email' => 'ivan@example.com',
        ]);

        $report = Report::create([
            'employee_id' => $employee->id,
            'report_date' => '2026-08-20',
            'status' => Report::STATUS_COMPLETED,
            'summary' => [
                'Загальний час' => '08:47:08',
                // Текст помилки Google буває багаторядковим (json тіла
                // відповіді) — після доливу від нього не має лишитись хвоста.
                'Попередження' => "офлайн активності за обрану дату відсутні.\n"
                    ."звіт не вивантажено в Google Таблицю: HTTP request returned status code 500:\n"
                    .'{"error": {"code": 500,',
            ],
        ]);

        $path = "reports/{$report->id}/yaware-report-2026-08-20.xlsx";
        File::ensureDirectoryExists(dirname(storage_path("app/{$path}")));
        File::put(storage_path("app/{$path}"), 'xlsx-bytes');

        ReportFile::create([
            'report_id' => $report->id,
            'type' => 'combined_excel',
            'path' => $path,
            'original_name' => basename($path),
        ]);

        return $report;
    }

    private function fakeGoogle(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'uploadType=resumable')) {
                return Http::response([], 200, ['Location' => 'https://upload.googleapis.com/session']);
            }

            if (str_contains($url, 'upload.googleapis.com/session')) {
                return Http::response(['id' => 'temp-file']);
            }

            if ($request->method() === 'GET' && str_contains($url, 'temp-file')) {
                return Http::response(['sheets' => [
                    ['properties' => ['sheetId' => 1, 'index' => 0, 'title' => 'Sheet1']],
                ]]);
            }

            if ($request->method() === 'GET' && str_contains($url, 'fields=sheets.properties')) {
                return Http::response(['sheets' => [
                    ['properties' => ['sheetId' => 10, 'index' => 0, 'title' => '19.08.2026']],
                ]]);
            }

            if (str_contains($url, ':copyTo')) {
                return Http::response(['sheetId' => self::COPIED_SHEET_ID]);
            }

            // Денна вкладка без тасок — місячний аркуш чіпати нема чого.
            if ($request->method() === 'GET' && str_contains(urldecode($url), '/values/')) {
                return Http::response(['values' => []]);
            }

            return Http::response([]);
        });
    }

    public function test_missing_day_is_pushed_to_the_sheet_and_warning_disappears(): void
    {
        $report = $this->reportWithFailedUpload();
        $this->fakeGoogle();

        $this->artisan('reports:sync-google')->assertSuccessful();

        $summary = $report->fresh()->summary;

        $this->assertStringEndsWith('#gid='.self::COPIED_SHEET_ID, $summary['Google Таблиця']);
        // Попередження про невдале вивантаження зникає, решта лишається.
        $this->assertSame('офлайн активності за обрану дату відсутні.', $summary['Попередження']);
    }

    public function test_report_already_in_the_sheet_is_not_touched(): void
    {
        $report = $this->reportWithFailedUpload();
        $report->update(['summary' => ['Google Таблиця' => 'https://docs.google.com/spreadsheets/d/x/edit#gid=1']]);
        Http::fake();

        $this->artisan('reports:sync-google')
            ->expectsOutputToContain('Звітів для вивантаження не знайдено.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
