<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('product_bundles', function (Blueprint $table) {
            $table->decimal('bundle_sale_price', 15, 2)->nullable()->after('price');
        });

        Schema::table('product_bundle_items', function (Blueprint $table) {
            $table->decimal('informational_item_price', 15, 2)->nullable()->after('price');
        });

        // Backfill bundle_sale_price
        DB::statement("
            UPDATE product_bundles
            SET bundle_sale_price = (
                SELECT sale_price
                FROM product_prices
                WHERE product_prices.product_id = product_bundles.parent_product_id
                  AND product_prices.setting_id = product_bundles.setting_id
            )
            WHERE EXISTS (
                SELECT 1
                FROM product_prices
                WHERE product_prices.product_id = product_bundles.parent_product_id
                  AND product_prices.setting_id = product_bundles.setting_id
            )
        ");

        // Backfill informational_item_price
        DB::statement("
            UPDATE product_bundle_items
            SET informational_item_price = (
                SELECT pp.sale_price
                FROM product_prices pp
                JOIN product_bundles pb ON pb.id = product_bundle_items.bundle_id
                WHERE pp.product_id = product_bundle_items.product_id
                  AND pp.setting_id = pb.setting_id
            )
            WHERE EXISTS (
                SELECT 1
                FROM product_prices pp
                JOIN product_bundles pb ON pb.id = product_bundle_items.bundle_id
                WHERE pp.product_id = product_bundle_items.product_id
                  AND pp.setting_id = pb.setting_id
            )
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('product_bundles', function (Blueprint $table) {
            $table->dropColumn('bundle_sale_price');
        });

        Schema::table('product_bundle_items', function (Blueprint $table) {
            $table->dropColumn('informational_item_price');
        });
    }
};
