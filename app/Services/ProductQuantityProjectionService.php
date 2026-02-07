<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;

class ProductQuantityProjectionService
{
    /**
     * Get 5 projected quantities for a specific product and setting.
     * 
     * @param int $productId
     * @param int $settingId
     * @return array
     */
    public static function getProjectedQuantities(int $productId, int $settingId): array
    {
        $stocks = self::getStockQuantities($productId, $settingId);
        $onOrder = self::getOnOrderStock($productId, $settingId);
        $inReturn = self::getInReturnProcessStock($productId, $settingId);

        return array_merge($stocks, [
            'on_order_stock' => $onOrder,
            'in_return_process_stock' => $inReturn,
        ]);
    }

    /**
     * Get stock quantities (total, good, broken) from product_stocks table.
     */
    public static function getStockQuantities(int $productId, int $settingId): array
    {
        $stockData = ProductStock::query()
            ->join('locations', 'product_stocks.location_id', '=', 'locations.id')
            ->where('product_stocks.product_id', $productId)
            ->where('locations.setting_id', $settingId)
            ->selectRaw('SUM(quantity) as total_stock, SUM(broken_quantity) as broken_stock')
            ->first();

        $total = (int) ($stockData->total_stock ?? 0);
        $broken = (int) ($stockData->broken_stock ?? 0);

        return [
            'total_stock' => $total,
            'good_stock'  => $total - $broken,
            'broken_stock' => $broken,
        ];
    }

    /**
     * Get stock that is "on-order" (Purchase Approved but either not received or partially received).
     */
    public static function getOnOrderStock($productId, $settingId): int
    {
        $purchaseTable = (new Purchase())->getTable();
        $detailTable = (new PurchaseDetail())->getTable();
        $rnTable = (new ReceivedNote())->getTable();
        $rnDetailTable = (new ReceivedNoteDetail())->getTable();

        // Formula (PR1-BE-02 approved):
        // Sum max(purchase_details.quantity - approved_received_qty, 0)
        // From purchases where status in [APPROVED, RECEIVED PARTIALLY]
        // approved_received_qty comes from received_note_details joined to received_notes.status = APPROVED
        // Exclude archived documents.

        $results = DB::table($purchaseTable)
            ->join($detailTable, "{$purchaseTable}.id", '=', "{$detailTable}.purchase_id")
            ->where("{$detailTable}.product_id", $productId)
            ->where("{$purchaseTable}.setting_id", $settingId)
            ->whereIn("{$purchaseTable}.status", [Purchase::STATUS_APPROVED, Purchase::STATUS_RECEIVED_PARTIALLY])
            ->whereNull("{$purchaseTable}.archived_at")
            ->select("{$detailTable}.id", "{$detailTable}.quantity")
            ->get();

        return (int) $results->sum(function ($detail) use ($rnDetailTable, $rnTable) {
            $approvedReceivedQuantity = DB::table($rnDetailTable)
                ->join($rnTable, "{$rnDetailTable}.received_note_id", '=', "{$rnTable}.id")
                ->where("{$rnDetailTable}.po_detail_id", $detail->id)
                ->where("{$rnTable}.status", ReceivedNote::STATUS_APPROVED)
                ->sum("{$rnDetailTable}.quantity_received");

            return max(0, $detail->quantity - $approvedReceivedQuantity);
        });
    }

    /**
     * Get stock that is "in-return-process" (Return created but not yet fulfilled/settled).
     */
    public static function getInReturnProcessStock($productId, $settingId): int
    {
        $returnTable = (new PurchaseReturn())->getTable();
        $detailTable = (new PurchaseReturnDetail())->getTable();
        $settlementTable = (new PurchaseReturnItemSettlement())->getTable();

        $rows = DB::table($returnTable)
            ->join($detailTable, "{$returnTable}.id", '=', "{$detailTable}.purchase_return_id")
            ->leftJoin($settlementTable, "{$detailTable}.id", '=', "{$settlementTable}.purchase_return_detail_id")
            ->where("{$detailTable}.product_id", $productId)
            ->where("{$returnTable}.setting_id", $settingId)
            ->whereRaw('LOWER(approval_status) = ?', ['approved'])
            ->whereRaw('LOWER(return_dispatch_status) = ?', ['dispatched'])
            ->whereNull("{$returnTable}.archived_at")
            ->select(
                "{$detailTable}.id as detail_id",
                "{$detailTable}.quantity",
                "{$settlementTable}.id as settlement_id",
                "{$settlementTable}.method",
                "{$settlementTable}.status as settlement_status"
            )
            ->get();

        $details = $rows->groupBy('detail_id');
        $totalInProcess = 0;

        foreach ($details as $detailId => $settlements) {
            $first = $settlements->first();
            $totalQty = (float) $first->quantity;
            
            $resolvedQty = 0;
            foreach ($settlements as $settlement) {
                if (!$settlement->settlement_id) continue;

                $method = strtoupper($settlement->method);
                $status = strtoupper($settlement->settlement_status);

                // Resolution rules (PR1-BE-03):
                // - MODIFY_PURCHASE: final at APPROVED
                // - PRODUCT_REPAIR / BROKEN_STOCK: final at RECEIVED
                // - CREDIT / CASH (legacy): final at APPROVED
                // - REJECTED: NOT final (quantity remains unresolved)
                if (in_array($method, ['MODIFY_PURCHASE', 'CREDIT', 'CASH']) && $status === 'APPROVED') {
                    $resolvedQty += 1;
                } elseif (in_array($method, ['PRODUCT_REPAIR', 'BROKEN_STOCK']) && $status === 'RECEIVED') {
                    $resolvedQty += 1;
                }
            }
            $totalInProcess += max(0, $totalQty - $resolvedQty);
        }

        return (int) $totalInProcess;
    }

    /**
     * Bulk fetch projected quantities for multiple products.
     */
    public static function getProjectedQuantitiesForProducts(array $productIds, int $settingId): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        return collect($productIds)->mapWithKeys(function ($id) use ($settingId) {
            return [$id => self::getProjectedQuantities($id, $settingId)];
        });
    }
}
