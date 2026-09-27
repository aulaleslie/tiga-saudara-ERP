<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pos_transactions', 'snapshot_hash')) {
            return;
        }

        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->char('snapshot_hash', 64)
                ->nullable()
                ->after('snapshot_totals');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pos_transactions', 'snapshot_hash')) {
            return;
        }

        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropColumn('snapshot_hash');
        });
    }
};
