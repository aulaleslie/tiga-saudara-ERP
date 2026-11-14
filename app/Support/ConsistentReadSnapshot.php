<?php

namespace App\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConsistentReadSnapshot
{
    /**
     * Execute a multi-statement read workflow within a single consistent transaction snapshot.
     *
     * Preserves any caller-owned enclosing transaction without altering its isolation level
     * or transaction boundary. When starting a new read transaction on MySQL, applies
     * transaction-scoped REPEATABLE READ isolation for that transaction only and guarantees
     * commit or rollback cleanup before returning.
     *
     * @template T
     *
     * @param  Closure(ConnectionInterface): T  $callback
     * @return T
     */
    public static function run(Closure $callback, ConnectionInterface|string|null $connection = null): mixed
    {
        $conn = $connection instanceof ConnectionInterface
            ? $connection
            : DB::connection($connection);

        if (method_exists($conn, 'transactionLevel') && $conn->transactionLevel() > 0) {
            return $callback($conn);
        }

        if (method_exists($conn, 'getDriverName') && $conn->getDriverName() === 'mysql') {
            $conn->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        $conn->beginTransaction();

        try {
            $result = $callback($conn);
            $conn->commit();

            return $result;
        } catch (Throwable $e) {
            $conn->rollBack();

            throw $e;
        }
    }
}
