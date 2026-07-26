<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleSheetsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleSpreadsheetController extends Controller
{
    /**
     * Стан інтеграції: чи підключений Google-акаунт (адмінський OAuth)
     * і чи створена персональна таблиця користувача.
     */
    public function status(Request $request, GoogleSheetsService $sheets): JsonResponse
    {
        $user = $request->user();
        $title = null;

        if ($sheets->hasGoogleAccount() && $user->google_spreadsheet_id) {
            try {
                $title = $sheets->spreadsheetTitle($user->google_spreadsheet_id);
            } catch (Throwable) {
                // Таблицю могли видалити — статус все одно віддаємо.
            }
        }

        return response()->json([
            'account_connected' => $sheets->hasGoogleAccount(),
            // Кому давати доступ редактора при підключенні власної таблиці.
            'account_email' => $sheets->hasGoogleAccount() ? $sheets->accountEmail() : null,
            'spreadsheet_id' => $user->google_spreadsheet_id,
            'spreadsheet_url' => $user->google_spreadsheet_id
                ? GoogleSheetsService::spreadsheetUrl($user->google_spreadsheet_id)
                : null,
            'spreadsheet_title' => $title,
        ]);
    }

    /**
     * Створює персональну таблицю користувача і дає йому доступ редактора.
     */
    public function store(Request $request, GoogleSheetsService $sheets): JsonResponse
    {
        $user = $request->user();

        if (! $sheets->hasGoogleAccount()) {
            return response()->json([
                'message' => 'Google-акаунт не підключено — попросіть адміністратора авторизуватися на /google/auth.',
            ], 409);
        }

        if ($user->google_spreadsheet_id) {
            return response()->json([
                'message' => 'Персональна таблиця вже створена. Спершу відв\'яжіть поточну.',
            ], 409);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            // Google-email користувача може відрізнятися від логіна в системі.
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        try {
            $spreadsheet = $sheets->createUserSpreadsheet(
                trim($validated['name'] ?? '') ?: "Звіти — {$user->name}",
                $validated['email'] ?? $user->email,
            );
        } catch (Throwable $exception) {
            Log::warning("Не вдалося створити Google Таблицю для користувача #{$user->id}: {$exception->getMessage()}");

            return response()->json([
                'message' => 'Google не дозволив створити таблицю: '.mb_substr($exception->getMessage(), 0, 300),
            ], 502);
        }

        $user->forceFill(['google_spreadsheet_id' => $spreadsheet['id']])->save();

        return response()->json([
            'message' => 'Таблицю створено — посилання надіслано на email.',
            'spreadsheet_id' => $spreadsheet['id'],
            'spreadsheet_url' => $spreadsheet['url'],
        ], 201);
    }

    /**
     * Підключає вже наявну Google Таблицю користувача: приймає посилання
     * або ID, перевіряє, що підключеному акаунту надано доступ редактора.
     * Вміст таблиці не змінюється — вкладки днів і місячний аркуш
     * створюються при генерації звітів.
     */
    public function link(Request $request, GoogleSheetsService $sheets): JsonResponse
    {
        $user = $request->user();

        if (! $sheets->hasGoogleAccount()) {
            return response()->json([
                'message' => 'Google-акаунт не підключено — попросіть адміністратора авторизуватися на /google/auth.',
            ], 409);
        }

        if ($user->google_spreadsheet_id) {
            return response()->json([
                'message' => 'Таблиця вже підключена. Спершу відв\'яжіть поточну.',
            ], 409);
        }

        $validated = $request->validate([
            'spreadsheet' => ['required', 'string', 'max:2048'],
        ]);

        $spreadsheetId = GoogleSheetsService::extractSpreadsheetId($validated['spreadsheet']);

        if ($spreadsheetId === null) {
            return response()->json([
                'message' => 'Не схоже на посилання на Google Таблицю — вставте адресу виду docs.google.com/spreadsheets/d/…',
            ], 422);
        }

        try {
            $title = $sheets->verifyEditableSpreadsheet($spreadsheetId);
        } catch (\RuntimeException $exception) {
            // Людське пояснення з перевірки доступу — віддаємо як є.
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Log::warning("Не вдалося підключити Google Таблицю {$spreadsheetId} для користувача #{$user->id}: {$exception->getMessage()}");

            return response()->json([
                'message' => 'Google не відповів на перевірку таблиці: '.mb_substr($exception->getMessage(), 0, 300),
            ], 502);
        }

        $user->forceFill(['google_spreadsheet_id' => $spreadsheetId])->save();

        return response()->json([
            'message' => "Таблицю «{$title}» підключено — звіти вивантажуватимуться в неї.",
            'spreadsheet_id' => $spreadsheetId,
            'spreadsheet_url' => GoogleSheetsService::spreadsheetUrl($spreadsheetId),
        ]);
    }

    /**
     * Відв'язує персональну таблицю (сам файл на Drive не видаляється —
     * у ньому вже можуть бути звіти). Далі звіти не вивантажуються,
     * поки користувач не створить нову таблицю.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->forceFill(['google_spreadsheet_id' => null])->save();

        return response()->json(['message' => 'Персональну таблицю відв\'язано.']);
    }
}
