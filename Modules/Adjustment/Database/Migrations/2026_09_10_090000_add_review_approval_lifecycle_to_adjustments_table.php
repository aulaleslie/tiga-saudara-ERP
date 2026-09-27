<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');

            $table->foreignId('approved_by')->nullable()->after('submitted_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->foreignId('rejected_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');

            $table->json('approval_result')->nullable()->after('rejection_reason');

            $table->index('status');
            $table->index('submitted_at');
        });

        // Migrate only normal versioned Stock Opname documents (count_draft present)
        // that are still in the legacy 'pending' status to the new 'draft' status.
        // Legacy (non-versioned) and breakage adjustments keep 'pending'.
        // Adjustment extends BaseModel, which uppercases all string attributes on
        // write, so `type`/`status` are persisted uppercase regardless of the
        // lowercase literals application code passes in.
        DB::table('adjustments')
            ->whereRaw('UPPER(type) = ?', ['NORMAL'])
            ->whereRaw('UPPER(status) = ?', [AdjustmentStatus::Pending->value])
            ->whereNotNull('count_draft')
            ->update(['status' => AdjustmentStatus::Draft->value]);
    }

    public function down(): void
    {
        DB::table('adjustments')
            ->whereRaw('UPPER(type) = ?', ['NORMAL'])
            ->whereRaw('UPPER(status) = ?', [AdjustmentStatus::Draft->value])
            ->whereNotNull('count_draft')
            ->update(['status' => AdjustmentStatus::Pending->value]);

        // SQLite cannot drop foreign keys (would require full table recreation)
        // and cannot process more than one dropColumn/dropConstrainedForeignId
        // per Schema::table() call, so each drop runs in its own call and the
        // foreign key constraint drop is skipped on SQLite (test/dev only;
        // dropping the column still removes it either way).
        $driver = DB::getDriverName();

        Schema::table('adjustments', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
        Schema::table('adjustments', function (Blueprint $table) {
            $table->dropIndex(['submitted_at']);
        });

        foreach (['submitted_by', 'approved_by', 'rejected_by'] as $column) {
            if ($driver !== 'sqlite') {
                Schema::table('adjustments', function (Blueprint $table) use ($column) {
                    $table->dropForeign([$column]);
                });
            }
            Schema::table('adjustments', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }

        foreach (['submitted_at', 'approved_at', 'rejected_at', 'rejection_reason', 'approval_result'] as $column) {
            Schema::table('adjustments', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }
};
