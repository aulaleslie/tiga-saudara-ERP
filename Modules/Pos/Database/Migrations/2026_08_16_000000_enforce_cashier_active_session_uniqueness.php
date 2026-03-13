<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_sessions')) {
            return;
        }

        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->unique(
                ['setting_id', 'cashier_user_id', 'active_marker'],
                'pos_sessions_user_active_unique'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_sessions')) {
            return;
        }

        Schema::table('pos_sessions', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropUnique('pos_sessions_user_active_unique');
            }
        });
    }
};
