<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;

class CrossBusinessInDeliveryStockService
{
    /**
     * Compute in-delivery (outstanding purchase) quantities keyed by product ID and setting ID.
     *
     * Formula per purchase detail:
     *   outstanding = max(0, detail.quantity - approved_received_quantity)
     *
     * Rules:
     * - Only purchases with status IN ('APPROVED', 'RECEIVED PARTIALLY')
     * - Exclude archived purchases (archived_at IS NOT NULL)
     * - Subtract ONLY quantities from received notes with status = 'APPROVED'
     * - Clamp per detail to >= 0 before summing by product and setting (prevents over-receipt from offsetting other lines)
     * - Preserves decimal quantities
     *
     * @param array<int> $productIds
     * @param array<int> $businessIds
     * @return array<int, array<int, float>> Map of [product_id => [setting_id => in_delivery_qty]]
     */
    public function getInDeliveryMatrix(array $productIds, array $businessIds): array
    {
        if (empty($productIds) || empty($businessIds)) {
            return [];
        }

        $purchaseTable = (new Purchase())->getTable();
        $detailTable = (new PurchaseDetail())->getTable();
        $rnTable = (new ReceivedNote())->getTable();
        $rnDetailTable = (new ReceivedNoteDetail())->getTable();

        // Aggregate approved-received quantity per purchase detail in SQL via a correlated
        // subquery, then clamp each detail's outstanding quantity to >= 0 and sum by
        // product/setting — all performed in the database to avoid loading every
        // outstanding purchase detail row into PHP and to keep decimal precision.
        $receivedSubquery = DB::table($rnDetailTable)
            ->join($rnTable, "{$rnDetailTable}.received_note_id", '=', "{$rnTable}.id")
            ->whereColumn("{$rnDetailTable}.po_detail_id", "{$detailTable}.id")
            ->where("{$rnTable}.status", ReceivedNote::STATUS_APPROVED)
            ->selectRaw("COALESCE(SUM({$rnDetailTable}.quantity_received), 0)");

        $outstandingExpr = "({$detailTable}.quantity - ({$receivedSubquery->toSql()}))";

        $rows = DB::table($detailTable)
            ->join($purchaseTable, "{$purchaseTable}.id", '=', "{$detailTable}.purchase_id")
            ->whereIn("{$detailTable}.product_id", $productIds)
            ->whereIn("{$purchaseTable}.setting_id", $businessIds)
            ->whereIn("{$purchaseTable}.status", [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY])
            ->whereNull("{$purchaseTable}.archived_at")
            ->select([
                "{$detailTable}.product_id",
                "{$purchaseTable}.setting_id",
                DB::raw("SUM(CASE WHEN {$outstandingExpr} > 0 THEN {$outstandingExpr} ELSE 0 END) as outstanding"),
            ])
            ->addBinding(array_merge($receivedSubquery->getBindings(), $receivedSubquery->getBindings()), 'select')
            ->groupBy("{$detailTable}.product_id", "{$purchaseTable}.setting_id")
            ->get();

        $matrix = [];

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $settingId = (int) $row->setting_id;

            if (!isset($matrix[$productId])) {
                $matrix[$productId] = [];
            }

            $matrix[$productId][$settingId] = (float) $row->outstanding;
        }

        return $matrix;
    }
}
