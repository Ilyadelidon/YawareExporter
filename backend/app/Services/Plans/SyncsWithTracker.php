<?php

namespace App\Services\Plans;

use App\Models\PlanTask;
use Illuminate\Support\Facades\Log;

/**
 * Спільне для синхронізацій «Планів» із трекерами ([[PlanBitrixSync]],
 * [[PlanTrelloSync]]): тристороннє злиття полів проти зліпка, підсумок
 * прогону і дрібниці, що мають збігатися в обох трекерах.
 *
 * Клас, що використовує трейт, задає LOG_PREFIX — з нього в laravel.log
 * видно, чий це прогін.
 */
trait SyncsWithTracker
{
    /** @var array{created: int, updated: int, pushed: int, unlinked: int, warnings: list<string>} */
    private array $summary = ['created' => 0, 'updated' => 0, 'pushed' => 0, 'unlinked' => 0, 'warnings' => []];

    /**
     * @return array{created: int, updated: int, pushed: int, unlinked: int, warnings: list<string>}
     */
    public function summary(): array
    {
        return $this->summary;
    }

    /**
     * Хто змінив поле, видно лише проти зліпка — стану, на якому сторони
     * востаннє зійшлися. Змінене в сервісі йде в трекер (і тоді, коли його
     * змінили обидві сторони — сервіс виграє), змінене лише в трекері —
     * у план. null у полях сервісу означає «не нав'язуємо».
     *
     * @param  list<string>  $fields
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [apply, push]
     */
    private function merge(array $local, array $remote, array $snapshot, array $fields): array
    {
        $apply = [];
        $push = [];

        foreach ($fields as $field) {
            $mine = $local[$field];
            $theirs = $remote[$field];
            $base = $snapshot[$field] ?? null;

            if ($mine !== null && $mine !== $base && $mine !== $theirs) {
                $push[$field] = $mine;
            } elseif ($theirs !== $base && $theirs !== $mine) {
                $apply[$field] = $theirs;
            }
        }

        return [$apply, $push];
    }

    /**
     * Поля, що в сервісі відрізняються від зліпка, — для надсилання без
     * читання трекера.
     *
     * @param  list<string>  $fields
     */
    private function changes(array $local, array $snapshot, array $fields): array
    {
        return array_filter(
            array_intersect_key($local, array_flip($fields)),
            fn ($value, string $field) => $value !== null && $value !== ($snapshot[$field] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Назва й опис із трекера в атрибутах задачі плану. */
    private function textAttributes(array $fields): array
    {
        $attributes = [];

        if (array_key_exists('title', $fields)) {
            $attributes['title'] = mb_substr($fields['title'], 0, 1000);
        }

        if (array_key_exists('description', $fields)) {
            $attributes['note'] = $fields['description'] === '' ? null : $fields['description'];
        }

        return $attributes;
    }

    /**
     * Після змін із трекера: виконавцю потрібен доступ до плану проекту, а
     * закрита чи передана задача вже не «поточна» для попереднього виконавця.
     */
    private function afterPull(PlanTask $task, int $previousEmployeeId): void
    {
        $task->unsetRelation('project');
        $task->project->members()->syncWithoutDetaching([$task->employee_id]);
        $task->releaseCurrent($previousEmployeeId);
    }

    /** Підзадачі — не правка задачі плану: updated_at не чіпаємо. */
    private function storeSubtasks(PlanTask $task, array $subtasks): void
    {
        if ($subtasks !== ($task->subtasks ?? [])) {
            PlanTask::withoutTimestamps(fn () => $task->forceFill(['subtasks' => $subtasks])->save());
        }
    }

    private function text(string $value): string
    {
        return trim(str_replace("\r\n", "\n", $value));
    }

    /** Назви тегів, міток і списків звіряємо без регістру й зайвих пробілів. */
    private function key(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }

    private function warn(string $message): void
    {
        $this->summary['warnings'][] = $message;
        Log::warning(self::LOG_PREFIX.': '.$message);
    }
}
