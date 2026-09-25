<?php

namespace Tests\Feature;

use App\Services\GoogleSheetsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Рознесення тасок по місячному аркушу для дати, якої ще немає в шапці
 * (вихідний день: місячний аркуш створюється лише з робочими днями).
 */
class GoogleSheetsMonthSyncTest extends TestCase
{
    private const SPREADSHEET_ID = 'sheet-abc';

    private const MONTH_TITLE = 'Звіт за місяць 07';

    protected function setUp(): void
    {
        parent::setUp();

        // Токен беремо з кешу — щоб тест не ходив по refresh_token у файл.
        Cache::put('google.oauth.access_token', 'test-access-token', now()->addHour());
    }

    private function serial(string $date): int
    {
        return (int) CarbonImmutable::create(1899, 12, 30)
            ->diffInDays(CarbonImmutable::parse($date)->startOfDay());
    }

    /**
     * Шапка місячного аркуша: 23.07 і 24.07 (робочі), далі 27.07 — і, після
     * вставки, колонка 25.07 між ними.
     *
     * @return array<int, array<int, mixed>>
     */
    private function grid(bool $withSaturday): array
    {
        $dates = $withSaturday
            ? ['2026-07-23', '2026-07-24', '2026-07-25', '2026-07-27']
            : ['2026-07-23', '2026-07-24', '2026-07-27'];

        $header = array_merge(
            ['№', 'Посилання на задачу', 'Задача'],
            array_map(fn (string $date) => $this->serial($date), $dates),
            ['Витрачений час на задачу'],
        );

        return [
            $header,
            [], [], [], // вільні рядки 2–4
            ['Відпрацьований час за день/за місяць'], // рядок підсумків — 5
        ];
    }

    public function test_new_date_column_is_written_with_put_not_post(): void
    {
        $gridReads = 0;

        Http::fake(function (Request $request) use (&$gridReads) {
            $url = urldecode($request->url());
            $method = $request->method();

            // Таски денної вкладки.
            if ($method === 'GET' && str_contains($url, "'25.07.2026'!V8")) {
                return Http::response(['values' => [['Таска А']]]);
            }

            // Перелік аркушів.
            if ($method === 'GET' && str_contains($url, 'fields=sheets.properties')) {
                return Http::response(['sheets' => [
                    ['properties' => ['sheetId' => 7, 'index' => 0, 'title' => self::MONTH_TITLE]],
                ]]);
            }

            // Місячний аркуш: перше читання — без 25.07, після вставки — з нею.
            if ($method === 'GET' && str_contains($url, "'".self::MONTH_TITLE."'!A1:AZ")) {
                return Http::response(['values' => $this->grid(++$gridReads > 1)]);
            }

            if ($method === 'PUT' || $method === 'POST') {
                return Http::response([]);
            }

            return Http::response([], 418);
        });

        (new GoogleSheetsService(self::SPREADSHEET_ID))
            ->syncMonthSheet('25.07.2026', CarbonImmutable::parse('2026-07-25'));

        // Значення дати в шапку пишеться через values.update — це PUT.
        // POST за тим самим URL Google відбиває HTML-сторінкою 400.
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_contains(urldecode($request->url()), "'".self::MONTH_TITLE."'!F1")
            && str_contains($request->url(), 'valueInputOption=USER_ENTERED')
            && $request->data() === ['values' => [[$this->serial('2026-07-25')]]]);

        // Жодного POST на /values/<діапазон> без суфікса методу (:clear, :batchUpdate).
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST'
            && preg_match('~/values/[^/]+$~', $request->url())
            && ! str_contains($request->url(), ':'));
    }

    public function test_day_tasks_land_in_the_inserted_column(): void
    {
        $gridReads = 0;

        Http::fake(function (Request $request) use (&$gridReads) {
            $url = urldecode($request->url());

            if ($request->method() === 'GET' && str_contains($url, "'25.07.2026'!V8")) {
                return Http::response(['values' => [['Таска А']]]);
            }

            if ($request->method() === 'GET' && str_contains($url, 'fields=sheets.properties')) {
                return Http::response(['sheets' => [
                    ['properties' => ['sheetId' => 7, 'index' => 0, 'title' => self::MONTH_TITLE]],
                ]]);
            }

            if ($request->method() === 'GET' && str_contains($url, "'".self::MONTH_TITLE."'!A1:AZ")) {
                return Http::response(['values' => $this->grid(++$gridReads > 1)]);
            }

            return Http::response([]);
        });

        (new GoogleSheetsService(self::SPREADSHEET_ID))
            ->syncMonthSheet('25.07.2026', CarbonImmutable::parse('2026-07-25'));

        // Колонка дня очищається перед записом.
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains(urldecode($request->url()), "'".self::MONTH_TITLE."'!F2:F4:clear"));

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'values:batchUpdate')) {
                return false;
            }

            $ranges = collect($request->data()['data'])->keyBy('range')
                ->map(fn (array $entry) => $entry['values'][0][0]);

            return $ranges["'".self::MONTH_TITLE."'!C2"] === 'Таска А'
                && $ranges["'".self::MONTH_TITLE."'!H2"] === '=SUM(D2:G2)'
                && $ranges["'".self::MONTH_TITLE."'!F2"] === "='25.07.2026'!X8";
        });
    }

    public function test_totals_cover_rows_inserted_above_them(): void
    {
        $grid = $this->grid(false);
        // Вільних рядків немає: таска в рядку 2, підсумок одразу під нею.
        $grid = [$grid[0], [2 => 'Стара таска'], $grid[4]];

        Http::fake(function (Request $request) use ($grid) {
            $url = urldecode($request->url());

            if ($request->method() === 'GET' && str_contains($url, "'24.07.2026'!V8")) {
                return Http::response(['values' => [['Таска А']]]);
            }

            if ($request->method() === 'GET' && str_contains($url, 'fields=sheets.properties')) {
                return Http::response(['sheets' => [
                    ['properties' => ['sheetId' => 7, 'index' => 0, 'title' => self::MONTH_TITLE]],
                ]]);
            }

            if ($request->method() === 'GET' && str_contains($url, "'".self::MONTH_TITLE."'!A1:AZ")) {
                return Http::response(['values' => $grid]);
            }

            return Http::response([]);
        });

        (new GoogleSheetsService(self::SPREADSHEET_ID))
            ->syncMonthSheet('24.07.2026', CarbonImmutable::parse('2026-07-24'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), ':batchUpdate')
            && ($request->data()['requests'][0]['insertDimension']['range']['startIndex'] ?? null) === 2);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'values:batchUpdate')) {
                return false;
            }

            $ranges = collect($request->data()['data'])->keyBy('range')
                ->map(fn (array $entry) => $entry['values'][0][0]);

            // Нова таска — у вставленому рядку 3, підсумок зʼїхав у рядок 4
            // і рахує обидва рядки, а не лише старий діапазон до рядка 2.
            return $ranges["'".self::MONTH_TITLE."'!C3"] === 'Таска А'
                && $ranges["'".self::MONTH_TITLE."'!E3"] === "='24.07.2026'!X8"
                && $ranges["'".self::MONTH_TITLE."'!D4"] === '=SUM(D2:D3)'
                && $ranges["'".self::MONTH_TITLE."'!E4"] === '=SUM(E2:E3)'
                && $ranges["'".self::MONTH_TITLE."'!F4"] === '=SUM(F2:F3)'
                && $ranges["'".self::MONTH_TITLE."'!G4"] === '=SUM(G2:G3)';
        });
    }
}
