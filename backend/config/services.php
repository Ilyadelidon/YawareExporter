<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        // OAuth-клієнт Google Cloud (тип «Web application», redirect http://localhost:8000/google/callback).
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
        'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
        // Refresh-токен користувача зберігається після одноразової авторизації через /google/auth.
        'token_path' => storage_path('app/google/oauth-token.json'),
    ],

    'telegram' => [
        // Бот для сповіщень працівникам (створюється через @BotFather).
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        // Username бота без @ — для deep-link підключення t.me/<bot>?start=<код>.
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        // Довільний секрет: Telegram шле його в заголовку кожного вебхука.
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    'trello' => [
        'key' => env('TRELLO_API_KEY'),
        // Дошка-шаблон: нові дошки користувачів клонують її списки й мітки (без карток).
        'template_board_id' => env('TRELLO_TEMPLATE_BOARD_ID'),
        // Часовий пояс, у якому дата звіту трактується як «день» для Start/Due карток.
        'timezone' => env('TRELLO_TIMEZONE', 'Europe/Kyiv'),
    ],

];
