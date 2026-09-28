<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PlanTask;
use App\Models\User;

/**
 * Куди йдуть зміни задачі плану: у Бітрікс24 чи в Trello. Звʼязана задача
 * лишається там, де вже є; нова — у трекер, вибраний її виконавцем для
 * звітів, а якщо там її створити нема де — в інший.
 */
class PlanTrackers
{
    /** Тег задачі Бітрікса й мітка картки Trello, що роблять їх задачами плану. */
    public const TAG = 'План';

    public static function push(PlanTask $task, ?User $actor): void
    {
        if ($task->bitrix_task_id !== null) {
            PlanBitrixSync::queuePush($task, $actor);
        } elseif ($task->trello_card_id !== null) {
            PlanTrelloSync::queuePush($task);
        } elseif (($tracker = self::trackerFor($task->employee)) === User::TASK_PROVIDER_BITRIX) {
            PlanBitrixSync::queuePush($task, $actor);
        } elseif ($tracker === User::TASK_PROVIDER_TRELLO) {
            PlanTrelloSync::queuePush($task);
        }
    }

    /** Задача зникає з плану — у трекері лишається, лише без мітки «План». */
    public static function remove(PlanTask $task, ?User $actor): void
    {
        PlanBitrixSync::queueTagRemoval($task, $actor);
        PlanTrelloSync::queueLabelRemoval($task);
    }

    /**
     * Нового виконавця задачі з Бітрікса Бітрікс і отримає; картка ж Trello
     * лежить на дошці попереднього — її відпускаємо, і задача йде далі як нова.
     */
    public static function executorChanged(PlanTask $task, ?User $actor): void
    {
        PlanTrelloSync::detachFromForeignBoard($task);
        self::push($task, $actor);
    }

    private static function trackerFor(?Employee $employee): ?string
    {
        $user = $employee?->user;
        $available = array_keys(array_filter([
            User::TASK_PROVIDER_BITRIX => PlanBitrixSync::isAvailable(),
            User::TASK_PROVIDER_TRELLO => PlanTrelloSync::hasBoard($user),
        ]));

        return in_array($user?->taskProvider(), $available, true) ? $user->taskProvider() : ($available[0] ?? null);
    }
}
