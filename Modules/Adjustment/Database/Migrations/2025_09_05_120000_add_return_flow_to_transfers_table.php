<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('transfers', 'return_dispatched_by')) {
                $table->unsignedBigInteger('return_dispatched_by')->nullable()->after('dispatched_by');
                $table->timestamp('return_dispatched_at')->nullable()->after('return_dispatched_by');
                $table->index('return_dispatched_by', 'transfers_return_dispatched_by_index');
                $table->foreign('return_dispatched_by', 'transfers_return_dispatched_by_foreign')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('transfers', 'return_received_by')) {
                $table->unsignedBigInteger('return_received_by')->nullable()->after('return_dispatched_at');
                $table->timestamp('return_received_at')->nullable()->after('return_received_by');
                $table->index('return_received_by', 'transfers_return_received_by_index');
                $table->foreign('return_received_by', 'transfers_return_received_by_foreign')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });

        DB::statement("ALTER TABLE `transfers` MODIFY `status` ENUM('PENDING','APPROVED','REJECTED','DISPATCHED','RECEIVED','RETURN_DISPATCHED','RETURN_RECEIVED') NOT NULL DEFAULT 'PENDING'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `transfers` MODIFY `status` ENUM('PENDING','APPROVED','REJECTED','DISPATCHED','RECEIVED') NOT NULL DEFAULT 'PENDING'");

        Schema::table('transfers', function (Blueprint $table) {
            if (Schema::hasColumn('transfers', 'return_received_by')) {
                $table->dropForeign('transfers_return_received_by_foreign');
                $table->dropIndex('transfers_return_received_by_index');
                $table->dropColumn(['return_received_by', 'return_received_at']);
            }
            if (Schema::hasColumn('transfers', 'return_dispatched_by')) {
                $table->dropForeign('transfers_return_dispatched_by_foreign');
                $table->dropIndex('transfers_return_dispatched_by_index');
                $table->dropColumn(['return_dispatched_by', 'return_dispatched_at']);
            }
        });
    }
};
