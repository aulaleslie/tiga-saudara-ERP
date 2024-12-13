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
        Schema::table('transactions', function (Blueprint $table) {
            $table->integer('previous_quantity');
            $table->integer('after_quantity');
            $table->integer('previous_quantity_at_location');
            $table->integer('after_quantity_at_location');
            $table->integer('quantity_non_tax');
            $table->integer('quantity_tax');
            $table->integer('broken_quantity_non_tax');
            $table->integer('broken_quantity_tax');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('previous_quantity');
            $table->dropColumn('after_quantity');
            $table->dropColumn('previous_quantity_at_location');
            $table->dropColumn('after_quantity_at_location');
            $table->dropColumn('quantity_non_tax');
            $table->dropColumn('quantity_tax');
            $table->dropColumn('broken_quantity_non_tax');
            $table->dropColumn('broken_quantity_tax');
        });
    }
};
