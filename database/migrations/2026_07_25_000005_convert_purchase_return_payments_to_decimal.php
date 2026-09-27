<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_return_payments', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->change();
        });

        // NOTE: This migration is safe only against an empty table. Existing rows have mixed units
        // so no single backfill expression is correct. The deployment plan ensures this table is empty.
    }

    public function down(): void
    {
        Schema::table('purchase_return_payments', function (Blueprint $table) {
            $table->integer('amount')->change();
        });
    }
};
