<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('pos_sessions')) {
            return;
        }

        Schema::table('pos_sessions', function (Blueprint $table) {
            // Drop the old scoped constraint if it exists
            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
                $table->dropUnique('pos_sessions_user_active_unique');
            }
        });

        // Ensure no duplicates exist before applying the global unique constraint.
        // If duplicates are found, we keep the latest one active and close the others.
        $this->ensureNoActiveDuplicates();

        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->unique(
                ['cashier_user_id', 'active_marker'],
                'pos_sessions_global_active_user_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('pos_sessions')) {
            return;
        }

        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->dropUnique('pos_sessions_global_active_user_unique');

            $table->unique(
                ['setting_id', 'cashier_user_id', 'active_marker'],
                'pos_sessions_user_active_unique'
            );
        });
    }

    private function ensureNoActiveDuplicates(): void
    {
        $duplicates = DB::table('pos_sessions')
            ->whereNotNull('active_marker')
            ->select('cashier_user_id', DB::raw('COUNT(*) as count'))
            ->groupBy('cashier_user_id')
            ->having('count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $sessions = DB::table('pos_sessions')
                ->where('cashier_user_id', $duplicate->cashier_user_id)
                ->whereNotNull('active_marker')
                ->orderBy('opened_at', 'desc')
                ->get();

            // Keep the latest session active
            $sessions->shift();
            
            // Collect IDs of sessions to close
            $idsToClose = [];
            foreach ($sessions as $session) {
                $idsToClose[] = $session->id;
            }

            if (!empty($idsToClose)) {
                DB::table('pos_sessions')
                    ->whereIn('id', $idsToClose)
                    ->update([
                        'active_marker' => null,
                        'status' => 'CLOSED',
                        'closed_at' => now(),
                        'close_notes' => 'Force closed by global uniqueness migration cleanup.'
                    ]);
            }
        }
    }
};
