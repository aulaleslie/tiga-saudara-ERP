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
            $table->unsignedInteger('quantity_tax')->default(0)->after('quantity');
            $table->unsignedInteger('quantity_non_tax')->default(0)->after('quantity_tax');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('adjusted_products', function (Blueprint $table) {
            $table->dropColumn(['quantity_tax', 'quantity_non_tax']);
        });
    }
};
