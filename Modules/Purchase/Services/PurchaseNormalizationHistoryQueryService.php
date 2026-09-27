<?php

namespace Modules\Purchase\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\UomNormalizationBatch;

class PurchaseNormalizationHistoryQueryService
{
    /**
     * Query executed UOM normalization batches related to a given purchase.
     *
     * @param  Purchase  $purchase
     * @return Collection<int, UomNormalizationBatch>
     */
    public function getExecutedBatchesForPurchase(Purchase $purchase): Collection
    {
        return UomNormalizationBatch::query()
            ->whereHas('lines', function ($q) use ($purchase) {
                $q->whereHas('purchaseDetail', fn ($q2) => $q2->where('purchase_id', $purchase->id));
            })
            ->where('status', UomNormalizationBatch::STATUS_EXECUTED)
            ->with([
                'lines',
                'actor',
                'product',
                'oldBaseUnit',
                'newBaseUnit',
                'legacySourceUnit',
                'legacyBaseUnit',
            ])
            ->orderByDesc('executed_at')
            ->get();
    }
}
