<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // Replace product-to-bundle-header cascade delete with restrict
            Schema::table('product_bundles', function (Blueprint $table) {
                $table->dropForeign(['parent_product_id']);
                $table->foreign('parent_product_id')
                    ->references('id')
                    ->on('products')
                    ->onDelete('restrict');
            });

            // Replace product-to-bundle-item cascade delete with restrict (bundle_id remains cascade)
            Schema::table('product_bundle_items', function (Blueprint $table) {
                $table->dropForeign(['product_id']);
                $table->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->onDelete('restrict');
            });
        } else {
            DB::unprepared("
                CREATE TRIGGER trg_restrict_product_deletion_in_bundles
                BEFORE DELETE ON products
                FOR EACH ROW
                WHEN (
                    EXISTS (SELECT 1 FROM product_bundles WHERE parent_product_id = OLD.id)
                    OR
                    EXISTS (SELECT 1 FROM product_bundle_items WHERE product_id = OLD.id)
                )
                BEGIN
                    SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed: product is referenced by a product bundle');
                END;
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('product_bundle_items', function (Blueprint $table) {
                $table->dropForeign(['product_id']);
                $table->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->onDelete('cascade');
            });

            Schema::table('product_bundles', function (Blueprint $table) {
                $table->dropForeign(['parent_product_id']);
                $table->foreign('parent_product_id')
                    ->references('id')
                    ->on('products')
                    ->onDelete('cascade');
            });
        } else {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_restrict_product_deletion_in_bundles;");
        }
    }
};
