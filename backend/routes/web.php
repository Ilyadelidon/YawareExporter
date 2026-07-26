<?php

use App\Services\GoogleSheetsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', function () {
    return view('welcome');
});

// Одноразове підключення Google-акаунта для вивантаження звітів у Google Таблицю.
// Відкривається вручну в браузері: http://localhost:8000/google/auth
Route::get('/google/auth', function (GoogleSheetsService $sheets) {
    if (! $sheets->hasClientCredentials()) {
        return response(
            'Спершу задайте GOOGLE_OAUTH_CLIENT_ID і GOOGLE_OAUTH_CLIENT_SECRET у backend/.env.',
            409,
        );
    }

    $state = Str::random(40);
    cache()->put('google.oauth.state', $state, now()->addMinutes(10));

    return redirect()->away($sheets->authorizationUrl(url('/google/callback'), $state));
});

Route::get('/google/callback', function (Request $request, GoogleSheetsService $sheets) {
    if ($request->query('state') !== cache()->pull('google.oauth.state')) {
        return response('Невалідний state — почніть заново з /google/auth.', 400);
    }

    if ($request->query('error') || ! $request->query('code')) {
        return response('Google відхилив авторизацію: '.($request->query('error') ?: 'code відсутній'), 400);
    }

    $sheets->storeAuthorizationCode($request->query('code'), url('/google/callback'));

    return response(
        'Google-акаунт підключено — звіти вивантажуватимуться в Google Таблицю. Цю вкладку можна закрити.',
        200,
    );
});
