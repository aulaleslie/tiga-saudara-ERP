<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fixed lineage discriminator shared by every FORWARD_DISPATCH, FORWARD_RECEIPT, and
     * RETURN_RECEIPT movement, which each still have exactly one lineage per (transfer_id, type).
     * Application code (TransferMovementDocumentService) must write this same constant for those
     * types so the NOT NULL unique index below enforces the original single-lineage invariant.
     */
    private const SINGLETON_LINEAGE = '00000000-0000-0000-0000-000000000000';

    public function up(): void
    {
        // Nullable during backfill only: every row (existing and new) is immediately assigned a
        // non-null lineage discriminator below, then the column is made NOT NULL so the unique
        // index below cannot be defeated by MySQL/SQLite's "NULLs are distinct" behavior.
        Schema::table('transfer_movements', function (Blueprint $table) {
            $table->string('return_batch_id', 36)->nullable()->after('supersedes_movement_id');
        });

        // Non-RETURN_DISPATCH movements have exactly one lineage per (transfer_id, type); assign
        // them the fixed sentinel used by the application for every future insert of those types.
        DB::table('transfer_movements')
            ->where('type', '!=', 'RETURN_DISPATCH')
            ->update(['return_batch_id' => self::SINGLETON_LINEAGE]);

        // Existing RETURN_DISPATCH rows (none expected pre-Delivery-8, since the route was gated
        // off) each become their own single-movement lineage keyed by their own id.
        DB::table('transfer_movements')
            ->where('type', 'RETURN_DISPATCH')
            ->whereNull('return_batch_id')
            ->orderBy('id')
            ->get(['id'])
            ->each(function ($row) {
                DB::table('transfer_movements')
                    ->where('id', $row->id)
                    ->update(['return_batch_id' => (string) \Illuminate\Support\Str::uuid()]);
            });

        Schema::table('transfer_movements', function (Blueprint $table) {
            $table->string('return_batch_id', 36)->nullable(false)->change();
        });

        Schema::table('transfer_movements', function (Blueprint $table) {
            // Revision numbering becomes per-lineage: (transfer_id, type, revision) alone is no longer
            // unique because multiple independent RETURN_DISPATCH batch lineages each start at revision 1.
            // return_batch_id is now NOT NULL for every row (a fixed sentinel for single-lineage types,
            // a real UUID per RETURN_DISPATCH batch), so this composite unique key fully replaces and
            // strictly strengthens the dropped constraint for every movement type.
            $table->dropUnique('transfer_movements_transfer_type_revision_unique');

            $table->unique(
                ['transfer_id', 'type', 'return_batch_id', 'revision'],
                'transfer_movements_batch_revision_unique'
            );
            $table->index(['return_batch_id'], 'transfer_movements_return_batch_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_movements', function (Blueprint $table) {
            $table->dropUnique('transfer_movements_batch_revision_unique');
            $table->dropIndex('transfer_movements_return_batch_id_index');
            $table->dropColumn('return_batch_id');

            $table->unique(['transfer_id', 'type', 'revision'], 'transfer_movements_transfer_type_revision_unique');
        });
    }
};
