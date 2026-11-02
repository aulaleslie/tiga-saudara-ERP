<?php

namespace Modules\Purchase\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;

class ApprovedOutstandingPurchaseLinesQuery
{
    /**
     * Return positive outstanding purchase lines after approved receipts.
     *
     * Purchase detail quantities are stored in canonical base units. Keeping
     * the remainder calculation on each detail prevents over-receipts from
     * offsetting another line.
     *
     * @param array<int> $productIds Empty means all products.
     * @param array<int> $businessIds Empty means all businesses.
     */
    public function build(array $productIds = [], array $businessIds = []): Builder
    {
        $purchaseTable = (new Purchase())->getTable();
        $detailTable = (new PurchaseDetail())->getTable();
        $receivedNoteTable = (new ReceivedNote())->getTable();
        $receivedDetailTable = (new ReceivedNoteDetail())->getTable();

        $approvedReceipts = DB::table("{$receivedDetailTable} as received_details")
            ->join(
                "{$receivedNoteTable} as received_notes",
                'received_details.received_note_id',
                '=',
                'received_notes.id'
            )
            ->where('received_notes.status', ReceivedNote::STATUS_APPROVED)
            ->groupBy('received_details.po_detail_id')
            ->select('received_details.po_detail_id')
            ->selectRaw('SUM(received_details.quantity_received) as approved_received_quantity');

        return DB::table("{$detailTable} as purchase_details")
            ->join(
                "{$purchaseTable} as purchases",
                'purchases.id',
                '=',
                'purchase_details.purchase_id'
            )
            ->leftJoinSub($approvedReceipts, 'approved_receipts', function ($join) {
                $join->on('approved_receipts.po_detail_id', '=', 'purchase_details.id');
            })
            ->whereIn('purchases.status', [
                Purchase::STATUS_APPROVED,
                Purchase::STATUS_RECEIVED_PARTIALLY,
            ])
            ->whereNull('purchases.archived_at')
            ->where('purchase_details.quantity', '>', 0)
            ->whereRaw(
                'purchase_details.quantity > COALESCE(approved_receipts.approved_received_quantity, 0)'
            )
            ->when($productIds !== [], fn (Builder $query) => $query->whereIn('purchase_details.product_id', $productIds))
            ->when($businessIds !== [], fn (Builder $query) => $query->whereIn('purchases.setting_id', $businessIds))
            ->select([
                'purchase_details.id as purchase_detail_id',
                'purchase_details.purchase_id',
                'purchase_details.product_id',
                'purchases.setting_id',
                'purchase_details.quantity',
                'purchase_details.sub_total',
                'purchase_details.product_tax_amount',
            ])
            ->selectRaw(
                'purchase_details.quantity - COALESCE(approved_receipts.approved_received_quantity, 0) as outstanding_quantity'
            );
    }
}
