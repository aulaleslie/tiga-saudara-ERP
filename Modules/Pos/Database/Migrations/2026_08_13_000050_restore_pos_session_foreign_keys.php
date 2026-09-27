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

        // Clean up orphaned records before adding foreign key constraints
        if (Schema::hasTable('pos_transactions') && Schema::hasColumn('pos_transactions', 'source_pos_session_id')) {
            Schema::getConnection()->statement(
                'DELETE FROM pos_transactions WHERE source_pos_session_id IS NOT NULL AND source_pos_session_id NOT IN (SELECT id FROM pos_sessions)'
            );
        }

        if (Schema::hasTable('pos_action_approval_requests') && Schema::hasColumn('pos_action_approval_requests', 'pos_session_id')) {
            Schema::getConnection()->statement(
                'DELETE FROM pos_action_approval_requests WHERE pos_session_id IS NOT NULL AND pos_session_id NOT IN (SELECT id FROM pos_sessions)'
            );
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

        if ($this->hasForeignOnColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $definition($table, $column));
    }

    private function dropForeignIfExists(string $tableName, string $column): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }

        foreach ($this->foreignKeysOnColumn($tableName, $column) as $constraintName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($constraintName));
        }
    }

    private function hasForeignOnColumn(string $tableName, string $column): bool
    {
        return count($this->foreignKeysOnColumn($tableName, $column)) > 0;
    }

    /**
     * @return list<string>
     */
    private function foreignKeysOnColumn(string $tableName, string $column): array
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return [];
        }

        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return $connection->table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $tableName)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->distinct()
            ->pluck('CONSTRAINT_NAME')
            ->map(static fn ($name): string => (string) $name)
            ->values()
            ->all();
    }
};
