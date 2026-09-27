<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add default value to product_stock_alert field.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('product_stock_alert')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('product_stock_alert')->change();
        });
    }
};
