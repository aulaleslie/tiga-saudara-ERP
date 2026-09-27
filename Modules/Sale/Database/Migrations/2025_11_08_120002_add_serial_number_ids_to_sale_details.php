<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add serial_number_ids JSON column to sale_details table for storing
     * arrays of serial number IDs allocated to this sale detail line item.
     * This enables tracking multiple serial numbers per product in a single order.
     */
    public function up(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->json('serial_number_ids')->nullable()->after('product_tax_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn('serial_number_ids');
        });
    }
};
