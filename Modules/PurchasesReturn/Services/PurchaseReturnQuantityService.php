<?php

namespace Modules\PurchasesReturn\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Purchase\Entities\Purchase;

/**
 * Decimal-safe canonical quantity arithmetic for Purchase returns.
 *
 * Purchase lines and receiving already support fractional canonical (base-unit)
 * quantities, so a return must reverse the exact same canonical amount without
 * float drift: a return of a line received across several fractional receipts
 * must not compare or subtract through binary floating point. Return quantities
 * carry no unit-conversion context of their own — a return is always expressed
 * and persisted in the product's canonical base unit — so this service only
 * centralizes fixed-scale decimal parsing and comparison, not unit conversion.
 */
class PurchaseReturnQuantityService
{
    /** Persisted scale of the purchase_return_details.quantity column (decimal(15,3)). */
    public const QUANTITY_SCALE = 3;

    /**
     * Parse and validate a canonical return quantity entered by a user.
     *
     * @throws InvalidArgumentException when the value is malformed, non-positive, or unrepresentable.
     */
    public function toCanonical(float|string $enteredQuantity): BigDecimal
    {
        try {
            $bd = BigDecimal::of((string) $enteredQuantity);
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Format jumlah retur tidak valid.');
        }

        if (! $bd->isPositive()) {
            throw new InvalidArgumentException('Jumlah retur harus lebih besar dari 0.');
        }

        if ($bd->stripTrailingZeros()->getScale() > self::QUANTITY_SCALE) {
            throw new InvalidArgumentException('Jumlah retur tidak boleh melebihi 3 angka di belakang koma.');
        }

        return $bd->toScale(self::QUANTITY_SCALE, RoundingMode::UNNECESSARY);
    }

    /** Normalize any stored/loosely-typed quantity value into a scale-3 BigDecimal. */
    public function toBigDecimal(mixed $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero()->toScale(self::QUANTITY_SCALE);
        }

        return BigDecimal::of((string) $value)->toScale(self::QUANTITY_SCALE, RoundingMode::HALF_UP);
    }

    /** Whether $a is strictly less than $b, compared at fixed decimal scale. */
    public function lessThan(mixed $a, mixed $b): bool
    {
        return $this->toBigDecimal($a)->compareTo($this->toBigDecimal($b)) < 0;
    }

    /** Whether $a is strictly greater than $b, compared at fixed decimal scale. */
    public function greaterThan(mixed $a, mixed $b): bool
    {
        return $this->toBigDecimal($a)->compareTo($this->toBigDecimal($b)) > 0;
    }

    /** The lesser of two quantities, compared at fixed decimal scale. */
    public function min(mixed $a, mixed $b): BigDecimal
    {
        $aBd = $this->toBigDecimal($a);
        $bBd = $this->toBigDecimal($b);

        return $aBd->compareTo($bBd) <= 0 ? $aBd : $bBd;
    }

    /** Sum a set of stored quantities without float drift. */
    public function sum(iterable $quantities): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($quantities as $quantity) {
            if ($quantity === null || $quantity === '') {
                continue;
            }
            $total = $total->plus(BigDecimal::of((string) $quantity));
        }

        return $total->toScale(self::QUANTITY_SCALE, RoundingMode::HALF_UP);
    }

    /**
     * The deterministic allocation order used both to reduce a purchase's matching
     * detail rows on approval (PurchasesReturnSettlementController::reducePurchaseDetailCollection)
     * and to price a settlement's nominal in the Livewire settlement form. A purchase
     * can carry more than one detail row for the same product at different prices;
     * without a fixed, shared order the displayed/approved nominal and the amount
     * actually removed from the purchase can diverge. Ordering by id gives both
     * sides the same, stable row sequence.
     *
     * @return Collection<int, \Modules\Purchase\Entities\PurchaseDetail>
     */
    public function orderedPurchaseDetailsForProduct(Purchase $purchase, mixed $productId): Collection
    {
        return $purchase->purchaseDetails
            ->where('product_id', $productId)
            ->sortBy('id')
            ->values();
    }

    /**
     * The monetary value that reducing $returnQty from $orderedDetails would actually
     * remove from a purchase, walking the rows in the exact order and per-row rate
     * that PurchasesReturnSettlementController::reducePurchaseDetailCollection() uses
     * to reduce them.
     *
     * A purchase can carry more than one detail row for the same product at
     * different prices. A flat weighted average across those rows only equals the
     * true removed amount when a return happens to consume the rows in the same
     * proportion the average assumes; a return that exhausts one row before
     * touching the next (as approval always does) generally removes a different
     * amount. This walks the same row-by-row allocation approval performs so the
     * settlement nominal shown/validated in the UI exactly matches what approval
     * will remove.
     *
     * $orderedDetails must be in the same order reducePurchaseDetailCollection()
     * will consume them in (see PurchasesReturnSettlementController::orderedPurchaseDetailsForProduct()).
     *
     * @param  iterable<object{quantity: mixed, sub_total: mixed}>  $orderedDetails
     */
    public function allocatedSubTotal(iterable $orderedDetails, mixed $returnQty): BigDecimal
    {
        $remaining = $this->toBigDecimal($returnQty);
        $allocated = BigDecimal::zero();
        $rateScale = 10;

        foreach ($orderedDetails as $detail) {
            if (! $remaining->isPositive()) {
                break;
            }

            $available = $this->toBigDecimal($detail->quantity);
            if (! $available->isPositive()) {
                continue;
            }

            $deduct = $this->min($remaining, $available);
            $perUnitSubTotal = BigDecimal::of((string) $detail->sub_total)
                ->dividedBy($available, $rateScale, RoundingMode::HALF_UP);

            $allocated = $allocated->plus($perUnitSubTotal->multipliedBy($deduct));
            $remaining = $remaining->minus($deduct);
        }

        return $allocated->toScale(2, RoundingMode::HALF_UP);
    }
}
