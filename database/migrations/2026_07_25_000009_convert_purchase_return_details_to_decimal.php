<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_return_details', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
            $table->decimal('unit_price', 15, 2)->change();
            $table->decimal('sub_total', 15, 2)->change();
            $table->decimal('product_discount_amount', 15, 2)->change();
            $table->decimal('product_tax_amount', 15, 2)->change();
        });

        // NOTE: This migration is safe only against an empty table. Existing rows have mixed units
        // (legacy rows in cents, Livewire rows in rupiah) so no single backfill expression is correct.
        // The deployment plan ensures this table is empty at migration time.
    }

    public function down(): void
    {
        Schema::table('purchase_return_details', function (Blueprint $table) {
            $table->integer('price')->change();
            $table->integer('unit_price')->change();
            $table->integer('sub_total')->change();
            $table->integer('product_discount_amount')->change();
            $table->integer('product_tax_amount')->change();
        });
    }
};
