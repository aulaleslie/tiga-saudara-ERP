<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive upgrade for uom_normalization_batches. The original
 * 2026_08_13_000001_create_uom_normalization_tables migration (already
 * executed in production) created this table with product_unit_conversion_id,
 * source_unit_id, and base_unit_id columns describing a single-conversion
 * model. The base-UOM-correction feature that replaced it needs separate
 * old/new primary and base Unit facts plus additional audit fields.
 *
 * This migration adds the new columns without touching the original ones —
 * product_unit_conversion_id, source_unit_id, and base_unit_id are left in
 * place (unused by current code, but preserved for any historical rows and
 * to avoid rewriting an already-executed production migration).
 *
 * New columns are nullable so existing production batch rows remain valid
 * without inventing old/new UOM facts that cannot be proven for them. All
 * NEW execution writes populate every new column.
 *
 * The original executed migration also made product_unit_conversion_id,
 * source_unit_id, and base_unit_id NOT NULL with no default. Current
 * execution code never writes those legacy columns (it writes the new
 * old/new primary/base columns instead), so they are relaxed to nullable
 * here — additive/widening only, not a drop or rewrite of the executed
 * migration — so new inserts do not violate a NOT NULL constraint the
 * current write path can no longer satisfy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uom_normalization_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('old_primary_unit_id')->nullable()->after('product_unit_conversion_id');
            $table->unsignedBigInteger('new_primary_unit_id')->nullable()->after('old_primary_unit_id');
            $table->unsignedBigInteger('old_base_unit_id')->nullable()->after('new_primary_unit_id');
            $table->unsignedBigInteger('new_base_unit_id')->nullable()->after('old_base_unit_id');

            $table->decimal('rounding_amount', 14, 6)->default(0)->after('conversion_factor');

            $table->boolean('is_acknowledged')->default(false)->after('rounding_amount');
            $table->boolean('is_sales_price_warning_acknowledged')->default(false)->after('is_acknowledged');
            $table->json('conversion_barcode_changes')->nullable()->after('is_sales_price_warning_acknowledged');
            $table->json('location_snapshots')->nullable()->after('conversion_barcode_changes');
        });

        Schema::table('uom_normalization_batches', function (Blueprint $table) {
            $table->foreign('old_primary_unit_id')->references('id')->on('units')->onDelete('restrict');
            $table->foreign('new_primary_unit_id')->references('id')->on('units')->onDelete('restrict');
            $table->foreign('old_base_unit_id')->references('id')->on('units')->onDelete('restrict');
            $table->foreign('new_base_unit_id')->references('id')->on('units')->onDelete('restrict');
        });

        Schema::table('uom_normalization_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('product_unit_conversion_id')->nullable()->change();
            $table->unsignedBigInteger('source_unit_id')->nullable()->change();
            $table->unsignedBigInteger('base_unit_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('uom_normalization_batches', function (Blueprint $table) {
            $table->dropForeign(['old_primary_unit_id']);
            $table->dropForeign(['new_primary_unit_id']);
            $table->dropForeign(['old_base_unit_id']);
            $table->dropForeign(['new_base_unit_id']);
        });

        Schema::table('uom_normalization_batches', function (Blueprint $table) {
            $table->dropColumn([
                'old_primary_unit_id',
                'new_primary_unit_id',
                'old_base_unit_id',
                'new_base_unit_id',
                'rounding_amount',
                'is_acknowledged',
                'is_sales_price_warning_acknowledged',
                'conversion_barcode_changes',
                'location_snapshots',
            ]);
        });

        // Nullability of the legacy columns is intentionally NOT restored to
        // NOT NULL on rollback: any batches inserted after this migration
        // ran will have NULL there, and reverting the constraint would break
        // rollback on a database containing such rows.
    }
};
