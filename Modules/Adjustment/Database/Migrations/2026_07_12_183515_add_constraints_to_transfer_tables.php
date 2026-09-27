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

        // Check and add foreign keys for users if they don't exist
        $this->addForeignKeySafely('transfers', 'created_by', 'users', 'id');
        $this->addForeignKeySafely('transfers', 'approved_by', 'users', 'id', true);
        $this->addForeignKeySafely('transfers', 'rejected_by', 'users', 'id', true);
        $this->addForeignKeySafely('transfers', 'dispatched_by', 'users', 'id', true);
        $this->addForeignKeySafely('transfers', 'received_by', 'users', 'id', true);

        // Add foreign keys for locations
        $this->addForeignKeySafely('transfers', 'origin_location_id', 'locations', 'id');
        $this->addForeignKeySafely('transfers', 'destination_location_id', 'locations', 'id');

        // Make quantity unsigned if possible without breaking data
        // For SQLite, modify column requires doctrine/dbal and can be tricky, so we skip it
        if (DB::getDriverName() !== 'sqlite') {
            try {
                Schema::table('transfer_products', function (Blueprint $table) {
                    $table->unsignedInteger('quantity')->change();
                    $table->unsignedInteger('dispatched_quantity')->change();
                    $table->unsignedInteger('dispatched_quantity_tax')->change();
                    $table->unsignedInteger('dispatched_quantity_non_tax')->change();
                    $table->unsignedInteger('dispatched_quantity_broken_tax')->change();
                    $table->unsignedInteger('dispatched_quantity_broken_non_tax')->change();
                });
            } catch (\Exception $e) {
                // Ignore DBAL change errors
            }
        }

        // Add the unique constraint separately
        try {
            Schema::table('transfer_products', function (Blueprint $table) {
                $table->unique(['transfer_id', 'product_id'], 'transfer_products_transfer_id_product_id_unique');
            });
        } catch (\Exception $e) {
            // Ignore if index already exists
            if (!str_contains(strtolower($e->getMessage()), 'already exists') && !str_contains(strtolower($e->getMessage()), 'duplicate key')) {
                throw $e;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropForeignKeySafely('transfers', 'created_by');
        $this->dropForeignKeySafely('transfers', 'approved_by');
        $this->dropForeignKeySafely('transfers', 'rejected_by');
        $this->dropForeignKeySafely('transfers', 'dispatched_by');
        $this->dropForeignKeySafely('transfers', 'received_by');
        $this->dropForeignKeySafely('transfers', 'origin_location_id');
        $this->dropForeignKeySafely('transfers', 'destination_location_id');

        try {
            Schema::table('transfer_products', function (Blueprint $table) {
                $table->dropUnique('transfer_products_transfer_id_product_id_unique');
            });
        } catch (\Exception $e) {
            // Ignore if index doesn't exist
        }
    }

    private function addForeignKeySafely(string $tableName, string $column, string $referencesTable, string $referencesColumn, bool $nullOnDelete = false): void
    {
        if (Schema::hasColumn($tableName, $column)) {
            try {
                Schema::table($tableName, function (Blueprint $table) use ($column, $referencesTable, $referencesColumn, $nullOnDelete) {
                    $foreign = $table->foreign($column)->references($referencesColumn)->on($referencesTable);
                    if ($nullOnDelete) {
                        $foreign->nullOnDelete();
                    }
                });
            } catch (\Exception $e) {
                // Ignore if it fails (likely already exists)
            }
        }
    }

    private function dropForeignKeySafely(string $tableName, string $column): void
    {
        $foreignKeyName = "{$tableName}_{$column}_foreign";
        try {
            Schema::table($tableName, function (Blueprint $table) use ($foreignKeyName) {
                $table->dropForeign($foreignKeyName);
            });
        } catch (\Exception $e) {
            // Ignore if foreign key doesn't exist
        }
    }
};
