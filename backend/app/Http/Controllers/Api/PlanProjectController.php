<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanTask;
use App\Models\PlanTaskDay;
use App\Services\Plans\PlanTaskPresenter;
use App\Support\Month;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Проекти «Планів». Створює, перейменовує й наповнює учасниками лише
 * адміністратор; працівник бачить тільки проекти, куди його додали.
 */
class PlanProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $projects = PlanProject::visibleTo($request->user())
            ->withCount('tasks')
            ->with('members:id,name')
            ->orderByRaw('archived_at is not null')
            ->orderBy('name')
            ->get()
            ->map(fn (PlanProject $project) => $this->projectSummary($project));

        return response()->json(['data' => $projects]);
    }

    /**
     * Проект з усіма задачами і відмітками днів за вибраний місяць.
     */
    public function show(Request $request, PlanProject $project): JsonResponse
    {
        abort_unless($project->isVisibleTo($request->user()), 403, 'Ви не учасник цього проекту.');

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = Month::parse($validated['month'] ?? null);

        $taskIds = $project->tasks()->pluck('id');
        $days = PlanTaskDay::forTasksInMonth($taskIds, $month)->get()->groupBy('plan_task_id');
        $lastWorked = PlanTaskDay::lastWorkedDates($taskIds);

        $members = $project->members()->orderBy('name')->get(['employees.id', 'name', 'position', 'current_plan_task_id']);

        $presenter = PlanTaskPresenter::make();

        $tasks = $project->tasks()->get()->map(fn (PlanTask $task) => [
            ...$presenter->present($task),
            'days' => (object) ($days[$task->id] ?? collect())
                ->mapWithKeys(fn (PlanTaskDay $day) => [$day->date->toDateString() => (string) $day->comment])
                ->all(),
            'last_worked_on' => $lastWorked[$task->id] ?? null,
        ]);

        $user = $request->user();

        // Виконавці задач, яких уже прибрали з учасників: задачі лишаються,
        // тож фронту треба знати імена.
        $assignees = Employee::whereIn('id', $tasks->pluck('employee_id')->unique())
            ->whereNotIn('id', $members->pluck('id'))
            ->get(['id', 'name', 'position', 'current_plan_task_id']);

        return response()->json([
            'project' => $this->projectSummary($project->loadCount('tasks')),
            'month' => $month->format('Y-m'),
            'days_in_month' => $month->daysInMonth,
            'today' => now()->toDateString(),
            'statuses' => PlanTask::STATUS_LABELS,
            'members' => $members->map(fn (Employee $e) => $this->person($e, true)),
            'former_members' => $assignees->map(fn (Employee $e) => $this->person($e, false)),
            'sections' => $project->sections()->get(['id', 'name', 'note', 'position']),
            'tasks' => $tasks,
            'can_manage' => $user->isAdmin(),
            'my_employee_id' => $user->isAdmin() ? null : $user->employee?->id,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_ids' => ['array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);

        $project = PlanProject::create(['name' => $validated['name']]);
        $project->members()->sync($validated['employee_ids'] ?? []);

        return response()->json(['data' => $this->freshSummary($project)], 201);
    }

    public function update(Request $request, PlanProject $project): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'archived' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $validated)) {
            $project->name = $validated['name'];
        }

        if (array_key_exists('archived', $validated)) {
            $project->archived_at = $validated['archived'] ? ($project->archived_at ?? now()) : null;
        }

        $project->save();

        return response()->json(['data' => $this->freshSummary($project)]);
    }

    public function destroy(PlanProject $project): JsonResponse
    {
        $project->delete();

        return response()->json(['ok' => true]);
    }

    public function updateMembers(Request $request, PlanProject $project): JsonResponse
    {
        $validated = $request->validate([
            'employee_ids' => ['present', 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);

        $project->members()->sync($validated['employee_ids']);

        return response()->json(['data' => $this->freshSummary($project)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function freshSummary(PlanProject $project): array
    {
        return $this->projectSummary($project->load('members:id,name')->loadCount('tasks'));
    }

    /**
     * @return array<string, mixed>
     */
    private function projectSummary(PlanProject $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'archived' => $project->archived_at !== null,
            'tasks_count' => (int) $project->tasks_count,
            'members' => $project->relationLoaded('members')
                ? $project->members->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->name])->values()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function person(Employee $employee, bool $isMember): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'position' => $employee->position,
            'current_task_id' => $employee->current_plan_task_id,
            'is_member' => $isMember,
        ];
    }
}
