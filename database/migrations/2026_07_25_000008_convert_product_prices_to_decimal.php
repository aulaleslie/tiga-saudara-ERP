<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('product_cost', 15, 2)->change();
            $table->decimal('product_price', 15, 2)->change();
        });

        DB::table('products')->update([
            'product_cost' => DB::raw('product_cost / 100'),
            'product_price' => DB::raw('product_price / 100'),
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('product_cost')->change();
            $table->integer('product_price')->change();
        });
    }
};
