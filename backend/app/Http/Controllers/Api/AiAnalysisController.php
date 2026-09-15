<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateDailyAnalysis;
use App\Models\DailyAnalysis;
use App\Models\Report;
use App\Services\Ai\AnalysisProvider;
use App\Services\AiAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AI-аналітика робочого дня. Увесь контролер під middleware('admin') —
 * працівники своїх розборів не бачать.
 */
class AiAnalysisController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $analysis = DailyAnalysis::where('employee_id', $validated['employee_id'])
            ->where('date', $validated['date'])
            ->first();

        $service = app(AiAnalysisService::class);

        return response()->json([
            'data' => $analysis,
            'configured' => $service->hasAnyConfigured(),
            // Список для вибору AI в інтерфейсі: незаповнені ключі видно одразу.
            'providers' => array_map(fn (AnalysisProvider $provider) => [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'configured' => $provider->isConfigured(),
                'default' => $provider->name() === config('services.ai.provider'),
            ], $service->providers()),
        ]);
    }

    /**
     * Перегенерація розбору за день. Потрібен готовий звіт: без нього немає ні
     * активностей, ні знімка тасок, тобто аналізувати нічого.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            // Разовий вибір AI для цього запуску; без нього — провайдер із .env.
            'provider' => ['nullable', 'string', Rule::in(array_keys(AiAnalysisService::PROVIDERS))],
        ]);

        $service = app(AiAnalysisService::class);
        $provider = $service->provider($validated['provider'] ?? null);

        if (! $provider->isConfigured()) {
            return response()->json([
                'message' => "AI-аналітику через {$provider->label()} не налаштовано: у .env немає {$provider->envKey()}.",
            ], 422);
        }

        $report = Report::where('employee_id', $validated['employee_id'])
            ->where('report_date', $validated['date'])
            ->where('status', Report::STATUS_COMPLETED)
            ->first();

        if (! $report) {
            return response()->json([
                'message' => 'За цю дату немає готового звіту — спершу має бути сформований звіт.',
            ], 422);
        }

        $analysis = DailyAnalysis::updateOrCreate(
            ['employee_id' => $report->employee_id, 'date' => $report->report_date->toDateString()],
            [
                'status' => DailyAnalysis::STATUS_PENDING,
                'provider' => $provider->name(),
                'error_message' => null,
            ],
        );

        // force: кнопку «Проаналізувати ще раз» тиснуть саме тоді, коли хочуть
        // свіжий розбір, — навіть якщо дані дня відтоді не змінились.
        GenerateDailyAnalysis::dispatch($report, $provider->name(), force: true);

        return response()->json(['data' => $analysis], 202);
    }
}
