<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Repairs the close_variance_approval_threshold column if it was dropped
     * by a previous migration (2026_03_15_060256_drop_close_variance_approval_threshold_from_pos_terminal_policies).
     * This ensures both fresh installs and upgraded databases have the column present.
     */
    public function up(): void
    {
        // If the table exists but the column is missing, recreate it
        if (Schema::hasTable('pos_terminal_policies') && !Schema::hasColumn('pos_terminal_policies', 'close_variance_approval_threshold')) {
            Schema::table('pos_terminal_policies', function (Blueprint $table) {
                // Use the same definition as the creation migration for consistency
                $table->decimal('close_variance_approval_threshold', 15, 2)->default(0)->after('require_opening_float');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('pos_terminal_policies') && Schema::hasColumn('pos_terminal_policies', 'close_variance_approval_threshold')) {
            Schema::table('pos_terminal_policies', function (Blueprint $table) {
                $table->dropColumn('close_variance_approval_threshold');
            });
        }
    }
};
