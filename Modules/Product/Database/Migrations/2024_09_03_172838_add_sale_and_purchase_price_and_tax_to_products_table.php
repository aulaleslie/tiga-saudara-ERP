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
            $table->boolean('is_purchased')->default(false)->after('product_stock_alert');
            $table->integer('purchase_price')->nullable()->after('is_purchased');
            $table->integer('purchase_tax')->nullable()->after('purchase_price');
            $table->boolean('is_sold')->default(false)->after('purchase_tax');
            $table->integer('sale_price')->nullable()->after('is_sold');
            $table->integer('sale_tax')->nullable()->after('sale_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'is_purchased',
                'purchase_price',
                'purchase_tax',
                'is_sold',
                'sale_price',
                'sale_tax'
            ]);
        });
    }
};
