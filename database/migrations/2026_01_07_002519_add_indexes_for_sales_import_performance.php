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
        Schema::table('products', function (Blueprint $table) {
            $table->index('product_name');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index('customer_name');
        });

        Schema::table('sales', function (Blueprint $table) {
            // Using a composite index for optimizing the duplicate check: 
            // where('imported_sales_reference_number', $ref)->where('setting_id', $settingId)
            $table->index(['imported_sales_reference_number', 'setting_id'], 'sales_import_ref_setting_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['product_name']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['customer_name']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_import_ref_setting_index');
        });
    }
};
