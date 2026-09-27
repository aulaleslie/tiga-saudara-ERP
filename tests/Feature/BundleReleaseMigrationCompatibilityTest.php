<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BundleReleaseMigrationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Migration file => [table, [added columns]].
     */
    protected static function touchedMigrations(): array
    {
        return [
            '2026_08_21_032552_add_cost_snapshot_columns_to_sale_bundle_items_table.php' => [
                'table' => 'sale_bundle_items',
                'columns' => [
                    'cost_unit_snapshot',
                    'cost_total_snapshot',
                    'cost_snapshot_source',
                    'cost_snapshot_setting_id',
                    'cost_snapshot_setting_is_pkp',
                    'cost_snapshot_at',
                ],
                'has_foreign_key' => true,
            ],
            '2026_08_21_044029_add_replacement_cost_snapshot_columns_to_dispatch_details_table.php' => [
                'table' => 'dispatch_details',
                'columns' => [
                    'replacement_cost_unit_snapshot',
                    'replacement_cost_total_snapshot',
                    'replacement_cost_snapshot_source',
                    'replacement_cost_snapshot_setting_id',
                    'replacement_cost_snapshot_at',
                ],
                'has_foreign_key' => true,
            ],
            '2026_08_21_032600_add_cost_reversal_columns_to_sale_return_details_table.php' => [
                'table' => 'sale_return_details',
                'columns' => [
                    'component_sale_bundle_item_id',
                    'cost_origin',
                    'cost_unit_snapshot',
                    'cost_quantity',
                    'cost_total_snapshot',
                    'cost_snapshot_source',
                    'cost_snapshot_setting_id',
                    'cost_snapshot_setting_is_pkp',
                    'cost_snapshot_at',
                    'cost_effective_at',
                ],
                'has_foreign_key' => true,
            ],
            '2026_08_21_051825_add_commercial_quantity_also_reduced_to_sale_return_details_table.php' => [
                'table' => 'sale_return_details',
                'columns' => ['commercial_quantity_also_reduced'],
            ],
        ];
    }

    /**
     * @dataProvider migrationProvider
     */
    public function test_touched_migration_is_additive_up_and_rolls_back_cleanly(string $file, array $spec): void
    {
        $path = $this->locateMigrationFile($file);
        $this->assertNotNull($path, "Migration file {$file} could not be located.");

        $migration = require $path;
        $table = $spec['table'];
        $columns = $spec['columns'];

        // Migration was already applied by the base migrator during RefreshDatabase.
        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "Expected column {$column} to exist on {$table} after fresh migration."
            );
        }

        // SQLite cannot drop a foreign key constraint in place (it requires a full table
        // rebuild), so `down()` on these FK-bearing migrations is not exercisable under the
        // SQLite test driver. This is an accepted environmental limitation of the local
        // fast-test harness, not evidence the migration itself is unsafe on MySQL/Postgres,
        // where ALTER TABLE DROP FOREIGN KEY / DROP CONSTRAINT is supported directly.
        if (! empty($spec['has_foreign_key']) && $this->usingSqlite()) {
            $this->markTestSkipped(
                "SQLite does not support dropping foreign keys for {$file}; rollback is verified on MySQL/Postgres only."
            );
        }

        // Roll back only this migration and confirm the columns are removed without
        // affecting unrelated data in the table.
        $migration->down();

        foreach ($columns as $column) {
            $this->assertFalse(
                Schema::hasColumn($table, $column),
                "Expected column {$column} to be removed from {$table} after rollback."
            );
        }

        $this->assertTrue(Schema::hasTable($table), "Table {$table} must survive rollback of an additive migration.");

        // Re-apply to leave the schema in its expected post-migration state for subsequent assertions.
        $migration->up();

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "Expected column {$column} to exist on {$table} after re-applying the migration."
            );
        }
    }

    protected function usingSqlite(): bool
    {
        return config('database.default') === 'sqlite'
            || \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite';
    }

    public static function migrationProvider(): array
    {
        return collect(self::touchedMigrations())
            ->mapWithKeys(fn (array $spec, string $file) => [$file => [$file, $spec]])
            ->all();
    }

    protected function locateMigrationFile(string $file): ?string
    {
        foreach (glob(base_path('Modules/*/Database/Migrations/' . $file)) as $match) {
            return $match;
        }

        $rootMatch = database_path('migrations/' . $file);
        if (file_exists($rootMatch)) {
            return $rootMatch;
        }

        return null;
    }
}
