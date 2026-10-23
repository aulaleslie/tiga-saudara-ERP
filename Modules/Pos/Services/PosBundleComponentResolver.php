<?php

namespace Modules\Pos\Services;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;

/**
 * Shared bundle-component resolution logic used by PosReturnSnapshotService
 * (read-time UI snapshot), PosReturnSubmissionService (draft submission
 * synthesis), PosReturnApprovalPreviewPlannerService (approval plan preview),
 * and PosReturnLifecycleService (execution).
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
     * Resolve original bundle definition, composition, and carrier links for a given
     * parent SaleDetails row using persisted checkout, POS line, dispatch, carrier,
     * and bundle-item evidence.
     *
     * Returns an array with:
     * - 'is_bundle': bool
     * - 'is_ambiguous': bool
     * - 'bundle_id': ?int
     * - 'bundle_name': ?string
     * - 'pos_transaction_line_id': ?int
     * - 'components': array<int, array{
     *       product_id: int,
     *       product_name: ?string,
     *       product_code: ?string,
     *       quantity_per_bundle: float,
     *       bundle_item_id: ?int,
     *       carrier_sale_detail_id: int,
     *       sale_id: int,
     *       sale_bundle_item_id: ?int,
     *       dispatch_detail_id: ?int,
     *   }>
     * Or null if this is an ordinary non-bundle sale.
     */
    public function resolveOriginalBundleContext(
        SaleDetails $detail,
        $allCheckoutSales = null,
        ?PosTransaction $posTransaction = null
    ): ?array {
        // Zero-quantity carrier rows are component carriers, never the actionable bundle parent.
        if ((float) $detail->quantity <= 0) {
            return null;
        }

        $allCheckoutSales = collect($allCheckoutSales ?? [])->filter();
        if ($allCheckoutSales->isEmpty() && $detail->sale_id) {
            $checkoutSale = \Modules\Pos\Entities\PosCheckoutSale::where('sale_id', $detail->sale_id)->first();
            if ($checkoutSale) {
                $allCheckoutSales = \Modules\Pos\Entities\PosCheckoutSale::with([
                    'sale.saleDetails.bundleItems.product',
                ])->where('pos_checkout_id', $checkoutSale->pos_checkout_id)->get();
            }
        }

        if ($posTransaction === null && $allCheckoutSales->isNotEmpty()) {
            $checkoutId = $allCheckoutSales->first()->pos_checkout_id;
            $posCheckout = \Modules\Pos\Entities\PosCheckout::find($checkoutId);
            if ($posCheckout && $posCheckout->pos_transaction_id) {
                $posTransaction = PosTransaction::with('lines')->find($posCheckout->pos_transaction_id);
            }
        }

        $allSaleIds = $allCheckoutSales->pluck('sale_id')->filter()->unique()->values();
        if ($allSaleIds->isEmpty() && $detail->sale_id) {
            $allSaleIds = collect([$detail->sale_id]);
        }

        $allSaleDetails = SaleDetails::with(['bundleItems.product'])
            ->whereIn('sale_id', $allSaleIds)
            ->get();

        $allSaleBundleItems = $allSaleDetails->flatMap(fn ($sd) => $sd->bundleItems ?? collect())->values();

        // Evidence 1: Direct SaleBundleItems on this detail (use eager-loaded instance from $allSaleDetails if available)
        $directDetail = $allSaleDetails->firstWhere('id', $detail->id) ?? $detail;
        $directBundleItems = $directDetail->bundleItems ?? collect();
        $hasDirectBundleItems = $directBundleItems->isNotEmpty();
        $directBundleId = $hasDirectBundleItems ? ((int) ($directBundleItems->first()->bundle_id ?? 0) ?: null) : null;

        // Evidence 2: Zero-quantity carrier rows in the checkout for the same parent product
        $carrierDetails = $allSaleDetails->filter(function ($sd) use ($detail) {
            return (int) $sd->product_id === (int) $detail->product_id
                && (float) $sd->quantity == 0
                && $sd->bundleItems
                && $sd->bundleItems->isNotEmpty();
        })->values();
        $hasCarrierRows = $carrierDetails->isNotEmpty();

        // Evidence 3: POS transaction lines with bundle metadata
        $ptlCandidates = collect();
        if ($posTransaction && $posTransaction->lines) {
            $ptlCandidates = $posTransaction->lines->filter(function ($ptl) use ($detail) {
                if ((int) $ptl->product_id !== (int) $detail->product_id) {
                    return false;
                }
                return !empty($ptl->line_meta['bundle_id'])
                    || !empty($ptl->line_meta['is_bundle'])
                    || !empty($ptl->line_meta['bundle_items']);
            })->values();
        }
        $hasPtlBundle = $ptlCandidates->isNotEmpty();

        // If no evidence exists anywhere in the checkout or POS transaction, it is an ordinary non-bundle sale!
        if (!$hasDirectBundleItems && !$hasCarrierRows && !$hasPtlBundle) {
            return null;
        }

        // Verify explicit pos_transaction_line_id identity on detail
        $explicitPtlId = ! empty($detail->pos_transaction_line_id) ? (int) $detail->pos_transaction_line_id : null;
        $matchedPtl = null;

        if ($explicitPtlId && $posTransaction && $posTransaction->lines) {
            $linkedPtl = $posTransaction->lines->firstWhere('id', $explicitPtlId);
            if ($linkedPtl) {
                $linkedPtlIsBundle = ! empty($linkedPtl->line_meta['bundle_id'])
                    || ! empty($linkedPtl->line_meta['is_bundle'])
                    || ! empty($linkedPtl->line_meta['bundle_items']);

                if (! $linkedPtlIsBundle && ! $hasDirectBundleItems) {
                    // Explicitly linked to an ordinary non-bundle POS transaction line!
                    return null;
                }

                if ($linkedPtlIsBundle) {
                    $matchedPtl = $linkedPtl;
                }
            }
        }

        // Check for positive-quantity parent instances of the same product
        $parentCandidatesSameProduct = $allSaleDetails->filter(function ($sd) use ($detail) {
            return (int) $sd->product_id === (int) $detail->product_id && (float) $sd->quantity > 0;
        })->sortBy('id')->values();

        // Row-level evidence: the row's own SaleBundleItems, or a stock-managed
        // dispatch for this exact row tagged with a bundle_id. When the rows
        // carrying it account for every bundle POS line, it decides identity
        // outright — row order is never consulted.
        $bundleDispatchedDetailIds = $parentCandidatesSameProduct->isNotEmpty()
            ? DispatchDetail::query()
                ->whereIn('sale_detail_id', $parentCandidatesSameProduct->pluck('id'))
                ->whereNotNull('bundle_id')
                ->pluck('sale_detail_id')
                ->map(fn ($id) => (int) $id)
            : collect();
        $rowEvidenceDetailIds = $parentCandidatesSameProduct
            ->filter(fn ($sd) => $sd->bundleItems && $sd->bundleItems->isNotEmpty())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->merge($bundleDispatchedDetailIds)
            ->unique()
            ->values();

        if ($matchedPtl === null && $hasPtlBundle && $rowEvidenceDetailIds->isNotEmpty()
            && $rowEvidenceDetailIds->count() === $ptlCandidates->count()
        ) {
            if (! $rowEvidenceDetailIds->contains((int) $detail->id)) {
                return null;
            }

            if ($ptlCandidates->count() === 1) {
                $matchedPtl = $ptlCandidates->first();
            }
        }

        // When pos_transaction_line_id is not persisted on SaleDetails (e.g. real checkout posting),
        // correlate parent candidates for the same product to POS transaction lines by positional order.
        // Checkout posting orders each owner's Sale independently, so position is only meaningful
        // when every candidate sits in one Sale.
        $allPtlsForProduct = $posTransaction && $posTransaction->lines
            ? $posTransaction->lines->filter(fn ($ptl) => (int) $ptl->product_id === (int) $detail->product_id)->sortBy('line_no')->values()
            : collect();
        $candidatesSpanSales = $parentCandidatesSameProduct->pluck('sale_id')->unique()->count() > 1;

        if ($matchedPtl === null && $hasPtlBundle && $candidatesSpanSales
            && $rowEvidenceDetailIds->isEmpty() && $allPtlsForProduct->count() > $ptlCandidates->count()
        ) {
            return [
                'is_bundle' => true,
                'is_ambiguous' => true,
                'error' => "Produk bundle {$detail->product_name} tidak dapat dibedakan dari penjualan biasa produk yang sama di beberapa penjualan.",
            ];
        }

        if ($matchedPtl === null && ! $candidatesSpanSales && $allPtlsForProduct->isNotEmpty() && $parentCandidatesSameProduct->isNotEmpty()
            && $allPtlsForProduct->count() === $parentCandidatesSameProduct->count()
        ) {
            $detailIndex = $parentCandidatesSameProduct->search(fn ($sd) => (int) $sd->id === (int) $detail->id);
            if ($detailIndex !== false && isset($allPtlsForProduct[$detailIndex])) {
                $correspondingPtl = $allPtlsForProduct[$detailIndex];
                $correspondingPtlIsBundle = ! empty($correspondingPtl->line_meta['bundle_id'])
                    || ! empty($correspondingPtl->line_meta['is_bundle'])
                    || ! empty($correspondingPtl->line_meta['bundle_items']);

                if (! $correspondingPtlIsBundle && ! $hasDirectBundleItems) {
                    // Positionally corresponds to a non-bundle POS transaction line!
                    return null;
                }

                if ($correspondingPtlIsBundle) {
                    if ($ptlCandidates->count() > 1) {
                        return [
                            'is_bundle' => true,
                            'is_ambiguous' => true,
                            'error' => "Produk bundle {$detail->product_name} memiliki beberapa instans berulang yang ambigu dalam transaksi.",
                        ];
                    }
                    $matchedPtl = $correspondingPtl;
                }
            }
        }

        if ($matchedPtl === null && $hasPtlBundle) {
            // Filter out PTLs already claimed by other sale details that explicitly reference them
            $otherClaimedPtlIds = $parentCandidatesSameProduct
                ->where('id', '!=', $detail->id)
                ->pluck('pos_transaction_line_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            $availablePtls = $ptlCandidates->reject(fn ($ptl) => in_array((int) $ptl->id, $otherClaimedPtlIds, true))->values();

            // If another candidate has direct bundle items and this detail does not, this detail is ordinary
            $otherHasDirectBundle = $parentCandidatesSameProduct
                ->where('id', '!=', $detail->id)
                ->contains(fn ($sd) => $sd->bundleItems && $sd->bundleItems->isNotEmpty());

            if ($otherHasDirectBundle && ! $hasDirectBundleItems && $availablePtls->isEmpty()) {
                return null;
            }

            if ($availablePtls->count() === 1 && $parentCandidatesSameProduct->count() === 1) {
                $matchedPtl = $availablePtls->first();
            } elseif ($availablePtls->count() === 1 && $parentCandidatesSameProduct->count() > 1) {
                $byQty = $availablePtls->filter(fn ($ptl) => abs((float) ($ptl->qty ?? 0) - (float) $detail->quantity) < 0.0001)->values();
                if ($byQty->count() === 1 && ($hasDirectBundleItems || $hasCarrierRows)) {
                    $matchedPtl = $byQty->first();
                } elseif (($hasDirectBundleItems || $hasCarrierRows) && (
                    abs((float) $parentCandidatesSameProduct->sum('quantity') - (float) ($availablePtls->first()->qty ?? 0)) < 0.0001
                    || (float) ($availablePtls->first()->qty ?? 0) >= (float) $detail->quantity
                )) {
                    $matchedPtl = $availablePtls->first();
                } else {
                    return [
                        'is_bundle' => true,
                        'is_ambiguous' => true,
                        'error' => "Produk bundle {$detail->product_name} memiliki beberapa instans berulang yang ambigu dalam transaksi.",
                    ];
                }
            } else {
                $byQty = $availablePtls->filter(fn ($ptl) => abs((float) ($ptl->qty ?? 0) - (float) $detail->quantity) < 0.0001)->values();
                if ($byQty->count() === 1 && $parentCandidatesSameProduct->count() === 1) {
                    $matchedPtl = $byQty->first();
                } elseif ($parentCandidatesSameProduct->count() > 1) {
                    return [
                        'is_bundle' => true,
                        'is_ambiguous' => true,
                        'error' => "Produk bundle {$detail->product_name} memiliki beberapa instans berulang yang ambigu dalam transaksi.",
                    ];
                }
            }
        }

        $bundleId = $matchedPtl ? (isset($matchedPtl->line_meta['bundle_id']) ? (int) $matchedPtl->line_meta['bundle_id'] : null) : null;
        if (!$bundleId && $directBundleId) {
            $bundleId = $directBundleId;
        }
        if (!$bundleId && $hasCarrierRows) {
            $carrierBi = $carrierDetails->first()->bundleItems->first();
            $bundleId = $carrierBi && $carrierBi->bundle_id ? (int) $carrierBi->bundle_id : null;
        }

        $bundleName = $matchedPtl ? ($matchedPtl->line_meta['bundle_name'] ?? null) : null;

        // Task 1.3: Resolve composition using captured original composition first!
        // 1) From PTL line_meta['bundle_items'] (authoritative captured checkout composition)
        $capturedItems = [];
        $hasCapturedPtlItems = false;
        $hasPersistedComposition = false;
        if ($matchedPtl && !empty($matchedPtl->line_meta['bundle_items']) && is_array($matchedPtl->line_meta['bundle_items'])) {
            $hasCapturedPtlItems = true;
            foreach ($matchedPtl->line_meta['bundle_items'] as $bi) {
                $capturedItems[] = [
                    'product_id' => (int) ($bi['product_id'] ?? 0),
                    'product_name' => $bi['product_name'] ?? $bi['name'] ?? null,
                    'product_code' => $bi['product_code'] ?? null,
                    'quantity_per_bundle' => (float) ($bi['quantity'] ?? $bi['qty'] ?? 1),
                    'bundle_item_id' => isset($bi['bundle_item_id']) ? (int) $bi['bundle_item_id'] : null,
                ];
            }
        }

        // 2) From persisted SaleBundleItem records — the composition actually posted at checkout,
        //    authoritative over a catalog that may have been edited since. Carrier rows are only
        //    used when a single carrier exists, so separate bundle instances never merge.
        if (empty($capturedItems)) {
            $sourceBundleItems = $hasDirectBundleItems
                ? $directBundleItems
                : ($carrierDetails->count() === 1 ? $carrierDetails->first()->bundleItems : collect());

            if ($sourceBundleItems->isNotEmpty()) {
                $parentQty = (float) $detail->quantity > 0 ? (float) $detail->quantity : 1.0;
                foreach ($sourceBundleItems as $sbi) {
                    $productName = $sbi->name ?? ($sbi->relationLoaded('product') ? $sbi->product?->product_name : null);
                    $productCode = $sbi->relationLoaded('product') ? $sbi->product?->product_code : null;
                    $capturedItems[] = [
                        'product_id' => (int) $sbi->product_id,
                        'product_name' => $productName,
                        'product_code' => $productCode,
                        'quantity_per_bundle' => (float) $sbi->quantity / $parentQty,
                        'bundle_item_id' => $sbi->bundle_item_id ? (int) $sbi->bundle_item_id : null,
                    ];
                }
                $hasPersistedComposition = true;
            }
        }

        // 3) No composition was captured at checkout. Today's catalog is not evidence of what was
        //    sold, so block with an actionable error instead of substituting it.
        if (empty($capturedItems)) {
            return [
                'is_bundle' => true,
                'is_ambiguous' => true,
                'is_unresolved_composition' => true,
                'error' => "Komposisi asli paket bundel {$detail->product_name} tidak dapat dipastikan: "
                    . 'tidak ada komposisi yang tercatat saat checkout (item bundel pada baris POS maupun item bundel penjualan). '
                    . 'Lengkapi data komposisi sumber sebelum memproses retur paket ini.',
            ];
        }

        // Link each component to carrier row, SaleBundleItem, and DispatchDetail
        $resolvedComponents = [];
        foreach ($capturedItems as $item) {
            $compProductId = $item['product_id'];
            $qtyPerBundle = $item['quantity_per_bundle'];
            $bundleItemId = $item['bundle_item_id'];

            // Find matching carrier row among carrierDetails if present
            $matchedCarrierDetail = $carrierDetails->first(function ($cd) use ($compProductId) {
                return $cd->bundleItems->contains('product_id', $compProductId);
            });

            $carrierSaleDetailId = $matchedCarrierDetail ? (int) $matchedCarrierDetail->id : (int) $detail->id;

            $bundleItem = $this->findComponentBundleItem(
                $allSaleIds,
                $compProductId,
                $bundleId,
                $bundleItemId,
                $qtyPerBundle,
                $carrierSaleDetailId
            );

            $carrierSaleDetail = $bundleItem?->saleDetail ?? $matchedCarrierDetail ?? $detail;
            $carrierSaleDetailId = (int) $carrierSaleDetail->id;
            $saleId = (int) ($bundleItem->sale_id ?? $carrierSaleDetail->sale_id ?? $detail->sale_id);

            $dispatchDetailId = $this->findComponentDispatchDetailId($saleId, $compProductId, $carrierSaleDetailId, $bundleId);

            // Source allocation lineage: ambiguous when several persisted bundle items remain
            // candidates; missing when the component's source Sale holds neither a dispatch nor
            // a sale row for it.
            if (! $bundleItem && SaleBundleItem::query()->whereIn('sale_id', $allSaleIds)->where('product_id', $compProductId)->count() > 1) {
                $lineageStatus = 'ambiguous';
            } elseif ($dispatchDetailId === null
                && ! SaleDetails::query()->where('sale_id', $saleId)->where('product_id', $compProductId)->exists()
            ) {
                $lineageStatus = 'missing';
            } else {
                $lineageStatus = 'resolved';
            }

            $resolvedComponents[] = [
                'product_id' => $compProductId,
                'product_name' => $item['product_name'] ?? $bundleItem?->name ?? ($bundleItem?->relationLoaded('product') ? $bundleItem->product?->product_name : null) ?? 'Unknown Component',
                'product_code' => $item['product_code'] ?? ($bundleItem?->relationLoaded('product') ? $bundleItem->product?->product_code : null),
                'quantity_per_bundle' => $qtyPerBundle,
                'bundle_item_id' => $bundleItemId ?? ($bundleItem ? (int) $bundleItem->bundle_item_id : null),
                'carrier_sale_detail_id' => $carrierSaleDetailId,
                'sale_id' => $saleId,
                'sale_bundle_item_id' => $bundleItem ? (int) $bundleItem->id : null,
                'dispatch_detail_id' => $dispatchDetailId,
                'lineage_status' => $lineageStatus,
                'allocated_price' => $bundleItem ? (float) ($bundleItem->price > 0 ? $bundleItem->price : ($bundleItem->sub_total > 0 ? $bundleItem->sub_total / max(1, $bundleItem->quantity) : 0.0)) : 0.0,
            ];
        }

        if (empty($resolvedComponents)) {
            return null;
        }

        return [
            'is_bundle' => true,
            'is_ambiguous' => false,
            'has_captured_composition' => $hasCapturedPtlItems || $hasPersistedComposition,
            'bundle_id' => $bundleId,
            'bundle_name' => $bundleName,
            'pos_transaction_line_id' => $matchedPtl?->id,
            'components' => $resolvedComponents,
        ];
    }

    /**
     * Resolve a bundle component's persisted SaleBundleItem across every
     * owner checkout sale in this transaction, using the strongest
     * available identity: bundle_id (shared across every owner's split
     * posting for the same bundle instance) plus product_id, disambiguated
     * further by bundle_item_id when supplied, carrierSaleDetailId when known,
     * and by quantity match when multiple candidates remain.
     *
     * @param iterable $saleIds sale_ids of every checkout sale/sale in scope
     */
    /**
     * Actionable message for a bundle component whose source allocation
     * lineage is not 'resolved'.
     */
    public function describeUnresolvedComponent(array $component, ?string $parentName = null): string
    {
        $name = $component['product_name'] ?? 'Unknown Component';
        $bundleLabel = $parentName ? " pada paket {$parentName}" : '';

        $reason = ($component['lineage_status'] ?? null) === 'ambiguous'
            ? 'alokasi penjualan sumbernya ambigu: beberapa item bundel penjualan cocok dan tidak dapat dibedakan'
            : 'alokasi penjualan sumbernya tidak ditemukan: tidak ada baris penjualan maupun pengiriman komponen pada penjualan sumber';

        return "Komponen bundel '{$name}'{$bundleLabel} tidak dapat diretur sebagai bagian dari seluruh paket karena {$reason}. "
            . 'Perbaiki data alokasi sumber komponen ini sebelum memproses retur.';
    }

    public function findComponentBundleItem(
        iterable $saleIds,
        int $productId,
        ?int $bundleId = null,
        ?int $bundleItemId = null,
        ?float $expectedQuantityPerBundle = null,
        ?int $carrierSaleDetailId = null
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

        if ($carrierSaleDetailId) {
            $byCarrier = $candidates->where('sale_detail_id', $carrierSaleDetailId)->values();
            if ($byCarrier->count() === 1) {
                return $byCarrier->first();
            }
            if ($byCarrier->count() > 1) {
                $candidates = $byCarrier;
            }
        }

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
            $byQuantity = $candidates->filter(function ($item) use ($expectedQuantityPerBundle) {
                $qty = (float) $item->quantity;
                if (abs($qty - $expectedQuantityPerBundle) < 0.0001) {
                    return true;
                }
                if ($expectedQuantityPerBundle > 0 && abs(fmod($qty, $expectedQuantityPerBundle)) < 0.0001) {
                    return true;
                }
                return false;
            })->values();
            if ($byQuantity->count() === 1) {
                return $byQuantity->first();
            }
        }

        return null;
    }

    /**
     * Resolve the catalog ProductBundleItem rows for a bundle whose PARENT
     * product is $parentProductId, via the ProductBundle header
     * (parent_product_id).
     *
     * @return \Illuminate\Support\Collection<int, \Modules\Product\Entities\ProductBundleItem>
     */
    public function resolveCatalogBundleComponents(int $parentProductId, ?int $bundleId = null): \Illuminate\Support\Collection
    {
        $query = ProductBundle::query()
            ->where('parent_product_id', $parentProductId);

        if ($bundleId) {
            $query->whereKey($bundleId);
        } else {
            if ($query->count() !== 1) {
                return collect();
            }
        }

        $bundle = $query->first();

        if (! $bundle) {
            return collect();
        }

        return ProductBundleItem::query()
            ->with('product')
            ->where('bundle_id', $bundle->id)
            ->get();
    }

    /**
     * Resolve the component's own DispatchDetail, matching exactly what
     * InlinePosCheckoutPostingAdapter persists: DispatchDetail.sale_id = the
     * component's owning Sale, DispatchDetail.product_id = the component's
     * own product_id, DispatchDetail.sale_detail_id = the CARRIER row's id.
     */
    public function findComponentDispatchDetailId(int $saleId, int $productId, int $carrierSaleDetailId, ?int $bundleId = null): ?int
    {
        $query = DispatchDetail::where('sale_id', $saleId)
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
            $id = DispatchDetail::where('sale_id', $saleId)
                ->where('product_id', $productId)
                ->value('id');
        }

        return $id ? (int) $id : null;
    }
}
