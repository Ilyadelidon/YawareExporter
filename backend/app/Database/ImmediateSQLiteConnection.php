<?php

namespace App\Database;

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\SQLiteConnection;
use Throwable;

/**
 * SQLite-зʼєднання, транзакції якого справді починаються як запис.
 *
 * Проблема: PDO відкриває транзакцію як DEFERRED. Така транзакція бере
 * снапшот на першому ж читанні, і коли після нього доходить до запису, а
 * інший процес тим часом закомітив своє, SQLite миттєво віддає
 * SQLITE_BUSY_SNAPSHOT — те саме «database is locked», але за нуль
 * мілісекунд, повз busy_timeout. Тому в логах і виходить безглузда пара:
 * `busy_timeout=10000`, а падіння сталося одразу. Під удар потрапляє будь-яка
 * транзакція, що починається з SELECT: `updateOrCreate` в
 * ReportHistoryService, `DatabaseQueue::pop` у воркерів. О 07:00, коли три
 * воркери, планувальник і PHP-FPM пишуть в один файл, це лотерея — найкращий
 * відомий кандидат на причину падіння ранкового прогону 2026-09-15 (лог із
 * прода на момент правки не звіряли).
 *
 * Фреймворк має від цього `transaction_mode = IMMEDIATE`, але вмикає його
 * лише на PHP >= 8.4 (див. SQLiteConnection::executeBeginTransactionStatement):
 * на 8.3, який стоїть на проді, PDO не бачить транзакції, відкритої через
 * exec('BEGIN IMMEDIATE'), і подальший commit() падає з «There is no active
 * transaction» — перевірено.
 *
 * Тому відкриваємо транзакцію штатно (PDO лишається при своєму обліку, commit
 * і rollback працюють як завжди) і одразу підвищуємо її до запису порожнім
 * UPDATE. Лок береться до першого читання, снапшоту ще немає — і busy_timeout
 * нарешті чекає своєї черги замість миттєвого падіння. Порожній UPDATE не
 * змінює жодного рядка і не додає у WAL жодного байта, тож ціна питання —
 * один statement на транзакцію.
 *
 * Прибрати цей клас можна буде після оновлення PHP до 8.4: тоді
 * `transaction_mode` з config/database.php почне діяти сам.
 */
class ImmediateSQLiteConnection extends SQLiteConnection
{
    use DetectsConcurrencyErrors;

    /**
     * Чи вдається на цьому зʼєднанні взяти лок пробним записом. Порожня база
     * до міграцій його взяти не дасть — тоді не пробуємо більше й працюємо
     * як звичайне зʼєднання.
     */
    private bool $locksOnBegin = true;

    /**
     * {@inheritDoc}
     */
    protected function executeBeginTransactionStatement()
    {
        parent::executeBeginTransactionStatement();

        if (! $this->needsManualLock()) {
            return;
        }

        try {
            $this->getPdo()->exec($this->lockStatement());
        } catch (Throwable $exception) {
            $this->handleLockFailure($exception);
        }
    }

    /**
     * Чи треба брати лок вручну: на PHP >= 8.4 фреймворк уже стартує
     * транзакцію потрібним режимом, у pretend-режимі писати нічого не можна,
     * а DEFERRED — це явне прохання не брати лок наперед.
     */
    private function needsManualLock(): bool
    {
        if (! $this->locksOnBegin || $this->pretending()) {
            return false;
        }

        if (version_compare(PHP_VERSION, '8.4.0', '>=')) {
            return false;
        }

        $mode = strtoupper((string) ($this->getConfig('transaction_mode') ?? 'DEFERRED'));

        return $mode === 'IMMEDIATE' || $mode === 'EXCLUSIVE';
    }

    /**
     * Запис, який не змінює жодного рядка, але змушує SQLite узяти лок на
     * запис. Таблиця міграцій підходить тим, що є в будь-якій робочій базі й
     * не належить жодній предметній частині застосунку.
     */
    private function lockStatement(): string
    {
        $migrations = config('database.migrations');

        $table = match (true) {
            is_array($migrations) => $migrations['table'] ?? 'migrations',
            is_string($migrations) => $migrations,
            default => 'migrations',
        };

        $table = str_replace('"', '', (string) $table);

        return "update \"{$table}\" set \"batch\" = \"batch\" where 0";
    }

    /**
     * Пробний запис не пройшов. База зайнята довше за busy_timeout — це
     * справжній лок, віддаємо його наверх; але спершу закриваємо вже відкриту
     * транзакцію, інакше PDO лишиться «в транзакції», фреймворк вважатиме, що
     * її немає, і наступний beginTransaction впаде вже на порожньому місці.
     *
     * Будь-яка інша помилка (немає таблиці міграцій — база ще не мігрована)
     * означає лише, що лок наперед узяти не вдається: працюємо далі як
     * звичайне зʼєднання.
     */
    private function handleLockFailure(Throwable $exception): void
    {
        if (! $this->causedByConcurrencyError($exception)) {
            $this->locksOnBegin = false;

            return;
        }

        try {
            $this->getPdo()->rollBack();
        } catch (Throwable) {
            // Транзакції вже немає — тоді й закривати нічого.
        }

        throw $exception;
    }
}
