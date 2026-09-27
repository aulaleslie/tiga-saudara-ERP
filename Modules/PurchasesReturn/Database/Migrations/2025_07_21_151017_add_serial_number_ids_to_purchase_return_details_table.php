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
        Schema::table('purchase_return_details', function (Blueprint $table) {
            $table->json('serial_number_ids')->nullable()->after('product_tax_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_details', function (Blueprint $table) {
            $table->dropColumn('serial_number_ids');
        });
    }
};
