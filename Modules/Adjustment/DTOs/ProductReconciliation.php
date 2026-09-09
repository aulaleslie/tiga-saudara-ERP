<?php

namespace Modules\Adjustment\DTOs;

/**
 * Reviewer-only reconciliation for one entered product. Never expose
 * baseline/current/total/projected/serial fields to a counter-safe view.
 */
class ProductReconciliation
{
    /**
     * @param SerialClassification[] $serials
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $productName,
        public readonly string $productCode,
        public readonly string $baseUnit,
        public readonly bool $isSerialized,

        // "Saat mulai dihitung"
        public readonly int $baselineGood,
        public readonly int $baselineBad,

        // "Saat ini" — current authoritative selected-location stock
        public readonly int $currentGood,
        public readonly int $currentBad,

        // "Hasil hitung" — entered absolute counts
        public readonly int $enteredGood,
        public readonly int $enteredBad,

        // Signed differences (entered - current) at selected location
        public readonly int $goodDifference,
        public readonly int $badDifference,

        // Current total across eligible same-owner non-consignment locations
        public readonly float $allLocationCurrentTotal,

        // "Setelah disetujui" — projected global total after applying this document
        public readonly float $projectedGlobalTotal,

        public readonly float $drift,
        public readonly bool $exceedsAllLocationTotal,
        public readonly float $potentialGlobalIncrease,

        public readonly array $serials = [],
        public readonly array $omittedSerials = [],
    ) {
    }

    public function currentTotal(): int
    {
        return $this->currentGood + $this->currentBad;
    }

    public function enteredTotal(): int
    {
        return $this->enteredGood + $this->enteredBad;
    }

    public function isConditionReclassificationOnly(): bool
    {
        return $this->goodDifference !== 0
            && $this->badDifference !== 0
            && ($this->enteredTotal() === $this->currentTotal());
    }

    public function toReviewerArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'product_code' => $this->productCode,
            'base_unit' => $this->baseUnit,
            'is_serialized' => $this->isSerialized,
            'baseline' => [
                'good' => $this->baselineGood,
                'bad' => $this->baselineBad,
                'total' => $this->baselineGood + $this->baselineBad,
            ],
            'current' => [
                'good' => $this->currentGood,
                'bad' => $this->currentBad,
                'total' => $this->currentTotal(),
            ],
            'entered' => [
                'good' => $this->enteredGood,
                'bad' => $this->enteredBad,
                'total' => $this->enteredTotal(),
            ],
            'difference' => [
                'good' => $this->goodDifference,
                'bad' => $this->badDifference,
            ],
            'is_condition_reclassification_only' => $this->isConditionReclassificationOnly(),
            'all_location_current_total' => $this->allLocationCurrentTotal,
            'projected_global_total' => $this->projectedGlobalTotal,
            'drift' => $this->drift,
            'exceeds_all_location_total' => $this->exceedsAllLocationTotal,
            'potential_global_increase' => $this->potentialGlobalIncrease,
            'serials' => array_map(fn (SerialClassification $s) => $s->toReviewerArray(), $this->serials),
            'omitted_serials' => array_map(fn (SerialClassification $s) => $s->toReviewerArray(), $this->omittedSerials),
        ];
    }

    /**
     * Only entered facts: identity, entered good/bad counts, entered serial
     * text/condition. Never baseline, current, totals-elsewhere, or serial
     * registration/source/tax/classification data.
     */
    public function toCounterArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'product_code' => $this->productCode,
            'base_unit' => $this->baseUnit,
            'is_serialized' => $this->isSerialized,
            'entered' => [
                'good' => $this->enteredGood,
                'bad' => $this->enteredBad,
            ],
            'serials' => array_map(fn (SerialClassification $s) => $s->toCounterArray(), $this->serials),
        ];
    }
}
