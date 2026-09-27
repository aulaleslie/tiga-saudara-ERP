<?php

namespace Modules\Consignment\Services;

/**
 * Pure server-side pricing calculator shared by the billing preview and the
 * conversion service. It never trusts a submitted total: every row and header
 * amount is recomputed here from the approved allocation evidence plus the
 * operator's pricing intent (row IDs + discount/tax controls), mirroring the
 * "recompute server-side, never trust the client" boundary already enforced
 * for ordinary Purchase create by PurchaseController/PurchaseNormalizer.
 */
class ConsignmentBillingPricingCalculator
{
    /**
     * Build a stable, deterministic row ID from a commercial group's identity.
     * The group key already encodes product/cost/DPP/tax identity; allocation
     * IDs are appended so the row ID also pins the exact set of approved
     * allocations it represents, making a stale/foreign row detectable.
     *
     * @param array<int> $allocationIds
     */
    public function buildRowId(string $groupKey, array $allocationIds): string
    {
        sort($allocationIds);

        return $groupKey . '::' . implode(',', $allocationIds);
    }

    /**
     * Calculate one row's pricing from the immutable allocation evidence and
     * the operator's submitted pricing intent for that row.
     *
     * @param array{
     *     quantity: float,
     *     original_unit_price: float,
     *     original_sub_total: float,
     *     original_tax_amount: float,
     *     original_total: float,
     *     allocation_tax_id: ?int,
     *     allocation_tax_rate: float
     * } $evidence
     * @param array{
     *     unit_price?: float|null,
     *     discount_type?: string|null,
     *     discount_value?: float|null,
     *     tax_id?: int|null,
     *     row_total_override?: float|null
     * } $intent
     * @param bool $isPkp
     * @param bool $isTaxIncluded
     * @param bool $isUnchanged Whether the operator submitted no edits for this row at all
     * (no unit price, discount, tax, or row-total override) -- the legacy
     * allocation-based total must be reproduced exactly in that case.
     * @param ?float $resolvedTaxRate The active rate (percent) for the selected tax_id,
     * looked up server-side by the caller. Required whenever the intent selects a tax_id
     * different from the allocation's own tax_id; never trust a client-submitted rate.
     * @return array{
     *     unit_price: float,
     *     discount_type: string,
     *     discount_amount: float,
     *     tax_id: ?int,
     *     tax_rate: float,
     *     tax_amount: float,
     *     sub_total: float,
     *     total: float,
     *     errors: array<string>
     * }
     */
    public function calculateRow(array $evidence, array $intent, bool $isPkp, bool $isTaxIncluded, bool $isUnchanged, ?float $resolvedTaxRate = null): array
    {
        $errors = [];
        $quantity = (float) $evidence['quantity'];

        if ($isUnchanged) {
            // Preserve the legacy allocation-based amount exactly, including PKP rows
            // whose displayed gross unit price must reconcile to stored DPP + tax.
            $unitPrice = (float) $evidence['original_unit_price'];
            $taxId = $isPkp ? $evidence['allocation_tax_id'] : null;
            $taxRate = $isPkp ? (float) $evidence['allocation_tax_rate'] : 0.0;
            $taxAmount = $isPkp ? round((float) $evidence['original_tax_amount'], 2) : 0.0;
            $subTotal = round((float) $evidence['original_sub_total'], 2);
            $total = $isPkp ? round($subTotal + $taxAmount, 2) : $subTotal;

            $displayUnitPrice = $isPkp && $isTaxIncluded && $quantity > 0
                ? round($total / $quantity, 6)
                : $unitPrice;

            return [
                'unit_price' => $displayUnitPrice,
                'discount_type' => 'fixed',
                'discount_amount' => 0.0,
                'tax_id' => $taxId,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'sub_total' => $subTotal,
                'total' => $total,
                'errors' => $errors,
            ];
        }

        $taxId = null;
        $taxRate = 0.0;
        if ($isPkp) {
            $taxId = ($intent['tax_id'] ?? null) !== null && $intent['tax_id'] !== ''
                ? (int) $intent['tax_id']
                : $evidence['allocation_tax_id'];

            if ($taxId !== null && $taxId === $evidence['allocation_tax_id']) {
                $taxRate = (float) $evidence['allocation_tax_rate'];
            } elseif ($taxId !== null) {
                // A different tax was selected: the caller must resolve its active rate
                // server-side (Tax::active()); a client-submitted rate is never trusted.
                if ($resolvedTaxRate === null) {
                    $errors[] = "Selected tax [{$taxId}] rate could not be resolved.";
                } else {
                    $taxRate = $resolvedTaxRate;
                }
            }
        }

        // "unit price" is whatever is currently DISPLAYED for this row: for a
        // PKP row with tax included, that is the gross (tax-inclusive) price
        // reproducing the original total, exactly as the unchanged-row branch
        // above computes it; otherwise it is the tax-exclusive DPP/cost. A
        // discount or row-total override must be applied against this same
        // displayed baseline, or a discount silently drops the tax portion too.
        $displayedOriginalUnitPrice = ($isPkp && $isTaxIncluded && $taxRate > 0 && $quantity > 0)
            ? round(((float) $evidence['original_total']) / $quantity, 6)
            : (float) $evidence['original_unit_price'];

        $unitPrice = ($intent['unit_price'] ?? null) !== null && $intent['unit_price'] !== ''
            ? (float) $intent['unit_price']
            : $displayedOriginalUnitPrice;

        // purchase_details.unit_price/price are DECIMAL(15,6) -- 9 integer digits
        // before the point -- so the largest representable value is
        // 999999999.999999. The controller validates this too, but the calculator
        // is also exercised directly (preview, tests), so it must never silently
        // overflow or truncate a value beyond that range.
        if (!is_finite($unitPrice) || $unitPrice < 0 || $unitPrice > 999999999.999999) {
            $errors[] = "Unit price [{$unitPrice}] is invalid.";
            $unitPrice = 0.0;
        }

        $discountType = strtolower((string) ($intent['discount_type'] ?? 'fixed'));
        if (!in_array($discountType, ['fixed', 'percentage'], true)) {
            $discountType = 'fixed';
        }

        $discountValue = (float) ($intent['discount_value'] ?? 0.0);
        if ($discountType === 'percentage') {
            if ($discountValue < 0 || $discountValue > 100) {
                $errors[] = "Row discount percentage [{$discountValue}] must be between 0 and 100.";
                $discountValue = max(0.0, min($discountValue, 100.0));
            }
            $discountAmountPerUnit = $unitPrice * ($discountValue / 100);
        } else {
            if ($discountValue < 0) {
                $errors[] = "Row discount amount [{$discountValue}] cannot be negative.";
                $discountValue = 0.0;
            }
            $discountAmountPerUnit = $discountValue;
        }

        $effectiveUnitPrice = $unitPrice - $discountAmountPerUnit;
        if ($effectiveUnitPrice < 0) {
            $errors[] = "Row discount produces a negative effective unit price for the submitted unit price.";
            $effectiveUnitPrice = 0.0;
        }

        $rowTotalOverride = $intent['row_total_override'] ?? null;
        $useTaxInclusivePricing = $isPkp && $taxRate > 0 && $isTaxIncluded;

        if ($rowTotalOverride !== null && $rowTotalOverride !== '') {
            $total = round((float) $rowTotalOverride, 2);
            if (!is_finite($total) || $total < 0) {
                $errors[] = "Row total override [{$total}] is invalid.";
                $total = 0.0;
            }

            if ($isPkp && $taxRate > 0) {
                if ($isTaxIncluded) {
                    $taxAmount = round($total - ($total / (1 + $taxRate / 100)), 2);
                    $subTotal = round($total - $taxAmount, 2);
                } else {
                    $subTotal = round($total / (1 + $taxRate / 100), 2);
                    $taxAmount = round($total - $subTotal, 2);
                }
            } else {
                $taxAmount = 0.0;
                $subTotal = $total;
            }

            // Back-solve the displayed (pre-discount) unit price from the reconciled
            // row total. The effective per-unit price is total/qty when the displayed
            // price is tax-inclusive (gross), or subTotal/qty when it is tax-exclusive
            // (net) -- both must match the SAME basis the effectiveUnitPrice*qty branch
            // below uses, or the saved unit price/discount cannot reproduce the saved
            // total.
            if ($quantity > 0) {
                $effectiveUnitPriceFromTotal = $useTaxInclusivePricing ? ($total / $quantity) : ($subTotal / $quantity);
                if ($discountType === 'percentage') {
                    if ($discountValue >= 100) {
                        // A 100% discount forces a zero effective price, so any nonzero
                        // override total is unreconcilable with the saved unit
                        // price/discount pair -- reject rather than silently accept it.
                        if (abs($effectiveUnitPriceFromTotal) > 0.0001) {
                            $errors[] = "Row total override [{$total}] cannot be reconciled with a 100% discount, which forces a zero row total.";
                        }
                        $unitPrice = 0.0;
                        $discountAmountPerUnit = 0.0;
                    } else {
                        $unitPrice = round($effectiveUnitPriceFromTotal / (1 - $discountValue / 100), 6);
                        // The discount amount is stored as a currency amount per unit, so it
                        // must be recomputed against the back-solved unit price -- otherwise
                        // it still reflects the pre-override unit price and the saved
                        // unit_price/discount_amount pair cannot reproduce the saved total.
                        $discountAmountPerUnit = $unitPrice * ($discountValue / 100);
                    }
                } else {
                    $unitPrice = round($effectiveUnitPriceFromTotal + $discountAmountPerUnit, 6);
                }

                // A row total that is well within its own DECIMAL(15,2) range can
                // still back-solve to a unit price beyond DECIMAL(15,6) when the
                // quantity is small/fractional (e.g. a large total over a quantity of
                // 0.01). The initial unit-price bound check above only covers a
                // directly submitted unit_price, not one derived here from the
                // override total, so it must be re-checked after back-solving.
                if (!is_finite($unitPrice) || $unitPrice < 0 || $unitPrice > 999999999.999999) {
                    $errors[] = "Row total override [{$total}] resolves to an out-of-range unit price [{$unitPrice}].";
                }
            }
        } else {
            if ($useTaxInclusivePricing) {
                $grossSubTotal = round($effectiveUnitPrice * $quantity, 2);
                $taxAmount = round($grossSubTotal - ($grossSubTotal / (1 + $taxRate / 100)), 2);
                $subTotal = round($grossSubTotal - $taxAmount, 2);
                $total = $grossSubTotal;
            } elseif ($isPkp && $taxRate > 0) {
                $subTotal = round($effectiveUnitPrice * $quantity, 2);
                $taxAmount = round($subTotal * ($taxRate / 100), 2);
                $total = round($subTotal + $taxAmount, 2);
            } else {
                $subTotal = round($effectiveUnitPrice * $quantity, 2);
                $taxAmount = 0.0;
                $total = $subTotal;
            }
        }

        return [
            'unit_price' => round($unitPrice, 6),
            'discount_type' => $discountType,
            'discount_amount' => round($discountAmountPerUnit, 6),
            'tax_id' => $taxId,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'sub_total' => $subTotal,
            'total' => $total,
            'errors' => $errors,
        ];
    }

    /**
     * Apply the document-level discount (fixed or percentage) to the sum of
     * tax-inclusive row totals, matching Purchase create semantics: the
     * discount reduces the payable but never changes individual row tax
     * amounts, and cannot exceed the sum it is applied against.
     *
     * @return array{discount_amount: float, total_amount: float, errors: array<string>}
     */
    public function applyGlobalDiscount(float $sumOfRowTotals, string $discountType, float $discountValue): array
    {
        $errors = [];
        $discountType = strtolower($discountType);
        if (!in_array($discountType, ['fixed', 'percentage'], true)) {
            $discountType = 'fixed';
        }

        if ($discountValue < 0) {
            $errors[] = "Global discount value [{$discountValue}] cannot be negative.";
            $discountValue = 0.0;
        }

        if ($discountType === 'percentage') {
            if ($discountValue > 100) {
                $errors[] = "Global discount percentage [{$discountValue}] cannot exceed 100.";
                $discountValue = 100.0;
            }
            $discountAmount = round($sumOfRowTotals * ($discountValue / 100), 2);
        } else {
            $discountAmount = round($discountValue, 2);
        }

        if ($discountAmount > $sumOfRowTotals) {
            $errors[] = "Global discount [{$discountAmount}] cannot exceed the sum of row totals [{$sumOfRowTotals}].";
            $discountAmount = $sumOfRowTotals;
        }

        return [
            'discount_amount' => $discountAmount,
            'total_amount' => round($sumOfRowTotals - $discountAmount, 2),
            'errors' => $errors,
        ];
    }
}
