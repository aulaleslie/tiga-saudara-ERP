<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropForeignAndColumnIfExists('sales', 'pos_session_id');
        $this->dropForeignAndColumnIfExists('sales', 'pos_receipt_id');

        $this->dropForeignAndColumnIfExists('sale_payments', 'pos_session_id');
        $this->dropForeignAndColumnIfExists('sale_payments', 'pos_receipt_id');

        // These tables survive this cleanup and must be detached from the legacy pos_sessions table first.
        $this->dropForeignIfExists('pos_transactions', 'source_pos_session_id');
        $this->dropForeignIfExists('pos_action_approval_requests', 'pos_session_id');

        $tables = [
            'pos_audit_logs',
            'pos_submit_idempotencies',
            'pos_draft_items',
            'pos_drafts',
            'pos_receipts',
            'cashier_cash_movements',
            'pos_sessions',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Forward-only migration.
    }

    private function dropForeignAndColumnIfExists(string $tableName, string $column): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (! Schema::hasColumn($tableName, $column)) {
            return;
        }

        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table($tableName, function (Blueprint $table) use ($column, $isSqlite) {
            if (! $isSqlite) {
                try {
                    $table->dropForeign([$column]);
                } catch (\Throwable) {
                    // No-op if foreign key name differs or FK is already absent.
                }
            }

            $table->dropColumn($column);
        });
    }

    private function dropForeignIfExists(string $tableName, string $column): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->dropForeign([$column]);
            });
        } catch (\Throwable) {
            // No-op if foreign key name differs or FK is already absent.
        }
    }
};
