<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
                $table->dropUnique('pos_sessions_active_pair_unique');
            }
            $table->unique(
                ['setting_id', 'terminal_id', 'active_marker'],
                'pos_sessions_terminal_active_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
                $table->dropUnique('pos_sessions_terminal_active_unique');
            }
            $table->unique(
                ['setting_id', 'terminal_id', 'cashier_user_id', 'active_marker'],
                'pos_sessions_active_pair_unique'
            );
        });
    }
};
