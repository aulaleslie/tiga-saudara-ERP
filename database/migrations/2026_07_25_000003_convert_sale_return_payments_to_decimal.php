<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_return_payments', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->change();
        });

        // Backfill: convert from cents to rupiah
        DB::table('sale_return_payments')->update([
            'amount' => DB::raw('amount / 100'),
        ]);
    }

    public function down(): void
    {
        Schema::table('sale_return_payments', function (Blueprint $table) {
            $table->integer('amount')->change();
        });
    }
};
