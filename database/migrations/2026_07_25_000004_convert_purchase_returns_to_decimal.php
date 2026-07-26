<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 2)->change();
            $table->decimal('paid_amount', 15, 2)->change();
        });

        // NOTE: This migration is safe only against an empty table. Existing rows have mixed units
        // (legacy rows in cents, Livewire rows in rupiah) so no single backfill expression is correct.
        // The deployment plan ensures this table is empty at migration time.
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->integer('total_amount')->change();
            $table->integer('paid_amount')->change();
        });
    }
};
