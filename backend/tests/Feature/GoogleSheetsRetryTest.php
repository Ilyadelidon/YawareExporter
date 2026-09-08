<?php

namespace Tests\Feature;

use App\Services\GoogleSheetsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * Тимчасова 500 «Internal Error» від Google — не привід втратити день:
 * вивантаження повторюється, а сліди невдалої спроби прибираються.
 */
class GoogleSheetsRetryTest extends TestCase
{
    private const SPREADSHEET_ID = 'sheet-abc';

    private const TEMP_FILE_ID = 'temp-file';

    private const COPIED_SHEET_ID = 999;

    private string $excelPath;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::put('google.oauth.access_token', 'test-access-token', now()->addHour());
        Sleep::fake();

        $this->excelPath = tempnam(sys_get_temp_dir(), 'yaware-test').'.xlsx';
        file_put_contents($this->excelPath, 'xlsx-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->excelPath);

        parent::tearDown();
    }

    /**
     * Фейк усього ланцюжка вивантаження. $copyResponses — відповіді на copyTo
     * по черзі; $destinationSheets — вкладки цільової таблиці за викликами
     * (останній набір повторюється).
     *
     * @param  array<int, Response|callable>  $copyResponses
     * @param  array<int, array<int, array<string, mixed>>>  $destinationSheets
     */
    private function fakeGoogle(array $copyResponses, array $destinationSheets): void
    {
        $copies = 0;
        $destinationReads = 0;

        Http::fake(function (Request $request) use (&$copies, &$destinationReads, $copyResponses, $destinationSheets) {
            $url = $request->url();

            if (str_contains($url, 'uploadType=resumable')) {
                return Http::response([], 200, ['Location' => 'https://upload.googleapis.com/session']);
            }

            if (str_contains($url, 'upload.googleapis.com/session')) {
                return Http::response(['id' => self::TEMP_FILE_ID]);
            }

            if ($request->method() === 'GET' && str_contains($url, self::TEMP_FILE_ID)) {
                return Http::response(['sheets' => [
                    ['properties' => ['sheetId' => 1, 'index' => 0, 'title' => 'Sheet1']],
                ]]);
            }

            if ($request->method() === 'GET' && str_contains($url, 'fields=sheets.properties')) {
                $sheets = $destinationSheets[min($destinationReads++, count($destinationSheets) - 1)];

                return Http::response(['sheets' => array_map(
                    fn (array $properties) => ['properties' => $properties],
                    $sheets,
                )]);
            }

            if (str_contains($url, ':copyTo')) {
                return $copyResponses[min($copies++, count($copyResponses) - 1)];
            }

            return Http::response([]);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function daySheets(): array
    {
        return [['sheetId' => 10, 'index' => 0, 'title' => '19.08.2026']];
    }

    public function test_transient_error_does_not_lose_the_day(): void
    {
        $this->fakeGoogle(
            [Http::response(['error' => ['code' => 500, 'message' => 'Internal Error']], 500),
                Http::response(['sheetId' => self::COPIED_SHEET_ID])],
            [$this->daySheets()],
        );

        $url = (new GoogleSheetsService(self::SPREADSHEET_ID))
            ->uploadReportSheet($this->excelPath, '20.08.2026', CarbonImmutable::parse('2026-08-20'));

        $this->assertStringEndsWith('#gid='.self::COPIED_SHEET_ID, $url);

        // Копіювання пробували двічі, і пауза між спробами була.
        $this->assertSame(2, collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), ':copyTo'))
            ->count());
        Sleep::assertSlept(fn () => true, 1);

        // Тимчасовий файл на Drive прибрано, попри повтор.
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), self::TEMP_FILE_ID));
    }

    public function test_copy_left_by_a_failed_attempt_is_removed(): void
    {
        // Друге читання вкладок бачить копію, яку Google створив, хоча й
        // відповів помилкою: назва копії містить назву аркуша-джерела.
        $this->fakeGoogle(
            [Http::response(['error' => ['code' => 500, 'message' => 'Internal Error']], 500),
                Http::response(['sheetId' => self::COPIED_SHEET_ID])],
            [
                $this->daySheets(),
                array_merge($this->daySheets(), [
                    ['sheetId' => 555, 'index' => 1, 'title' => 'Копія Sheet1'],
                    ['sheetId' => self::COPIED_SHEET_ID, 'index' => 2, 'title' => 'Копія Sheet1'],
                ]),
            ],
        );

        (new GoogleSheetsService(self::SPREADSHEET_ID))
            ->uploadReportSheet($this->excelPath, '20.08.2026', CarbonImmutable::parse('2026-08-20'));

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), ':batchUpdate')) {
                return false;
            }

            $requests = collect($request->data()['requests']);

            return $requests->contains(fn (array $entry) => ($entry['deleteSheet']['sheetId'] ?? null) === 555)
                && ! $requests->contains(fn (array $entry) => ($entry['deleteSheet']['sheetId'] ?? null) === self::COPIED_SHEET_ID);
        });
    }

    public function test_permanent_error_fails_fast_and_names_the_reason(): void
    {
        $this->fakeGoogle(
            [Http::response(['error' => ['code' => 403, 'message' => 'The caller does not have permission']], 403)],
            [$this->daySheets()],
        );

        try {
            (new GoogleSheetsService(self::SPREADSHEET_ID))
                ->uploadReportSheet($this->excelPath, '20.08.2026', CarbonImmutable::parse('2026-08-20'));
            $this->fail('Очікували RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('вивантаження вкладки «20.08.2026»', $exception->getMessage());
            $this->assertStringContainsString('HTTP 403', $exception->getMessage());
            $this->assertStringContainsString('does not have permission', $exception->getMessage());
        }

        // Помилку прав повторювати марно — жодного повтору й жодної паузи.
        $this->assertSame(1, collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), ':copyTo'))
            ->count());
        Sleep::assertNeverSlept();
    }
}
