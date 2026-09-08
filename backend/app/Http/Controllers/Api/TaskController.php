<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tasks\TaskProviders;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Таски за день з активного таск-трекера користувача — Trello або Бітрікс24.
 * Форма відповіді однакова для обох, тож фронтенд про різницю не знає.
 */
class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $provider = TaskProviders::forUser($request->user());

        if (! $provider->isConfigured()) {
            return response()->json([
                'message' => "{$provider->providerLabel()} не підключено.",
                'provider' => $provider->providerKey(),
                'not_connected' => true,
            ], 503);
        }

        try {
            $tasks = $provider->tasksForDate($validated['date']);
        } catch (RequestException $exception) {
            $status = $exception->response->status();

            return response()->json([
                'message' => in_array($status, [401, 403], true)
                    ? "{$provider->providerLabel()} відхилив доступ — перевірте підключення на сторінці інтеграцій."
                    : "Не вдалося отримати дані з {$provider->providerLabel()} (HTTP {$status}).",
            ], 502);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json([
            'data' => $tasks,
            'provider' => $provider->providerKey(),
        ]);
    }

    /**
     * Перемикає таск-трекер користувача. Перемикання нічого не видаляє:
     * налаштування обох інтеграцій лишаються, працює лише вибраний.
     */
    public function updateProvider(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in([User::TASK_PROVIDER_TRELLO, User::TASK_PROVIDER_BITRIX])],
        ]);

        $request->user()->forceFill(['task_provider' => $validated['provider']])->save();

        return response()->json([
            'message' => 'Активний таск-трекер — '.TaskProviders::label($validated['provider']).'.',
            'provider' => $validated['provider'],
        ]);
    }
}
