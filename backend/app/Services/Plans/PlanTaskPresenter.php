<?php

namespace App\Services\Plans;

use App\Models\BitrixWorkspace;
use App\Models\PlanTask;
use App\Models\User;
use App\Services\BitrixService;

/**
 * Задача плану для інтерфейсу — одна форма і для сторінки проекту, і для
 * відповідей на правку задачі.
 */
class PlanTaskPresenter
{
    /** Адреса порталу потрібна для посилань на задачі Бітрікса. */
    private function __construct(private readonly ?string $portalUrl) {}

    public static function make(): self
    {
        return new self(BitrixWorkspace::active()?->portal_url);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PlanTask $task): array
    {
        return [
            'id' => $task->id,
            'section_id' => $task->plan_section_id,
            'employee_id' => $task->employee_id,
            'title' => $task->title,
            'note' => $task->note,
            'status' => $task->status,
            'position' => $task->position,
            ...$this->tracker($task),
        ];
    }

    /**
     * Звʼязок задачі з трекером: який (bitrix / trello), посилання і стан —
     * linked / pending (ще не дійшло) / unlinked (у трекері зникла) / null.
     * Підзадачі — з трекера, лише для перегляду.
     *
     * @return array{tracker: ?string, tracker_url: ?string, tracker_state: ?string, subtasks: list<array{id: string, title: string, url: ?string}>}
     */
    private function tracker(PlanTask $task): array
    {
        [$tracker, $url, $pending, $unlinkedAt] = match (true) {
            $task->bitrix_task_id !== null || $task->bitrix_pending => [
                User::TASK_PROVIDER_BITRIX,
                $task->bitrix_task_id && $this->portalUrl
                    ? BitrixService::taskLink($this->portalUrl, $task->bitrix_task_id, $task->bitrix_snapshot['responsible'] ?? null)
                    : null,
                $task->bitrix_pending,
                $task->bitrix_unlinked_at,
            ],
            $task->trello_card_id !== null || $task->trello_pending => [
                User::TASK_PROVIDER_TRELLO,
                $task->trello_card_id ? PlanTrelloSync::cardUrl($task->trello_card_id) : null,
                $task->trello_pending,
                $task->trello_unlinked_at,
            ],
            default => [null, null, false, null],
        };

        return [
            'tracker' => $tracker,
            'tracker_url' => $url,
            'tracker_state' => match (true) {
                $unlinkedAt !== null => 'unlinked',
                $pending => 'pending',
                ($task->bitrix_task_id ?? $task->trello_card_id) !== null => 'linked',
                default => null,
            },
            'subtasks' => $task->subtasks ?? [],
        ];
    }
}
