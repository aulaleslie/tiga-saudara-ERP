<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Preflight check for duplicate transfer_product entries BEFORE any schema modifications
        $duplicates = DB::table('transfer_products')
            ->selectRaw('transfer_id, product_id, COUNT(*) as count')
            ->groupBy('transfer_id', 'product_id')
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $duplicateList = $duplicates->map(fn($d) => "Transfer ID {$d->transfer_id} / Product ID {$d->product_id} ({$d->count} rows)")
                ->join('; ');
            throw new RuntimeException(
                "Cannot add constraints: duplicate transfer_product entries found. "
                . "Please manually review and consolidate these entries before retrying: "
                . $duplicateList
            );
        }

        Schema::table('transfers', function (Blueprint $table) {
            // Check and add foreign keys for users if they don't exist
            $this->addForeignKeySafely($table, 'transfers', 'created_by', 'users', 'id');
            $this->addForeignKeySafely($table, 'transfers', 'approved_by', 'users', 'id', true);
            $this->addForeignKeySafely($table, 'transfers', 'rejected_by', 'users', 'id', true);
            $this->addForeignKeySafely($table, 'transfers', 'dispatched_by', 'users', 'id', true);
            $this->addForeignKeySafely($table, 'transfers', 'received_by', 'users', 'id', true);

            // Add foreign keys for locations
            $this->addForeignKeySafely($table, 'transfers', 'origin_location_id', 'locations', 'id');
            $this->addForeignKeySafely($table, 'transfers', 'destination_location_id', 'locations', 'id');
        });

        Schema::table('transfer_products', function (Blueprint $table) {
            // Make quantity unsigned if possible without breaking data
            // For SQLite, modify column requires doctrine/dbal and can be tricky, so we skip it
            if (DB::getDriverName() !== 'sqlite') {
                $table->unsignedInteger('quantity')->change();
                $table->unsignedInteger('dispatched_quantity')->change();
                $table->unsignedInteger('dispatched_quantity_tax')->change();
                $table->unsignedInteger('dispatched_quantity_non_tax')->change();
                $table->unsignedInteger('dispatched_quantity_broken_tax')->change();
                $table->unsignedInteger('dispatched_quantity_broken_non_tax')->change();
            }
        });

        // Add the unique constraint separately after preflight check confirms no duplicates
        if (!$this->hasIndex('transfer_products', 'transfer_products_transfer_id_product_id_unique')) {
            Schema::table('transfer_products', function (Blueprint $table) {
                $table->unique(['transfer_id', 'product_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $this->dropForeignKeySafely($table, 'transfers', 'created_by');
            $this->dropForeignKeySafely($table, 'transfers', 'approved_by');
            $this->dropForeignKeySafely($table, 'transfers', 'rejected_by');
            $this->dropForeignKeySafely($table, 'transfers', 'dispatched_by');
            $this->dropForeignKeySafely($table, 'transfers', 'received_by');
            $this->dropForeignKeySafely($table, 'transfers', 'origin_location_id');
            $this->dropForeignKeySafely($table, 'transfers', 'destination_location_id');
        });

        Schema::table('transfer_products', function (Blueprint $table) {
            if ($this->hasIndex('transfer_products', 'transfer_products_transfer_id_product_id_unique')) {
                $table->dropUnique('transfer_products_transfer_id_product_id_unique');
            }
        });
    }

    private function addForeignKeySafely(Blueprint $table, string $tableName, string $column, string $referencesTable, string $referencesColumn, bool $nullOnDelete = false): void
    {
        if (Schema::hasColumn($tableName, $column)) {
            $foreignKeyName = "{$tableName}_{$column}_foreign";
            if (!$this->hasForeignKey($tableName, $foreignKeyName)) {
                $foreign = $table->foreign($column)->references($referencesColumn)->on($referencesTable);
                if ($nullOnDelete) {
                    $foreign->nullOnDelete();
                }
            }
        }
    }

    private function dropForeignKeySafely(Blueprint $table, string $tableName, string $column): void
    {
        $foreignKeyName = "{$tableName}_{$column}_foreign";
        if ($this->hasForeignKey($tableName, $foreignKeyName)) {
            $table->dropForeign($foreignKeyName);
        }
    }

    private function hasForeignKey(string $table, string $foreignKeyName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite doesn't support retrieving foreign keys easily by name in this way
            return false;
        }

        $foreignKeys = Schema::getConnection()->getDoctrineSchemaManager()->listTableForeignKeys($table);
        foreach ($foreignKeys as $fk) {
            if ($fk->getName() === $foreignKeyName) {
                return true;
            }
        }
        return false;
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return false;
        }

        $indexes = Schema::getConnection()->getDoctrineSchemaManager()->listTableIndexes($table);
        foreach ($indexes as $index) {
            if ($index->getName() === $indexName) {
                return true;
            }
        }
        return false;
    }
};
