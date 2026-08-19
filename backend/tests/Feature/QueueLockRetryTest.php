<?php

namespace Tests\Feature;

use App\Queue\RetryingDatabaseQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDOException;
use Tests\TestCase;

/**
 * Короткий лок SQLite не має коштувати незробленого звіту: штатна черга
 * списує джобу у failed_jobs при першому ж винятку після вибірки.
 */
class QueueLockRetryTest extends TestCase
{
    use RefreshDatabase;

    private function pushJob(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Jobs\GenerateYawareReport', 'job' => 'x', 'data' => []]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
    }

    /**
     * Черга, що падає з локом задану кількість разів, — так виглядає зайнятий
     * іншим воркером файл бази.
     */
    private function queueFailingTimes(int $failures): RetryingDatabaseQueue
    {
        $queue = new class(DB::connection(), 'jobs', 'default', 60) extends RetryingDatabaseQueue
        {
            public int $failures = 0;

            public int $attempts = 0;

            protected function marshalJob($queue, $job)
            {
                $this->attempts++;

                if ($this->failures-- > 0) {
                    throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
                }

                return parent::marshalJob($queue, $job);
            }
        };

        $queue->failures = $failures;
        $queue->setContainer($this->app);
        $queue->setConnectionName('database');

        return $queue;
    }

    public function test_database_queue_is_the_lock_tolerant_one(): void
    {
        $this->assertInstanceOf(RetryingDatabaseQueue::class, Queue::connection('database'));
    }

    public function test_short_lock_does_not_lose_the_job(): void
    {
        $this->pushJob();

        $job = $this->queueFailingTimes(1)->pop('default');

        $this->assertNotNull($job);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_lock_on_every_attempt_still_falls_back_to_normal_behaviour(): void
    {
        $this->pushJob();

        $queue = $this->queueFailingTimes(99);

        // Якщо база зайнята постійно, це вже не випадковість — після своїх
        // спроб віддаємо керування штатному pop, інакше зіпсована джоба
        // крутилась би вічно.
        try {
            $queue->pop('default');
            $this->fail('Очікували, що лок зрештою прокинеться назовні.');
        } catch (PDOException) {
            // Дві власні спроби, третя — штатна.
            $this->assertSame(3, $queue->attempts);
        }
    }
}
