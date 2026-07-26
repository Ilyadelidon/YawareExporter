<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\GoogleSpreadsheetController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\TelegramController;
use App\Http\Controllers\Api\TimesheetController;
use App\Http\Controllers\Api\TrelloAccountController;
use App\Http\Controllers\Api\TrelloTaskController;
use Illuminate\Support\Facades\Route;

// Жорсткий ліміт: невдалий логін ставить у чергу Playwright-перевірку в Yaware
// (до 2 хв браузерної сесії на спробу), тому перебір паролів ще й забиває чергу.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
// Полінг стану перевірки (фронтенд питає раз на ~3 с, поки джоба працює).
Route::get('/auth/login/pending/{checkId}', [AuthController::class, 'loginStatus'])
    ->whereUuid('checkId')
    ->middleware('throttle:30,1');

// Вебхук Telegram: без auth (його кличе Telegram), захищений секретом у заголовку.
Route::post('/telegram/webhook', [TelegramController::class, 'webhook'])->middleware('throttle:60,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/{report}', [ReportController::class, 'show']);
    Route::get('/reports/{report}/download', [ReportController::class, 'download']);
    Route::post('/reports', [ReportController::class, 'store']);

    Route::get('/trello/tasks', [TrelloTaskController::class, 'index']);

    Route::get('/trello/status', [TrelloAccountController::class, 'status']);
    Route::post('/trello/token', [TrelloAccountController::class, 'storeToken']);
    Route::delete('/trello/token', [TrelloAccountController::class, 'destroyToken']);
    Route::get('/trello/boards', [TrelloAccountController::class, 'boards']);
    Route::post('/trello/boards', [TrelloAccountController::class, 'createBoard']);
    Route::put('/trello/board', [TrelloAccountController::class, 'selectBoard']);

    Route::get('/telegram/status', [TelegramController::class, 'status']);
    Route::post('/telegram/link', [TelegramController::class, 'link']);
    Route::delete('/telegram/link', [TelegramController::class, 'unlink']);

    Route::get('/google/status', [GoogleSpreadsheetController::class, 'status']);
    Route::post('/google/spreadsheet', [GoogleSpreadsheetController::class, 'store']);
    Route::post('/google/spreadsheet/link', [GoogleSpreadsheetController::class, 'link']);
    Route::delete('/google/spreadsheet', [GoogleSpreadsheetController::class, 'destroy']);

    Route::get('/stats', [StatsController::class, 'index']);
    Route::get('/stats/activities', [StatsController::class, 'activities']);

    Route::middleware('admin')->group(function () {
        Route::get('/timesheet', [TimesheetController::class, 'index']);
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::patch('/employees/{employee}', [EmployeeController::class, 'update']);
    });
});
