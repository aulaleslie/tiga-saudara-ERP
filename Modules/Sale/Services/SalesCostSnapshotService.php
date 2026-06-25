<?php

namespace Modules\Sale\Services;

use Carbon\Carbon;
use Modules\Sale\Entities\SaleDetails;

class SalesCostSnapshotService
{
    const SOURCE_NON_STOCK_MANAGED = 'NON_STOCK_MANAGED';
    const SOURCE_MISSING_AVERAGE_PRICE = 'MISSING_AVERAGE_PRICE';
    const SOURCE_CURRENT_AVERAGE_PRICE = 'CURRENT_AVERAGE_PRICE';

    /**
     * Snapshot the cost for a sale detail.
     * Non-stock managed products (services) receive 0 cost.
     * Stock managed products without a purchase price receive 0 cost with a warning source.
     */
    public function snapshotSaleDetailCost(SaleDetails $saleDetail, ?Carbon $snapshotAt = null): void
    {
        $product = $saleDetail->product;
        $snapshotAt = $snapshotAt ?? now();

        $saleDetail->cost_snapshot_at = $snapshotAt;

        if (!$product || !$product->stock_managed) {
            $saleDetail->cost_unit_snapshot = 0;
            $saleDetail->cost_total_snapshot = 0;
            $saleDetail->cost_snapshot_source = self::SOURCE_NON_STOCK_MANAGED;
            return;
        }

        $sale = $saleDetail->sale;
        $settingId = $sale ? $sale->setting_id : null;

        $averagePrice = $product->averagePurchasePrice($settingId);

        if ($averagePrice === null || $averagePrice === '') {
            $saleDetail->cost_unit_snapshot = 0;
            $saleDetail->cost_total_snapshot = 0;
            $saleDetail->cost_snapshot_source = self::SOURCE_MISSING_AVERAGE_PRICE;
            return;
        }

        $unitCost = (float) $averagePrice;
        $qty = (float) $saleDetail->quantity;

        $saleDetail->cost_unit_snapshot = round($unitCost, 6);
        $saleDetail->cost_total_snapshot = round($unitCost * $qty, 2);
        $saleDetail->cost_snapshot_source = self::SOURCE_CURRENT_AVERAGE_PRICE;
    }
}
