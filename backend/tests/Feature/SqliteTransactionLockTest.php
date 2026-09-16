<?php

namespace Tests\Feature;

use App\Database\ImmediateSQLiteConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Транзакція, що починається з читання, не має падати з «database is locked»
 * тієї ж миті, коли інший процес щось закомітив: саме так о 07:00 зникали
 * ранкові звіти, хоча busy_timeout стояв на 10 секунд.
 */
class SqliteTransactionLockTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Гонку двох процесів на файлі бази не зіграти в :memory: — кожне
        // зʼєднання отримало б власну базу.
        $this->databasePath = tempnam(sys_get_temp_dir(), 'lock-test-').'.sqlite';

        $seed = new PDO('sqlite:'.$this->databasePath);
        $seed->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $seed->exec('PRAGMA journal_mode = WAL');
        $seed->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
        $seed->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        $seed->exec("INSERT INTO notes (body) VALUES ('перший')");
    }

    protected function tearDown(): void
    {
        DB::purge('lock_test');

        foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm'] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * Зʼєднання застосунку до тестової бази у заданому режимі транзакцій.
     */
    private function connection(string $transactionMode)
    {
        DB::purge('lock_test');

        config()->set('database.connections.lock_test', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 500,
            'journal_mode' => 'WAL',
            'synchronous' => null,
            'transaction_mode' => $transactionMode,
        ]);

        return DB::connection('lock_test');
    }

    /**
     * Другий процес: окремий воркер, що пише в ту саму базу. busy_timeout = 0,
     * щоб тест не чекав, а одразу бачив, узятий лок чи ні.
     */
    private function otherWorker(): PDO
    {
        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 0');

        return $pdo;
    }

    public function test_application_uses_the_lock_aware_sqlite_connection(): void
    {
        $this->assertInstanceOf(ImmediateSQLiteConnection::class, DB::connection());
    }

    /**
     * Так виглядала аварія: транзакція прочитала дані, інший воркер за цей час
     * закомітив своє — і запис падає миттєво, не витративши жодної
     * мілісекунди з busy_timeout.
     */
    public function test_deferred_transaction_dies_when_someone_commits_first(): void
    {
        $connection = $this->connection('DEFERRED');
        $other = $this->otherWorker();

        $connection->beginTransaction();
        $connection->select('select count(*) as total from notes');

        $other->exec("INSERT INTO notes (body) VALUES ('чужий')");

        $started = microtime(true);

        try {
            $connection->insert("insert into notes (body) values ('свій')");
            $this->fail('Очікували, що DEFERRED-транзакція впаде на переході читання→запис.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('database is locked', $exception->getMessage());
            // Головна прикмета: busy_timeout тут не діє взагалі.
            $this->assertLessThan(0.3, microtime(true) - $started);
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Те саме місце з IMMEDIATE: лок узято ще на BEGIN, чужий запис уже не
     * встромиться між читанням і записом, і транзакція доходить до кінця.
     */
    public function test_immediate_transaction_survives_the_same_race(): void
    {
        $connection = $this->connection('IMMEDIATE');
        $other = $this->otherWorker();

        $connection->beginTransaction();
        $connection->select('select count(*) as total from notes');

        // Тепер чекає той, хто прийшов другим, а не падає той, хто вже в
        // транзакції.
        $this->assertTrue($this->writeFails($other));

        $connection->insert("insert into notes (body) values ('свій')");
        $connection->commit();

        $this->assertSame(2, (int) $connection->selectOne('select count(*) as total from notes')->total);
    }

    /**
     * Порожня транзакція не лишає по собі відкритого локу: після неї сусід
     * знову може писати.
     */
    public function test_transaction_releases_the_lock(): void
    {
        $connection = $this->connection('IMMEDIATE');

        $connection->transaction(fn () => $connection->select('select count(*) as total from notes'));

        $this->assertFalse($this->writeFails($this->otherWorker()));
    }

    private function writeFails(PDO $pdo): bool
    {
        try {
            $pdo->exec("INSERT INTO notes (body) VALUES ('чужий')");

            return false;
        } catch (PDOException) {
            return true;
        }
    }
}
