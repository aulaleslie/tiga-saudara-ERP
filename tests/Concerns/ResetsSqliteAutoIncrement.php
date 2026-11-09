<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * For tests that deliberately commit the RefreshDatabase wrapping transaction and then delete
 * what they committed by hand. Deleting rows does not rewind SQLite's autoincrement counters,
 * so the next test's first row gets id 2+ and any test that assumes id 1 fails.
 */
trait ResetsSqliteAutoIncrement
{
    /**
     * @param  array<int, string>  $tables
     */
    protected function resetSqliteAutoIncrement(array $tables): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::table('sqlite_sequence')->whereIn('name', $tables)->delete();
    }
}
