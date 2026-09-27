<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('tax_amount', 15, 2)->change();
            $table->decimal('discount_amount', 15, 2)->change();
            $table->decimal('shipping_amount', 15, 2)->change();
            $table->decimal('total_amount', 15, 2)->change();
        });

        // Backfill: convert from cents to rupiah
        DB::table('quotations')->update([
            'tax_amount' => DB::raw('tax_amount / 100'),
            'discount_amount' => DB::raw('discount_amount / 100'),
            'shipping_amount' => DB::raw('shipping_amount / 100'),
            'total_amount' => DB::raw('total_amount / 100'),
        ]);
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->integer('tax_amount')->change();
            $table->integer('discount_amount')->change();
            $table->integer('shipping_amount')->change();
            $table->integer('total_amount')->change();
        });
    }
};
