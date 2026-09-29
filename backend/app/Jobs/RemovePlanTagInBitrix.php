<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Plans\PlanBitrixSync;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Задачу видалили з плану — у Бітріксі вона лишається, але без тегу «План»,
 * інакше наступний прогін знову притягнув би її в план.
 */
class RemovePlanTagInBitrix
{
    use Dispatchable;

    public function __construct(
        public readonly string $bitrixTaskId,
        public readonly ?int $actorId,
        public readonly ?int $employeeId,
    ) {}

    public function handle(PlanBitrixSync $sync): void
    {
        try {
            $sync->removeTag($this->bitrixTaskId, $this->actorId ? User::find($this->actorId) : null, $this->employeeId);
        } catch (Throwable $exception) {
            Log::warning('Плани ↔ Бітрікс24: не вдалося зняти тег «План»', [
                'bitrix_task_id' => $this->bitrixTaskId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
