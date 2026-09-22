<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PlanProject;
use App\Models\PlanTask;
use App\Models\PlanTaskDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Задачі плану, відмітки днів і «працюю зараз». Адміністратор править усе;
 * працівник — лише власні задачі в проектах, де він учасник.
 */
class PlanTaskController extends Controller
{
    public function store(Request $request, PlanProject $project): JsonResponse
    {
        $user = $request->user();
        $memberIds = $project->members()->pluck('employees.id');

        if ($user->isAdmin()) {
            $employeeRule = ['required', 'integer', Rule::in($memberIds->all())];
        } else {
            abort_unless($project->hasMember($user->employee), 403, 'Ви не учасник цього проекту.');
            // Працівник ставить задачу лише собі, що б не прийшло в запиті.
            $request->merge(['employee_id' => $user->employee->id]);
            $employeeRule = ['required', 'integer'];
        }

        $validated = $request->validate([
            'employee_id' => $employeeRule,
            'title' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(array_keys(PlanTask::STATUS_LABELS))],
            'section_id' => ['nullable', 'integer', Rule::exists('plan_sections', 'id')->where('plan_project_id', $project->id)],
        ], [
            'employee_id.in' => 'Виконавець має бути учасником проекту.',
        ]);

        $task = $project->tasks()->create([
            'employee_id' => $validated['employee_id'],
            'plan_section_id' => $validated['section_id'] ?? null,
            'title' => $validated['title'],
            'note' => $validated['note'] ?? null,
            'status' => $validated['status'] ?? PlanTask::STATUS_PENDING,
            'position' => (int) PlanTask::where('plan_project_id', $project->id)->max('position') + 1,
        ]);

        return response()->json(['data' => $this->payload($task)], 201);
    }

    public function update(Request $request, PlanTask $task): JsonResponse
    {
        $this->authorizeEdit($request, $task);

        $user = $request->user();

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(array_keys(PlanTask::STATUS_LABELS))],
            'section_id' => ['nullable', 'integer', Rule::exists('plan_sections', 'id')->where('plan_project_id', $task->plan_project_id)],
            // Передати задачу іншому може лише адміністратор.
            'employee_id' => [
                Rule::prohibitedIf(! $user->isAdmin()),
                'integer',
                Rule::in($task->project->members()->pluck('employees.id')->all()),
            ],
        ], [
            'employee_id.in' => 'Виконавець має бути учасником проекту.',
            'employee_id.prohibited' => 'Передати задачу іншому може лише адміністратор.',
        ]);

        if (array_key_exists('section_id', $validated)) {
            $validated['plan_section_id'] = $validated['section_id'];
            unset($validated['section_id']);
        }

        DB::transaction(function () use ($task, $validated) {
            $previousEmployeeId = $task->employee_id;
            $task->update($validated);

            // Закрита чи передана задача вже не «поточна» для попереднього виконавця.
            if (in_array($task->status, PlanTask::INACTIVE_STATUSES, true) || $task->employee_id !== $previousEmployeeId) {
                Employee::whereKey($previousEmployeeId)
                    ->where('current_plan_task_id', $task->id)
                    ->update(['current_plan_task_id' => null]);
            }
        });

        return response()->json(['data' => $this->payload($task->fresh())]);
    }

    public function destroy(Request $request, PlanTask $task): JsonResponse
    {
        $this->authorizeEdit($request, $task);

        $task->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Відмітка «працював над задачею цього дня» з необовʼязковим коментарем.
     * Повторний виклик лише оновлює коментар.
     */
    public function markDay(Request $request, PlanTask $task, string $date): JsonResponse
    {
        $this->authorizeEdit($request, $task);
        $this->validateDate($date);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $day = PlanTaskDay::updateOrCreate(
            ['plan_task_id' => $task->id, 'date' => $date],
            ['comment' => filled($validated['comment'] ?? null) ? trim($validated['comment']) : null],
        );

        return response()->json(['data' => ['date' => $date, 'comment' => (string) $day->comment]]);
    }

    public function unmarkDay(Request $request, PlanTask $task, string $date): JsonResponse
    {
        $this->authorizeEdit($request, $task);
        $this->validateDate($date);

        PlanTaskDay::where('plan_task_id', $task->id)->where('date', $date)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * «Працюю зараз»: одна задача на людину. Заразом відмічає сьогоднішній
     * день і, якщо задача ще не була в роботі, переводить її в «В роботі».
     */
    public function setCurrent(Request $request, PlanTask $task): JsonResponse
    {
        $this->authorizeEdit($request, $task);

        DB::transaction(function () use ($task) {
            Employee::whereKey($task->employee_id)->update(['current_plan_task_id' => $task->id]);

            PlanTaskDay::firstOrCreate(['plan_task_id' => $task->id, 'date' => now()->toDateString()]);

            if (in_array($task->status, [PlanTask::STATUS_PENDING, ...PlanTask::INACTIVE_STATUSES], true)) {
                $task->update(['status' => PlanTask::STATUS_IN_PROGRESS]);
            }
        });

        return response()->json(['data' => $this->payload($task->fresh())]);
    }

    public function clearCurrent(Request $request, PlanTask $task): JsonResponse
    {
        $this->authorizeEdit($request, $task);

        Employee::whereKey($task->employee_id)
            ->where('current_plan_task_id', $task->id)
            ->update(['current_plan_task_id' => null]);

        return response()->json(['ok' => true]);
    }

    private function authorizeEdit(Request $request, PlanTask $task): void
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return;
        }

        $employee = $user->employee;

        abort_unless(
            $employee !== null && $task->employee_id === $employee->id && $task->project->hasMember($employee),
            403,
            'Редагувати можна лише власні задачі.',
        );
    }

    private function validateDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        abort_unless($parsed && $parsed->format('Y-m-d') === $date, 422, 'Невірна дата.');
        // Таймлайн — про зроблене, а не про заплановане.
        abort_if($date > now()->toDateString(), 422, 'Не можна відмітити день, який ще не настав.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PlanTask $task): array
    {
        return [
            'id' => $task->id,
            'section_id' => $task->plan_section_id,
            'employee_id' => $task->employee_id,
            'title' => $task->title,
            'note' => $task->note,
            'status' => $task->status,
            'position' => $task->position,
        ];
    }
}
