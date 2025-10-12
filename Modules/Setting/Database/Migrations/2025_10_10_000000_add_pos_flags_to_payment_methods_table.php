<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('is_cash')->default(false)->after('coa_id');
            $table->boolean('is_available_in_pos')->default(false)->after('is_cash');
        });

        DB::table('payment_methods')->update([
            'is_cash' => false,
            'is_available_in_pos' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['is_cash', 'is_available_in_pos']);
        });
    }
};
