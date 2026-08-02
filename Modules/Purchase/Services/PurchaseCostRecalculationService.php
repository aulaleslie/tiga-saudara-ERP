<?php

namespace Modules\Purchase\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;

class PurchaseCostRecalculationService
{
    public function __construct(
        private HistoricalReplayEngine $replayEngine,
    ) {
    }

    public function previewRecalculation(Purchase $purchase): array
    {
        $lastCorrection = $purchase->corrections()->latest()->first();
        if (!$lastCorrection) {
            return ['success' => false, 'error' => 'No correction found'];
        }

        $affectedProductIds = $purchase->purchaseDetails()
            ->pluck('product_id')
            ->unique()
            ->toArray();

        if (empty($affectedProductIds)) {
            return [
                'success' => true,
                'affected_products' => 0,
                'affected_purchase_prices' => 0,
                'affected_sales' => 0,
                'earliest_receipt_date' => null,
                'warning' => 'No products in this purchase',
            ];
        }

        $earliestReceipt = ReceivedNote::where('po_id', $purchase->id)
            ->where('status', ReceivedNote::STATUS_APPROVED)
            ->oldest('approved_at')
            ->first();

        $earliestReceiptDate = $earliestReceipt?->approved_at ?? $purchase->date;

        $affectedPriceCount = ProductPrice::whereIn('product_id', $affectedProductIds)
            ->where('setting_id', $purchase->setting_id)
            ->count();

        $affectedSalesCount = Sale::where('setting_id', $purchase->setting_id)
            ->whereDate('date', '>=', $earliestReceiptDate)
            ->whereHas('saleDetails', function ($q) use ($affectedProductIds) {
                $q->whereIn('product_id', $affectedProductIds);
            })
            ->count();

        return [
            'success' => true,
            'affected_products' => count($affectedProductIds),
            'affected_purchase_prices' => $affectedPriceCount,
            'affected_sales' => $affectedSalesCount,
            'earliest_receipt_date' => $earliestReceiptDate?->toDateTimeString(),
            'warning' => 'Rekalkulation akan mengubah riwayat biaya yang mungkin mempengaruhi laporan historis',
        ];
    }

    public function executeRecalculation(
        Purchase $purchase,
        User $actor,
        bool $includeDownstreamHpp = false,
    ): array {
        return DB::transaction(function () use ($purchase, $actor, $includeDownstreamHpp) {
            $lastCorrection = $purchase->corrections()->latest()->first();
            if (!$lastCorrection) {
                return ['success' => false, 'error' => 'No correction found'];
            }

            $affectedProductIds = $purchase->purchaseDetails()
                ->pluck('product_id')
                ->unique()
                ->toArray();

            if (empty($affectedProductIds)) {
                return [
                    'success' => true,
                    'result' => [
                        'products_processed' => 0,
                        'purchase_prices_updated' => 0,
                        'sales_hpp_updated' => 0,
                        'imported_hpp_skipped' => 0,
                    ],
                ];
            }

            $earliestReceipt = ReceivedNote::where('po_id', $purchase->id)
                ->where('status', ReceivedNote::STATUS_APPROVED)
                ->oldest('approved_at')
                ->first();

            $earliestReceiptDate = $earliestReceipt?->approved_at ?? $purchase->date;

            $resultData = [
                'products_processed' => 0,
                'purchase_prices_updated' => 0,
                'sales_hpp_updated' => 0,
                'imported_hpp_skipped' => 0,
            ];

            // Use replay engine to calculate corrected average costs
            $this->replayEngine->withCorrectionPurchase($purchase);
            $averageCosts = [];

            foreach ($affectedProductIds as $productId) {
                $events = $this->replayEngine->collectTimelineEvents(
                    productId: $productId,
                    untilDate: null,
                );
                $averageCost = $this->replayEngine->replayAverageCost($events, $earliestReceiptDate);
                $averageCosts[$productId] = $averageCost;
            }

            foreach ($affectedProductIds as $productId) {
                if (!isset($averageCosts[$productId])) {
                    continue;
                }

                $productPrice = ProductPrice::firstOrCreate(
                    [
                        'product_id' => $productId,
                        'setting_id' => $purchase->setting_id,
                    ],
                    [
                        'average_purchase_price' => 0,
                        'last_purchase_price' => 0,
                    ],
                );

                $productPrice->average_purchase_price = $averageCosts[$productId];
                $productPrice->save();

                $resultData['products_processed']++;
                $resultData['purchase_prices_updated']++;
            }

            if ($includeDownstreamHpp) {
                $data = $this->replayDownstreamSaleHpp(
                    affectedProductIds: $affectedProductIds,
                    fromDate: $earliestReceiptDate,
                    correctionId: $lastCorrection->id,
                    settingId: $purchase->setting_id,
                    averageCosts: $averageCosts,
                );
                $resultData['sales_hpp_updated'] = $data['updated_count'];
                $resultData['imported_hpp_skipped'] = $data['skipped_count'];
            }

            $lastCorrection->update([
                'recalculation_result' => $resultData,
            ]);

            return [
                'success' => true,
                'result' => $resultData,
            ];
        });
    }

    private function replayDownstreamSaleHpp(
        array $affectedProductIds,
        $fromDate,
        int $correctionId,
        int $settingId,
        array $averageCosts,
    ): array {
        $updatedCount = 0;
        $importedHppCount = 0;

        // For each affected product, collect all timeline events and replay with historical HPP
        foreach ($affectedProductIds as $productId) {
            $events = $this->replayEngine->collectTimelineEvents(
                productId: $productId,
                untilDate: null,
            );

            // Replay with bucket isolation to get per-sale historical averages
            $result = $this->replayEngine->replayWithBucketIsolation($events, \Carbon\Carbon::parse($fromDate));

            // Update sale details with historical averages
            $sales = Sale::where('setting_id', $settingId)
                ->whereDate('date', '>=', $fromDate)
                ->whereHas('saleDetails', function ($q) use ($productId) {
                    $q->where('product_id', $productId);
                })
                ->with(['saleDetails' => function ($q) use ($productId) {
                    $q->where('product_id', $productId);
                }])
                ->get();

            foreach ($sales as $sale) {
                foreach ($sale->saleDetails as $saleDetail) {
                    // Skip if this is an authoritative imported HPP snapshot
                    if ($saleDetail->cost_snapshot_source === 'HPP_SNAPSHOT_IMPORT') {
                        $importedHppCount++;
                        continue;
                    }

                    // Use historical moving average from replay
                    $unitCost = $result['sale_snapshots'][$saleDetail->id] ?? 0;
                    $totalCost = round($unitCost * $saleDetail->quantity, 2);

                    $saleDetail->cost_unit_snapshot = round($unitCost, 6);
                    $saleDetail->cost_total_snapshot = $totalCost;
                    $saleDetail->cost_snapshot_source = 'CORRECTION_REPLAY';
                    $saleDetail->cost_snapshot_at = now();
                    $saleDetail->save();

                    $updatedCount++;
                }
            }
        }

        return [
            'updated_count' => $updatedCount,
            'skipped_count' => $importedHppCount,
        ];
    }
}
