<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Вивантаження звітів у Google Таблицю під OAuth-акаунтом користувача.
 *
 * Саме OAuth, а не сервісний акаунт: з травня 2025 сервісні акаунти не мають
 * власного сховища Drive («storage quota has been exceeded») і не можуть
 * створити навіть тимчасовий файл для конвертації xlsx → Google Sheets.
 */
class GoogleSheetsService
{
    private const SHEETS_API = 'https://sheets.googleapis.com/v4/spreadsheets';

    private const DRIVE_API = 'https://www.googleapis.com/drive/v3/files';

    private const DRIVE_UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3/files';

    private const DRIVE_ABOUT_API = 'https://www.googleapis.com/drive/v3/about';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    // drive.file — лише файли, створені цим застосунком (тимчасові конвертовані копії).
    private const SCOPES = 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/spreadsheets';

    // Розташування таблиці тасок у денній вкладці (задане воркером export-yaware-xls.mjs:
    // TASK_TABLE_START_COLUMN_INDEX=20, TASK_TABLE_START_ROW_INDEX=6 → назви у V з рядка 8, час у X).
    private const DAY_TASK_NAME_COLUMN = 'V';

    private const DAY_TASK_TIME_COLUMN = 'X';

    private const DAY_TASK_FIRST_ROW = 8;

    // Місячний аркуш: колонка з назвами тасок і рядок підсумків у щойно створеному аркуші.
    private const MONTH_TASK_NAME_COLUMN_INDEX = 2; // C

    private const MONTH_FIRST_DATE_COLUMN_INDEX = 3; // D

    private const MONTH_TOTALS_ROW = 41;

    // Google час від часу відповідає 500 «Internal Error» на цілком коректний
    // запит і за секунди починає його ж виконувати нормально. Тому вивантаження
    // повторюється, а не падає з першої спроби.
    private const TRANSIENT_ATTEMPTS = 4;

    /** Пауза перед повтором у секундах; кожна наступна — удвічі довша. */
    private const TRANSIENT_BACKOFF_SECONDS = 3;

    /** Коди, за якими Google по суті просить просто повторити запит. */
    private const TRANSIENT_STATUSES = [408, 429, 500, 502, 503, 504];

    /**
     * Запис у велику таблицю, яку давно не відкривали: Google спершу
     * «прокидає» її — перший batchUpdate на копії плану йшов 168 с.
     */
    private const LARGE_SHEET_TIMEOUT_SECONDS = 240;

    public function __construct(private readonly ?string $spreadsheetId = null) {}

    /**
     * Інстанс у контексті користувача: звіти йдуть лише в його персональну
     * таблицю; без неї вивантаження не налаштоване — глобального fallback немає.
     * Google-акаунт (OAuth-токен) при цьому один — адмінський.
     */
    public static function forUser(?User $user): self
    {
        return new self($user?->google_spreadsheet_id);
    }

    public function isConfigured(): bool
    {
        return $this->hasGoogleAccount() && $this->spreadsheetId();
    }

    /**
     * Чи підключено Google-акаунт (OAuth-ключі + разова авторизація) —
     * незалежно від того, чи вибрана таблиця для вивантаження.
     */
    public function hasGoogleAccount(): bool
    {
        return $this->hasClientCredentials() && $this->refreshToken() !== null;
    }

    public function hasClientCredentials(): bool
    {
        return config('services.google.client_id') && config('services.google.client_secret');
    }

    private function spreadsheetId(): ?string
    {
        return $this->spreadsheetId;
    }

    /**
     * URL сторінки згоди Google для одноразової авторизації.
     */
    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            // offline + consent — інакше Google не видасть refresh_token повторно.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * Обмінює code з callback на refresh_token і зберігає його.
     */
    public function storeAuthorizationCode(string $code, string $redirectUri): void
    {
        $tokens = Http::asForm()
            ->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => $redirectUri,
            ])
            ->throw()
            ->json();

        if (empty($tokens['refresh_token'])) {
            throw new RuntimeException('Google не повернув refresh_token — спробуйте авторизуватися ще раз.');
        }

        $tokenPath = config('services.google.token_path');
        File::ensureDirectoryExists(dirname($tokenPath));
        File::put($tokenPath, json_encode([
            'refresh_token' => $tokens['refresh_token'],
            'created_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        Cache::forget('google.oauth.access_token');
    }

    /**
     * Створює порожню Google Таблицю для звітів користувача і дає йому
     * доступ редактора. Таблиця живе на Drive підключеного (адмінського)
     * акаунта — scope drive.file дозволяє керувати файлами, створеними
     * застосунком, зокрема й правами доступу.
     *
     * @return array{id: string, url: string}
     */
    public function createUserSpreadsheet(string $title, string $editorEmail): array
    {
        // Ланцюжок з ~10 запитів до Google легко перевищує 30-секундний ліміт PHP,
        // і фатальна помилка посеред процесу лишає недоформатовану таблицю-сироту.
        set_time_limit(0);

        $spreadsheetId = $this->request()
            ->post(self::SHEETS_API, ['properties' => ['title' => $title]])
            ->throw()
            ->json('spreadsheetId')
            ?? throw new RuntimeException('Sheets не повернув ID створеної таблиці.');

        try {
            // sendNotificationEmail — користувач отримує лист із посиланням на свою таблицю.
            $this->request()
                ->post(self::DRIVE_API."/{$spreadsheetId}/permissions?sendNotificationEmail=true", [
                    'role' => 'writer',
                    'type' => 'user',
                    'emailAddress' => $editorEmail,
                ])
                ->throw();

            $this->prepareMonthSheet($spreadsheetId, CarbonImmutable::now());
        } catch (Throwable $exception) {
            // Без доступу чи місячного аркуша таблиця не готова — не лишаємо сироту на Drive.
            $this->request()->delete(self::DRIVE_API."/{$spreadsheetId}");

            throw $exception;
        }

        return ['id' => $spreadsheetId, 'url' => self::spreadsheetUrl($spreadsheetId)];
    }

    /**
     * Готує в таблиці аркуш «Звіт за місяць MM» поточного місяця (той самий
     * формат, що будує syncMonthSheet у наявній таблиці) і прибирає порожні
     * дефолтні аркуші («Аркуш1»), щоб таблиця одразу мала робочий вигляд.
     */
    public function prepareMonthSheet(string $spreadsheetId, CarbonImmutable $reportDate): void
    {
        $monthTitle = 'Звіт за місяць '.$reportDate->format('m');
        $existing = $this->sheetProperties($spreadsheetId);

        $monthSheetId = collect($existing)
            ->first(fn (array $properties) => trim($properties['title']) === $monthTitle)['sheetId']
            ?? $this->createMonthSheet($spreadsheetId, $monthTitle, $reportDate);

        // Дефолтний аркуш нової таблиці порожній і без даних — видаляємо;
        // аркуші з даними (raw A1 непорожній) не чіпаємо.
        $deleteRequests = [];

        foreach ($existing as $properties) {
            if ($properties['sheetId'] === $monthSheetId || ! preg_match('/^(Аркуш|Sheet|Лист)\s?\d+$/ui', trim($properties['title']))) {
                continue;
            }

            $values = $this->request()
                ->get(self::SHEETS_API."/{$spreadsheetId}/values/".rawurlencode("'{$properties['title']}'!A1:Z20"))
                ->throw()
                ->json('values');

            if (empty($values)) {
                $deleteRequests[] = ['deleteSheet' => ['sheetId' => $properties['sheetId']]];
            }
        }

        if ($deleteRequests !== []) {
            $this->request()
                ->post(self::SHEETS_API."/{$spreadsheetId}:batchUpdate", ['requests' => $deleteRequests])
                ->throw();
        }
    }

    /**
     * ID таблиці з посилання виду docs.google.com/spreadsheets/d/{id}/…
     * або з голого ID; null, якщо рядок не схожий ні на те, ні на інше.
     */
    public static function extractSpreadsheetId(string $input): ?string
    {
        $input = trim($input);

        if (preg_match('~/spreadsheets/d/([A-Za-z0-9_-]{20,})~', $input, $matches)) {
            return $matches[1];
        }

        return preg_match('~^[A-Za-z0-9_-]{20,}$~', $input) ? $input : null;
    }

    /**
     * Перевіряє наявну таблицю користувача перед підключенням: підключений
     * акаунт має її бачити і мати право редагування (scope spreadsheets
     * покриває будь-яку таблицю, до якої акаунту надали доступ). Право запису
     * підтверджується no-op перейменуванням на поточну назву. Повертає назву.
     */
    public function verifyEditableSpreadsheet(string $spreadsheetId): string
    {
        $account = $this->accountEmail() ?? 'застосунку';

        $read = $this->request()
            ->get(self::SHEETS_API."/{$spreadsheetId}", ['fields' => 'properties.title']);

        if (! $read->successful()) {
            throw new RuntimeException(
                "Таблиця недоступна. Перевірте посилання і надайте в ній доступ редактора акаунту {$account}."
            );
        }

        $title = (string) $read->json('properties.title');

        $write = $this->request()
            ->post(self::SHEETS_API."/{$spreadsheetId}:batchUpdate", ['requests' => [[
                'updateSpreadsheetProperties' => [
                    'properties' => ['title' => $title],
                    'fields' => 'title',
                ],
            ]]]);

        if (! $write->successful()) {
            throw new RuntimeException(
                "Таблицю «{$title}» видно, але запис у неї заборонено — акаунту {$account} потрібен доступ саме редактора, а не глядача."
            );
        }

        return $title;
    }

    /**
     * Email підключеного (адмінського) Google-акаунта — його показуємо
     * користувачу як адресата доступу редактора при підключенні своєї таблиці.
     */
    public function accountEmail(): ?string
    {
        try {
            return Cache::remember('google.oauth.account_email', now()->addDay(), function () {
                return $this->request()
                    ->get(self::DRIVE_ABOUT_API, ['fields' => 'user(emailAddress)'])
                    ->throw()
                    ->json('user.emailAddress')
                    ?? throw new RuntimeException('Drive не повернув email акаунта.');
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Назва таблиці — для відображення стану інтеграції; null, якщо
     * таблицю видалили або доступ до неї втрачено.
     */
    public function spreadsheetTitle(string $spreadsheetId): ?string
    {
        $response = $this->request()
            ->get(self::SHEETS_API."/{$spreadsheetId}", ['fields' => 'properties.title']);

        return $response->successful() ? $response->json('properties.title') : null;
    }

    public static function spreadsheetUrl(string $spreadsheetId): string
    {
        return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/edit";
    }

    /**
     * Повністю перезаписує аркуш готовою сіткою клітинок (значення разом із
     * форматом) одним batchUpdate: очищення, розмір сітки, закріплення, дані.
     * Інших аркушів таблиці не чіпає — вона може бути спільною.
     *
     * @param  list<list<array<string, mixed>|object>>  $rows  клітинки у форматі CellData Sheets API
     * @param  array<int, int>  $columnWidths  ширина колонки в пікселях за індексом
     */
    public function replaceSheet(
        string $spreadsheetId,
        string $title,
        array $rows,
        int $frozenRows = 0,
        int $frozenColumns = 0,
        array $columnWidths = [],
        array $hiddenColumns = [],
    ): void {
        $this->withRetries("вивантаження аркуша «{$title}»", function () use ($spreadsheetId, $title, $rows, $frozenRows, $frozenColumns, $columnWidths, $hiddenColumns) {
            $existing = $this->sheetProperties($spreadsheetId);
            $sheetId = collect($existing)->first(fn (array $properties) => $properties['title'] === $title)['sheetId'] ?? null;

            if ($sheetId === null) {
                $sheetId = $this->request()->timeout(self::LARGE_SHEET_TIMEOUT_SECONDS)
                    ->post(self::SHEETS_API."/{$spreadsheetId}:batchUpdate", [
                        'requests' => [['addSheet' => ['properties' => ['title' => $title]]]],
                    ])
                    ->throw()
                    ->json('replies.0.addSheet.properties.sheetId');
            }

            $rowCount = max(1, count($rows));
            $columnCount = max(1, ...array_map('count', $rows ?: [[]]));

            $requests = [
                // Спершу знімаємо закріплення: інакше зменшення сітки впирається в нього.
                ['updateSheetProperties' => [
                    'properties' => ['sheetId' => $sheetId, 'gridProperties' => ['frozenRowCount' => 0, 'frozenColumnCount' => 0]],
                    'fields' => 'gridProperties(frozenRowCount,frozenColumnCount)',
                ]],
                ['updateCells' => ['range' => ['sheetId' => $sheetId], 'fields' => '*']],
                ['updateSheetProperties' => [
                    'properties' => ['sheetId' => $sheetId, 'gridProperties' => [
                        'rowCount' => $rowCount + 1,
                        'columnCount' => $columnCount,
                    ]],
                    'fields' => 'gridProperties(rowCount,columnCount)',
                ]],
                ['updateCells' => [
                    'start' => ['sheetId' => $sheetId, 'rowIndex' => 0, 'columnIndex' => 0],
                    'rows' => array_map(fn (array $cells) => ['values' => $cells], $rows),
                    'fields' => 'userEnteredValue,userEnteredFormat,note',
                ]],
                ['updateSheetProperties' => [
                    'properties' => ['sheetId' => $sheetId, 'gridProperties' => [
                        'frozenRowCount' => $frozenRows,
                        'frozenColumnCount' => $frozenColumns,
                    ]],
                    'fields' => 'gridProperties(frozenRowCount,frozenColumnCount)',
                ]],
            ];

            foreach ($columnWidths as $index => $pixels) {
                $requests[] = ['updateDimensionProperties' => [
                    'range' => ['sheetId' => $sheetId, 'dimension' => 'COLUMNS', 'startIndex' => $index, 'endIndex' => $index + 1],
                    'properties' => ['pixelSize' => $pixels],
                    'fields' => 'pixelSize',
                ]];
            }

            // hiddenByUser, а не нульова ширина: службову колонку видно тим,
            // хто спеціально розгорне сусідні, і не видно всім іншим.
            foreach ($hiddenColumns as $index) {
                $requests[] = ['updateDimensionProperties' => [
                    'range' => ['sheetId' => $sheetId, 'dimension' => 'COLUMNS', 'startIndex' => $index, 'endIndex' => $index + 1],
                    'properties' => ['hiddenByUser' => true],
                    'fields' => 'hiddenByUser',
                ]];
            }

            $this->request()->timeout(self::LARGE_SHEET_TIMEOUT_SECONDS)
                ->post(self::SHEETS_API."/{$spreadsheetId}:batchUpdate", ['requests' => $requests])
                ->throw();
        });
    }

    /**
     * Аркуш таким, яким його бачить людина: текст, нотатка і колір фону
     * кожної клітинки. Values-API тут замало — у плані день відмічають саме
     * заливкою клітинки, а коментар дня живе в нотатці.
     *
     * @return list<list<array{text: string, note: ?string, background: ?string}>>|null
     *                                                                                  null — такого аркуша в таблиці немає (перейменували чи видалили)
     */
    public function readSheet(string $spreadsheetId, string $title): ?array
    {
        return $this->withRetries("читання аркуша «{$title}»", function () use ($spreadsheetId, $title) {
            $exists = collect($this->sheetProperties($spreadsheetId))
                ->contains(fn (array $properties) => $properties['title'] === $title);

            if (! $exists) {
                return null;
            }

            // Саме userEnteredFormat, а не effectiveFormat: для незалитих
            // клітинок він просто відсутній, і відповідь на аркуш плану
            // виходить у рази меншою (їх там переважна більшість).
            $rows = $this->request()->timeout(self::LARGE_SHEET_TIMEOUT_SECONDS)
                ->get(self::SHEETS_API."/{$spreadsheetId}", [
                    'ranges' => "'{$title}'",
                    'includeGridData' => 'true',
                    'fields' => 'sheets(data(rowData(values(formattedValue,note,userEnteredFormat/backgroundColor))))',
                ])
                ->throw()
                ->json('sheets.0.data.0.rowData') ?? [];

            return array_map(fn (array $row) => array_map(fn (array $cell) => [
                'text' => trim((string) ($cell['formattedValue'] ?? '')),
                'note' => isset($cell['note']) ? trim((string) $cell['note']) : null,
                'background' => $this->hexColor($cell['userEnteredFormat']['backgroundColor'] ?? null),
            ], $row['values'] ?? []), $rows);
        });
    }

    /**
     * Колір Google (частки одиниці) у звичний #rrggbb. Відсутня складова —
     * нуль: так задано в API, тож {"blue": 1} — це синій, а не білий.
     *
     * @param  array<string, float>|null  $color
     */
    private function hexColor(?array $color): ?string
    {
        if ($color === null) {
            return null;
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($color['red'] ?? 0) * 255),
            (int) round(($color['green'] ?? 0) * 255),
            (int) round(($color['blue'] ?? 0) * 255),
        );
    }

    /**
     * Додає звіт вкладкою в цільову Google Таблицю: Excel-файл конвертується
     * у тимчасову Google-таблицю на Drive, її аркуш копіюється в цільову
     * (зі збереженням формул і форматування), тимчасовий файл видаляється.
     * Вкладка з тією ж назвою (перегенерований звіт) замінюється.
     *
     * @return string URL створеної вкладки
     */
    public function uploadReportSheet(string $excelPath, string $sheetTitle, CarbonImmutable $reportDate): string
    {
        $spreadsheetId = $this->spreadsheetId();

        // Знімок вкладок до вивантаження робиться один раз на всі спроби: якщо
        // Google встиг створити копію, але відповів помилкою, вона лишиться
        // в таблиці як «зайва» вкладка — і буде видалена після успішної спроби.
        $knownSheetIds = null;

        return $this->withRetries("вивантаження вкладки «{$sheetTitle}»", function () use ($spreadsheetId, $excelPath, $sheetTitle, $reportDate, &$knownSheetIds) {
            $knownSheetIds ??= array_column($this->sheetProperties($spreadsheetId), 'sheetId');

            $tempFileId = $this->convertExcelToTempSpreadsheet($excelPath, $sheetTitle);

            try {
                $sourceSheet = $this->sheetProperties($tempFileId)[0]
                    ?? throw new RuntimeException('Тимчасова таблиця не містить жодного аркуша.');

                $copiedSheetId = $this->request()
                    ->post(self::SHEETS_API."/{$tempFileId}/sheets/{$sourceSheet['sheetId']}:copyTo", [
                        'destinationSpreadsheetId' => $spreadsheetId,
                    ])
                    ->throw()
                    ->json('sheetId');

                $this->placeCopiedSheet(
                    $spreadsheetId,
                    $copiedSheetId,
                    $sheetTitle,
                    $reportDate,
                    $knownSheetIds,
                    trim((string) $sourceSheet['title']),
                );

                return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/edit#gid={$copiedSheetId}";
            } finally {
                $this->deleteTempFile($tempFileId);
            }
        });
    }

    /**
     * Розносить таски денної вкладки по аркушу «Звіт за місяць MM»:
     * таска, що вже є в колонці C, отримує формулу часу в колонку свого дня;
     * нова — займає перший вільний рядок. Аркуш місяця і колонка дати
     * створюються за потреби.
     */
    public function syncMonthSheet(string $dayTitle, CarbonImmutable $reportDate): void
    {
        $monthTitle = 'Звіт за місяць '.$reportDate->format('m');

        $this->withRetries(
            "рознесення тасок по «{$monthTitle}»",
            fn () => $this->writeMonthSheet($dayTitle, $reportDate, $monthTitle),
        );
    }

    /**
     * Один прохід рознесення. Викликається з-під withRetries: усе, що змінює
     * структуру аркуша (нова колонка дати, додаткові рядки), спершу
     * перечитується з таблиці, тому повторний прохід нічого не дублює.
     */
    private function writeMonthSheet(string $dayTitle, CarbonImmutable $reportDate, string $monthTitle): void
    {
        $spreadsheetId = $this->spreadsheetId();

        $dayTasks = $this->dayTaskRows($spreadsheetId, $dayTitle);

        if ($dayTasks === []) {
            return;
        }

        $monthSheetId = collect($this->sheetProperties($spreadsheetId))
            ->first(fn (array $properties) => trim($properties['title']) === $monthTitle)['sheetId']
            ?? $this->createMonthSheet($spreadsheetId, $monthTitle, $reportDate);

        $grid = $this->readGrid($spreadsheetId, $monthTitle);
        $serial = $this->excelSerial($reportDate);
        $dateColumn = $this->findDateColumn($grid[0] ?? [], $serial);

        if ($dateColumn === null) {
            $this->insertDateColumn($spreadsheetId, $monthSheetId, $monthTitle, $grid[0] ?? [], $serial);
            $grid = $this->readGrid($spreadsheetId, $monthTitle);
            $dateColumn = $this->findDateColumn($grid[0] ?? [], $serial)
                ?? throw new RuntimeException("Не вдалося додати колонку {$reportDate->format('d.m.Y')} в «{$monthTitle}».");
        }

        $totalsRow = $this->findTotalsRow($grid);
        [$existingRows, $freeRows] = $this->monthTaskRows($grid, $totalsRow);

        $newTaskCount = count(array_diff_key($dayTasks, $existingRows));
        $missingRows = $newTaskCount - count($freeRows);

        if ($missingRows > 0) {
            // Вільних рядків між тасками і підсумком не лишилось — розсуваємо.
            $this->request()
                ->post(self::SHEETS_API."/{$spreadsheetId}:batchUpdate", ['requests' => [[
                    'insertDimension' => [
                        'range' => [
                            'sheetId' => $monthSheetId,
                            'dimension' => 'ROWS',
                            'startIndex' => $totalsRow - 1,
                            'endIndex' => $totalsRow - 1 + $missingRows,
                        ],
                        'inheritFromBefore' => true,
                    ],
                ]]])
                ->throw();

            for ($i = 0; $i < $missingRows; $i++) {
                $freeRows[] = $totalsRow + $i;
            }
        }

        $header = $grid[0] ?? [];
        $dateColumnLetter = $this->columnLetter($dateColumn);
        $lastDateColumnLetter = $this->columnLetter($this->lastDateColumn($header, $dateColumn));
        $spentColumn = $this->findSpentColumn($header);

        // Колонка дня повністю віддзеркалює денну вкладку: при перегенерації
        // звіту старі формули дня не повинні лишатися.
        $this->request()
            ->post(self::SHEETS_API."/{$spreadsheetId}/values/".rawurlencode("'{$monthTitle}'!{$dateColumnLetter}2:{$dateColumnLetter}".($totalsRow - 1)).':clear', (object) [])
            ->throw();

        $data = [];

        foreach ($dayTasks as $taskName => $dayRow) {
            $row = $existingRows[$taskName] ?? null;

            if ($row === null) {
                $row = array_shift($freeRows);
                $existingRows[$taskName] = $row;
                $nameColumnLetter = $this->columnLetter(self::MONTH_TASK_NAME_COLUMN_INDEX);
                $data[] = [
                    'range' => "'{$monthTitle}'!{$nameColumnLetter}{$row}",
                    'values' => [[$taskName]],
                ];

                if ($spentColumn !== null) {
                    $spentColumnLetter = $this->columnLetter($spentColumn);
                    $data[] = [
                        'range' => "'{$monthTitle}'!{$spentColumnLetter}{$row}",
                        'values' => [["=SUM(D{$row}:{$lastDateColumnLetter}{$row})"]],
                    ];
                }
            }

            $data[] = [
                'range' => "'{$monthTitle}'!{$dateColumnLetter}{$row}",
                'values' => [["='{$dayTitle}'!".self::DAY_TASK_TIME_COLUMN.$dayRow]],
            ];
        }

        $this->request()
            ->post(self::SHEETS_API."/{$spreadsheetId}/values:batchUpdate", [
                'valueInputOption' => 'USER_ENTERED',
                'data' => $data,
            ])
            ->throw();
    }

    /**
     * Таски з таблиці денної вкладки: назва → номер рядка (для формул часу).
     *
     * @return array<string, int>
     */
    private function dayTaskRows(string $spreadsheetId, string $dayTitle): array
    {
        $nameColumn = self::DAY_TASK_NAME_COLUMN;
        $firstRow = self::DAY_TASK_FIRST_ROW;

        $rows = $this->request()
            ->get(self::SHEETS_API."/{$spreadsheetId}/values/".rawurlencode("'{$dayTitle}'!{$nameColumn}{$firstRow}:{$nameColumn}".($firstRow + 60)))
            ->throw()
            ->json('values') ?? [];

        $tasks = [];

        foreach ($rows as $offset => $row) {
            $name = trim((string) ($row[0] ?? ''));

            // Таски йдуть підряд з першого рядка таблиці; порожня клітинка або
            // підсумок — кінець списку. Без зупинки на порожньому рядку скан
            // доходив би до блоку офлайн-активностей нижче (він ділить колонку
            // з назвами тасок) і тягнув би його часи в місячний аркуш, коли
            // день згенеровано без тасок трекера і рядка «Час разом» немає.
            if ($name === '' || $name === 'Час разом') {
                break;
            }

            $tasks[$name] = $firstRow + $offset;
        }

        return $tasks;
    }

    /**
     * Порожній аркуш місяця у форматі наявних: заголовок з робочими днями,
     * колонка «Витрачений час на задачу», рядок підсумків.
     *
     * @return int sheetId створеного аркуша
     */
    private function createMonthSheet(string $spreadsheetId, string $title, CarbonImmutable $reportDate): int
    {
        $weekdays = [];
        for ($day = $reportDate->startOfMonth(); $day->month === $reportDate->month; $day = $day->addDay()) {
            if ($day->isWeekday()) {
                $weekdays[] = $this->excelSerial($day);
            }
        }

        $firstDateColumn = self::MONTH_FIRST_DATE_COLUMN_INDEX;
        $spentColumn = $firstDateColumn + count($weekdays);
        $totalsRow = self::MONTH_TOTALS_ROW;

        // Позиція аркуша — за хронологією, після денних вкладок свого місяця.
        $insertIndex = null;
        $year = $reportDate->year;
        $monthKey = $reportDate->endOfMonth();
        foreach ($this->sheetProperties($spreadsheetId) as $properties) {
            $key = $this->sheetSortKey($properties['title'], $year);
            if ($key && $key->greaterThan($monthKey)) {
                $insertIndex = $properties['index'];
                break;
            }
        }

        $sheetId = $this->request()
            ->post(self::SHEETS_API."/{$spreadsheetId}:batchUpdate", ['requests' => [[
                'addSheet' => [
                    'properties' => [
                        'title' => $title,
                        'gridProperties' => ['rowCount' => $totalsRow + 20, 'columnCount' => $spentColumn + 2],
                    ] + ($insertIndex !== null ? ['index' => $insertIndex] : []),
                ],
            ]]])
            ->throw()
            ->json('replies.0.addSheet.properties.sheetId');

        $header = array_merge(
            ['№', 'Посилання на задачу', 'Задача'],
            $weekdays,
            ['Витрачений час на задачу'],
        );

        $totals = ['Відпрацьований час за день/за місяць', '', ''];
        foreach ($weekdays as $offset => $serial) {
            $letter = $this->columnLetter($firstDateColumn + $offset);
            $totals[] = "=SUM({$letter}2:{$letter}".($totalsRow - 1).')';
        }

        // Загальний підсумок місяця в колонці «Витрачений час на задачу».
        $spentLetter = $this->columnLetter($spentColumn);
        $totals[] = "=SUM({$spentLetter}2:{$spentLetter}".($totalsRow - 1).')';

        $this->request()
            ->post(self::SHEETS_API."/{$spreadsheetId}/values:batchUpdate", [
                'valueInputOption' => 'USER_ENTERED',
                'data' => [
                    ['range' => "'{$title}'!A1", 'values' => [$header]],
                    ['range' => "'{$title}'!A{$totalsRow}", 'values' => [$totals]],
                ],
            ])
            ->throw();

        // Заливки як у наявних місячних аркушах: шапка і рядок підсумків —
        // жовті жирні, клітинка загального підсумку місяця — помаранчева.
        $yellow = ['red' => 1, 'green' => 1, 'blue' => 0];
        $orange = ['red' => 1, 'green' => 0.6, 'blue' => 0];

        $this->request()
            ->post(self::SHEETS_API."/{$spreadsheetId}:batchUpdate", ['requests' => [
                // Шапка — жовта, жирна (включно з колонкою «Витрачений час»).
                ['repeatCell' => [
                    'range' => ['sheetId' => $sheetId, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => $spentColumn + 1],
                    'cell' => ['userEnteredFormat' => [
                        'backgroundColor' => $yellow,
                        'textFormat' => ['bold' => true],
                    ]],
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat.bold)',
                ]],
                // Дати в заголовку — dd.mm.yyyy.
                ['repeatCell' => [
                    'range' => ['sheetId' => $sheetId, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => $firstDateColumn, 'endColumnIndex' => $spentColumn],
                    'cell' => ['userEnteredFormat' => [
                        'numberFormat' => ['type' => 'DATE', 'pattern' => 'dd.mm.yyyy'],
                    ]],
                    'fields' => 'userEnteredFormat.numberFormat',
                ]],
                // Час тасок і підсумків — тривалість (може перевищувати добу).
                ['repeatCell' => [
                    'range' => ['sheetId' => $sheetId, 'startRowIndex' => 1, 'endRowIndex' => $totalsRow, 'startColumnIndex' => $firstDateColumn, 'endColumnIndex' => $spentColumn + 1],
                    'cell' => ['userEnteredFormat' => ['numberFormat' => ['type' => 'TIME', 'pattern' => '[h]:mm:ss']]],
                    'fields' => 'userEnteredFormat.numberFormat',
                ]],
                // Рядок підсумків — жовтий жирний, загальний підсумок — помаранчевий.
                ['repeatCell' => [
                    'range' => ['sheetId' => $sheetId, 'startRowIndex' => $totalsRow - 1, 'endRowIndex' => $totalsRow, 'startColumnIndex' => 0, 'endColumnIndex' => $spentColumn],
                    'cell' => ['userEnteredFormat' => [
                        'backgroundColor' => $yellow,
                        'textFormat' => ['bold' => true],
                    ]],
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat.bold)',
                ]],
                ['repeatCell' => [
                    'range' => ['sheetId' => $sheetId, 'startRowIndex' => $totalsRow - 1, 'endRowIndex' => $totalsRow, 'startColumnIndex' => $spentColumn, 'endColumnIndex' => $spentColumn + 1],
                    'cell' => ['userEnteredFormat' => ['backgroundColor' => $orange]],
                    'fields' => 'userEnteredFormat.backgroundColor',
                ]],
                ['mergeCells' => [
                    'range' => ['sheetId' => $sheetId, 'startRowIndex' => $totalsRow - 1, 'endRowIndex' => $totalsRow, 'startColumnIndex' => 0, 'endColumnIndex' => $firstDateColumn],
                    'mergeType' => 'MERGE_ALL',
                ]],
                // Ширини колонок як у наявній таблиці: «Задача» — 599 px, решта — стандартні 100.
                ['updateDimensionProperties' => [
                    'range' => ['sheetId' => $sheetId, 'dimension' => 'COLUMNS', 'startIndex' => self::MONTH_TASK_NAME_COLUMN_INDEX, 'endIndex' => self::MONTH_TASK_NAME_COLUMN_INDEX + 1],
                    'properties' => ['pixelSize' => 599],
                    'fields' => 'pixelSize',
                ]],
            ]])
            ->throw();

        return $sheetId;
    }

    /**
     * Вставляє колонку дати, зберігаючи хронологічний порядок заголовка
     * (потрібно для дат поза робочими днями або нового місяця).
     */
    private function insertDateColumn(string $spreadsheetId, int $sheetId, string $title, array $header, int $serial): void
    {
        $insertAt = null;
        $lastDateColumn = null;

        foreach ($header as $index => $value) {
            if ($index < self::MONTH_FIRST_DATE_COLUMN_INDEX || ! is_numeric($value)) {
                continue;
            }

            if ((int) round((float) $value) > $serial) {
                $insertAt = $index;
                break;
            }

            $lastDateColumn = $index;
        }

        $insertAt ??= $lastDateColumn !== null ? $lastDateColumn + 1 : self::MONTH_FIRST_DATE_COLUMN_INDEX;

        $this->request()
            ->post(self::SHEETS_API."/{$spreadsheetId}:batchUpdate", ['requests' => [[
                'insertDimension' => [
                    'range' => [
                        'sheetId' => $sheetId,
                        'dimension' => 'COLUMNS',
                        'startIndex' => $insertAt,
                        'endIndex' => $insertAt + 1,
                    ],
                    'inheritFromBefore' => $insertAt > self::MONTH_FIRST_DATE_COLUMN_INDEX,
                ],
            ]]])
            ->throw();

        // Запис значення в діапазон — це values.update, тобто PUT: на POST за
        // цим URL Google відповідає HTML-сторінкою 400, а не помилкою API.
        $letter = $this->columnLetter($insertAt);
        $this->request()
            ->put(self::SHEETS_API."/{$spreadsheetId}/values/".rawurlencode("'{$title}'!{$letter}1").'?valueInputOption=USER_ENTERED', [
                'values' => [[$serial]],
            ])
            ->throw();
    }

    /**
     * @return array{0: array<string, int>, 1: array<int, int>} [назва таски → рядок, вільні рядки]
     */
    private function monthTaskRows(array $grid, int $totalsRow): array
    {
        $existing = [];
        $free = [];

        for ($row = 2; $row < $totalsRow; $row++) {
            $name = trim((string) ($grid[$row - 1][self::MONTH_TASK_NAME_COLUMN_INDEX] ?? ''));

            if ($name === '') {
                $free[] = $row;
            } else {
                $existing[$name] = $row;
            }
        }

        return [$existing, $free];
    }

    private function findTotalsRow(array $grid): int
    {
        foreach ($grid as $index => $row) {
            $label = is_string($row[0] ?? null) ? mb_strtolower($row[0]) : '';

            if (str_contains($label, 'час за день')) {
                return $index + 1;
            }
        }

        return self::MONTH_TOTALS_ROW;
    }

    private function findDateColumn(array $header, int $serial): ?int
    {
        foreach ($header as $index => $value) {
            if ($index >= self::MONTH_FIRST_DATE_COLUMN_INDEX && is_numeric($value) && (int) round((float) $value) === $serial) {
                return $index;
            }
        }

        return null;
    }

    private function lastDateColumn(array $header, int $fallback): int
    {
        $last = $fallback;

        foreach ($header as $index => $value) {
            if ($index >= self::MONTH_FIRST_DATE_COLUMN_INDEX && is_numeric($value)) {
                $last = max($last, $index);
            }
        }

        return $last;
    }

    private function findSpentColumn(array $header): ?int
    {
        foreach ($header as $index => $value) {
            if (is_string($value) && str_contains(mb_strtolower($value), 'витрачений')) {
                return $index;
            }
        }

        return null;
    }

    private function readGrid(string $spreadsheetId, string $title): array
    {
        return $this->request()
            ->get(self::SHEETS_API."/{$spreadsheetId}/values/".rawurlencode("'{$title}'!A1:AZ".(self::MONTH_TOTALS_ROW + 60)), [
                'valueRenderOption' => 'UNFORMATTED_VALUE',
            ])
            ->throw()
            ->json('values') ?? [];
    }

    /**
     * Серійний номер дати в Excel/Sheets (днів від 30.12.1899) — так
     * зберігаються дати в заголовку місячного аркуша.
     */
    private function excelSerial(CarbonImmutable $date): int
    {
        return (int) CarbonImmutable::create(1899, 12, 30)->diffInDays($date->startOfDay());
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $index++;

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letter = chr(65 + $remainder).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    /**
     * Завантажує Excel на Drive з конвертацією в Google Sheets (resumable upload,
     * бо звичайний multipart вимагає multipart/related, якого немає в Http-клієнті).
     *
     * @return string ID створеного файлу на Drive
     */
    private function convertExcelToTempSpreadsheet(string $excelPath, string $name): string
    {
        $contentType = str_ends_with(strtolower($excelPath), '.xlsx')
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/vnd.ms-excel';

        $uploadUrl = $this->request()
            ->withHeaders(['X-Upload-Content-Type' => $contentType])
            ->post(self::DRIVE_UPLOAD_API.'?uploadType=resumable', [
                'name' => "yaware-tmp-{$name}",
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
            ])
            ->throw()
            ->header('Location');

        if (! $uploadUrl) {
            throw new RuntimeException('Drive не повернув URL сесії завантаження.');
        }

        return Http::withToken($this->accessToken())
            ->withBody(file_get_contents($excelPath), $contentType)
            ->send('PUT', $uploadUrl)
            ->throw()
            ->json('id') ?? throw new RuntimeException('Drive не повернув ID сконвертованого файлу.');
    }

    /**
     * Перейменовує скопійований аркуш на дату звіту і ставить його на місце
     * серед вкладок-дат; стару вкладку з такою ж назвою видаляє. Разом з нею
     * прибирає копії, що лишились від невдалих спроб (див. $knownSheetIds).
     *
     * @param  array<int, int>  $knownSheetIds  вкладки, що були в таблиці до вивантаження
     * @param  string  $sourceTitle  назва аркуша-джерела: копія Google завжди містить її в назві
     */
    private function placeCopiedSheet(
        string $spreadsheetId,
        int $copiedSheetId,
        string $title,
        CarbonImmutable $reportDate,
        array $knownSheetIds = [],
        string $sourceTitle = '',
    ): void {
        $requests = [];
        $insertIndex = null;
        $year = $reportDate->year;

        foreach ($this->sheetProperties($spreadsheetId) as $properties) {
            if ($properties['sheetId'] === $copiedSheetId) {
                continue;
            }

            if (trim($properties['title']) === $title) {
                $requests[] = ['deleteSheet' => ['sheetId' => $properties['sheetId']]];
                $insertIndex ??= $properties['index'];

                continue;
            }

            if ($this->isStrayCopy($properties, $knownSheetIds, $sourceTitle)) {
                $requests[] = ['deleteSheet' => ['sheetId' => $properties['sheetId']]];

                continue;
            }

            $key = $this->sheetSortKey($properties['title'], $year);

            if ($key && $insertIndex === null && $key->greaterThan($reportDate->startOfDay())) {
                $insertIndex = $properties['index'];
            }
        }

        $requests[] = [
            'updateSheetProperties' => [
                'properties' => ['sheetId' => $copiedSheetId, 'title' => $title]
                    + ($insertIndex !== null ? ['index' => $insertIndex] : []),
                'fields' => $insertIndex !== null ? 'title,index' : 'title',
            ],
        ];

        $this->request()
            ->post(self::SHEETS_API."/{$spreadsheetId}:batchUpdate", ['requests' => $requests])
            ->throw();
    }

    /**
     * Слід невдалої спроби: вкладки не було до вивантаження, це не наша щойно
     * скопійована вкладка (перевірено вище) і назву їй дав Google при
     * копіюванні («Копія <аркуш-джерело>» — префікс залежить від мови акаунта,
     * тому шукаємо саме назву джерела).
     *
     * @param  array{sheetId: int, index: int, title: string}  $properties
     * @param  array<int, int>  $knownSheetIds
     */
    private function isStrayCopy(array $properties, array $knownSheetIds, string $sourceTitle): bool
    {
        return $sourceTitle !== ''
            && ! in_array($properties['sheetId'], $knownSheetIds, true)
            && str_contains(trim($properties['title']), $sourceTitle);
    }

    /**
     * Тимчасова копія на Drive потрібна лише на час конвертації. Її видалення
     * не має зривати вже успішне вивантаження: у найгіршому разі на Drive
     * лишиться файл-сирота, і про це буде запис у журналі.
     */
    private function deleteTempFile(string $tempFileId): void
    {
        try {
            $this->request()->delete(self::DRIVE_API."/{$tempFileId}");
        } catch (Throwable $exception) {
            Log::warning("Тимчасовий файл Drive {$tempFileId} не видалено: {$exception->getMessage()}");
        }
    }

    /**
     * Повторює операцію, якщо Google відповів тимчасовою помилкою (500
     * «Internal Error», 503, 429 — вони трапляються без причини з нашого боку
     * і зникають за секунди).
     *
     * Повторюється саме вся операція, а не окремий HTTP-запит: кожна спроба
     * наново перечитує стан таблиці, тому повтор не додає другу колонку дати,
     * другий аркуш місяця чи зайві рядки — на відміну від сліпого ретраю
     * запиту insertDimension/addSheet, який не є ідемпотентним.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $operation
     * @return TResult
     */
    private function withRetries(string $description, callable $operation): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $operation();
            } catch (Throwable $exception) {
                if ($attempt >= self::TRANSIENT_ATTEMPTS || ! $this->isTransient($exception)) {
                    throw $this->describeFailure($description, $attempt, $exception);
                }

                $pause = self::TRANSIENT_BACKOFF_SECONDS * 2 ** ($attempt - 1);

                Log::warning(
                    "Google: {$description} — спроба {$attempt} невдала ({$this->failureDetails($exception)}), "
                    ."повтор через {$pause} с."
                );

                Sleep::for($pause)->seconds();
            }
        }
    }

    /**
     * Тимчасова помилка — та, що минає сама: обрив зʼєднання або 5xx/429 від
     * Google. Помилки прав, ліміту клітинок чи невалідного запиту повторювати
     * марно — вони повернуться такими ж.
     */
    private function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && in_array($exception->response->status(), self::TRANSIENT_STATUSES, true);
    }

    /**
     * Помилка з контекстом: що саме робили, скільки разів пробували і що
     * відповів Google. Без цього в журналі лишається лише «HTTP request
     * returned status code 500» — за ним не видно ні кроку, ні endpoint.
     */
    private function describeFailure(string $description, int $attempts, Throwable $exception): RuntimeException
    {
        $tries = $attempts > 1 ? " після {$attempts} спроб" : '';

        return new RuntimeException(
            "Google: {$description} не вдалось{$tries} — {$this->failureDetails($exception)}",
            0,
            $exception,
        );
    }

    private function failureDetails(Throwable $exception): string
    {
        if (! $exception instanceof RequestException) {
            return $exception->getMessage();
        }

        $response = $exception->response;
        $message = $response->json('error.message');
        $endpoint = $this->endpointOf($response);

        return trim(sprintf(
            'HTTP %d%s%s',
            $response->status(),
            is_string($message) && $message !== '' ? " {$message}" : '',
            $endpoint ? " ({$endpoint})" : '',
        ));
    }

    /**
     * Шлях запиту, на якому впала операція, — щоб з тексту помилки одразу було
     * видно крок (copyTo, batchUpdate, upload тощо).
     */
    private function endpointOf(Response $response): ?string
    {
        try {
            $uri = $response->effectiveUri();
        } catch (Throwable) {
            return null;
        }

        return $uri ? urldecode($uri->getPath()) : null;
    }

    /**
     * Хронологічний ключ вкладки: «dd.mm.yyyy» — сама дата, місячна підсумкова
     * («Час за місяць 07») — кінець місяця, щоб нові дати вставлялись перед нею.
     * Рік місячної вкладки береться з останньої вкладки-дати перед нею.
     */
    private function sheetSortKey(string $title, int &$year): ?CarbonImmutable
    {
        $title = trim($title);

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $title, $matches)) {
            $year = (int) $matches[3];

            return CarbonImmutable::create($year, (int) $matches[2], (int) $matches[1]);
        }

        if (preg_match('/(\d{1,2})\s*$/', $title, $matches) && (int) $matches[1] >= 1 && (int) $matches[1] <= 12) {
            return CarbonImmutable::create($year, (int) $matches[1])->endOfMonth();
        }

        return null;
    }

    /**
     * @return array<int, array{sheetId: int, index: int, title: string}>
     */
    private function sheetProperties(string $spreadsheetId): array
    {
        $sheets = $this->request()
            ->get(self::SHEETS_API."/{$spreadsheetId}", ['fields' => 'sheets.properties(sheetId,index,title)'])
            ->throw()
            ->json('sheets') ?? [];

        return array_column($sheets, 'properties');
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->accessToken())->acceptJson();
    }

    private function accessToken(): string
    {
        return Cache::remember('google.oauth.access_token', now()->addMinutes(50), function () {
            $refreshToken = $this->refreshToken()
                ?? throw new RuntimeException('Google-акаунт не підключено — відкрийте /google/auth і авторизуйтесь.');

            return Http::asForm()
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => config('services.google.client_id'),
                    'client_secret' => config('services.google.client_secret'),
                ])
                ->throw()
                ->json('access_token');
        });
    }

    private function refreshToken(): ?string
    {
        $tokenPath = config('services.google.token_path');

        if (! $tokenPath || ! is_file($tokenPath)) {
            return null;
        }

        return json_decode((string) file_get_contents($tokenPath), true)['refresh_token'] ?? null;
    }
}
