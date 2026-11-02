<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Purchase\Services\ApprovedOutstandingPurchaseLinesQuery;

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

        $outstandingLines = app(ApprovedOutstandingPurchaseLinesQuery::class)
            ->build($productIds, $businessIds);

        $rows = DB::query()
            ->fromSub($outstandingLines, 'outstanding_purchase_lines')
            ->select('product_id', 'setting_id')
            ->selectRaw('SUM(outstanding_quantity) as outstanding')
            ->groupBy('product_id', 'setting_id')
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
