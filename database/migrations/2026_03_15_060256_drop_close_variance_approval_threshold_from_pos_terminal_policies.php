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
        Schema::table('pos_terminal_policies', function (Blueprint $table) {
            $table->dropColumn('close_variance_approval_threshold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_terminal_policies', function (Blueprint $table) {
            // Note: Column backup should be taken before running this migration
            // Recreate as nullable decimal in case rollback is needed
            $table->decimal('close_variance_approval_threshold', 19, 2)->nullable()->after('cash_threshold');
        });
    }
};
