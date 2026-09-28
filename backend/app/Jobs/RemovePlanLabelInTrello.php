<?php

namespace App\Jobs;

use App\Services\PlanTrelloSync;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Задачу видалили з плану (або передали іншому) — картка лишається, але без
 * мітки «План», інакше наступний прогін знову притягнув би її в план.
 */
class RemovePlanLabelInTrello
{
    use Dispatchable;

    public function __construct(
        public readonly string $cardId,
        public readonly ?string $boardId,
    ) {}

    public function handle(PlanTrelloSync $sync): void
    {
        try {
            $sync->removeLabel($this->cardId, $this->boardId);
        } catch (Throwable $exception) {
            Log::warning('Плани ↔ Trello: не вдалося зняти мітку «План»', [
                'trello_card_id' => $this->cardId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
