<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds indexes to speed up POS product search and stock lookups.
     */
    public function up(): void
    {
        // Product search indexes
        Schema::table('products', function (Blueprint $table) {
            $table->index('product_name', 'idx_products_product_name');
            $table->index('product_code', 'idx_products_product_code');
            $table->index('barcode', 'idx_products_barcode');
            $table->index(['serial_number_required', 'id'], 'idx_products_serial_req_id');
        });

        // Product stock indexes for location-based SUM queries
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->index(['location_id', 'product_id'], 'idx_product_stocks_location_product');
        });

        // Unit conversion barcode index
        Schema::table('product_unit_conversions', function (Blueprint $table) {
            $table->index('barcode', 'idx_product_unit_conversions_barcode');
            $table->index('product_id', 'idx_product_unit_conversions_product_id');
        });

        // Serial number indexes
        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->index(['location_id', 'product_id', 'dispatch_detail_id', 'is_broken'], 'idx_psn_location_product_dispatch_broken');
            $table->index('serial_number', 'idx_psn_serial_number');
        });

        // Purchase details index for "purchased products" check
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->index('product_id', 'idx_purchase_details_product_id');
        });

        // Product prices index for setting lookups
        Schema::table('product_prices', function (Blueprint $table) {
            $table->index(['product_id', 'setting_id'], 'idx_product_prices_product_setting');
        });

        // Product bundles index
        Schema::table('product_bundles', function (Blueprint $table) {
            $table->index('parent_product_id', 'idx_product_bundles_parent_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_product_name');
            $table->dropIndex('idx_products_product_code');
            $table->dropIndex('idx_products_barcode');
            $table->dropIndex('idx_products_serial_req_id');
        });

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropIndex('idx_product_stocks_location_product');
        });

        Schema::table('product_unit_conversions', function (Blueprint $table) {
            $table->dropIndex('idx_product_unit_conversions_barcode');
            $table->dropIndex('idx_product_unit_conversions_product_id');
        });

        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->dropIndex('idx_psn_location_product_dispatch_broken');
            $table->dropIndex('idx_psn_serial_number');
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropIndex('idx_purchase_details_product_id');
        });

        Schema::table('product_prices', function (Blueprint $table) {
            $table->dropIndex('idx_product_prices_product_setting');
        });

        Schema::table('product_bundles', function (Blueprint $table) {
            $table->dropIndex('idx_product_bundles_parent_product_id');
        });
    }
};
