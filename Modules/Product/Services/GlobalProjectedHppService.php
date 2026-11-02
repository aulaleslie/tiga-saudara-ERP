<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Purchase\Services\ApprovedOutstandingPurchaseLinesQuery;

class GlobalProjectedHppService
{
    public function project(Product $product, float $currentGlobalHpp): float
    {
        $outstandingLines = app(ApprovedOutstandingPurchaseLinesQuery::class)
            ->build([(int) $product->id]);

        $totals = DB::query()
            ->fromSub($outstandingLines, 'outstanding_purchase_lines')
            ->selectRaw('COALESCE(SUM(outstanding_quantity), 0) as incoming_quantity')
            ->selectRaw(
                'COALESCE(SUM(outstanding_quantity * ((COALESCE(sub_total, 0) - COALESCE(product_tax_amount, 0)) / NULLIF(quantity, 0))), 0) as incoming_value'
            )
            ->first();

        $incomingQuantity = (float) ($totals->incoming_quantity ?? 0);
        $incomingValue = (float) ($totals->incoming_value ?? 0);

        if ($incomingQuantity <= 0) {
            return $currentGlobalHpp;
        }

        $resultingQuantity = (float) $product->product_quantity + $incomingQuantity;

        if ($resultingQuantity <= 0) {
            return $incomingValue / $incomingQuantity;
        }

        return (((float) $product->product_quantity * $currentGlobalHpp) + $incomingValue)
            / $resultingQuantity;
    }
}
