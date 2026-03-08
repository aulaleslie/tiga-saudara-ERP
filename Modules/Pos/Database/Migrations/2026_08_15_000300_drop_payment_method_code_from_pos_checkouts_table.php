<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Safety guard: Abort if any pos_checkouts still have NULL payment_method_id
        $nullCount = DB::table('pos_checkouts')
            ->whereNull('payment_method_id')
            ->count();

        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Cannot drop payment_method_code column: {$nullCount} pos_checkouts still have NULL payment_method_id. "
                . "Please run backfill migration first to populate all payment method IDs."
            );
        }

        // Skip for SQLite
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('pos_checkouts', function (Blueprint $table) {
            if (Schema::hasColumn('pos_checkouts', 'payment_method_code')) {
                $table->dropColumn('payment_method_code');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Skip for SQLite
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('pos_checkouts', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_checkouts', 'payment_method_code')) {
                $table->string('payment_method_code', 20)->nullable()->after('payment_method_id');
            }
        });
    }
};
