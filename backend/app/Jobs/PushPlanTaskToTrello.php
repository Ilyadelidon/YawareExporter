<?php

namespace App\Jobs;

use App\Models\PlanTask;
use App\Services\PlanTrelloSync;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Зміна задачі плану → Trello, одразу після відповіді на запит (як і
 * [[PushPlanTaskToBitrix]]). Невдачу дотисне прогін plans:sync-trello —
 * задача лишається з trello_pending.
 */
class PushPlanTaskToTrello
{
    use Dispatchable;

    public function __construct(
        public readonly int $taskId,
    ) {}

    public function handle(PlanTrelloSync $sync): void
    {
        $task = PlanTask::find($this->taskId);

        if ($task && $task->trello_pending) {
            $sync->push($task);
        }
    }
}
