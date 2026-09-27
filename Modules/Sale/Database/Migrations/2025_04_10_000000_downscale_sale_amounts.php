<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
            $table->decimal('unit_price', 15, 2)->change();
            $table->decimal('sub_total', 15, 2)->change();
            $table->decimal('product_discount_amount', 15, 2)->change();
            $table->decimal('product_tax_amount', 15, 2)->change();
        });

        Schema::table('sale_bundle_items', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
            $table->decimal('sub_total', 15, 2)->change();
        });

        DB::transaction(function () {
            DB::statement('UPDATE sales SET tax_amount = ROUND(tax_amount / 100, 2), discount_amount = ROUND(discount_amount / 100, 2), shipping_amount = ROUND(shipping_amount / 100, 2), total_amount = ROUND(total_amount / 100, 2), paid_amount = ROUND(paid_amount / 100, 2), due_amount = ROUND(due_amount / 100, 2)');
            DB::statement('UPDATE sale_payments SET amount = ROUND(amount / 100, 2)');
            DB::statement('UPDATE sale_details SET price = ROUND(price / 100, 2), unit_price = ROUND(unit_price / 100, 2), sub_total = ROUND(sub_total / 100, 2), product_discount_amount = ROUND(product_discount_amount / 100, 2), product_tax_amount = ROUND(product_tax_amount / 100, 2)');
            DB::statement('UPDATE sale_bundle_items SET price = ROUND(price / 100, 2), sub_total = ROUND(sub_total / 100, 2)');
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::statement('UPDATE sale_bundle_items SET price = price * 100, sub_total = sub_total * 100');
            DB::statement('UPDATE sale_details SET price = price * 100, unit_price = unit_price * 100, sub_total = sub_total * 100, product_discount_amount = product_discount_amount * 100, product_tax_amount = product_tax_amount * 100');
            DB::statement('UPDATE sale_payments SET amount = amount * 100');
            DB::statement('UPDATE sales SET tax_amount = tax_amount * 100, discount_amount = discount_amount * 100, shipping_amount = shipping_amount * 100, total_amount = total_amount * 100, paid_amount = paid_amount * 100, due_amount = due_amount * 100');
        });

        Schema::table('sale_bundle_items', function (Blueprint $table) {
            $table->integer('price')->change();
            $table->integer('sub_total')->change();
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->integer('price')->change();
            $table->integer('unit_price')->change();
            $table->integer('sub_total')->change();
            $table->integer('product_discount_amount')->change();
            $table->integer('product_tax_amount')->change();
        });
    }
};
