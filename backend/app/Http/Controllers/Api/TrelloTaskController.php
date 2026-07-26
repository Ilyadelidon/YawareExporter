<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrelloService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TrelloTaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $trello = TrelloService::forUser($request->user());

        if (! $trello->isConfigured()) {
            return response()->json([
                'message' => 'Trello не підключено.',
                'not_connected' => true,
            ], 503);
        }

        try {
            $tasks = $trello->tasksForDate($validated['date']);
        } catch (RequestException $exception) {
            $status = $exception->response->status();

            return response()->json([
                'message' => in_array($status, [401, 403], true)
                    ? 'Trello відхилив токен — підключіться заново на сторінці інтеграцій.'
                    : "Не вдалося отримати дані з Trello (HTTP {$status}).",
            ], 502);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['data' => $tasks]);
    }
}
