<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_details', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
            $table->decimal('unit_price', 15, 2)->change();
            $table->decimal('sub_total', 15, 2)->change();
            $table->decimal('product_discount_amount', 15, 2)->change();
            $table->decimal('product_tax_amount', 15, 2)->change();
        });

        // Backfill: convert from cents to rupiah
        DB::table('quotation_details')->update([
            'price' => DB::raw('price / 100'),
            'unit_price' => DB::raw('unit_price / 100'),
            'sub_total' => DB::raw('sub_total / 100'),
            'product_discount_amount' => DB::raw('product_discount_amount / 100'),
            'product_tax_amount' => DB::raw('product_tax_amount / 100'),
        ]);
    }

    public function down(): void
    {
        Schema::table('quotation_details', function (Blueprint $table) {
            $table->integer('price')->change();
            $table->integer('unit_price')->change();
            $table->integer('sub_total')->change();
            $table->integer('product_discount_amount')->change();
            $table->integer('product_tax_amount')->change();
        });
    }
};
