<?php

namespace App\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Queue\DatabaseQueue;
use Throwable;

/**
 * Черга на SQLite, яка переживає короткий лок бази замість того, щоб губити
 * джобу.
 *
 * Проблема: DatabaseQueue::pop бере джобу двома кроками в одній транзакції —
 * SELECT, потім UPDATE reserved_at. SQLite стартує таку транзакцію як
 * читання, і на переході до запису, якщо файл уже зайняв інший воркер,
 * миттєво віддає SQLITE_BUSY_SNAPSHOT — busy_timeout у цьому шляху не діє
 * взагалі. Штатний pop ловить будь-який виняток після вибірки й одразу
 * позначає джобу failed (див. коментар «Potentially invalid job» у
 * фреймворку), тож випадковий лок коштував не затримки, а незробленого звіту.
 *
 * Рішення фреймворку — transaction_mode = IMMEDIATE — на цьому сервері не
 * працює: SQLiteConnection застосовує його лише на PHP >= 8.4, а тут 8.3.
 * Тому ловимо лок самі й повторюємо всю транзакцію з нуля: повтор усередині
 * тієї ж транзакції безглуздий, бо її снапшот уже застарів.
 */
class RetryingDatabaseQueue extends DatabaseQueue
{
    use DetectsConcurrencyErrors;

    /** Скільки разів пробувати, перш ніж віддати керування штатному pop. */
    private const LOCK_ATTEMPTS = 3;

    /** Пауза між спробами; росте, щоб воркери не билися в такт. */
    private const LOCK_BACKOFF_MICROSECONDS = 50_000;

    /**
     * {@inheritDoc}
     */
    public function pop($queue = null)
    {
        for ($attempt = 1; $attempt < self::LOCK_ATTEMPTS; $attempt++) {
            try {
                return $this->popWithoutFailingOnLock($queue);
            } catch (Throwable $e) {
                if (! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                usleep(self::LOCK_BACKOFF_MICROSECONDS * $attempt);
            }
        }

        // Спроби вичерпано: далі поводимось як звичайна черга, щоб справді
        // зіпсована джоба потрапила у failed_jobs, а не крутилась вічно.
        return parent::pop($queue);
    }

    /**
     * Та сама вибірка джоби, але без штатного «впав — значить джоба погана»:
     * лок бази нічого не говорить про саму джобу.
     *
     * @param  string|null  $queue
     * @return Job|null
     */
    private function popWithoutFailingOnLock($queue)
    {
        $queue = $this->getQueue($queue);

        return $this->database->transaction(function () use ($queue) {
            if ($jobRecord = $this->getNextAvailableJob($queue)) {
                return $this->marshalJob($queue, $jobRecord);
            }

            return null;
        });
    }
}
