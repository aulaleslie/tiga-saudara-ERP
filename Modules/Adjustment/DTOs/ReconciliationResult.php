<?php

namespace Modules\Adjustment\DTOs;

/**
 * Top-level output of StockOpnameReconciliationService, in either "preview"
 * (informative, can go stale) or "locked" (computed inside approval's
 * database transaction, becomes approval_result) mode.
 *
 * Split into counter-safe and reviewer-only projections at construction time
 * so protected facts never accidentally reach an unauthorized view — callers
 * must pick toCounterArray() or toReviewerArray() explicitly.
 */
class ReconciliationResult
{
    /**
     * @param ProductReconciliation[] $products
     * @param string[] $warnings Bahasa Indonesia warning messages (reviewer-only)
     * @param string[] $conflicts Bahasa Indonesia conflict messages (reviewer-only); non-empty blocks approval
     */
    public function __construct(
        public readonly int $adjustmentId,
        public readonly bool $locked,
        public readonly int $locationId,
        public readonly string $locationName,
        public readonly int $settingId,
        public readonly bool $isPkp,
        public readonly array $products,
        public readonly array $warnings = [],
        public readonly array $conflicts = [],
        public readonly ?string $computedAt = null,
    ) {
    }

    public function hasConflicts(): bool
    {
        return !empty($this->conflicts);
    }

    public function toReviewerArray(): array
    {
        return [
            'adjustment_id' => $this->adjustmentId,
            'locked' => $this->locked,
            'location_id' => $this->locationId,
            'location_name' => $this->locationName,
            'setting_id' => $this->settingId,
            'is_pkp' => $this->isPkp,
            'computed_at' => $this->computedAt,
            'products' => array_map(fn (ProductReconciliation $p) => $p->toReviewerArray(), $this->products),
            'warnings' => $this->warnings,
            'conflicts' => $this->conflicts,
            'has_conflicts' => $this->hasConflicts(),
        ];
    }

    /**
     * Only document metadata and entered product/serial facts. No baseline,
     * current stock, totals-elsewhere, serial source/tax, warnings, or
     * conflicts — those are derived from system stock and must stay
     * reviewer-only per adjustments.view-system-stock.
     */
    public function toCounterArray(): array
    {
        return [
            'adjustment_id' => $this->adjustmentId,
            'location_id' => $this->locationId,
            'location_name' => $this->locationName,
            'products' => array_map(fn (ProductReconciliation $p) => $p->toCounterArray(), $this->products),
        ];
    }
}
