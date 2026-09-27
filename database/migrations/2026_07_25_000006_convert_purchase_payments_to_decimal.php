<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->change();
        });

        DB::table('purchase_payments')->update([
            'amount' => DB::raw('amount / 100'),
        ]);
    }

    public function down(): void
    {
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->integer('amount')->change();
        });
    }
};
