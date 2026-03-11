<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'pos_transactions_enabled')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('pos_transactions_enabled')
                ->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('settings', 'pos_transactions_enabled')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('pos_transactions_enabled');
        });
    }
};
