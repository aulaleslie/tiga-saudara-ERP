<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Entities\Purchase;

class PurchaseTaxInclusionResolver
{
    private const TAX_TOLERANCE = 1.00;

    /**
     * Resolve the effective tax inclusion flag for duplicate prefill.
     *
     * @return array{
     *     effective: bool,
     *     stored: bool,
     *     inferred: ?bool,
     *     used_fallback: bool,
     *     reason: string
     * }
     */
    public function resolveForDuplicate(Purchase $purchase): array
    {
        $stored = (bool) $purchase->is_tax_included;
        $analysis = $this->analyzeDetails($purchase);
        $inferred = $analysis['result'];

        if ($inferred === null) {
            return [
                'effective' => $stored,
                'stored' => $stored,
                'inferred' => null,
                'used_fallback' => false,
                'reason' => $analysis['reason'],
            ];
        }

        if ($inferred !== $stored) {
            return [
                'effective' => $inferred,
                'stored' => $stored,
                'inferred' => $inferred,
                'used_fallback' => true,
                'reason' => 'inferred_from_details',
            ];
        }

        return [
            'effective' => $stored,
            'stored' => $stored,
            'inferred' => $inferred,
            'used_fallback' => false,
            'reason' => 'stored',
        ];
    }

    public function inferFromDetails(Purchase $purchase): ?bool
    {
        return $this->analyzeDetails($purchase)['result'];
    }

    /**
     * @return array{result:?bool,reason:string}
     */
    private function analyzeDetails(Purchase $purchase): array
    {
        $includedVotes = 0;
        $excludedVotes = 0;
        $ambiguousVotes = 0;
        $eligibleLines = 0;

        foreach ($purchase->purchaseDetails as $detail) {
            $taxId = $detail->tax_id;
            $taxRate = (float) ($detail->tax?->value ?? 0);
            $quantity = (int) ($detail->quantity ?? 0);

            if (! $taxId || $taxRate <= 0 || $quantity <= 0) {
                continue;
            }

            $eligibleLines++;

            $price = max(0.0, (float) ($detail->price ?? 0));
            $discountPerUnit = max(0.0, (float) ($detail->product_discount_amount ?? 0));
            $effectiveUnitPrice = max(0.0, $price - $discountPerUnit);
            $actualTax = max(0.0, (float) ($detail->product_tax_amount ?? 0));

            $expectedTaxIfIncluded = $quantity * ($effectiveUnitPrice * $taxRate / (100 + $taxRate));
            $expectedTaxIfExcluded = $quantity * ($effectiveUnitPrice * $taxRate / 100);

            $deltaIncluded = abs($actualTax - $expectedTaxIfIncluded);
            $deltaExcluded = abs($actualTax - $expectedTaxIfExcluded);

            $includedMatch = $deltaIncluded <= self::TAX_TOLERANCE;
            $excludedMatch = $deltaExcluded <= self::TAX_TOLERANCE;

            if ($includedMatch && ! $excludedMatch) {
                $includedVotes++;
                continue;
            }

            if ($excludedMatch && ! $includedMatch) {
                $excludedVotes++;
                continue;
            }

            if ($includedMatch && $excludedMatch) {
                if ($deltaIncluded < $deltaExcluded) {
                    $includedVotes++;
                    continue;
                }

                if ($deltaExcluded < $deltaIncluded) {
                    $excludedVotes++;
                    continue;
                }
            }

            $ambiguousVotes++;
        }

        $informativeVotes = $includedVotes + $excludedVotes;

        if ($eligibleLines === 0) {
            return ['result' => null, 'reason' => 'no_inferable_lines_keep_stored'];
        }

        if ($informativeVotes === 0) {
            return ['result' => null, 'reason' => 'ambiguous_keep_stored'];
        }

        if ($includedVotes > 0 && $excludedVotes === 0) {
            return ['result' => true, 'reason' => 'stored'];
        }

        if ($excludedVotes > 0 && $includedVotes === 0) {
            return ['result' => false, 'reason' => 'stored'];
        }

        return ['result' => null, 'reason' => 'ambiguous_keep_stored'];
    }
}
