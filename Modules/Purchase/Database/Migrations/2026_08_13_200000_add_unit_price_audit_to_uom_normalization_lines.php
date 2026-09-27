<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uom_normalization_lines', function (Blueprint $table) {
            $table->decimal('normalized_unit_price', 14, 2)->nullable()->after('source_unit_price');
            $table->decimal('unit_price_rounding_effect', 14, 6)->nullable()->after('normalized_unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('uom_normalization_lines', function (Blueprint $table) {
            $table->dropColumn(['normalized_unit_price', 'unit_price_rounding_effect']);
        });
    }
};
