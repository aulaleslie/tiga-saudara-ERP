<?php

namespace Modules\Pos\Services;

class PackedLinePricingService
{
    public function price(int $qty, ?string $tier, array $pricingBasis): array
    {
        $factor = (int) ($pricingBasis['factor'] ?? 1);
        if ($factor <= 0) {
            $factor = 1;
        }

        $boxCount = intdiv($qty, $factor);
        $remainder = $qty % $factor;

        $tierBasePrice = $this->resolveTierBasePrice($tier, $pricingBasis);
        $boxPrice = (int) ($pricingBasis['box_price'] ?? 0);

        $boxGroupPrice = min($boxPrice, $factor * $tierBasePrice);

        $lineTotalMinor = ($boxCount * $boxGroupPrice) + ($remainder * $tierBasePrice);

        $blendedUnitPrice = $qty > 0 ? intdiv($lineTotalMinor, $qty) : 0;

        $normalizedTier = $this->normalizeCustomerTier($tier);

        $breakdown = [
            'box_count' => $boxCount,
            'loose_count' => $remainder,
            'box_price_applied' => $boxGroupPrice,
            'loose_price_applied' => $tierBasePrice,
            'is_box_cheaper' => $boxPrice < ($factor * $tierBasePrice),
            'box_price' => $boxPrice,
            'tier_base_price' => $tierBasePrice,
            'factor_by_tier_base_price' => $factor * $tierBasePrice,
            'tier' => $normalizedTier,
            'factor' => $factor
        ];

        return [
            'line_total_minor' => $lineTotalMinor,
            'blended_unit_price' => $blendedUnitPrice,
            'breakdown' => $breakdown,
        ];
    }

    private function resolveTierBasePrice(?string $tier, array $pricingBasis): int
    {
        $basePrice = (int) ($pricingBasis['base_price'] ?? 0);

        // Map customer tier strings to internal tier keys
        $normalizedTier = $this->normalizeCustomerTier($tier);

        if ($normalizedTier === 'tier_1') {
            $tier1Price = (int) ($pricingBasis['tier_1_price'] ?? 0);
            return $tier1Price > 0 ? $tier1Price : $basePrice;
        }

        if ($normalizedTier === 'tier_2') {
            $tier2Price = (int) ($pricingBasis['tier_2_price'] ?? 0);
            return $tier2Price > 0 ? $tier2Price : $basePrice;
        }

        return $basePrice;
    }

    private function normalizeCustomerTier(?string $tier): ?string
    {
        if (!$tier) {
            return null;
        }

        // Map customer tier strings to internal tier keys
        return match ($tier) {
            'WHOLESALER' => 'tier_1',
            'RESELLER' => 'tier_2',
            default => $tier, // Pass through already-normalized tier_1/tier_2
        };
    }
}
