<?php

namespace App\Services\Tasks;

use App\Models\User;
use App\Services\BitrixService;
use App\Services\TrelloService;

class TaskProviders
{
    /**
     * Таск-трекер, вибраний користувачем. Без користувача (або з невідомим
     * значенням у колонці) лишається Trello — історична поведінка сервісу.
     */
    public static function forUser(?User $user): TaskProvider
    {
        return $user?->taskProvider() === User::TASK_PROVIDER_BITRIX
            ? BitrixService::forUser($user)
            : TrelloService::forUser($user);
    }

    /**
     * Людська назва провайдера за ключем — для повідомлень там, де інстанс
     * сервісу не потрібен.
     */
    public static function label(?string $key): string
    {
        return $key === User::TASK_PROVIDER_BITRIX ? 'Бітрікс24' : 'Trello';
    }
}
