<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite' || ! Schema::hasTable('pos_sessions')) {
            return;
        }

        $this->addForeignIfMissing(
            'pos_transactions',
            'source_pos_session_id',
            fn (Blueprint $table, string $column) => $table->foreign($column)
                ->references('id')
                ->on('pos_sessions')
                ->onDelete('restrict')
        );

        $this->addForeignIfMissing(
            'pos_action_approval_requests',
            'pos_session_id',
            fn (Blueprint $table, string $column) => $table->foreign($column)
                ->references('id')
                ->on('pos_sessions')
                ->onDelete('cascade')
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        $this->dropForeignIfExists('pos_action_approval_requests', 'pos_session_id');
        $this->dropForeignIfExists('pos_transactions', 'source_pos_session_id');
    }

    private function addForeignIfMissing(string $tableName, string $column, callable $definition): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $definition) {
            try {
                $definition($table, $column);
            } catch (\Throwable) {
                // No-op if FK already exists or cannot be added in current state.
            }
        });
    }

    private function dropForeignIfExists(string $tableName, string $column): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column) {
            try {
                $table->dropForeign([$column]);
            } catch (\Throwable) {
                // No-op if FK is already absent.
            }
        });
    }
};
