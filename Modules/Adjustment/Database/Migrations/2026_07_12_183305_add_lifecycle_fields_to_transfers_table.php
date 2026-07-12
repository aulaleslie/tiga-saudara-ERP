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
        Schema::table('transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('transfers', 'revision')) {
                $table->unsignedInteger('revision')->default(1)->after('id');
            }

            if (!Schema::hasColumn('transfers', 'archived_by')) {
                $table->unsignedBigInteger('archived_by')->nullable()->after('return_received_at');
                $table->string('archive_reason')->nullable()->after('archived_by');
                $table->timestamp('archived_at')->nullable()->after('archive_reason');
                
                $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        // Upgrade status column to support new statuses: COMPLETED, AWAITING_RETURN, ARCHIVED, DRAFT
        // Handles database-specific constraints and ENUM conversion
        $this->upgradeTransferStatusColumn();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to convert back to ENUM, just drop the new columns
        Schema::table('transfers', function (Blueprint $table) {
            if (Schema::hasColumn('transfers', 'revision')) {
                $table->dropColumn('revision');
            }

            if (Schema::hasColumn('transfers', 'archived_by')) {
                $table->dropForeign(['archived_by']);
                $table->dropColumn(['archived_by', 'archive_reason', 'archived_at']);
            }
        });
    }

    /**
     * Upgrade the status column to support new lifecycle statuses.
     * Handles ENUM constraints on MySQL and SQLite column recreation.
     */
    private function upgradeTransferStatusColumn(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // For MySQL: check if status is an ENUM and convert to VARCHAR
            $table = DB::selectOne("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                                    WHERE TABLE_NAME = 'transfers' AND COLUMN_NAME = 'status'");

            if ($table && strpos($table->COLUMN_TYPE, 'enum') !== false) {
                DB::statement("ALTER TABLE `transfers` MODIFY `status` VARCHAR(50) NOT NULL DEFAULT 'PENDING'");
            }
        } elseif ($driver === 'sqlite') {
            // For SQLite: need to remove the ENUM CHECK constraint from status column
            // SQLite doesn't support direct ALTER TABLE for CHECK constraints
            // Preserve all existing columns, indexes, and foreign key actions

            $fkState = DB::selectOne("PRAGMA foreign_keys")?->foreign_keys ?? true;
            DB::statement("PRAGMA foreign_keys = OFF");

            try {
                // Retrieve all indexes on transfers table
                $indexList = DB::select("PRAGMA index_list(transfers)");
                $indexesToRecreate = [];

                foreach ($indexList as $index) {
                    // Skip auto-indexes, but include manually-created unique indexes
                    if (strpos($index->name, 'sqlite_autoindex') === 0) {
                        continue;
                    }
                    $indexInfo = DB::select("PRAGMA index_info({$index->name})");
                    $indexesToRecreate[$index->name] = [
                        'columns' => $indexInfo,
                        'unique' => $index->unique === 1,
                    ];
                }

                // Retrieve all existing foreign keys to preserve their actions
                $foreignKeys = DB::select("PRAGMA foreign_key_list(transfers)");
                $fkDefinitions = [];
                $existingFkColumns = [];
                foreach ($foreignKeys as $fk) {
                    $fkDefinitions[] = [
                        'column' => $fk->from,
                        'references_table' => $fk->table,
                        'references_column' => $fk->to,
                        'on_update' => $fk->on_update,
                        'on_delete' => $fk->on_delete,
                    ];
                    $existingFkColumns[] = $fk->from;
                }

                // Add missing foreign keys that should exist but may not be on SQLite
                // These are defined by the constraints migration
                $requiredForeignKeys = [
                    ['column' => 'created_by', 'references_table' => 'users', 'references_column' => 'id', 'on_delete' => 'RESTRICT', 'on_update' => 'NO ACTION'],
                    ['column' => 'approved_by', 'references_table' => 'users', 'references_column' => 'id', 'on_delete' => 'SET NULL', 'on_update' => 'NO ACTION'],
                    ['column' => 'rejected_by', 'references_table' => 'users', 'references_column' => 'id', 'on_delete' => 'SET NULL', 'on_update' => 'NO ACTION'],
                    ['column' => 'dispatched_by', 'references_table' => 'users', 'references_column' => 'id', 'on_delete' => 'SET NULL', 'on_update' => 'NO ACTION'],
                    ['column' => 'received_by', 'references_table' => 'users', 'references_column' => 'id', 'on_delete' => 'SET NULL', 'on_update' => 'NO ACTION'],
                    ['column' => 'return_dispatched_by', 'references_table' => 'users', 'references_column' => 'id', 'on_delete' => 'SET NULL', 'on_update' => 'NO ACTION'],
                    ['column' => 'return_received_by', 'references_table' => 'users', 'references_column' => 'id', 'on_delete' => 'SET NULL', 'on_update' => 'NO ACTION'],
                    ['column' => 'archived_by', 'references_table' => 'users', 'references_column' => 'id', 'on_delete' => 'SET NULL', 'on_update' => 'NO ACTION'],
                    ['column' => 'origin_location_id', 'references_table' => 'locations', 'references_column' => 'id', 'on_delete' => 'RESTRICT', 'on_update' => 'NO ACTION'],
                    ['column' => 'destination_location_id', 'references_table' => 'locations', 'references_column' => 'id', 'on_delete' => 'RESTRICT', 'on_update' => 'NO ACTION'],
                ];

                foreach ($requiredForeignKeys as $fk) {
                    if (!in_array($fk['column'], $existingFkColumns)) {
                        $fkDefinitions[] = $fk;
                    }
                }

                // Create new table preserving all columns but without status CHECK constraint
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

                // Reconstruct foreign keys with their original delete/update actions
                foreach ($fkDefinitions as $fk) {
                    $onDelete = strtoupper($fk['on_delete']) ?: 'NO ACTION';
                    $onUpdate = strtoupper($fk['on_update']) ?: 'NO ACTION';
                    $createSql .= ",\n                        FOREIGN KEY ({$fk['column']}) REFERENCES {$fk['references_table']}({$fk['references_column']}) ON UPDATE {$onUpdate} ON DELETE {$onDelete}";
                }

                $createSql .= "\n                    )";

                DB::statement($createSql);

                // Copy all data from current transfers to transfers_new
                DB::statement("
                    INSERT INTO transfers_new
                    (id, document_number, revision, origin_location_id, destination_location_id, created_by,
                     approved_by, rejected_by, dispatched_by, received_by, return_dispatched_by, return_received_by,
                     status, approved_at, rejected_at, dispatched_at, received_at, return_dispatched_at, return_received_at,
                     archived_by, archive_reason, archived_at, created_at, updated_at)
                    SELECT
                    id, document_number, revision, origin_location_id, destination_location_id, created_by,
                    approved_by, rejected_by, dispatched_by, received_by, return_dispatched_by, return_received_by,
                    status, approved_at, rejected_at, dispatched_at, received_at, return_dispatched_at, return_received_at,
                    archived_by, archive_reason, archived_at, created_at, updated_at
                    FROM transfers
                ");

                // Drop the old transfers table
                DB::statement("DROP TABLE transfers");

                // Rename transfers_new to transfers
                DB::statement("ALTER TABLE transfers_new RENAME TO transfers");

                // Recreate all original indexes with their uniqueness constraints
                foreach ($indexesToRecreate as $indexName => $indexData) {
                    $columnNames = array_map(fn ($col) => $col->name, $indexData['columns']);
                    $columnList = implode(', ', $columnNames);
                    $uniqueKeyword = $indexData['unique'] ? 'UNIQUE ' : '';
                    DB::statement("CREATE {$uniqueKeyword}INDEX {$indexName} ON transfers({$columnList})");
                }
            } finally {
                // Re-enable foreign keys
                if ($fkState) {
                    DB::statement("PRAGMA foreign_keys = ON");
                }

                // Reconnect to clear any cached statements
                try {
                    DB::reconnect();
                } catch (\Exception $e) {
                    // Ignore reconnect errors, migration may continue
                }
            }
        }
    }
};
