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
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transfer_products 
                MODIFY COLUMN dispatched_quantity INT UNSIGNED NOT NULL DEFAULT 0,
                MODIFY COLUMN dispatched_quantity_tax INT UNSIGNED NOT NULL DEFAULT 0,
                MODIFY COLUMN dispatched_quantity_non_tax INT UNSIGNED NOT NULL DEFAULT 0,
                MODIFY COLUMN dispatched_quantity_broken_tax INT UNSIGNED NOT NULL DEFAULT 0,
                MODIFY COLUMN dispatched_quantity_broken_non_tax INT UNSIGNED NOT NULL DEFAULT 0
            ");
        } else {
            // For SQLite and other drivers that support Schema change()
            Schema::table('transfer_products', function (Blueprint $table) {
                $table->unsignedInteger('dispatched_quantity')->default(0)->change();
                $table->unsignedInteger('dispatched_quantity_tax')->default(0)->change();
                $table->unsignedInteger('dispatched_quantity_non_tax')->default(0)->change();
                $table->unsignedInteger('dispatched_quantity_broken_tax')->default(0)->change();
                $table->unsignedInteger('dispatched_quantity_broken_non_tax')->default(0)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transfer_products 
                MODIFY COLUMN dispatched_quantity INT UNSIGNED NOT NULL,
                MODIFY COLUMN dispatched_quantity_tax INT UNSIGNED NOT NULL,
                MODIFY COLUMN dispatched_quantity_non_tax INT UNSIGNED NOT NULL,
                MODIFY COLUMN dispatched_quantity_broken_tax INT UNSIGNED NOT NULL,
                MODIFY COLUMN dispatched_quantity_broken_non_tax INT UNSIGNED NOT NULL
            ");
        } else {
            Schema::table('transfer_products', function (Blueprint $table) {
                $table->unsignedInteger('dispatched_quantity')->change();
                $table->unsignedInteger('dispatched_quantity_tax')->change();
                $table->unsignedInteger('dispatched_quantity_non_tax')->change();
                $table->unsignedInteger('dispatched_quantity_broken_tax')->change();
                $table->unsignedInteger('dispatched_quantity_broken_non_tax')->change();
            });
        }
    }
};
