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
            // Add a new foreign key to the units table for the primary unit
            $table->unsignedBigInteger('unit_id')->nullable()->after('product_price');
            $table->foreign('unit_id')->references('id')->on('units')->onDelete('set null');

            // Add a new foreign key to the units table for the base unit (optional)
            $table->unsignedBigInteger('base_unit_id')->nullable()->after('unit_id');
            $table->foreign('base_unit_id')->references('id')->on('units')->onDelete('set null');

            // Add a new foreign key to the settings table for the setting id
            $table->unsignedBigInteger('setting_id')->after('id');
            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');

            $table->dropForeign(['base_unit_id']);
            $table->dropColumn('base_unit_id');

            $table->dropForeign(['setting_id']);
            $table->dropColumn('setting_id');
        });
    }
};
