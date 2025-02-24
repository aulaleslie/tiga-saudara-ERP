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
            $table->decimal('tier_1_price', 10)->nullable()->default(0)->after('sale_price');
            $table->decimal('tier_2_price', 10)->nullable()->default(0)->after('tier_1_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['tier_1_price', 'tier_2_price']);
        });
    }
};
