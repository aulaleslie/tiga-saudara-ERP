<?php

namespace Modules\Consignment\Entities\Concerns;

use Modules\Consignment\Entities\ConsignmentBillingConfirmation;

/**
 * Immutability guards run inside model events, where the caller controls which relations
 * happen to be loaded. Dereferencing a relation there is unsafe: with lazy loading
 * disabled it throws a LazyLoadingViolationException before the guard can decide, turning
 * a legitimate write into an infrastructure failure.
 *
 * These helpers resolve the owning confirmation's status without ever lazy loading: they
 * use an already-loaded relation when present, and otherwise issue one narrow status query
 * keyed by the foreign key the model already holds.
 */
trait ResolvesGuardConfirmationStatus
{
    /**
     * Resolve the owning confirmation's status string, or null when it cannot be
     * determined (no foreign key, or the parent no longer exists).
     */
    protected function guardConfirmationStatus(?string $relation, string $foreignKey): ?string
    {
        // Prefer an already-loaded relation: it reflects any in-transaction changes the
        // caller has made, and costs no query.
        if ($relation !== null && $this->relationLoaded($relation)) {
            $loaded = $this->getRelation($relation);
            if ($loaded) {
                return (string) $loaded->status;
            }
        }

        return $this->readConfirmationStatus($this->getAttribute($foreignKey));
    }

    /**
     * Resolve the owning confirmation's status through an intermediate relation
     * (for example ReceiptAllocation -> line -> confirmation) without lazy loading.
     */
    protected function guardConfirmationStatusVia(
        string $parentRelation,
        string $parentForeignKey,
        string $parentClass,
        string $parentConfirmationForeignKey
    ): ?string {
        if ($this->relationLoaded($parentRelation)) {
            $parent = $this->getRelation($parentRelation);

            if (! $parent) {
                return null;
            }

            if ($parent->relationLoaded('confirmation') && $parent->getRelation('confirmation')) {
                return (string) $parent->getRelation('confirmation')->status;
            }

            $confirmationId = $parent->getAttribute($parentConfirmationForeignKey);

            return $this->readConfirmationStatus($confirmationId);
        }

        $parentId = $this->getAttribute($parentForeignKey);
        if (empty($parentId)) {
            return null;
        }

        return $this->readConfirmationStatus(
            $parentClass::whereKey($parentId)->value($parentConfirmationForeignKey)
        );
    }

    /**
     * Narrow, un-hydrated status read: never a lazy load, never a full model.
     */
    private function readConfirmationStatus($confirmationId): ?string
    {
        if (empty($confirmationId)) {
            return null;
        }

        $status = ConsignmentBillingConfirmation::whereKey($confirmationId)->value('status');

        return $status === null ? null : (string) $status;
    }

    protected function guardStatusIsApproved(?string $status): bool
    {
        return $status === ConsignmentBillingConfirmation::STATUS_APPROVED;
    }

    protected function guardStatusIsWaitingApproval(?string $status): bool
    {
        return $status === ConsignmentBillingConfirmation::STATUS_WAITING_APPROVAL;
    }
}
