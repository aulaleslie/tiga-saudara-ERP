<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Exceptions\PurchaseSourceOperationNotAllowedException;

class PurchaseSourceGuard
{
    /**
     * Assert that a purchase allows receiving operations.
     *
     * @throws PurchaseSourceOperationNotAllowedException
     */
    public static function assertReceivingAllowed(Purchase $purchase): void
    {
        if ($purchase->isConsignmentBilling()) {
            throw new PurchaseSourceOperationNotAllowedException("Receiving is not permitted for consignment-billing Purchase #{$purchase->id} ({$purchase->reference}). Goods were received under consignment custody.");
        }
    }

    /**
     * Assert that commercial metadata (supplier, products, quantities, costs, taxes) may be edited.
     *
     * @throws PurchaseSourceOperationNotAllowedException
     */
    public static function assertCommercialEditAllowed(Purchase $purchase): void
    {
        if ($purchase->isConsignmentBilling()) {
            throw new PurchaseSourceOperationNotAllowedException("Commercial details cannot be edited for consignment-billing Purchase #{$purchase->id} ({$purchase->reference}). Commercial evidence is derived from approved consignment allocations.");
        }
    }

    /**
     * Assert that a purchase may participate in return settlement (as return source or
     * as the target of a cash refund / supplier credit application).
     *
     * Phase 3 excludes post-billing supplier returns and credits: settling against a
     * consignment-billing Purchase would mutate commercial evidence derived from approved
     * consignment allocations, and the CREDIT path additionally deletes active payments
     * and resets balances.
     *
     * @throws PurchaseSourceOperationNotAllowedException
     */
    public static function assertReturnAllowed(Purchase $purchase): void
    {
        if ($purchase->isConsignmentBilling()) {
            throw new PurchaseSourceOperationNotAllowedException("Return settlement is not permitted for consignment-billing Purchase #{$purchase->id} ({$purchase->reference}). Post-billing supplier returns and credits are out of scope for consignment billing evidence.");
        }
    }

    /**
     * Assert that deletion/archival is allowed.
     *
     * @throws PurchaseSourceOperationNotAllowedException
     */
    public static function assertDeletionAllowed(Purchase $purchase): void
    {
        if ($purchase->isConsignmentBilling()) {
            throw new PurchaseSourceOperationNotAllowedException("Deletion or archival is prohibited for consignment-billing Purchase #{$purchase->id} ({$purchase->reference}).");
        }
    }

    /**
     * Assert that a purchase allows payment recording and settlement operations.
     *
     * Consignment-billing Purchases produce real commercial payables that MUST be paid.
     * Payments are allowed for both ordinary and consignment-billing Purchases provided
     * the Purchase is not archived or cancelled.
     *
     * @throws PurchaseSourceOperationNotAllowedException
     */
    public static function assertPaymentAllowed(Purchase $purchase): void
    {
        if ($purchase->isArchived()) {
            throw new PurchaseSourceOperationNotAllowedException("Payments are not permitted for archived Purchase #{$purchase->id} ({$purchase->reference}).");
        }

        if (defined(Purchase::class . '::STATUS_CANCELLED') && $purchase->status === Purchase::STATUS_CANCELLED) {
            throw new PurchaseSourceOperationNotAllowedException("Payments are not permitted for cancelled Purchase #{$purchase->id} ({$purchase->reference}).");
        }
    }

    /**
     * Assert that reporting-date overrides are allowed.
     *
     * @throws PurchaseSourceOperationNotAllowedException
     */
    public static function assertReportingDateOverrideAllowed(Purchase $purchase): void
    {
        if ($purchase->isConsignmentBilling()) {
            throw new PurchaseSourceOperationNotAllowedException("Reporting date overrides are not permitted for consignment-billing Purchase #{$purchase->id} ({$purchase->reference}).");
        }
    }
}
