<?php

namespace Tests\Unit\Support;

use App\Support\ConsistentReadSnapshot;
use Illuminate\Database\SQLiteConnection;
use PDO;
use RuntimeException;
use Tests\TestCase;

class ConsistentReadSnapshotTest extends TestCase
{
    private string $tempDbPath = '';

    protected function tearDown(): void
    {
        if ($this->tempDbPath !== '' && file_exists($this->tempDbPath)) {
            @unlink($this->tempDbPath);
            @unlink($this->tempDbPath . '-wal');
            @unlink($this->tempDbPath . '-shm');
        }

        parent::tearDown();
    }

    private function makeWalSqliteConnection(): SQLiteConnection
    {
        if ($this->tempDbPath === '') {
            $this->tempDbPath = sys_get_temp_dir() . '/consistent_read_snapshot_' . uniqid('', true) . '.sqlite';
        }

        $pdo = new PDO('sqlite:' . $this->tempDbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');

        return new SQLiteConnection($pdo, $this->tempDbPath);
    }

    public function test_it_opens_and_commits_new_transaction_and_sees_fresh_commits_afterward(): void
    {
        $connA = $this->makeWalSqliteConnection();
        $connA->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, val TEXT)');
        $connA->table('items')->insert(['id' => 1, 'val' => 'initial']);

        $connB = $this->makeWalSqliteConnection();

        $this->assertSame(0, $connA->transactionLevel());

        $readsInsideSnapshot = ConsistentReadSnapshot::run(function ($c) use ($connB) {
            $this->assertSame(1, $c->transactionLevel());
            $first = $c->table('items')->where('id', 1)->value('val');

            // Concurrent commit on a separate connection after snapshot starts
            $connB->table('items')->where('id', 1)->update(['val' => 'updated']);

            $second = $c->table('items')->where('id', 1)->value('val');

            return [$first, $second];
        }, $connA);

        $this->assertSame(['initial', 'initial'], $readsInsideSnapshot);
        $this->assertSame(0, $connA->transactionLevel());

        // Subsequent fresh snapshot observes the committed change
        $freshRead = ConsistentReadSnapshot::run(
            fn ($c) => $c->table('items')->where('id', 1)->value('val'),
            $connA
        );

        $this->assertSame('updated', $freshRead);
        $this->assertSame(0, $connA->transactionLevel());
    }

    public function test_it_preserves_enclosing_transaction_without_committing_or_rolling_back(): void
    {
        $conn = $this->makeWalSqliteConnection();
        $conn->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, val TEXT)');

        $conn->beginTransaction();
        $conn->table('items')->insert(['id' => 1, 'val' => 'uncommitted-by-caller']);
        $this->assertSame(1, $conn->transactionLevel());

        $observed = ConsistentReadSnapshot::run(function ($c) {
            $this->assertSame(1, $c->transactionLevel());

            return $c->table('items')->where('id', 1)->value('val');
        }, $conn);

        $this->assertSame('uncommitted-by-caller', $observed);
        // Enclosing transaction remains open and caller still controls rollback/commit
        $this->assertSame(1, $conn->transactionLevel());

        $conn->rollBack();
        $this->assertSame(0, $conn->transactionLevel());
        $this->assertNull($conn->table('items')->where('id', 1)->value('val'));
    }

    public function test_it_rolls_back_and_releases_transaction_state_when_callback_throws(): void
    {
        $conn = $this->makeWalSqliteConnection();
        $conn->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, val TEXT)');
        $conn->table('items')->insert(['id' => 1, 'val' => 'ok']);

        try {
            ConsistentReadSnapshot::run(function ($c) {
                $this->assertSame(1, $c->transactionLevel());
                $c->table('items')->where('id', 1)->update(['val' => 'dirty']);
                throw new RuntimeException('boom');
            }, $conn);

            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, $conn->transactionLevel());
        $this->assertSame('ok', $conn->table('items')->where('id', 1)->value('val'));
    }

    public function test_mysql_driver_sets_transaction_scoped_repeatable_read_only_for_new_transactions(): void
    {
        $events = [];

        $fakePdo = new class($events) extends PDO {
            /** @var array<int, string> */
            private array $events;

            public function __construct(array &$events)
            {
                parent::__construct('sqlite::memory:');
                $this->events = &$events;
            }

            public function exec(string $statement): int|false
            {
                $this->events[] = $statement;

                return 0;
            }
        };

        $conn = new class($fakePdo, $events) extends \Illuminate\Database\MySqlConnection {
            /** @var array<int, string> */
            private array $recordedEvents;

            public function __construct($pdo, array &$events)
            {
                parent::__construct($pdo, '', '', ['driver' => 'mysql']);
                $this->recordedEvents = &$events;
            }

            public function beginTransaction(): void
            {
                $this->transactions++;
                $this->recordedEvents[] = 'BEGIN';
            }

            public function commit(): void
            {
                $this->transactions = max(0, $this->transactions - 1);
                $this->recordedEvents[] = 'COMMIT';
            }

            public function rollBack($toLevel = null): void
            {
                $this->transactions = 0;
                $this->recordedEvents[] = 'ROLLBACK';
            }
        };

        $result = ConsistentReadSnapshot::run(fn () => 'done', $conn);

        $this->assertSame('done', $result);
        $this->assertSame([
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'BEGIN',
            'COMMIT',
        ], $events);

        // When already inside an enclosing transaction, isolation is untouched and no BEGIN/COMMIT occurs
        $events = [];
        $conn->beginTransaction();
        $resultNested = ConsistentReadSnapshot::run(fn () => 'nested', $conn);
        $conn->rollBack();

        $this->assertSame('nested', $resultNested);
        $this->assertSame(['BEGIN', 'ROLLBACK'], $events);
    }
}
