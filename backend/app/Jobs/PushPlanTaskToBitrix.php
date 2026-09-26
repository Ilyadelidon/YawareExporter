<?php

namespace App\Jobs;

use App\Models\PlanTask;
use App\Models\User;
use App\Services\PlanBitrixSync;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Зміна задачі плану → Бітрікс24. Виконується одразу після відповіді на
 * запит (не через чергу: зранку черга `default` зайнята звітами). Невдача
 * не губиться — задача лишається з bitrix_pending, і її дотисне прогін
 * plans:sync-bitrix.
 */
class PushPlanTaskToBitrix
{
    use Dispatchable;

    public function __construct(
        public readonly int $taskId,
        public readonly ?int $actorId,
    ) {}

    public function handle(PlanBitrixSync $sync): void
    {
        $task = PlanTask::find($this->taskId);

        if ($task && $task->bitrix_pending) {
            $sync->push($task, $this->actorId ? User::find($this->actorId) : null);
        }
    }
}
