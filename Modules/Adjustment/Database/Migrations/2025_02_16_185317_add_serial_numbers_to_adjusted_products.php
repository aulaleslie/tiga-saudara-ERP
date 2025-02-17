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
        Schema::table('adjusted_products', function (Blueprint $table) {
            $table->text('serial_numbers')->nullable()->after('quantity');
            // Store serial numbers as a comma-separated string
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('adjusted_products', function (Blueprint $table) {
            $table->dropColumn('serial_numbers');
        });
    }
};
