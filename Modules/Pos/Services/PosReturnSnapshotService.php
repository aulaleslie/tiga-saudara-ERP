<?php

namespace Modules\Pos\Services;

use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Sale\Entities\SaleDetails;

class PosReturnSnapshotService
{
    public function __construct(
        protected PosBundleComponentResolver $bundleComponentResolver = new PosBundleComponentResolver()
    ) {
    }

    /**
     * Build source snapshot for a POS transaction.
     *
     * @param int $posTransactionId
     * @return array
     */
    public function build(int $posTransactionId): array
    {
        $transaction = PosTransaction::with([
            'customer',
            'completedCheckout.checkoutSales.sale.saleDetails.product',
            'completedCheckout.checkoutSales.sale.saleDetails.bundleItems.product',
            'completedCheckout.payments.paymentMethod'
        ])->findOrFail($posTransactionId);

        $checkout = $transaction->completedCheckout;

        $snapshot = [
            'header' => [
                'transaction_id' => $transaction->id,
                'transaction_code' => $transaction->code,
                'checkout_id' => $checkout->id,
                'receipt_number' => $checkout->receipt_number,
                'customer_id' => $transaction->customer_id,
                'customer_name' => optional($transaction->customer)->customer_name,
                'date' => $transaction->created_at->toIso8601String(),
                'grand_total' => (float) $checkout->grand_total,
            ],
            'owners' => $checkout->checkoutSales->map(function ($cs) {
                return [
                    'checkout_sale_id' => $cs->id,
                    'split_key' => $cs->split_key,
                    'source_setting_id' => $cs->source_setting_id,
                    'source_location_id' => $cs->source_location_id,
                    'sale_id' => $cs->sale_id,
                    'grand_total' => (float) $cs->grand_total,
                ];
            })->toArray(),
            'payments' => $checkout->payments->map(function ($p) {
                return [
                    'method_name' => $p->paymentMethod->name ?? 'Unknown',
                    'amount' => (float) $p->amount,
                ];
            })->toArray(),
            'lines' => []
        ];

        $lines = [];
        $allSaleDetails = collect();
        $checkoutSalesBySaleId = $checkout->checkoutSales->keyBy('sale_id');
        
        foreach ($checkout->checkoutSales as $cs) {
            $sale = $cs->sale;
            if (!$sale) continue;
            
            foreach ($sale->saleDetails as $detail) {
                $allSaleDetails->put($detail->id, $detail);
            }
        }

        foreach ($allSaleDetails as $detailId => $detail) {
            $cs = $checkoutSalesBySaleId->get($detail->sale_id);
            if ($cs) {
                $lines = array_merge($lines, $this->buildLineSnapshots($transaction, $cs, $detail, $checkout->checkoutSales));
            }
        }
        
        $snapshot['lines'] = array_values(array_filter($lines, fn($l) => !($l['is_zero_qty_component'] ?? false)));
        $snapshot['hash'] = $this->hash($snapshot);

        return $snapshot;
    }

    protected function buildLineSnapshots(PosTransaction $transaction, $checkoutSale, $detail, $allCheckoutSales = null): array
    {
        $allCheckoutSales = $allCheckoutSales ?? collect([$checkoutSale]);

        $dispatchDetailId = \Modules\Sale\Entities\DispatchDetail::where('sale_id', $detail->sale_id)
            ->where('product_id', $detail->product_id)
            ->value('id');

        $isBundle = $detail->bundleItems->isNotEmpty();
        $originalQuantity = (float) $detail->quantity;

        // Bundle details store price=0; derive per-bundle unit price from the checkout_sale subtotal.
        $unitPrice = (float) $detail->unit_price;
        $lineTotal = (float) $detail->sub_total;
        if ($isBundle && $unitPrice == 0.0 && $originalQuantity > 0) {
            $checkoutSubtotal = (float) $checkoutSale->subtotal;
            $unitPrice = $checkoutSubtotal / $originalQuantity;
            $lineTotal = $checkoutSubtotal;
        }

        $snIds = $detail->serial_number_ids ?? [];
        
        // If no SN IDs in detail, check dispatch detail
        if (empty($snIds) && $dispatchDetailId) {
            $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::find($dispatchDetailId);
            if ($dispatchDetail && !empty($dispatchDetail->serial_numbers)) {
                $snIds = is_array($dispatchDetail->serial_numbers) 
                    ? $dispatchDetail->serial_numbers 
                    : explode(',', $dispatchDetail->serial_numbers);
            }
        }

        $serialNumbers = [];
        if (!empty($snIds)) {
            $serialNumbers = \Modules\Product\Entities\ProductSerialNumber::whereIn('id', $snIds)
                ->get(['id', 'serial_number'])
                ->map(fn($sn) => ['id' => $sn->id, 'serial_number' => $sn->serial_number])
                ->toArray();
        }

        $isTracked = (bool) ($detail->product->serial_number_required ?? false);
        $results = [];

        // Corrections/2 (and split-owner carrier-row correction): bundle_items
        // entries now carry full component identity (own sale_detail_id/
        // dispatch_detail_id/is_tracked/serials) so the UI and backend can
        // act on each component independently, not just trace it.
        //
        // $detail->bundleItems (SaleBundleItem rows attached directly to THIS
        // parent's own SaleDetails row) only reflects same-owner bundle
        // composition. In a split-owner checkout, a component whose owner
        // differs from the parent's has its SaleBundleItem attached to a
        // CARRIER row in the COMPONENT's own owner Sale instead — $detail
        // (the parent's row) never gets that SaleBundleItem at all, so
        // $detail->bundleItems is empty or incomplete for cross-owner
        // components. Resolve the catalog ProductBundle definition (by the
        // parent product's own bundle header) whenever available, and use ITS
        // full component list to drive resolution across all checkout sales
        // — this is authoritative for "what components does this bundle
        // instance actually have," independent of which owner posted them.
        $catalogBundleItems = $this->resolveCatalogBundleComponents((int) $detail->product_id);

        if ($catalogBundleItems->isNotEmpty()) {
            $bundleId = $catalogBundleItems->first()->bundle_id;
            $bundleItems = $catalogBundleItems->map(function ($cbi) use ($checkoutSale, $allCheckoutSales, $detail, $bundleId) {
                return $this->buildComponentBundleItemEntry(
                    $checkoutSale,
                    $cbi->product_id,
                    (float) $cbi->quantity,
                    $cbi->product->product_name ?? null,
                    $cbi->product->product_code ?? null,
                    $detail->id,
                    $allCheckoutSales,
                    $bundleId,
                    (int) $cbi->id
                );
            })->toArray();
        } else {
            // No catalog bundle definition resolvable (e.g. legacy/manual
            // test data) — fall back to the parent's own persisted
            // SaleBundleItem rows, matching the previous (same-owner-safe)
            // behavior exactly.
            $bundleItems = $detail->bundleItems->map(function ($bi) use ($checkoutSale, $allCheckoutSales, $detail) {
                return $this->buildComponentBundleItemEntry(
                    $checkoutSale,
                    $bi->product_id,
                    (float) $bi->quantity,
                    $bi->product->product_name,
                    $bi->product->product_code,
                    $detail->id,
                    $allCheckoutSales,
                    $bi->bundle_id,
                    (int) $bi->id
                );
            })->toArray();
        }

        // Check if zero-quantity split component (Task 2.8)
        $isZeroQtyComponent = false;
        if ($originalQuantity == 0.0 && $unitPrice == 0.0) {
            $isZeroQtyComponent = true;
        }

        if ($isTracked && count($serialNumbers) > 0) {
            // Build one row per serial number
            foreach ($serialNumbers as $sn) {
                // Find pos_transaction_line_id
                $ptlId = \Modules\Pos\Entities\PosTransactionLineSerial::where('serial_number', $sn['serial_number'])
                    ->whereHas('line', fn($q) => $q->where('pos_transaction_id', $transaction->id))
                    ->value('pos_transaction_line_id');

                $ptl = $ptlId ? \Modules\Pos\Entities\PosTransactionLine::find($ptlId) : null;
                
                $serialIsBundle = $isBundle;
                $serialBundleItems = $bundleItems;
                $serialUnitPrice = $unitPrice;

                // Task 2.7: POS transaction line metadata is the source of truth for bundle context.
                // Accept bundle_id (real POS checkout), is_bundle (test/manual), or non-empty bundle_items.
                $ptlIsBundle = $ptl && (
                    !empty($ptl->line_meta['bundle_id']) ||
                    !empty($ptl->line_meta['is_bundle']) ||
                    !empty($ptl->line_meta['bundle_items'])
                );

                if ($ptlIsBundle) {
                    $serialIsBundle = true;
                    $serialBundleItems = [];
                    $bundleMetaItems = $ptl->line_meta['bundle_items'] ?? [];
                    $ptlBundleId = isset($ptl->line_meta['bundle_id']) ? (int) $ptl->line_meta['bundle_id'] : null;

                    foreach ($bundleMetaItems as $itemIndex => $item) {
                        $componentProductId = $item['product_id'] ?? null;

                        // Task 2.10 / Corrections/2 / split-owner carrier-row
                        // correction: Align to source allocation context and
                        // expose full component identity (own sale_detail_id/
                        // dispatch_detail_id/is_tracked/serials), passing the
                        // PTL's own bundle_id (the strongest identity signal
                        // available here) so the same component product
                        // appearing in two different bundle purchases in one
                        // transaction cannot be cross-matched.
                        $serialBundleItems[] = $this->buildComponentBundleItemEntry(
                            $checkoutSale,
                            $componentProductId,
                            (float)($item['quantity'] ?? $item['qty'] ?? 0),
                            $item['name'] ?? $item['product_name'] ?? 'Unknown Component',
                            null,
                            $detail->id,
                            $allCheckoutSales,
                            $ptlBundleId,
                            isset($item['bundle_item_id']) ? (int) $item['bundle_item_id'] : null
                        );
                    }
                    
                    // Task 3.9: Use full original POS unit price for bundled serials
                    if ($ptl->unit_price > 0) {
                        $serialUnitPrice = (float) $ptl->unit_price;
                    }
                }

                // Returned qty is either 0 or 1 for a serial
                $returnedQty = (float) PosReturnLine::whereHas('posReturn', function ($q) {
                    $q->consumesReturnQuantity();
                })->where('sale_detail_id', $detail->id)->where('returned_serial_id', $sn['id'])->sum('quantity');

                $results[] = [
                    'checkout_sale_id' => $checkoutSale->id,
                    'sale_id' => $detail->sale_id,
                    'sale_detail_id' => $detail->id,
                    'dispatch_detail_id' => $dispatchDetailId,
                    'pos_transaction_line_id' => $ptlId,
                    'product_id' => $detail->product_id,
                    'product_name' => $detail->product->product_name,
                    'product_code' => $detail->product->product_code,
                    'original_quantity' => 1.0,
                    'returned_quantity' => $returnedQty,
                    'returnable_quantity' => max(0, 1.0 - $returnedQty),
                    'unit_price' => $serialUnitPrice,
                    'line_total' => $serialUnitPrice, // For 1 qty, line total is unit price
                    'tax_id' => $detail->tax_id,
                    'is_tracked' => true,
                    'serial_number_ids' => [$sn['id']],
                    'serial_numbers' => [$sn],
                    // Task 2.7: bundle context from PTL metadata as source of truth
                    'is_bundle' => $serialIsBundle,
                    'bundle_id' => $ptlIsBundle ? ($ptl->line_meta['bundle_id'] ?? null) : null,
                    'bundle_name' => $ptlIsBundle ? ($ptl->line_meta['bundle_name'] ?? null) : null,
                    'bundle_items' => $serialBundleItems,
                    'is_zero_qty_component' => false,
                ];
            }
        } else {
            // Non-serial tracked, one row for the whole sale detail
            $returnedQty = (float) PosReturnLine::whereHas('posReturn', function ($q) {
                $q->consumesReturnQuantity();
            })->where('sale_detail_id', $detail->id)->sum('quantity');

            $results[] = [
                'checkout_sale_id' => $checkoutSale->id,
                'sale_id' => $detail->sale_id,
                'sale_detail_id' => $detail->id,
                'dispatch_detail_id' => $dispatchDetailId,
                'pos_transaction_line_id' => null, // Requirement only mandates it for serialized lines
                'product_id' => $detail->product_id,
                'product_name' => $detail->product->product_name,
                'product_code' => $detail->product->product_code,
                'original_quantity' => $originalQuantity,
                'returned_quantity' => $returnedQty,
                'returnable_quantity' => max(0, $originalQuantity - $returnedQty),
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'tax_id' => $detail->tax_id,
                'is_tracked' => false,
                'serial_number_ids' => [],
                'serial_numbers' => [],
                'is_bundle' => $isBundle,
                'bundle_items' => $bundleItems,
                'is_zero_qty_component' => $isZeroQtyComponent,
            ];
        }

        return $results;
    }

    /**
     * Resolve the catalog ProductBundleItem rows for a bundle whose PARENT
     * product is $parentProductId, via the ProductBundle header
     * (parent_product_id). This is the authoritative "what components does
     * this bundle instance have" source for a non-serial bundle parent
     * line — used instead of $detail->bundleItems (the parent's own
     * persisted SaleBundleItem rows), which is empty/incomplete whenever a
     * component's owner differs from the parent's in a split-owner checkout
     * (that component's SaleBundleItem is attached to a carrier row in the
     * COMPONENT's own owner Sale, never to the parent's row at all).
     *
     * @return \Illuminate\Support\Collection<int, \Modules\Product\Entities\ProductBundleItem>
     */
    protected function resolveCatalogBundleComponents(int $parentProductId): \Illuminate\Support\Collection
    {
        // Corrections/10: delegated to PosBundleComponentResolver — shared
        // with PosReturnSubmissionService so both call sites can never
        // disagree about the catalog bundle definition source.
        return $this->bundleComponentResolver->resolveCatalogBundleComponents($parentProductId);
    }

    /**
     * Resolve a bundle component's persisted SaleBundleItem across every
     * owner checkout sale in this transaction, using the strongest
     * available identity: bundle_id (shared across every owner's split
     * posting for the same bundle instance) plus product_id, disambiguated
     * further by bundle_item_id when supplied and by exact quantity_per_bundle
     * match when multiple candidates remain (e.g. the same component product
     * appears in two different bundle purchases in one transaction).
     *
     * CRITICAL: in a split-owner checkout (InlinePosCheckoutPostingAdapter),
     * the component owner's Sale does NOT get a standalone SaleDetails row
     * keyed by the component's OWN product_id. Instead it gets a
     * zero-quantity "carrier" SaleDetails row keyed by the PARENT's
     * product_id (the owner group's own residual/audit line), and the
     * component is represented ONLY via a SaleBundleItem row whose
     * sale_detail_id points at that carrier row
     * (InlinePosCheckoutPostingAdapter::post(), the SaleBundleItem::create()
     * call). Searching for a sibling SaleDetails row keyed by the
     * COMPONENT's own product_id (the previous, incorrect approach) can
     * never find this — no such row exists in that owner's Sale. The
     * SaleBundleItem itself, and its sale_detail_id (the carrier row), are
     * the only reliable source of the component's owning Sale/owner.
     *
     * Returns the matched SaleBundleItem (never null on success) so callers
     * can read bundle_id/bundle_item_id/sale_detail_id/quantity directly,
     * plus (via ->saleDetail) the carrier row for owning Sale/owner context.
     */
    protected function findComponentBundleItem(
        $allCheckoutSales,
        int $productId,
        ?int $bundleId = null,
        ?int $bundleItemId = null,
        ?float $expectedQuantityPerBundle = null
    ): ?\Modules\Sale\Entities\SaleBundleItem {
        // Corrections/10: delegated to PosBundleComponentResolver — shared
        // with PosReturnSubmissionService so both services always agree on
        // disambiguation order (never allowed to drift).
        $saleIds = collect($allCheckoutSales ?? [])->pluck('sale_id')->filter()->unique()->values();

        return $this->bundleComponentResolver->findComponentBundleItem(
            $saleIds,
            $productId,
            $bundleId,
            $bundleItemId,
            $expectedQuantityPerBundle
        );
    }

    /**
     * Corrections/2 (and split-owner carrier-row correction): Build a fully
     * identified bundle_items[] entry for one component, shaped so it can be
     * passed through PosReturnCreateForm::buildLineKey() unchanged (i.e. it
     * carries sale_detail_id, is_tracked, serial_number_ids,
     * pos_transaction_line_id just like a top-level snapshot line).
     *
     * Resolution is now SaleBundleItem-first (see findComponentBundleItem()
     * docblock): the component's owning Sale/carrier SaleDetails/owner are
     * all derived from the matched SaleBundleItem itself, never guessed via
     * a hypothetical sibling SaleDetails row keyed by the component's own
     * product_id — no such row exists in a split-owner checkout. The
     * component's own DispatchDetail is resolved using the CARRIER row's id
     * (SaleBundleItem->sale_detail_id) + the component's own product_id +
     * bundle_id, matching exactly what InlinePosCheckoutPostingAdapter
     * persists (DispatchDetail.sale_detail_id = carrier row id,
     * DispatchDetail.product_id = component product id).
     */
    protected function buildComponentBundleItemEntry(
        $checkoutSale,
        $productId,
        float $quantityPerBundle,
        ?string $productName,
        ?string $productCode,
        int $parentSaleDetailId,
        $allCheckoutSales = null,
        ?int $bundleId = null,
        ?int $bundleItemId = null
    ): array {
        $bundleItem = $this->findComponentBundleItem(
            $allCheckoutSales ?? collect([$checkoutSale])->filter(),
            (int) $productId,
            $bundleId,
            $bundleItemId,
            $quantityPerBundle
        );

        $carrierDetail = $bundleItem?->saleDetail;
        $saleId = $bundleItem->sale_id ?? ($checkoutSale->sale_id ?? optional($checkoutSale->sale)->id);

        $dispatchDetailId = null;
        if ($saleId && $carrierDetail) {
            $dispatchQuery = \Modules\Sale\Entities\DispatchDetail::where('sale_id', $saleId)
                ->where('product_id', $productId)
                ->where('sale_detail_id', $carrierDetail->id);

            if ($bundleItem->bundle_id) {
                $withBundleId = (clone $dispatchQuery)->where('bundle_id', $bundleItem->bundle_id);
                if ($withBundleId->exists()) {
                    $dispatchQuery = $withBundleId;
                }
            }

            $dispatchDetailId = $dispatchQuery->value('id');
        }

        // Fallback: no carrier-row-scoped match found (e.g. legacy/hand-built
        // data without a carrier row) — degrade to the older sale_id+product_id
        // lookup rather than leaving dispatch_detail_id permanently null.
        if (!$dispatchDetailId && $saleId) {
            $dispatchDetailId = \Modules\Sale\Entities\DispatchDetail::where('sale_id', $saleId)
                ->where('product_id', $productId)
                ->value('id');
        }

        $product = $bundleItem->product ?? \Modules\Product\Entities\Product::find($productId);
        $isTracked = (bool) ($product->serial_number_required ?? false);

        // The component's OWN owning checkout sale/owner — resolved by
        // matching the bundle item's own sale_id, which may differ from the
        // parent checkout sale's owner in a split-owner checkout. Falls back
        // to the parent's checkout sale only when no distinct owner could be
        // found (component entirely unresolved).
        $componentCheckoutSale = ($allCheckoutSales ?? collect([$checkoutSale])->filter())
            ->first(fn ($cs) => (int) ($cs->sale_id ?? 0) === (int) $saleId) ?? $checkoutSale;

        $entry = [
            'product_id' => $productId,
            'product_name' => $productName ?? optional($product)->product_name ?? 'Unknown Component',
            'product_code' => $productCode ?? optional($product)->product_code,
            'quantity' => $quantityPerBundle,
            // The CARRIER row's id — the only SaleDetails identity that
            // actually exists for this component in a split-owner checkout.
            // Downstream code (draft submission, preview, lifecycle) must
            // treat this as the component's own return-line identity; it
            // must never be conflated with "the parent's own sale_detail_id"
            // even though it is literally the parent-product-keyed carrier
            // row — sale_bundle_item_id (below) is what disambiguates the
            // component's true identity from the carrier's nominal product.
            'sale_detail_id' => $carrierDetail?->id,
            'dispatch_detail_id' => $dispatchDetailId,
            'unit_price' => 0.0, // Component allocations carry no separate commercial price (see design.md: internal allocation only).
            'is_tracked' => $isTracked,
            'serial_number_ids' => [],
            'serial_numbers' => [],
            'returnable_quantity' => 0.0,
            'checkout_sale_id' => $componentCheckoutSale->id ?? null,
            'source_setting_id' => $componentCheckoutSale->source_setting_id ?? null,
            'source_location_id' => $componentCheckoutSale->source_location_id ?? null,
            // Explicit component identity — the persisted SaleBundleItem this
            // entry was resolved from. Preserved so downstream stages (draft
            // submission, preview, persistence, lifecycle execution) can
            // carry this exact identity through rather than re-deriving it.
            'sale_bundle_item_id' => $bundleItem?->id,
            'bundle_id' => $bundleItem?->bundle_id,
            'bundle_item_id' => $bundleItem?->bundle_item_id,
        ];

        if (!$isTracked) {
            // Non-serial component: returnable_quantity mirrors the top-level
            // non-serial pattern — original allocation qty minus already-returned.
            // The carrier row's OWN quantity is always 0 (it's an audit line,
            // never the component's real quantity) — the component's real
            // originally-fulfilled quantity is the bundle item's own quantity.
            $originalQty = $bundleItem ? (float) $bundleItem->quantity : 0.0;
            $returnedQty = $carrierDetail
                ? (float) PosReturnLine::whereHas('posReturn', function ($q) {
                    $q->consumesReturnQuantity();
                })->where('sale_detail_id', $carrierDetail->id)->sum('quantity')
                : 0.0;
            $entry['returnable_quantity'] = max(0, $originalQty - $returnedQty);

            return $entry;
        }

        // Serial-tracked component: resolve dispatched serials via the
        // component's own dispatch_detail_id, matching the convention used
        // elsewhere for resolving dispatched serials (ProductSerialNumber::
        // where('dispatch_detail_id', ...)).
        $serialNumberIds = [];
        if ($dispatchDetailId) {
            $serialNumberIds = \Modules\Product\Entities\ProductSerialNumber::where('dispatch_detail_id', $dispatchDetailId)
                ->pluck('id')
                ->toArray();
        }

        if (empty($serialNumberIds)) {
            return $entry;
        }

        $serials = \Modules\Product\Entities\ProductSerialNumber::whereIn('id', $serialNumberIds)->get(['id', 'serial_number']);

        $serialEntries = [];
        foreach ($serials as $serial) {
            $returnedQty = $carrierDetail
                ? (float) PosReturnLine::whereHas('posReturn', function ($q) {
                    $q->consumesReturnQuantity();
                })->where('sale_detail_id', $carrierDetail->id)->where('returned_serial_id', $serial->id)->sum('quantity')
                : 0.0;

            $serialEntries[] = [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'returnable_quantity' => max(0, 1.0 - $returnedQty),
            ];
        }

        $entry['serial_number_ids'] = array_column($serialEntries, 'id');
        $entry['serial_numbers'] = array_map(fn ($s) => ['id' => $s['id'], 'serial_number' => $s['serial_number']], $serialEntries);
        $entry['returnable_quantity'] = (float) array_sum(array_column($serialEntries, 'returnable_quantity'));

        return $entry;
    }

    /**
     * Generate canonical hash for a snapshot.
     *
     * @param array $snapshot
     * @return string
     */
    public function hash(array $snapshot): string
    {
        // Remove hash from snapshot before hashing if it exists
        $data = $snapshot;
        unset($data['hash']);
        
        if (isset($data['lines']) && is_array($data['lines'])) {
            usort($data['lines'], function ($a, $b) {
                // Task 2.9: Use POS line ID + Serial ID for keying/sorting where possible
                $aKey = ($a['pos_transaction_line_id'] ?? $a['sale_detail_id'] ?? 0) . '-' . 
                        (!empty($a['serial_number_ids']) ? $a['serial_number_ids'][0] : 0);
                $bKey = ($b['pos_transaction_line_id'] ?? $b['sale_detail_id'] ?? 0) . '-' . 
                        (!empty($b['serial_number_ids']) ? $b['serial_number_ids'][0] : 0);
                return strcmp($aKey, $bKey);
            });
        }
        
        // Sort keys recursively for canonicality
        $this->ksortRecursive($data);
        
        return md5(json_encode($data));
    }

    protected function ksortRecursive(&$array): void
    {
        if (is_array($array)) {
            ksort($array);
            foreach ($array as &$value) {
                $this->ksortRecursive($value);
            }
        }
    }

    /**
     * Consolidate all lines with the same product_id into a single line.
     * This handles cases where POS splits products across multiple sales or bundle fragments.
     */
}
