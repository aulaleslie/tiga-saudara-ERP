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
        Schema::table('product_bundles', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('active_to');
        });

        Schema::table('product_bundle_items', function (Blueprint $table) {
            $table->unique(['bundle_id', 'product_id'], 'pbi_bundle_product_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_bundle_items', function (Blueprint $table) {
            $table->dropUnique('pbi_bundle_product_unique');
        });

        Schema::table('product_bundles', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
