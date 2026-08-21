<?php

namespace Modules\Pos\Services;

use Modules\Sale\Entities\SaleBundleItem;

/**
 * Shared bundle-component resolution logic used by PosReturnSnapshotService
 * (read-time UI snapshot) and PosReturnSubmissionService (draft submission
 * synthesis). Extracted so both services always agree on what counts as
 * "correctly disambiguated" vs "genuinely ambiguous, block rather than
 * guess" — duplicating this logic risked drift between the two call sites.
 *
 * CRITICAL context (split-owner checkout persistence shape, confirmed via
 * InlinePosCheckoutPostingAdapter): when a bundle component's owner differs
 * from its parent's, the component owner's Sale does NOT get a standalone
 * SaleDetails row keyed by the component's OWN product_id. Instead it gets a
 * zero-quantity "carrier" SaleDetails row keyed by the PARENT's product_id
 * (that owner group's own residual/audit line), and the component is
 * represented ONLY via a SaleBundleItem row whose sale_detail_id points at
 * that carrier row. Searching for a sibling SaleDetails row keyed by the
 * component's own product_id (the old, incorrect approach) can never find
 * this. The SaleBundleItem itself — and its sale_detail_id (the carrier row)
 * — is the only reliable source of the component's owning Sale/owner.
 */
class PosBundleComponentResolver
{
    /**
     * Resolve a bundle component's persisted SaleBundleItem across every
     * owner checkout sale in this transaction, using the strongest
     * available identity: bundle_id (shared across every owner's split
     * posting for the same bundle instance) plus product_id, disambiguated
     * further by bundle_item_id when supplied and by exact
     * quantity_per_bundle match when multiple candidates remain (e.g. the
     * same component product appears in two different bundle purchases in
     * one transaction).
     *
     * Returns the matched SaleBundleItem, or null when no candidate exists
     * or ambiguity cannot be resolved by any available signal — callers
     * must never guess by first-match/product-id-only order in that case.
     *
     * @param iterable $saleIds sale_ids of every checkout sale/sale in scope
     */
    public function findComponentBundleItem(
        iterable $saleIds,
        int $productId,
        ?int $bundleId = null,
        ?int $bundleItemId = null,
        ?float $expectedQuantityPerBundle = null
    ): ?SaleBundleItem {
        $saleIds = collect($saleIds)->filter()->unique()->values();

        if ($saleIds->isEmpty()) {
            return null;
        }

        $candidates = SaleBundleItem::query()
            ->with(['saleDetail', 'product'])
            ->whereIn('sale_id', $saleIds)
            ->where('product_id', $productId)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // Multiple candidates: the same component product appears more than
        // once (either multiple bundle instances in this transaction, or
        // multiple owner groups for the same bundle instance). Disambiguate
        // using the strongest identity available, in order — never guess by
        // arbitrary/first-match order.
        if ($bundleItemId) {
            $byBundleItemId = $candidates->where('bundle_item_id', $bundleItemId)->values();
            if ($byBundleItemId->count() === 1) {
                return $byBundleItemId->first();
            }
            if ($byBundleItemId->count() > 1) {
                $candidates = $byBundleItemId;
            }
        }

        if ($bundleId) {
            $byBundleId = $candidates->where('bundle_id', $bundleId)->values();
            if ($byBundleId->count() === 1) {
                return $byBundleId->first();
            }
            if ($byBundleId->count() > 1) {
                $candidates = $byBundleId;
            }
        }

        if ($expectedQuantityPerBundle !== null) {
            $byQuantity = $candidates->filter(
                fn ($item) => abs((float) $item->quantity - $expectedQuantityPerBundle) < 0.0001
            )->values();
            if ($byQuantity->count() === 1) {
                return $byQuantity->first();
            }
        }

        // Still ambiguous after every available identity signal — do not
        // guess by first-match/product-id-only order (two identical bundle
        // occurrences of the same component in one transaction cannot be
        // told apart without a stronger persisted key). Return null so the
        // caller surfaces a null/unresolved component rather than silently
        // picking the wrong physical unit.
        return null;
    }

    /**
     * Resolve the catalog ProductBundleItem rows for a bundle whose PARENT
     * product is $parentProductId, via the ProductBundle header
     * (parent_product_id). This is the authoritative "what components does
     * this bundle instance have" source — used instead of a parent
     * SaleDetail's own persisted SaleBundleItem relation, which is empty or
     * incomplete whenever a component's owner differs from the parent's in
     * a split-owner checkout.
     *
     * @return \Illuminate\Support\Collection<int, \Modules\Product\Entities\ProductBundleItem>
     */
    public function resolveCatalogBundleComponents(int $parentProductId): \Illuminate\Support\Collection
    {
        $bundle = \Modules\Product\Entities\ProductBundle::query()
            ->where('parent_product_id', $parentProductId)
            ->first();

        if (! $bundle) {
            return collect();
        }

        return \Modules\Product\Entities\ProductBundleItem::query()
            ->with('product')
            ->where('bundle_id', $bundle->id)
            ->get();
    }

    /**
     * Resolve the component's own DispatchDetail, matching exactly what
     * InlinePosCheckoutPostingAdapter persists: DispatchDetail.sale_id = the
     * component's owning Sale, DispatchDetail.product_id = the component's
     * own product_id, DispatchDetail.sale_detail_id = the CARRIER row's id
     * (never a hypothetical standalone component-product row).
     */
    public function findComponentDispatchDetailId(int $saleId, int $productId, int $carrierSaleDetailId, ?int $bundleId = null): ?int
    {
        $query = \Modules\Sale\Entities\DispatchDetail::where('sale_id', $saleId)
            ->where('product_id', $productId)
            ->where('sale_detail_id', $carrierSaleDetailId);

        if ($bundleId) {
            $withBundleId = (clone $query)->where('bundle_id', $bundleId);
            if ($withBundleId->exists()) {
                $query = $withBundleId;
            }
        }

        $id = $query->value('id');

        if (! $id) {
            // Fallback: no carrier-row-scoped match found (e.g. legacy/
            // hand-built data without a carrier row) — degrade to the older
            // sale_id+product_id lookup rather than leaving it permanently
            // null.
            $id = \Modules\Sale\Entities\DispatchDetail::where('sale_id', $saleId)
                ->where('product_id', $productId)
                ->value('id');
        }

        return $id ? (int) $id : null;
    }
}
