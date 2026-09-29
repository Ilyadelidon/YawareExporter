<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlanProject;
use App\Models\PlanSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Розділи плану. Додати розділ може будь-який учасник — працівник сам
 * розкладає свій план. Перейменовує й видаляє лише адміністратор.
 */
class PlanSectionController extends Controller
{
    public function store(Request $request, PlanProject $project): JsonResponse
    {
        abort_unless($project->isVisibleTo($request->user()), 403, 'Ви не учасник цього проекту.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $section = $project->sections()->create([
            'name' => $validated['name'],
            'note' => $validated['note'] ?? null,
            'position' => $project->nextSectionPosition(),
        ]);

        return response()->json(['data' => $this->payload($section)], 201);
    }

    public function update(Request $request, PlanSection $section): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $section->update($validated);

        return response()->json(['data' => $this->payload($section)]);
    }

    public function destroy(PlanSection $section): JsonResponse
    {
        $section->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PlanSection $section): array
    {
        return $section->only(['id', 'name', 'note', 'position']);
    }
}
