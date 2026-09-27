<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_movements', function (Blueprint $table) {
            if (!Schema::hasColumn('transfer_movements', 'tax_setting_id')) {
                $table->foreignId('tax_setting_id')
                    ->nullable()
                    ->after('metadata')
                    ->constrained('settings', 'id', 'fk_tm_tax_setting_id')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('transfer_movements', 'tax_id')) {
                $table->foreignId('tax_id')
                    ->nullable()
                    ->after('tax_setting_id')
                    ->constrained('taxes', 'id', 'fk_tm_tax_id')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('transfer_movements', 'tax_name')) {
                $table->string('tax_name')->nullable()->after('tax_id');
            }
            if (!Schema::hasColumn('transfer_movements', 'tax_rate')) {
                $table->decimal('tax_rate', 8, 4)->nullable()->after('tax_name');
            }
            if (!Schema::hasColumn('transfer_movements', 'tax_resolver_provenance')) {
                $table->string('tax_resolver_provenance', 32)->nullable()->after('tax_rate');
            }
            if (!Schema::hasColumn('transfer_movements', 'tax_resolved_at')) {
                $table->timestamp('tax_resolved_at')->nullable()->after('tax_resolver_provenance');
            }

            // Explicit index for return-receipt lineage lookups under 64 chars
            $table->index(['type', 'return_batch_id', 'status'], 'idx_tm_type_batch_status');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_movements', function (Blueprint $table) {
            $table->dropIndex('idx_tm_type_batch_status');

            if (Schema::hasColumn('transfer_movements', 'tax_setting_id')) {
                $table->dropForeign('fk_tm_tax_setting_id');
                $table->dropColumn('tax_setting_id');
            }
            if (Schema::hasColumn('transfer_movements', 'tax_id')) {
                $table->dropForeign('fk_tm_tax_id');
                $table->dropColumn('tax_id');
            }
            if (Schema::hasColumn('transfer_movements', 'tax_name')) {
                $table->dropColumn('tax_name');
            }
            if (Schema::hasColumn('transfer_movements', 'tax_rate')) {
                $table->dropColumn('tax_rate');
            }
            if (Schema::hasColumn('transfer_movements', 'tax_resolver_provenance')) {
                $table->dropColumn('tax_resolver_provenance');
            }
            if (Schema::hasColumn('transfer_movements', 'tax_resolved_at')) {
                $table->dropColumn('tax_resolved_at');
            }
        });
    }
};
