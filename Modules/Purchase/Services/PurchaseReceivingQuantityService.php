<?php

namespace Modules\Purchase\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;

/**
 * Decimal-safe canonical quantity arithmetic for Purchase receiving.
 *
 * Receiving compares quantities that originate from conversion factors, so float
 * arithmetic is not safe here: a remaining quantity of 0.1 + 0.2 must not read as
 * 0.30000000000000004 and reject a legitimate final receipt. Every comparison in
 * this class runs through BigDecimal at the persisted scale of 3.
 *
 * Received quantities are always stored canonically (base units). The entered unit
 * is presentation only, resolved through the Purchase detail's snapshot.
 */
class PurchaseReceivingQuantityService
{
    /** Persisted scale of quantity columns (decimal(15,3)). */
    public const QUANTITY_SCALE = 3;

    /**
     * Convert a quantity entered in the given unit into canonical base units.
     *
     * @param  'ordered'|'base'  $unitMode Which unit the entered value is expressed in.
     * @throws InvalidArgumentException when the value is malformed or unrepresentable.
     */
    public function toCanonical(PurchaseDetail $detail, float|string $enteredQuantity, string $unitMode = 'ordered'): BigDecimal
    {
        try {
            $enteredBd = BigDecimal::of((string) $enteredQuantity);
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Format jumlah penerimaan tidak valid.');
        }

        if ($enteredBd->isNegative()) {
            throw new InvalidArgumentException('Jumlah penerimaan tidak boleh negatif.');
        }

        if ($enteredBd->stripTrailingZeros()->getScale() > self::QUANTITY_SCALE) {
            throw new InvalidArgumentException('Jumlah penerimaan tidak boleh melebihi 3 angka di belakang koma.');
        }

        // Receiving in the base unit needs no conversion; the ordered unit is
        // scaled by the factor captured on the Purchase line, never by current
        // product configuration, so a later conversion change cannot restate history.
        $canonicalBd = $unitMode === 'base'
            ? $enteredBd
            : $enteredBd->multipliedBy($this->factorFor($detail));

        if ($canonicalBd->stripTrailingZeros()->getScale() > self::QUANTITY_SCALE) {
            throw new InvalidArgumentException(
                'Jumlah penerimaan tidak dapat dikonversi ke satuan dasar tanpa melebihi 3 angka di belakang koma.'
            );
        }

        return $canonicalBd->toScale(self::QUANTITY_SCALE, RoundingMode::UNNECESSARY);
    }

    /**
     * The conversion factor captured on the Purchase line, falling back to 1 for
     * legacy rows that predate unit snapshots.
     */
    public function factorFor(PurchaseDetail $detail): BigDecimal
    {
        $factor = BigDecimal::of((string) $detail->effective_conversion_factor);

        return $factor->isPositive() ? $factor : BigDecimal::of('1');
    }

    /** The canonical quantity ordered on this line. */
    public function orderedCanonical(PurchaseDetail $detail): BigDecimal
    {
        return BigDecimal::of((string) $detail->quantity)->toScale(self::QUANTITY_SCALE, RoundingMode::HALF_UP);
    }

    /**
     * Canonical quantity already received on this line through APPROVED notes only.
     * Pending and rejected notes are explicitly excluded: counting them would both
     * overstate progress and let a rejected note permanently consume order capacity.
     *
     * @param  int|null  $excludeNoteId A note to leave out (used when re-checking a note being approved).
     */
    public function approvedReceivedCanonical(PurchaseDetail $detail, ?int $excludeNoteId = null): BigDecimal
    {
        $query = ReceivedNoteDetail::query()
            ->where('po_detail_id', $detail->id)
            ->whereHas('receivedNote', function ($q) use ($excludeNoteId) {
                $q->where('status', ReceivedNote::STATUS_APPROVED);
                if ($excludeNoteId !== null) {
                    $q->where('id', '!=', $excludeNoteId);
                }
            });

        return $this->sumCanonical($query->pluck('quantity_received'));
    }

    /** Canonical quantity still outstanding on this line, never negative. */
    public function remainingCanonical(PurchaseDetail $detail, ?int $excludeNoteId = null): BigDecimal
    {
        $remaining = $this->orderedCanonical($detail)
            ->minus($this->approvedReceivedCanonical($detail, $excludeNoteId));

        return $remaining->isNegative()
            ? BigDecimal::zero()->toScale(self::QUANTITY_SCALE)
            : $remaining;
    }

    /**
     * Whether accepting $canonicalQuantity would exceed what remains on the line.
     * Exact at the boundary: receiving precisely the remaining amount is allowed.
     */
    public function wouldOverReceive(PurchaseDetail $detail, BigDecimal $canonicalQuantity, ?int $excludeNoteId = null): bool
    {
        return $canonicalQuantity->compareTo($this->remainingCanonical($detail, $excludeNoteId)) > 0;
    }

    /**
     * Sum a set of stored quantities without float drift.
     *
     * @param  iterable<mixed>  $quantities
     */
    public function sumCanonical(iterable $quantities): BigDecimal
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
     * A serialized product is counted in whole base units: half a serialized item
     * cannot exist, and each base unit must carry exactly one serial.
     */
    public function assertWholeCanonicalForSerialized(PurchaseDetail $detail, BigDecimal $canonicalQuantity): void
    {
        if (! ($detail->product?->serial_number_required ?? false)) {
            return;
        }

        if ($canonicalQuantity->stripTrailingZeros()->getScale() > 0) {
            throw new InvalidArgumentException(
                "Produk {$detail->product_name} memerlukan serial number, sehingga jumlah diterima harus berupa satuan dasar utuh."
            );
        }
    }
}
