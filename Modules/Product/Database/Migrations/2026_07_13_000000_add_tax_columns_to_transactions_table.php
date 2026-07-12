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
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'quantity_tax')) {
                $table->integer('quantity_tax')->nullable()->default(0)->after('quantity');
            }
            if (!Schema::hasColumn('transactions', 'quantity_non_tax')) {
                $table->integer('quantity_non_tax')->nullable()->default(0)->after('quantity_tax');
            }
            if (!Schema::hasColumn('transactions', 'broken_quantity_tax')) {
                $table->integer('broken_quantity_tax')->nullable()->default(0)->after('broken_quantity');
            }
            if (!Schema::hasColumn('transactions', 'broken_quantity_non_tax')) {
                $table->integer('broken_quantity_non_tax')->nullable()->default(0)->after('broken_quantity_tax');
            }
            if (!Schema::hasColumn('transactions', 'previous_quantity')) {
                $table->integer('previous_quantity')->nullable()->default(0)->after('quantity_non_tax');
            }
            if (!Schema::hasColumn('transactions', 'after_quantity')) {
                $table->integer('after_quantity')->nullable()->default(0)->after('previous_quantity');
            }
            if (!Schema::hasColumn('transactions', 'previous_quantity_at_location')) {
                $table->integer('previous_quantity_at_location')->nullable()->default(0)->after('after_quantity');
            }
            if (!Schema::hasColumn('transactions', 'after_quantity_at_location')) {
                $table->integer('after_quantity_at_location')->nullable()->default(0)->after('previous_quantity_at_location');
            }
            if (!Schema::hasColumn('transactions', 'current_quantity_at_location')) {
                $table->integer('current_quantity_at_location')->nullable()->default(0)->after('after_quantity_at_location');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $columns = [
                'quantity_tax',
                'quantity_non_tax',
                'broken_quantity_tax',
                'broken_quantity_non_tax',
                'previous_quantity',
                'after_quantity',
                'previous_quantity_at_location',
                'after_quantity_at_location',
                'current_quantity_at_location',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
