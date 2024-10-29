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
        // Move fields from product_stocks to products
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('last_purchase_price', 10, 2)->nullable()->after('sale_tax');
            $table->decimal('average_purchase_price', 10, 2)->nullable()->after('last_purchase_price');
        });

        // Set sale_price column to nullable with a default value
        Schema::table('products', function (Blueprint $table) {
            $table->integer('sale_price')->nullable()->default(0)->change();
        });

        // Change the sale_price column to decimal
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('sale_price', 10, 2)->nullable()->default(0)->change();
        });

        // Drop columns from product_stocks table
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn(['last_purchase_price', 'average_purchase_price', 'sale_price']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert the changes
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['last_purchase_price', 'average_purchase_price']);
            $table->integer('sale_price')->change();
        });

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->decimal('last_purchase_price', 10, 2)->nullable();
            $table->decimal('average_purchase_price', 10, 2)->nullable();
            $table->decimal('sale_price', 10, 2)->nullable();
        });
    }
};
