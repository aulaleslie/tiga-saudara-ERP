<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * For test classes that use DatabaseTruncation (committed writes, no wrapping transaction).
 *
 * DatabaseTruncation empties every table, including rows that migrations seed as reference
 * data (cash denominations, default payment terms, permissions created by data migrations).
 * On the shared SQLite database that leaks an empty table into every later test.
 *
 * These tables are excluded from truncation, and their rows are snapshotted before and
 * restored after each test, so anything the test itself adds, edits or removes never
 * persists to the next test.
 */
trait PreservesMigrationSeededTables
{
    /** @var array<int, string> */
    protected array $exceptTables = ['cash_denominations', 'payment_terms', 'permissions'];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $migrationSeededSnapshot = [];

    protected function setUpPreservesMigrationSeededTables(): void
    {
        foreach ($this->exceptTables as $table) {
            $this->migrationSeededSnapshot[$table] = DB::table($table)->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();
        }
    }

    protected function tearDownPreservesMigrationSeededTables(): void
    {
        foreach ($this->migrationSeededSnapshot as $table => $rows) {
            DB::table($table)->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }

        $this->migrationSeededSnapshot = [];

        // Spatie caches permissions in-process; drop it so restored rows are the source of truth.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
