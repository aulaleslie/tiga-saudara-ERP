<?php

namespace Modules\Purchase\Services;

use Carbon\Carbon;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;

/**
 * Legacy wrapper for PurchaseCostReplayEngine.
 * Delegates to HistoricalReplayEngine for actual replay logic.
 */
class PurchaseCostReplayEngine
{
    public function __construct(
        private HistoricalReplayEngine $replayEngine,
    ) {
    }

    public function withCorrectionPurchase(Purchase $purchase): self
    {
        $this->replayEngine->withCorrectionPurchase($purchase);
        return $this;
    }

    /**
     * Replay the purchase cost timeline for affected products from earliest affected receipt forward.
     * Returns the calculated average purchase price for each product.
     *
     * @param Purchase $purchase The corrected purchase
     * @param string|Carbon $fromDate The earliest affected receipt effective date
     * @param array $affectedProductIds Product IDs affected by the correction
     * @return array{product_id: float} Calculated average purchase prices keyed by product ID
     */
    public function replayProductAverageCosts(
        Purchase $purchase,
        $fromDate,
        array $affectedProductIds,
    ): array {
        $fromDate = $fromDate instanceof Carbon ? $fromDate : Carbon::parse($fromDate);
        $results = [];

        foreach ($affectedProductIds as $productId) {
            $product = Product::find($productId);
            if (!$product) {
                continue;
            }

            $events = $this->replayEngine->collectTimelineEvents(
                productId: $productId,
                untilDate: null,
            );

            $averageCost = $this->replayEngine->replayAverageCost($events, $fromDate);
            $results[$productId] = $averageCost;
        }

        return $results;
    }
}
