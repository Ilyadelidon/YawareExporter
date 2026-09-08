<?php

namespace App\Services\Tasks;

/**
 * Джерело тасок для звіту. Реалізації — TrelloService і BitrixService;
 * далі по конвеєру (Excel-воркер, знімок у звіті, AI-розбір) вони
 * невідрізнимі, бо повертають однакову форму таски:
 *
 *   id, name, comment, url, list, start ('Y-m-d H:i'|null),
 *   due ('Y-m-d H:i'|null), due_complete (bool), labels (list<array{name,color}>)
 */
interface TaskProvider
{
    /** Ключ провайдера: 'trello' | 'bitrix'. */
    public function providerKey(): string;

    /** Назва провайдера для повідомлень користувачу. */
    public function providerLabel(): string;

    /** Чи вистачає налаштувань користувача, щоб тягнути таски. */
    public function isConfigured(): bool;

    /**
     * Таски, що потрапляють у вказаний день (перетин інтервалу start–due з добою).
     *
     * @return array<int, array<string, mixed>>
     */
    public function tasksForDate(string $date): array;
}
