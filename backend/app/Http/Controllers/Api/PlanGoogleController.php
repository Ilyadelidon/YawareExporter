<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExportPlansToGoogle;
use App\Models\AppSetting;
use App\Services\GoogleSheetsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Спільна Google Таблиця планів: адміністратор підключає наявну таблицю
 * посиланням, а експорт вивантажує в неї всі проекти, кожен окремим аркушем.
 */
class PlanGoogleController extends Controller
{
    public function show(GoogleSheetsService $sheets): JsonResponse
    {
        $spreadsheetId = AppSetting::get(AppSetting::PLANS_SPREADSHEET_ID);
        $connected = $sheets->hasGoogleAccount();

        return response()->json(['data' => [
            'account_connected' => $connected,
            // Кому надати доступ редактора в таблиці перед підключенням.
            'account_email' => $connected ? $sheets->accountEmail() : null,
            'spreadsheet_url' => $spreadsheetId ? GoogleSheetsService::spreadsheetUrl($spreadsheetId) : null,
            'spreadsheet_title' => $connected && $spreadsheetId ? $sheets->spreadsheetTitle($spreadsheetId) : null,
            'export' => ExportPlansToGoogle::status(),
        ]]);
    }

    /**
     * Підключає таблицю за посиланням або ID. Вміст не змінюється — аркуші
     * проектів зʼявляться при експорті. Перевіряється лише, що таблицю видно:
     * пробний запис у велику «холодну» таблицю йде хвилинами, тож відсутність
     * права редагування покаже вже сам експорт.
     */
    public function link(Request $request, GoogleSheetsService $sheets): JsonResponse
    {
        abort_unless($sheets->hasGoogleAccount(), 409, 'Google-акаунт не підключено — підключіть його в налаштуваннях.');

        $validated = $request->validate([
            'spreadsheet' => ['required', 'string', 'max:2048'],
        ]);

        $spreadsheetId = GoogleSheetsService::extractSpreadsheetId($validated['spreadsheet']);

        abort_if($spreadsheetId === null, 422, 'Не схоже на посилання на Google Таблицю — вставте адресу виду docs.google.com/spreadsheets/d/…');

        try {
            $title = $sheets->spreadsheetTitle($spreadsheetId);
        } catch (Throwable $exception) {
            Log::warning("Не вдалося підключити таблицю планів {$spreadsheetId}: {$exception->getMessage()}");

            return response()->json([
                'message' => 'Google не відповів на перевірку таблиці: '.mb_substr($exception->getMessage(), 0, 300),
            ], 502);
        }

        if ($title === null) {
            $account = $sheets->accountEmail() ?? 'застосунку';

            abort(422, "Таблиця недоступна. Перевірте посилання і надайте в ній доступ редактора акаунту {$account}.");
        }

        AppSetting::put(AppSetting::PLANS_SPREADSHEET_ID, $spreadsheetId);

        return response()->json(['data' => [
            'spreadsheet_url' => GoogleSheetsService::spreadsheetUrl($spreadsheetId),
            'spreadsheet_title' => $title,
        ]]);
    }

    /**
     * Відвʼязує таблицю; сам файл і вже вивантажені аркуші лишаються.
     */
    public function unlink(): JsonResponse
    {
        AppSetting::put(AppSetting::PLANS_SPREADSHEET_ID, null);

        return response()->json(['ok' => true]);
    }

    /**
     * Ставить вивантаження в чергу; результат сторінка бачить у GET /plans/google.
     */
    public function export(GoogleSheetsService $sheets): JsonResponse
    {
        abort_unless($sheets->hasGoogleAccount(), 409, 'Google-акаунт не підключено — підключіть його в налаштуваннях.');

        $spreadsheetId = AppSetting::get(AppSetting::PLANS_SPREADSHEET_ID);

        abort_if($spreadsheetId === null, 409, 'Таблицю для планів не підключено — вставте посилання на неї.');

        // Повторне натискання під час експорту не ставить другу копію:
        // джоба унікальна, поки попередня не завершилась.
        ExportPlansToGoogle::markQueued($spreadsheetId);
        ExportPlansToGoogle::dispatch($spreadsheetId);

        return response()->json(['data' => ['export' => ExportPlansToGoogle::status()]], 202);
    }
}
