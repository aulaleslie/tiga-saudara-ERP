<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // Update purchases table
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('tax_percentage', 5, 2)->default(0)->change();
            $table->decimal('tax_amount', 15, 2)->default(0)->change();
            $table->decimal('discount_percentage', 5, 2)->default(0)->change();
            $table->decimal('discount_amount', 15, 2)->default(0)->change();
            $table->decimal('shipping_amount', 15, 2)->default(0)->change();
            $table->decimal('total_amount', 15, 2)->change();
            $table->decimal('paid_amount', 15, 2)->change();
            $table->decimal('due_amount', 15, 2)->change();
        });

        // Update purchase_details table
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
            $table->decimal('unit_price', 15, 2)->change();
            $table->decimal('sub_total', 15, 2)->change();
            $table->decimal('product_discount_amount', 15, 2)->change();
            $table->decimal('product_tax_amount', 15, 2)->change();
        });

        // Update purchase_payments table
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        // Revert changes back to integer
        Schema::table('purchases', function (Blueprint $table) {
            $table->integer('tax_percentage')->default(0)->change();
            $table->integer('tax_amount')->default(0)->change();
            $table->integer('discount_percentage')->default(0)->change();
            $table->integer('discount_amount')->default(0)->change();
            $table->integer('shipping_amount')->default(0)->change();
            $table->integer('total_amount')->change();
            $table->integer('paid_amount')->change();
            $table->integer('due_amount')->change();
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            $table->integer('price')->change();
            $table->integer('unit_price')->change();
            $table->integer('sub_total')->change();
            $table->integer('product_discount_amount')->change();
            $table->integer('product_tax_amount')->change();
        });

        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->integer('amount')->change();
        });
    }
};
