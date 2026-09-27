<?php

namespace Modules\Pos\Support;

class PosMergeKeyGenerator
{
    /**
     * Build merge key for line deduplication.
     */
    public static function build(
        int $productId,
        float $unitPrice,
        ?int $taxId,
        ?int $conversionId = null,
        ?int $bundleId = null,
        ?string $tier = null,
        string $priceSource = 'BASE'
    ): string {
        $taxIdStr = $taxId ?? 'null';
        $conversionIdStr = $conversionId ?? 'null';
        $bundleIdStr = $bundleId ?? 'null';
        $tierStr = $tier ?? 'null';

        if ($priceSource === 'PACKED') {
            // Invariant: This relies on the one-box-conversion-per-product invariant.
            // If a product can ever have multiple conversions, this key would wrongly merge two different box types.
            // If buildPricingBasis ever stops assuming "first conversion", this key must be revisited.
            return "{$productId}:PACKED:{$taxIdStr}:{$tierStr}:{$bundleIdStr}";
        }

        return "{$productId}:" . round($unitPrice, 2) . ":{$taxIdStr}:{$conversionIdStr}:{$bundleIdStr}";
    }
}
