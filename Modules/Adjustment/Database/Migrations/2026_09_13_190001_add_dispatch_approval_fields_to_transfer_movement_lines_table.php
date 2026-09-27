<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('transfer_movement_lines')) {
            Schema::table('transfer_movement_lines', function (Blueprint $table) {
                if (!Schema::hasColumn('transfer_movement_lines', 'count_confirmed')) {
                    $table->boolean('count_confirmed')->default(false)->after('quantity');
                }
                if (!Schema::hasColumn('transfer_movement_lines', 'applied_quantity_non_tax')) {
                    $table->decimal('applied_quantity_non_tax', 14, 4)->nullable()->after('count_confirmed');
                }
                if (!Schema::hasColumn('transfer_movement_lines', 'applied_quantity_tax')) {
                    $table->decimal('applied_quantity_tax', 14, 4)->nullable()->after('applied_quantity_non_tax');
                }
                if (!Schema::hasColumn('transfer_movement_lines', 'applied_quantity_broken_non_tax')) {
                    $table->decimal('applied_quantity_broken_non_tax', 14, 4)->nullable()->after('applied_quantity_tax');
                }
                if (!Schema::hasColumn('transfer_movement_lines', 'applied_quantity_broken_tax')) {
                    $table->decimal('applied_quantity_broken_tax', 14, 4)->nullable()->after('applied_quantity_broken_non_tax');
                }
                if (!Schema::hasColumn('transfer_movement_lines', 'stock_snapshot_before')) {
                    $table->json('stock_snapshot_before')->nullable()->after('applied_quantity_broken_tax');
                }
                if (!Schema::hasColumn('transfer_movement_lines', 'stock_snapshot_after')) {
                    $table->json('stock_snapshot_after')->nullable()->after('stock_snapshot_before');
                }
                if (!Schema::hasColumn('transfer_movement_lines', 'inventory_transaction_reference')) {
                    $table->string('inventory_transaction_reference', 64)->nullable()->after('stock_snapshot_after');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('transfer_movement_lines')) {
            Schema::table('transfer_movement_lines', function (Blueprint $table) {
                $columns = [
                    'count_confirmed',
                    'applied_quantity_non_tax',
                    'applied_quantity_tax',
                    'applied_quantity_broken_non_tax',
                    'applied_quantity_broken_tax',
                    'stock_snapshot_before',
                    'stock_snapshot_after',
                    'inventory_transaction_reference',
                ];

                foreach ($columns as $column) {
                    if (Schema::hasColumn('transfer_movement_lines', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
