<?php

use App\Http\Controllers\Api\AiAnalysisController;
use App\Http\Controllers\Api\AiSettingsController;
use App\Http\Controllers\Api\AlertSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BitrixAccountController;
use App\Http\Controllers\Api\BitrixWorkspaceController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeMemoryController;
use App\Http\Controllers\Api\GoogleSpreadsheetController;
use App\Http\Controllers\Api\HintController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\OpsTelegramController;
use App\Http\Controllers\Api\PlanGoogleController;
use App\Http\Controllers\Api\PlanProjectController;
use App\Http\Controllers\Api\PlanSectionController;
use App\Http\Controllers\Api\PlanTaskController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TelegramController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\TimesheetController;
use App\Http\Controllers\Api\TrelloAccountController;
use Illuminate\Support\Facades\Route;

// Жорсткий ліміт: невдалий логін ставить у чергу Playwright-перевірку в Yaware
// (до 2 хв браузерної сесії на спробу), тому перебір паролів ще й забиває чергу.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
// Полінг стану перевірки (фронтенд питає раз на ~3 с, поки джоба працює).
Route::get('/auth/login/pending/{checkId}', [AuthController::class, 'loginStatus'])
    ->whereUuid('checkId')
    ->middleware('throttle:30,1');

// Вебхук Telegram: без auth (його кличе Telegram), захищений секретом у заголовку.
Route::post('/telegram/webhook', TelegramWebhookController::class)->middleware('throttle:60,1');

// Повернення з авторизації Бітрікса: браузер працівника приходить сюди без
// токена Sanctum, тож упізнаємо його за одноразовим state з посилання.
Route::get('/bitrix/oauth/callback', [BitrixAccountController::class, 'callback'])
    ->middleware('throttle:20,1');

// not-dismissed іде поруч із auth:sanctum, а не в групу 'api': там ще не
// відпрацював гард, і звільненого не було б з чим порівнювати.
Route::middleware(['auth:sanctum', 'not-dismissed'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/{report}', [ReportController::class, 'show']);
    Route::get('/reports/{report}/download', [ReportController::class, 'download']);
    Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:reports');

    // Таски за день з активного трекера користувача (Trello або Бітрікс24).
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::put('/tasks/provider', [TaskController::class, 'updateProvider']);

    Route::get('/trello/status', [TrelloAccountController::class, 'status']);
    Route::post('/trello/token', [TrelloAccountController::class, 'storeToken']);
    Route::delete('/trello/token', [TrelloAccountController::class, 'destroyToken']);
    Route::get('/trello/boards', [TrelloAccountController::class, 'boards']);
    Route::post('/trello/boards', [TrelloAccountController::class, 'createBoard']);
    Route::put('/trello/board', [TrelloAccountController::class, 'selectBoard']);

    // Портал Бітрікса один на команду: підключає його адміністратор, а
    // працівник лише починає власну авторизацію на цьому порталі.
    Route::get('/bitrix/status', [BitrixAccountController::class, 'status']);
    Route::post('/bitrix/oauth/start', [BitrixAccountController::class, 'startAuthorization']);
    Route::delete('/bitrix/user', [BitrixAccountController::class, 'destroy']);

    Route::get('/telegram/status', [TelegramController::class, 'status']);
    Route::post('/telegram/link', [TelegramController::class, 'link']);
    Route::delete('/telegram/link', [TelegramController::class, 'unlink']);

    Route::get('/google/status', [GoogleSpreadsheetController::class, 'status']);
    Route::post('/google/spreadsheet', [GoogleSpreadsheetController::class, 'store']);
    Route::post('/google/spreadsheet/link', [GoogleSpreadsheetController::class, 'link']);
    Route::delete('/google/spreadsheet', [GoogleSpreadsheetController::class, 'destroy']);

    // Готовність до звіту (трекер + таблиця) і готові посилання на підключені
    // сервіси — кнопки переходу в боковому меню.
    Route::get('/integrations/status', [IntegrationController::class, 'status']);
    Route::get('/integrations/links', [IntegrationController::class, 'links']);

    // Підказки працівнику в боковому меню: пропущені звіти й дні без задач у планах.
    Route::get('/hints', [HintController::class, 'index']);

    Route::get('/stats', [StatsController::class, 'index']);
    Route::get('/stats/activities', [StatsController::class, 'activities']);

    // «Плани»: працівник бачить проекти, де він учасник, і веде власні задачі;
    // керування проектами — нижче, в адмінській групі.
    Route::get('/plans/projects', [PlanProjectController::class, 'index']);
    Route::get('/plans/projects/{project}', [PlanProjectController::class, 'show']);
    Route::post('/plans/projects/{project}/sections', [PlanSectionController::class, 'store']);
    Route::post('/plans/projects/{project}/tasks', [PlanTaskController::class, 'store']);
    Route::patch('/plans/tasks/{task}', [PlanTaskController::class, 'update']);
    Route::delete('/plans/tasks/{task}', [PlanTaskController::class, 'destroy']);
    Route::put('/plans/tasks/{task}/days/{date}', [PlanTaskController::class, 'markDay']);
    Route::delete('/plans/tasks/{task}/days/{date}', [PlanTaskController::class, 'unmarkDay']);
    Route::put('/plans/tasks/{task}/current', [PlanTaskController::class, 'setCurrent']);
    Route::delete('/plans/tasks/{task}/current', [PlanTaskController::class, 'clearCurrent']);

    Route::middleware('admin')->group(function () {
        Route::post('/plans/projects', [PlanProjectController::class, 'store']);
        Route::patch('/plans/projects/{project}', [PlanProjectController::class, 'update']);
        Route::delete('/plans/projects/{project}', [PlanProjectController::class, 'destroy']);
        Route::put('/plans/projects/{project}/members', [PlanProjectController::class, 'updateMembers']);
        Route::patch('/plans/sections/{section}', [PlanSectionController::class, 'update']);
        Route::delete('/plans/sections/{section}', [PlanSectionController::class, 'destroy']);
        Route::get('/plans/google', [PlanGoogleController::class, 'show']);
        Route::put('/plans/google', [PlanGoogleController::class, 'link']);
        Route::delete('/plans/google', [PlanGoogleController::class, 'unlink']);
        Route::post('/plans/export', [PlanGoogleController::class, 'export'])->middleware('throttle:5,1');

        Route::get('/bitrix/workspace', [BitrixWorkspaceController::class, 'show']);
        Route::post('/bitrix/workspace', [BitrixWorkspaceController::class, 'store']);
        Route::delete('/bitrix/workspace', [BitrixWorkspaceController::class, 'destroy']);

        // AI-розбір дня бачить лише адміністратор.
        Route::get('/analysis', [AiAnalysisController::class, 'show']);
        Route::post('/analysis', [AiAnalysisController::class, 'store']);
        Route::get('/analysis/settings', [AiSettingsController::class, 'show']);
        Route::put('/analysis/settings', [AiSettingsController::class, 'update']);

        // Пошти, на які керівнику йдуть листи про критичні порушення.
        Route::get('/alerts/emails', [AlertSettingsController::class, 'show']);
        Route::put('/alerts/emails', [AlertSettingsController::class, 'update']);

        // Технічний Telegram адміністратора: ранковий підсумок і тривоги монітора.
        Route::get('/alerts/telegram', [OpsTelegramController::class, 'status']);
        Route::post('/alerts/telegram/link', [OpsTelegramController::class, 'link']);
        Route::delete('/alerts/telegram/{chat}', [OpsTelegramController::class, 'unlink']);
        Route::post('/alerts/telegram/{chat}/test', [OpsTelegramController::class, 'test'])->middleware('throttle:5,1');

        Route::get('/timesheet', [TimesheetController::class, 'index']);
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::patch('/employees/{employee}', [EmployeeController::class, 'update']);

        // Звільнення — окремою дією, а не полем у update: воно відкликає
        // токени й стирає креди, тож не має їхати разом із правкою посади.
        Route::post('/employees/{employee}/dismissal', [EmployeeController::class, 'dismiss']);
        Route::delete('/employees/{employee}/dismissal', [EmployeeController::class, 'reinstate']);

        // Пам'ять AI по працівнику: вердикти, які модель більше не перешукує.
        // scopeBindings: рядок чужого працівника за цією адресою — 404.
        Route::scopeBindings()->group(function () {
            Route::get('/employees/{employee}/memory', [EmployeeMemoryController::class, 'index']);
            Route::post('/employees/{employee}/memory', [EmployeeMemoryController::class, 'store']);
            Route::patch('/employees/{employee}/memory/{memory}', [EmployeeMemoryController::class, 'update']);
            Route::delete('/employees/{employee}/memory/{memory}', [EmployeeMemoryController::class, 'destroy']);
        });
    });
});
