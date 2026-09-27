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
        Schema::table('product_unit_conversions', function (Blueprint $table) {
            // add price after conversion_factor, defaulting to 0 so existing rows stay valid
            $table->decimal('price', 15, 2)
                ->default(0)
                ->after('conversion_factor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_unit_conversions', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
