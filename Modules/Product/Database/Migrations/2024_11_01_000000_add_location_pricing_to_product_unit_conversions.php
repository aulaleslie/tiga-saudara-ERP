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
            $table->unsignedBigInteger('location_id')->nullable()->after('base_unit_id');
            $table->decimal('price', 15, 2)->nullable()->after('barcode');

            $table->foreign('location_id')
                ->references('id')
                ->on('locations')
                ->onDelete('cascade');

            $table->unique(['product_id', 'unit_id', 'location_id'], 'product_conversion_location_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_unit_conversions', function (Blueprint $table) {
            $table->dropUnique('product_conversion_location_unique');
            $table->dropForeign(['location_id']);
            $table->dropColumn(['location_id', 'price']);
        });
    }
};
