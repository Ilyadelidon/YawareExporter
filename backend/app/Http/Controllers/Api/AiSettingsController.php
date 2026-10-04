<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Перемикач автоматичного AI-розбору дня (див. GenerateYawareReport). Налаштування
 * одне на всю команду — на відміну від персональних пошт для сповіщень.
 */
class AiSettingsController extends Controller
{
    public function show(AiAnalysisService $service): JsonResponse
    {
        return response()->json($this->state($service));
    }

    public function update(Request $request, AiAnalysisService $service): JsonResponse
    {
        $validated = $request->validate([
            'auto_analysis' => ['required', 'boolean'],
        ]);

        $service->setAutoEnabled($validated['auto_analysis']);

        return response()->json($this->state($service) + [
            'message' => $validated['auto_analysis']
                ? 'AI-розбір запускатиметься після кожного звіту.'
                : 'Автоматичний AI-розбір вимкнено. Запустити його можна вручну зі звіту.',
        ]);
    }

    /** @return array<string, bool> */
    private function state(AiAnalysisService $service): array
    {
        return [
            'auto_analysis' => $service->autoEnabled(),
            'configured' => $service->isConfigured(),
        ];
    }
}
