<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_unit_conversion_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('product_unit_conversion_prices', 'sales_enabled')) {
                $table->boolean('sales_enabled')->default(true)->after('price');
            }
            if (!Schema::hasColumn('product_unit_conversion_prices', 'purchase_enabled')) {
                $table->boolean('purchase_enabled')->default(true)->after('sales_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_unit_conversion_prices', function (Blueprint $table) {
            if (Schema::hasColumn('product_unit_conversion_prices', 'purchase_enabled')) {
                $table->dropColumn('purchase_enabled');
            }
            if (Schema::hasColumn('product_unit_conversion_prices', 'sales_enabled')) {
                $table->dropColumn('sales_enabled');
            }
        });
    }
};
