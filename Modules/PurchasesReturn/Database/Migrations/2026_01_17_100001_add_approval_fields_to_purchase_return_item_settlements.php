<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\PurchasesReturn\Entities\PurchaseReturnSettlement;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Ticket 1: Add per-line approval fields to settlement items.
     * - Make method nullable for pending/draft lines
     * - Add status, submitted, approved, rejected metadata columns
     * - Backfill existing rows based on header settlement status
     */
    public function up(): void
    {
        Schema::table('purchase_return_item_settlements', function (Blueprint $table) {
            // Make method nullable to allow pending/draft lines without a method
            $table->string('method')->nullable()->change();
            
            // Add status column with default 'draft'
            $table->string('status')->default(PurchaseReturnItemSettlement::STATUS_DRAFT)->after('target_purchase_id');
            
            // Add submission tracking
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->unsignedBigInteger('submitted_by')->nullable()->after('submitted_at');
            
            // Add approval tracking
            $table->timestamp('approved_at')->nullable()->after('submitted_by');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            
            // Add rejection tracking
            $table->timestamp('rejected_at')->nullable()->after('approved_by');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('rejected_at');
            $table->text('rejection_reason')->nullable()->after('rejected_by');
            
            // Add foreign keys for user columns
            $table->foreign('submitted_by', 'items_settlement_sub_by_foreign')
                ->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by', 'items_settlement_app_by_foreign')
                ->references('id')->on('users')->onDelete('set null');
            $table->foreign('rejected_by', 'items_settlement_rej_by_foreign')
                ->references('id')->on('users')->onDelete('set null');
        });

        // Backfill existing rows based on header settlement status
        $this->backfillExistingData();
    }

    /**
     * Backfill existing settlement items based on their parent header settlement status.
     * 
     * Logic:
     * - If header settlement status is 'approved' or 'completed': item status = 'approved'
     * - If header settlement status is 'pending': item status = 'submitted'
     * - Otherwise: item status = 'draft'
     */
    protected function backfillExistingData(): void
    {
        // Get all existing item settlements with their header settlements
        $items = DB::table('purchase_return_item_settlements as items')
            ->leftJoin('purchase_return_settlements as headers', function ($join) {
                $join->on('items.purchase_return_id', '=', 'headers.purchase_return_id');
            })
            ->select([
                'items.id',
                'headers.status as header_status',
                'headers.approved_at as header_approved_at',
                'headers.approved_by as header_approved_by',
                'headers.submitted_at as header_submitted_at',
                'headers.submitted_by as header_submitted_by',
            ])
            ->get();

        foreach ($items as $item) {
            $updateData = [];

            if (in_array($item->header_status, ['approved', 'completed'])) {
                // Mark as approved, copy metadata from header
                $updateData = [
                    'status' => PurchaseReturnItemSettlement::STATUS_APPROVED,
                    'approved_at' => $item->header_approved_at,
                    'approved_by' => $item->header_approved_by,
                    'submitted_at' => $item->header_submitted_at,
                    'submitted_by' => $item->header_submitted_by,
                ];
            } elseif ($item->header_status === 'pending') {
                // Mark as submitted, copy metadata from header
                $updateData = [
                    'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
                    'submitted_at' => $item->header_submitted_at,
                    'submitted_by' => $item->header_submitted_by,
                ];
            }
            // If no header or draft status, keep default 'draft' status

            if (!empty($updateData)) {
                DB::table('purchase_return_item_settlements')
                    ->where('id', $item->id)
                    ->update($updateData);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_item_settlements', function (Blueprint $table) {
            // Drop foreign keys first
            $table->dropForeign('items_settlement_sub_by_foreign');
            $table->dropForeign('items_settlement_app_by_foreign');
            $table->dropForeign('items_settlement_rej_by_foreign');
            
            // Drop new columns
            $table->dropColumn([
                'status',
                'submitted_at',
                'submitted_by',
                'approved_at',
                'approved_by',
                'rejected_at',
                'rejected_by',
                'rejection_reason',
            ]);
        });

        // Revert method to NOT NULL (only safe if no null values exist)
        // First, set any null methods to a default value
        DB::table('purchase_return_item_settlements')
            ->whereNull('method')
            ->delete(); // Delete rows without method (or set a default if preferred)

        Schema::table('purchase_return_item_settlements', function (Blueprint $table) {
            $table->string('method')->nullable(false)->change();
        });
    }
};
