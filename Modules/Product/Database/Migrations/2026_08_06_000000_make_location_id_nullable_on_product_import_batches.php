<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Import types that perform no stock operation (e.g. dual-company tier price)
     * have no meaningful location, so the column must accept null.
     */
    public function up(): void
    {
        Schema::table('product_import_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows created without a location cannot be restored to a non-null value,
        // so drop them before reinstating the constraint.
        \Illuminate\Support\Facades\DB::table('product_import_batches')
            ->whereNull('location_id')
            ->delete();

        Schema::table('product_import_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
        });
    }
};
