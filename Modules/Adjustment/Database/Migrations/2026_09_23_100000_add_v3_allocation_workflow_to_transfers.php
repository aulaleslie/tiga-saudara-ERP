<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, version-compatible schema for Stock Transfer workflow version 3
 * (location-free goods entry, approver allocations, atomic multi-route
 * dispatch, confirmation receipt and dispatch cancellation).
 *
 * Legacy rows are never rewritten: the only change to existing columns is
 * relaxing transfers.origin_location_id and transfer_movements.origin_location_id
 * to NULL, which version 3 headers need because a v3 document has no single
 * route. Legacy services keep requiring those locations themselves.
 *
 * Re-runnable: MySQL commits DDL statement by statement, so an interrupted
 * earlier attempt can leave some tables/columns/constraints behind. Every
 * step therefore checks what already exists and completes only the missing
 * pieces. All foreign keys and indexes carry explicit names within MySQL's
 * 64-character identifier limit. On SQLite, foreign keys are declared inline
 * when a table is created (SQLite cannot add them afterwards).
 */
return new class extends Migration
{
    /**
     * table => [[column, referenced table, constraint name, on delete], ...]
     */
    private const FOREIGN_KEYS = [
        'transfers' => [
            ['created_in_setting_id', 'settings', 'fk_trf_created_in_setting', 'set null'],
            ['cancelled_by', 'users', 'fk_trf_cancelled_by', 'set null'],
        ],
        'transfer_request_revisions' => [
            ['transfer_id', 'transfers', 'fk_trr_transfer', 'cascade'],
            ['submitted_by', 'users', 'fk_trr_submitted_by', 'set null'],
            ['submitted_in_setting_id', 'settings', 'fk_trr_submitted_setting', 'set null'],
        ],
        'transfer_approval_allocations' => [
            ['transfer_id', 'transfers', 'fk_taa_transfer', 'cascade'],
            ['request_revision_id', 'transfer_request_revisions', 'fk_taa_request_revision', 'cascade'],
            ['product_id', 'products', 'fk_taa_product', 'restrict'],
            ['source_location_id', 'locations', 'fk_taa_source_location', 'restrict'],
            ['destination_location_id', 'locations', 'fk_taa_destination_location', 'restrict'],
            ['saved_by', 'users', 'fk_taa_saved_by', 'set null'],
        ],
        'transfer_approval_allocation_serials' => [
            ['transfer_approval_allocation_id', 'transfer_approval_allocations', 'fk_taas_allocation', 'cascade'],
            ['transfer_id', 'transfers', 'fk_taas_transfer', 'cascade'],
            ['product_serial_number_id', 'product_serial_numbers', 'fk_taas_serial', 'restrict'],
        ],
        'transfer_movement_allocations' => [
            ['transfer_id', 'transfers', 'fk_tma_transfer', 'cascade'],
            ['transfer_movement_id', 'transfer_movements', 'fk_tma_movement', 'cascade'],
            ['transfer_movement_line_id', 'transfer_movement_lines', 'fk_tma_movement_line', 'cascade'],
            ['dispatch_allocation_id', 'transfer_movement_allocations', 'fk_tma_dispatch_alloc', 'restrict'],
            ['product_id', 'products', 'fk_tma_product', 'restrict'],
            ['source_location_id', 'locations', 'fk_tma_source_location', 'restrict'],
            ['destination_location_id', 'locations', 'fk_tma_destination_location', 'restrict'],
            ['actor_id', 'users', 'fk_tma_actor', 'set null'],
        ],
        'transfer_movement_serials' => [
            ['transfer_movement_allocation_id', 'transfer_movement_allocations', 'fk_tms_movement_alloc', 'set null'],
        ],
    ];

    /**
     * table => [[columns, index name, unique], ...]
     */
    private const INDEXES = [
        'transfers' => [
            [['v3_reference_key'], 'transfers_v3_reference_key_unique', true],
        ],
        'transfer_request_revisions' => [
            [['transfer_id', 'revision_number'], 'transfer_request_revisions_transfer_revision_unique', true],
        ],
        'transfer_approval_allocations' => [
            [['transfer_id', 'request_revision_id', 'configuration_revision'], 'transfer_approval_alloc_plan_idx', false],
        ],
        'transfer_approval_allocation_serials' => [
            // One assignment per serial per plan (request revision + configuration revision).
            [['transfer_id', 'request_revision_id', 'configuration_revision', 'product_serial_number_id'], 'transfer_approval_alloc_serial_plan_unique', true],
        ],
        'transfer_movement_allocations' => [
            [['transfer_id', 'kind'], 'transfer_movement_alloc_kind_idx', false],
            // A dispatch allocation is received or cancelled at most once.
            [['dispatch_allocation_id', 'kind'], 'transfer_movement_alloc_dispatch_kind_unique', true],
        ],
    ];

    public function up(): void
    {
        $this->makeColumnNullable('transfers', 'origin_location_id', 'locations');
        $this->makeColumnNullable('transfer_movements', 'origin_location_id', 'locations');

        $this->addMissingColumns('transfers', [
            'v3_reference_key'                => fn (Blueprint $t) => $t->string('v3_reference_key', 64)->nullable(),
            'created_in_setting_id'           => fn (Blueprint $t) => $t->unsignedBigInteger('created_in_setting_id')->nullable(),
            'current_request_revision_id'     => fn (Blueprint $t) => $t->unsignedBigInteger('current_request_revision_id')->nullable(),
            'approval_configuration_revision' => fn (Blueprint $t) => $t->unsignedInteger('approval_configuration_revision')->default(0),
            'cancelled_by'                    => fn (Blueprint $t) => $t->unsignedBigInteger('cancelled_by')->nullable(),
            'cancelled_at'                    => fn (Blueprint $t) => $t->timestamp('cancelled_at')->nullable(),
            'cancellation_reason'             => fn (Blueprint $t) => $t->text('cancellation_reason')->nullable(),
        ]);

        $this->createTableIfMissing('transfer_request_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transfer_id');
            $table->unsignedInteger('revision_number');
            $table->string('stock_condition', 20);
            // [{product_id, quantity, serials: [{id, serial_number}]}]
            $table->json('lines');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('submitted_in_setting_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        $this->createTableIfMissing('transfer_approval_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transfer_id');
            $table->unsignedBigInteger('request_revision_id');
            $table->unsignedInteger('configuration_revision');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('source_location_id');
            $table->unsignedBigInteger('destination_location_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('saved_by')->nullable();
            $table->timestamps();
        });

        $this->createTableIfMissing('transfer_approval_allocation_serials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transfer_approval_allocation_id');
            $table->unsignedBigInteger('transfer_id');
            $table->unsignedBigInteger('request_revision_id');
            $table->unsignedInteger('configuration_revision');
            $table->unsignedBigInteger('product_serial_number_id');
            $table->timestamps();
        });

        $this->createTableIfMissing('transfer_movement_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transfer_id');
            $table->unsignedBigInteger('transfer_movement_id');
            $table->unsignedBigInteger('transfer_movement_line_id');
            $table->unsignedBigInteger('dispatch_allocation_id')->nullable();
            $table->unsignedBigInteger('approval_allocation_id')->nullable();
            $table->string('kind', 32); // DISPATCH, RECEIPT, CANCELLATION
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('source_location_id');
            $table->unsignedBigInteger('destination_location_id');
            $table->unsignedBigInteger('source_setting_id');
            $table->unsignedBigInteger('destination_setting_id');
            $table->boolean('cross_business')->default(false);
            $table->string('stock_condition', 20);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('applied_quantity_non_tax')->default(0);
            $table->unsignedInteger('applied_quantity_tax')->default(0);
            $table->unsignedInteger('applied_quantity_broken_non_tax')->default(0);
            $table->unsignedInteger('applied_quantity_broken_tax')->default(0);
            $table->string('destination_classification', 32);
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->string('tax_resolver_provenance', 32)->nullable();
            $table->json('stock_snapshot_before')->nullable();
            $table->json('stock_snapshot_after')->nullable();
            $table->unsignedBigInteger('inventory_transaction_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamps();
        });

        $this->addMissingColumns('transfer_movement_serials', [
            'transfer_movement_allocation_id' => fn (Blueprint $t) => $t->unsignedBigInteger('transfer_movement_allocation_id')->nullable(),
        ]);

        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as [$columns, $name, $unique]) {
                $this->ensureIndex($table, $columns, $name, $unique);
            }
        }

        foreach (self::FOREIGN_KEYS as $table => $foreignKeys) {
            foreach ($foreignKeys as [$column, $references, $name, $onDelete]) {
                if (DB::getDriverName() === 'sqlite') {
                    // New tables received theirs inline; columns added to
                    // existing tables need a rebuild (also repairs databases
                    // migrated before this constraint was declared).
                    $this->ensureSqliteColumnForeignKey($table, $column, $references, $name, $onDelete);
                } else {
                    $this->ensureForeignKey($table, $column, $references, $name, $onDelete);
                }
            }
        }
    }

    /**
     * Rollback deliberately refuses to destroy populated version 3 evidence.
     * The documented operational rollback is disabling v3 creation
     * (STOCK_TRANSFERS_V3_CREATION_ENABLED=false) while keeping this schema.
     * Relaxed origin columns are not re-tightened.
     */
    public function down(): void
    {
        if (Schema::hasColumn('transfers', 'workflow_version')
            && DB::table('transfers')->where('workflow_version', 3)->exists()) {
            throw new RuntimeException('Version 3 stock transfers exist; refusing destructive rollback. Disable v3 creation instead.');
        }

        // Resolve every actual constraint name (new or legacy-generated) before
        // the first change, so rollback never stops half-way on MySQL, where
        // each DDL statement commits on its own.
        $foreignKeyDrops = [];
        if (DB::getDriverName() !== 'sqlite') {
            foreach (['transfer_movement_serials', 'transfers'] as $table) {
                foreach (self::FOREIGN_KEYS[$table] as [$column]) {
                    foreach ($this->foreignKeyNamesForColumn($table, $column) as $constraint) {
                        $foreignKeyDrops[] = [$table, $constraint];
                    }
                }
            }
        }

        foreach ($foreignKeyDrops as [$table, $constraint]) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign($constraint));
        }

        if ($this->indexExists('transfers', 'transfers_v3_reference_key_unique')) {
            Schema::table('transfers', fn (Blueprint $t) => $t->dropUnique('transfers_v3_reference_key_unique'));
        }

        foreach (['transfer_movement_serials' => ['transfer_movement_allocation_id'], 'transfers' => [
            'v3_reference_key', 'created_in_setting_id', 'current_request_revision_id',
            'approval_configuration_revision', 'cancelled_by', 'cancelled_at', 'cancellation_reason',
        ]] as $table => $columns) {
            $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));
            if ($existing !== []) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($existing));
            }
        }

        Schema::dropIfExists('transfer_movement_allocations');
        Schema::dropIfExists('transfer_approval_allocation_serials');
        Schema::dropIfExists('transfer_approval_allocations');
        Schema::dropIfExists('transfer_request_revisions');
    }

    private function createTableIfMissing(string $table, Closure $columns): void
    {
        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) use ($table, $columns) {
            $columns($blueprint);

            if (DB::getDriverName() === 'sqlite') {
                foreach (self::FOREIGN_KEYS[$table] ?? [] as [$column, $references, $name, $onDelete]) {
                    $blueprint->foreign($column, $name)->references('id')->on($references)->onDelete($onDelete);
                }
            }
        });
    }

    /**
     * @param array<string, Closure> $definitions
     */
    private function addMissingColumns(string $table, array $definitions): void
    {
        $missing = array_filter($definitions, fn ($definition, $column) => ! Schema::hasColumn($table, $column), ARRAY_FILTER_USE_BOTH);

        if ($missing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($missing) {
            foreach ($missing as $definition) {
                $definition($blueprint);
            }
        });
    }

    private function ensureIndex(string $table, array $columns, string $name, bool $unique): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($columns, $name) : $t->index($columns, $name));
    }

    private function ensureForeignKey(string $table, string $column, string $references, string $name, string $onDelete): void
    {
        if ($this->foreignKeyExists($table, $name) || $this->columnHasForeignKey($table, $column, $references)) {
            return;
        }

        Schema::table($table, fn (Blueprint $t) => $t->foreign($column, $name)->references('id')->on($references)->onDelete($onDelete));
    }

    private function indexExists(string $table, string $name): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', $table)->where('name', $name)->exists();
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists();
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return false; // inline, unnamed at the engine level
        }

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }

    /**
     * A constraint added by an interrupted earlier attempt under Laravel's
     * generated (short enough) name also satisfies the requirement.
     */
    private function columnHasForeignKey(string $table, string $column, string $references): bool
    {
        return DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->where('referenced_table_name', $references)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private function foreignKeyNamesForColumn(string $table, string $column): array
    {
        return DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->whereNotNull('referenced_table_name')
            ->pluck('constraint_name')
            ->unique()
            ->values()
            ->all();
    }

    private function ensureSqliteColumnForeignKey(string $table, string $column, string $references, string $name, string $onDelete): void
    {
        $existing = collect(DB::select("PRAGMA foreign_key_list(\"{$table}\")"))->pluck('from')->all();

        if (in_array($column, $existing, true)) {
            return;
        }

        $constraint = sprintf(' CONSTRAINT "%s" REFERENCES "%s"("id") ON DELETE %s', $name, $references, strtoupper($onDelete));
        $pattern = '/((?:^|[\s,(])["`]?' . preg_quote($column, '/') . '["`]?\s+[^,]*?)(?=\s*,|\s*\)\s*$)/i';

        $this->rebuildSqliteTable($table, function (string $definition) use ($pattern, $constraint) {
            $updated = preg_replace($pattern, '$1' . $constraint, $definition, 1, $count);

            return $count === 1 ? $updated : null;
        });
    }

    private function makeColumnNullable(string $table, string $column, string $referencedTable): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->makeColumnNullableSqlite($table, $column);

            return;
        }

        $nullable = DB::selectOne(
            'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )?->IS_NULLABLE === 'YES';

        $constraint = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        )?->CONSTRAINT_NAME;

        if (! $nullable) {
            if ($constraint) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
            }

            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` BIGINT UNSIGNED NULL");
        } elseif ($constraint) {
            return; // already relaxed with its foreign key in place
        }

        // Restore the foreign key (also repairs an attempt interrupted after the drop).
        $name = $constraint ?? "{$table}_{$column}_foreign";
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, $name)->references('id')->on($referencedTable)->restrictOnDelete());
    }

    /**
     * SQLite cannot relax NOT NULL in place. Rebuild the table from its own
     * stored definition with only that column's NOT NULL removed, copying
     * every row and recreating every explicit index, so all other columns,
     * defaults and foreign keys survive unchanged.
     */
    private function makeColumnNullableSqlite(string $table, string $column): void
    {
        $pattern = '/((?:^|[\s,(])["`]?' . preg_quote($column, '/') . '["`]?\s+[^,]*?)\s+not\s+null/i';

        $this->rebuildSqliteTable($table, function (string $definition) use ($pattern) {
            $relaxed = preg_replace($pattern, '$1', $definition, 1, $count);

            return $count === 0 ? null : $relaxed; // null: already nullable
        });
    }

    /**
     * Rebuilds a SQLite table from its own stored definition as transformed
     * by $transform (null = nothing to change), copying every row and
     * recreating every explicit index.
     */
    private function rebuildSqliteTable(string $table, Closure $transform): void
    {
        $definition = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])?->sql;

        $relaxed = $definition ? $transform($definition) : null;

        if ($relaxed === null) {
            return;
        }

        $temporary = $table . '__v3_rebuild';
        $relaxed = preg_replace('/^CREATE TABLE\s+["`]?' . preg_quote($table, '/') . '["`]?/i', 'CREATE TABLE "' . $temporary . '"', $relaxed, 1);

        $indexes = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]);

        $foreignKeysEnabled = (bool) (DB::selectOne('PRAGMA foreign_keys')?->foreign_keys ?? true);
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::statement($relaxed);
            DB::statement("INSERT INTO \"{$temporary}\" SELECT * FROM \"{$table}\"");
            DB::statement("DROP TABLE \"{$table}\"");
            DB::statement("ALTER TABLE \"{$temporary}\" RENAME TO \"{$table}\"");

            foreach ($indexes as $index) {
                DB::statement($index->sql);
            }
        } finally {
            if ($foreignKeysEnabled) {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        }
    }
};
