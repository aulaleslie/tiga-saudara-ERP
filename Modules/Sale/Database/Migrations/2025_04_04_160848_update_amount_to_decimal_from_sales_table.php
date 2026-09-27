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
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('tax_amount', 15, 2)->default(0)->change();
            $table->decimal('discount_amount', 15, 2)->default(0)->change();
            $table->decimal('shipping_amount', 15, 2)->default(0)->change();
            $table->decimal('total_amount', 15, 2)->change();
            $table->decimal('paid_amount', 15, 2)->change();
            $table->decimal('due_amount', 15, 2)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->integer('tax_amount')->default(0)->change();
            $table->integer('discount_amount')->default(0)->change();
            $table->integer('shipping_amount')->default(0)->change();
            $table->integer('total_amount')->change();
            $table->integer('paid_amount')->change();
            $table->integer('due_amount')->change();
        });
    }
};
