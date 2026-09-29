<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Особисті Telegram-сповіщення працівника. Сам Start у боті обробляє
 * [[TelegramWebhookController]].
 */
class TelegramController extends Controller
{
    public function status(Request $request, TelegramService $telegram): JsonResponse
    {
        return response()->json([
            'configured' => $telegram->isConfigured(),
            'connected' => $request->user()->hasTelegramConnected(),
        ]);
    }

    /**
     * Видає одноразовий deep-link на бота.
     */
    public function link(Request $request, TelegramService $telegram): JsonResponse
    {
        abort_unless($telegram->isConfigured(), 503, 'Telegram-бот ще не налаштований адміністратором.');

        return response()->json(['url' => $telegram->issueLink($request->user()->id)]);
    }

    public function unlink(Request $request): JsonResponse
    {
        $request->user()->unlinkTelegram();

        return response()->json(['message' => 'Telegram відключено — сповіщення більше не надсилаються.']);
    }
}
