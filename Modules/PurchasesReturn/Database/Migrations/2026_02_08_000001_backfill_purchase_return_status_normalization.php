<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Product\Entities\ProductSerialNumber;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Normalize purchase_returns.status
        $this->normalizePurchaseReturnStatus();

        // 2. Normalize purchase_returns.approval_status and return_dispatch_status to uppercase
        DB::statement("UPDATE purchase_returns SET approval_status = UPPER(approval_status) WHERE approval_status IS NOT NULL");
        DB::statement("UPDATE purchase_returns SET return_dispatch_status = UPPER(return_dispatch_status) WHERE return_dispatch_status IS NOT NULL");

        // 3. Normalize purchase_return_item_settlements.status to uppercase
        DB::statement("UPDATE purchase_return_item_settlements SET status = UPPER(status) WHERE status IS NOT NULL");

        // 4. Reconcile serial lifecycle states
        $this->reconcileSerialLifecycle();
    }

    protected function normalizePurchaseReturnStatus(): void
    {
        $statusMap = [
            'pending approval' => PurchaseReturn::STATUS_PENDING_APPROVAL,
            'pending_approval' => PurchaseReturn::STATUS_PENDING_APPROVAL,
            'draft' => PurchaseReturn::STATUS_DRAFT,
            'approved' => PurchaseReturn::STATUS_AWAITING_DISPATCH,
            'rejected' => PurchaseReturn::STATUS_REJECTED,
            'completed' => PurchaseReturn::STATUS_COMPLETED,
            'settled' => PurchaseReturn::STATUS_COMPLETED,
            'in_return' => PurchaseReturn::STATUS_IN_RETURN,
            'partially_settled' => PurchaseReturn::STATUS_PARTIAL_SETTLEMENT,
        ];

        foreach ($statusMap as $old => $new) {
            DB::table('purchase_returns')
                ->whereRaw("UPPER(status) = ?", [strtoupper($old)])
                ->update(['status' => $new]);
        }
    }

    protected function reconcileSerialLifecycle(): void
    {
        // Serials with is_in_return_process=true OR purchase_return_id NOT NULL but status != 'RETURN_IN_PROCESS'
        DB::table('product_serial_numbers')
            ->where(function($q) {
                $q->where('is_in_return_process', true)
                  ->orWhereNotNull('purchase_return_id');
            })
            ->whereRaw("UPPER(status) != 'RETURN_IN_PROCESS'")
            ->update(['status' => ProductSerialNumber::STATUS_RETURN_IN_PROCESS]);

        // Serials with status = 'RETURN_IN_PROCESS' but is_in_return_process=false
        DB::table('product_serial_numbers')
            ->whereRaw("UPPER(status) = 'RETURN_IN_PROCESS'")
            ->where(function($q) {
                $q->where('is_in_return_process', false)
                  ->orWhereNull('is_in_return_process');
            })
            ->update(['is_in_return_process' => true]);
            
        // Normalize other statuses to uppercase
        DB::statement("UPDATE product_serial_numbers SET status = UPPER(status) WHERE status IS NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Down migration intentionally limited to basic casing if possible, 
        // but since we are normalizing to canonical form, strict reversal is not strictly required
        // and could be destructive if we don't know the exact previous state.
    }
};
