<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Make the category_id nullable
            $table->unsignedBigInteger('category_id')->nullable()->change();

            // Add brand_id as a nullable foreign key
            $table->unsignedInteger('brand_id')->nullable()->after('category_id');
            $table->foreign('brand_id')->references('id')->on('brands')->onDelete('set null');

            // Add stock_managed column
            $table->boolean('stock_managed')->default(true)->after('product_tax_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Revert the category_id column to be non-nullable
            $table->unsignedBigInteger('category_id')->nullable(false)->change();

            // Remove the brand_id column and its foreign key constraint
            $table->dropForeign(['brand_id']);
            $table->dropColumn('brand_id');

            // Remove the stock_managed column
            $table->dropColumn('stock_managed');
        });
    }
};
