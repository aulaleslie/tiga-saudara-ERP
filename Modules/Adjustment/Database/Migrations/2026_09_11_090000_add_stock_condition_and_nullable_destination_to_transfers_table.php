<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('transfers', 'stock_condition')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->string('stock_condition', 20)->nullable()->after('destination_location_id');
            });
        }

        $this->makeDestinationLocationNullable();
        $this->classifyUnambiguousHistoricalTransfers();
    }

    /**
     * Backfill stock_condition for historical transfers whose product rows
     * unambiguously use only good-stock buckets or only broken-stock buckets.
     * Transfers mixing both bucket types per line, or across lines, are left
     * with a null stock_condition and remain readable as legacy records.
     */
    private function classifyUnambiguousHistoricalTransfers(): void
    {
        DB::table('transfers')
            ->whereNull('stock_condition')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('transfer_products')
                    ->whereColumn('transfer_products.transfer_id', 'transfers.id')
                    ->where(function ($inner) {
                        $inner->where('quantity_tax', '>', 0)
                            ->orWhere('quantity_non_tax', '>', 0);
                    })
                    ->where(function ($inner) {
                        $inner->where('quantity_broken_tax', '>', 0)
                            ->orWhere('quantity_broken_non_tax', '>', 0);
                    });
            })
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('transfer_products')
                    ->whereColumn('transfer_products.transfer_id', 'transfers.id')
                    ->where(function ($inner) {
                        $inner->where('quantity_tax', '>', 0)
                            ->orWhere('quantity_non_tax', '>', 0);
                    });
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('transfer_products')
                    ->whereColumn('transfer_products.transfer_id', 'transfers.id')
                    ->where(function ($inner) {
                        $inner->where('quantity_broken_tax', '>', 0)
                            ->orWhere('quantity_broken_non_tax', '>', 0);
                    });
            })
            ->update(['stock_condition' => 'GOOD']);

        DB::table('transfers')
            ->whereNull('stock_condition')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('transfer_products')
                    ->whereColumn('transfer_products.transfer_id', 'transfers.id')
                    ->where(function ($inner) {
                        $inner->where('quantity_tax', '>', 0)
                            ->orWhere('quantity_non_tax', '>', 0);
                    });
            })
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('transfer_products')
                    ->whereColumn('transfer_products.transfer_id', 'transfers.id')
                    ->where(function ($inner) {
                        $inner->where('quantity_broken_tax', '>', 0)
                            ->orWhere('quantity_broken_non_tax', '>', 0);
                    });
            })
            ->update(['stock_condition' => 'BREAKAGE']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $destinationLessDrafts = DB::table('transfers')->whereNull('destination_location_id')->count();

        if ($destinationLessDrafts > 0) {
            throw new \RuntimeException(
                "Cannot roll back: {$destinationLessDrafts} transfer(s) have no destination_location_id. "
                . 'Complete or remove these drafts through an explicit operational decision before '
                . 'reverting this migration; rolling back must never silently delete or corrupt them.'
            );
        }

        if (Schema::hasColumn('transfers', 'stock_condition')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->dropColumn('stock_condition');
            });
        }

        // Guard confirmed no destination-less rows remain, so it is now safe
        // to restore the original NOT NULL constraint.
        $this->restoreDestinationLocationNotNull();
    }

    /**
     * Restore destination_location_id to NOT NULL, in a driver-compatible
     * way, mirroring makeDestinationLocationNullable()'s approach.
     */
    private function restoreDestinationLocationNotNull(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->restoreDestinationLocationNotNullSqlite();

            return;
        }

        $foreignKeyName = 'transfers_destination_location_id_foreign';

        $existingConstraintName = $this->findMysqlForeignKeyConstraintName('destination_location_id');

        if ($existingConstraintName) {
            DB::statement("ALTER TABLE `transfers` DROP FOREIGN KEY `{$existingConstraintName}`");
        }

        DB::statement('ALTER TABLE `transfers` MODIFY `destination_location_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('transfers', function (Blueprint $table) use ($foreignKeyName) {
            $table->foreign('destination_location_id', $foreignKeyName)
                ->references('id')->on('locations');
        });
    }

    /**
     * SQLite requires a full table rebuild to restore a column's NOT NULL
     * constraint. Mirrors makeDestinationLocationNullableSqlite() with
     * destination_location_id reverted to NOT NULL and stock_condition
     * already dropped by the caller.
     */
    private function restoreDestinationLocationNotNullSqlite(): void
    {
        $fkState = DB::selectOne('PRAGMA foreign_keys')?->foreign_keys ?? true;
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            $indexList = DB::select('PRAGMA index_list(transfers)');
            $indexesToRecreate = [];

            foreach ($indexList as $index) {
                if (strpos($index->name, 'sqlite_autoindex') === 0) {
                    continue;
                }
                $indexInfo = DB::select("PRAGMA index_info({$index->name})");
                $indexesToRecreate[$index->name] = [
                    'columns' => $indexInfo,
                    'unique' => $index->unique === 1,
                ];
            }

            $foreignKeys = DB::select('PRAGMA foreign_key_list(transfers)');
            $fkDefinitions = [];

            foreach ($foreignKeys as $fk) {
                $fkDefinitions[] = [
                    'column' => $fk->from,
                    'references_table' => $fk->table,
                    'references_column' => $fk->to,
                    'on_update' => $fk->on_update,
                    'on_delete' => $fk->on_delete,
                ];
            }

            $createSql = "
                CREATE TABLE transfers_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    document_number VARCHAR(255),
                    revision INTEGER NOT NULL DEFAULT 1,
                    origin_location_id BIGINT UNSIGNED NOT NULL,
                    destination_location_id BIGINT UNSIGNED NOT NULL,
                    created_by BIGINT UNSIGNED NOT NULL,
                    approved_by BIGINT UNSIGNED,
                    rejected_by BIGINT UNSIGNED,
                    dispatched_by BIGINT UNSIGNED,
                    received_by BIGINT UNSIGNED,
                    return_dispatched_by BIGINT UNSIGNED,
                    return_received_by BIGINT UNSIGNED,
                    status VARCHAR(50) NOT NULL DEFAULT 'PENDING',
                    approved_at DATETIME,
                    rejected_at DATETIME,
                    dispatched_at DATETIME,
                    received_at DATETIME,
                    return_dispatched_at DATETIME,
                    return_received_at DATETIME,
                    archived_by BIGINT UNSIGNED,
                    archive_reason VARCHAR(255),
                    archived_at DATETIME,
                    created_at DATETIME,
                    updated_at DATETIME";

            foreach ($fkDefinitions as $fk) {
                $onDelete = strtoupper($fk['on_delete']) ?: 'NO ACTION';
                $onUpdate = strtoupper($fk['on_update']) ?: 'NO ACTION';
                $createSql .= ",\n                    FOREIGN KEY ({$fk['column']}) REFERENCES {$fk['references_table']}({$fk['references_column']}) ON UPDATE {$onUpdate} ON DELETE {$onDelete}";
            }

            $createSql .= "\n                )";

            DB::statement($createSql);

            DB::statement('
                INSERT INTO transfers_new
                (id, document_number, revision, origin_location_id, destination_location_id,
                 created_by, approved_by, rejected_by, dispatched_by, received_by, return_dispatched_by, return_received_by,
                 status, approved_at, rejected_at, dispatched_at, received_at, return_dispatched_at, return_received_at,
                 archived_by, archive_reason, archived_at, created_at, updated_at)
                SELECT
                id, document_number, revision, origin_location_id, destination_location_id,
                created_by, approved_by, rejected_by, dispatched_by, received_by, return_dispatched_by, return_received_by,
                status, approved_at, rejected_at, dispatched_at, received_at, return_dispatched_at, return_received_at,
                archived_by, archive_reason, archived_at, created_at, updated_at
                FROM transfers
            ');

            DB::statement('DROP TABLE transfers');
            DB::statement('ALTER TABLE transfers_new RENAME TO transfers');

            foreach ($indexesToRecreate as $indexName => $indexData) {
                $columnNames = array_map(fn ($col) => $col->name, $indexData['columns']);
                $columnList = implode(', ', $columnNames);
                $uniqueKeyword = $indexData['unique'] ? 'UNIQUE ' : '';
                DB::statement("CREATE {$uniqueKeyword}INDEX {$indexName} ON transfers({$columnList})");
            }
        } finally {
            if ($fkState) {
                DB::statement('PRAGMA foreign_keys = ON');
            }

            try {
                DB::reconnect();
            } catch (\Exception $e) {
                // Ignore reconnect errors, migration may continue
            }
        }
    }

    /**
     * Make destination_location_id nullable while preserving its foreign key
     * and indexes, in a driver-compatible way (MySQL/MariaDB and SQLite).
     */
    private function makeDestinationLocationNullable(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->makeDestinationLocationNullableSqlite();

            return;
        }

        // MySQL/MariaDB: dropping and re-adding the foreign key around a
        // column modification keeps this compatible without doctrine/dbal.
        $foreignKeyName = 'transfers_destination_location_id_foreign';

        $existingConstraintName = $this->findMysqlForeignKeyConstraintName('destination_location_id');

        if ($existingConstraintName) {
            DB::statement("ALTER TABLE `transfers` DROP FOREIGN KEY `{$existingConstraintName}`");
        }

        DB::statement('ALTER TABLE `transfers` MODIFY `destination_location_id` BIGINT UNSIGNED NULL');

        Schema::table('transfers', function (Blueprint $table) use ($foreignKeyName) {
            $table->foreign('destination_location_id', $foreignKeyName)
                ->references('id')->on('locations');
        });
    }

    /**
     * Find the actual foreign-key constraint name on transfers.destination_location_id
     * within the current database only, so this never touches a similarly
     * named table in another schema.
     */
    private function findMysqlForeignKeyConstraintName(string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            ['transfers', $column]
        );

        return $row->CONSTRAINT_NAME ?? null;
    }

    /**
     * SQLite requires a full table rebuild to alter a column's NOT NULL
     * constraint. Mirrors the rebuild routine used for the status column
     * upgrade so indexes and foreign keys survive the swap.
     */
    private function makeDestinationLocationNullableSqlite(): void
    {
        $fkState = DB::selectOne('PRAGMA foreign_keys')?->foreign_keys ?? true;
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            $indexList = DB::select('PRAGMA index_list(transfers)');
            $indexesToRecreate = [];

            foreach ($indexList as $index) {
                if (strpos($index->name, 'sqlite_autoindex') === 0) {
                    continue;
                }
                $indexInfo = DB::select("PRAGMA index_info({$index->name})");
                $indexesToRecreate[$index->name] = [
                    'columns' => $indexInfo,
                    'unique' => $index->unique === 1,
                ];
            }

            $foreignKeys = DB::select('PRAGMA foreign_key_list(transfers)');
            $fkDefinitions = [];

            foreach ($foreignKeys as $fk) {
                $fkDefinitions[] = [
                    'column' => $fk->from,
                    'references_table' => $fk->table,
                    'references_column' => $fk->to,
                    'on_update' => $fk->on_update,
                    'on_delete' => $fk->on_delete,
                ];
            }

            $createSql = "
                CREATE TABLE transfers_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    document_number VARCHAR(255),
                    revision INTEGER NOT NULL DEFAULT 1,
                    origin_location_id BIGINT UNSIGNED NOT NULL,
                    destination_location_id BIGINT UNSIGNED,
                    stock_condition VARCHAR(20),
                    created_by BIGINT UNSIGNED NOT NULL,
                    approved_by BIGINT UNSIGNED,
                    rejected_by BIGINT UNSIGNED,
                    dispatched_by BIGINT UNSIGNED,
                    received_by BIGINT UNSIGNED,
                    return_dispatched_by BIGINT UNSIGNED,
                    return_received_by BIGINT UNSIGNED,
                    status VARCHAR(50) NOT NULL DEFAULT 'PENDING',
                    approved_at DATETIME,
                    rejected_at DATETIME,
                    dispatched_at DATETIME,
                    received_at DATETIME,
                    return_dispatched_at DATETIME,
                    return_received_at DATETIME,
                    archived_by BIGINT UNSIGNED,
                    archive_reason VARCHAR(255),
                    archived_at DATETIME,
                    created_at DATETIME,
                    updated_at DATETIME";

            foreach ($fkDefinitions as $fk) {
                $onDelete = strtoupper($fk['on_delete']) ?: 'NO ACTION';
                $onUpdate = strtoupper($fk['on_update']) ?: 'NO ACTION';
                $createSql .= ",\n                    FOREIGN KEY ({$fk['column']}) REFERENCES {$fk['references_table']}({$fk['references_column']}) ON UPDATE {$onUpdate} ON DELETE {$onDelete}";
            }

            $createSql .= "\n                )";

            DB::statement($createSql);

            DB::statement("
                INSERT INTO transfers_new
                (id, document_number, revision, origin_location_id, destination_location_id, stock_condition,
                 created_by, approved_by, rejected_by, dispatched_by, received_by, return_dispatched_by, return_received_by,
                 status, approved_at, rejected_at, dispatched_at, received_at, return_dispatched_at, return_received_at,
                 archived_by, archive_reason, archived_at, created_at, updated_at)
                SELECT
                id, document_number, revision, origin_location_id, destination_location_id, stock_condition,
                created_by, approved_by, rejected_by, dispatched_by, received_by, return_dispatched_by, return_received_by,
                status, approved_at, rejected_at, dispatched_at, received_at, return_dispatched_at, return_received_at,
                archived_by, archive_reason, archived_at, created_at, updated_at
                FROM transfers
            ");

            DB::statement('DROP TABLE transfers');
            DB::statement('ALTER TABLE transfers_new RENAME TO transfers');

            foreach ($indexesToRecreate as $indexName => $indexData) {
                $columnNames = array_map(fn ($col) => $col->name, $indexData['columns']);
                $columnList = implode(', ', $columnNames);
                $uniqueKeyword = $indexData['unique'] ? 'UNIQUE ' : '';
                DB::statement("CREATE {$uniqueKeyword}INDEX {$indexName} ON transfers({$columnList})");
            }
        } finally {
            if ($fkState) {
                DB::statement('PRAGMA foreign_keys = ON');
            }

            try {
                DB::reconnect();
            } catch (\Exception $e) {
                // Ignore reconnect errors, migration may continue
            }
        }
    }
};
