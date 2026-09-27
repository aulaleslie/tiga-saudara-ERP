<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Create the adjustment_locations pivot table that stores the
     * authoritative ordered set of selected locations for multi-location
     * Stock Opname (schema version 2) documents.
     *
     * Historical single-location documents continue to use
     * adjustments.location_id; this table is additive and does not
     * modify any existing column or row.
     */
    public function up(): void
    {
        Schema::create('adjustment_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('adjustment_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedTinyInteger('position')->nullable()
                ->comment('Stable selection-position within the document; nullable for unordered legacy backfills.');
            $table->timestamps();

            $table->unique(['adjustment_id', 'location_id'], 'adj_loc_unique');
            $table->index('location_id', 'adj_loc_location_idx');

            $table->foreign('adjustment_id')
                ->references('id')
                ->on('adjustments')
                ->onDelete('cascade');

            $table->foreign('location_id')
                ->references('id')
                ->on('locations')
                ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Safe to drop only when no schema-version-2 documents exist.
     * The table may remain harmlessly during application rollback;
     * historical version-1 documents require no rollback transformation.
     */
    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('adjustment_locations')->exists()) {
            throw new \RuntimeException('Cannot rollback adjustment_locations table while schema-version-2 stock opname records exist.');
        }

        Schema::dropIfExists('adjustment_locations');
    }
};
