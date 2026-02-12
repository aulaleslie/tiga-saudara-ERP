<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropColumnsIfExist('settings', [
            'pos_document_prefix',
            'pos_draft_flow_enabled',
            'pos_draft_expiry_minutes',
            'pos_idle_threshold_minutes',
            'pos_default_cash_threshold',
        ]);

        $this->dropColumnsIfExist('locations', [
            'pos_cash_threshold',
        ]);

        $this->dropColumnsIfExist('payment_methods', [
            'is_available_in_pos',
        ]);
    }

    public function down(): void
    {
        // Forward-only migration.
    }

    private function dropColumnsIfExist(string $tableName, array $columns): void
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }
};
