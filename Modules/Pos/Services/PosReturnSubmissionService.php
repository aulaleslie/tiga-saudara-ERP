<?php

namespace Modules\Pos\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\SaleDetails;
use App\Support\PosReturn\PosReturnQuantityGuard;
use Modules\Setting\Entities\Setting;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\DispatchDetail;

class PosReturnSubmissionService
{
    protected $snapshotService;
    protected $quantityGuard;
    protected $replacementGuard;
    protected $replacementOwnerResolver;
    protected $serialChainResolver;
    protected $bundleComponentResolver;

    public function __construct(
        PosReturnSnapshotService $snapshotService,
        PosReturnQuantityGuard $quantityGuard,
        PosReturnReplacementGuard $replacementGuard,
        PosReturnReplacementOwnerResolver $replacementOwnerResolver,
        PosReturnSerialReplacementChainResolver $serialChainResolver,
        PosBundleComponentResolver $bundleComponentResolver = new PosBundleComponentResolver()
    ) {
        $this->snapshotService = $snapshotService;
        $this->quantityGuard = $quantityGuard;
        $this->replacementGuard = $replacementGuard;
        $this->replacementOwnerResolver = $replacementOwnerResolver;
        $this->serialChainResolver = $serialChainResolver;
        $this->bundleComponentResolver = $bundleComponentResolver;
    }

    /**
     * Store a new POS return draft.
     *
     * @param array $data
     * @return \Modules\Pos\Entities\PosReturn
     * @throws \Exception
     */
    public function store(array $data)
    {
        return DB::transaction(function () use ($data) {
            $transaction = PosTransaction::with(['customer', 'completedCheckout'])
                ->findOrFail($data['pos_transaction_id']);
            $checkout = $transaction->completedCheckout;

            if (!$checkout) {
                throw new \Exception("Transaction has no completed checkout.");
            }

            $currentSnapshot = $this->snapshotService->build($transaction->id);
            $validatedLines = $this->validateDraftLines(
                $data['lines'] ?? [],
                $currentSnapshot,
                $data['source_snapshot_hash'] ?? null,
                null,
                $data['return_option'] ?? null
            );

            // 3. Create POS Return header as Draft
            $posReturn = PosReturn::create([
                'reference' => $this->generatePosReturnReference($transaction->setting_id),
                'setting_id' => $transaction->setting_id,
                'pos_transaction_id' => $transaction->id,
                'pos_checkout_id' => $checkout->id,
                'transaction_code' => $transaction->code,
                'receipt_number' => $checkout->receipt_number,
                'customer_id' => $transaction->customer_id,
                'customer_name' => $transaction->customer_id ? (optional($transaction->customer)->customer_name ?? '-') : 'Walk-in Customer',
                'return_option' => PosReturn::OPTION_CASH_RETURN, // Keep standard option logic or defer to line-level
                'status' => PosReturn::STATUS_DRAFT,
                'approval_status' => PosReturn::APPROVAL_STATUS_DRAFT,
                'source_snapshot' => $currentSnapshot,
                'source_snapshot_hash' => $currentSnapshot['hash'],
                'total_amount' => 0, // Will be updated
                'created_by' => Auth::id(),
            ]);

            $totalAmount = 0;
            $checkoutSales = PosCheckoutSale::where('pos_checkout_id', $checkout->id)
                ->get()
                ->keyBy('sale_id');

            // 4. Process lines
            foreach ($validatedLines as $lineData) {
                $resolution = $lineData['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
                $quantity = (float) ($lineData['quantity'] ?? 0);
                $returnedSerialId = $lineData['returned_serial_id'] ?? null;
                $isSerial = !empty($returnedSerialId);

                $saleDetail = SaleDetails::with(['bundleItems.product'])->findOrFail($lineData['sale_detail_id']);
                $checkoutSale = $checkoutSales->get($lineData['sale_id'] ?? $saleDetail->sale_id);

                if (!$checkoutSale) {
                    throw new \Exception("Checkout sale not found for sale_id {$saleDetail->sale_id}.");
                }

                // Corrections/10 (split-owner carrier-row correction): a
                // synthesized bundle component line's sale_detail_id points at
                // the CARRIER SaleDetails row, whose own product_id is the
                // PARENT's — never the component's. synthesizeBundleCashReturnComponents()/
                // synthesizeSerialComponentLines() persist the component's real
                // identity explicitly via __component_* keys; those MUST be used
                // here instead of re-deriving identity from $saleDetail whenever
                // present, or the persisted PosReturnLine would carry the wrong
                // product.
                $isSynthesizedCrossOwnerComponent = array_key_exists('__component_product_id', $lineData);
                $effectiveProductId = $isSynthesizedCrossOwnerComponent ? (int) $lineData['__component_product_id'] : (int) $saleDetail->product_id;
                $effectiveProduct = $isSynthesizedCrossOwnerComponent
                    ? \Modules\Product\Entities\Product::find($effectiveProductId)
                    : $saleDetail->product;
                $effectiveProductName = $isSynthesizedCrossOwnerComponent ? ($lineData['__component_product_name'] ?? $effectiveProduct?->product_name) : $saleDetail->product_name;
                $effectiveProductCode = $isSynthesizedCrossOwnerComponent ? ($lineData['__component_product_code'] ?? $effectiveProduct?->product_code) : $saleDetail->product_code;

                // Sequence 10 correction: an EXPLICIT (non-synthesized)
                // serial-tracked line — e.g. an independent component
                // product_replacement submitted directly against a carrier
                // row's sale_detail_id, which synthesis never runs for —
                // still needs its true product identity. The returned
                // serial's OWN product_id is always authoritative (a serial
                // can only belong to the product it was issued for), so use
                // it whenever no __component_* tag already resolved
                // identity and this line is serial-tracked.
                if (!$isSynthesizedCrossOwnerComponent && $isSerial && $returnedSerialId) {
                    $returnedSerialProductId = (int) (ProductSerialNumber::find($returnedSerialId)->product_id ?? 0);
                    if ($returnedSerialProductId > 0 && $returnedSerialProductId !== (int) $saleDetail->product_id) {
                        $effectiveProductId = $returnedSerialProductId;
                        $effectiveProduct = \Modules\Product\Entities\Product::find($effectiveProductId);
                        $effectiveProductName = $effectiveProduct?->product_name;
                        $effectiveProductCode = $effectiveProduct?->product_code;
                    }
                }

                // Guard returnable quantity - for non-serial, we check the general returnable qty
                // For serials, the qty is always 1, but we should ensure the specific serial is returnable.
                //
                // Semantic correction: a cash_return line (including a
                // synthesized bundle component) is checked against what's
                // still REFUNDABLE — original quantity minus quantity already
                // consumed by another active cash_return — not the
                // resolution-agnostic "already returned by any resolution"
                // figure. A prior product_replacement never reduces
                // refundable quantity (the customer holds an equivalent
                // replacement unit), so it must not double-block what
                // synthesis already validated. product_replacement lines
                // still use the resolution-agnostic guard: a replaced
                // physical unit remains consumed and cannot also be
                // cash-returned or re-replaced.
                // A synthesized cross-owner component line's sale_detail_id
                // points at the carrier row, whose own SaleDetails.quantity
                // is always 0 by construction — pass the component's true
                // original quantity explicitly (quantity_per_bundle × the
                // bundle parent's own fully-refundable quantity), the same
                // basis synthesizeBundleCashReturnComponents() itself used,
                // instead of letting the guard read SaleDetails.quantity.
                $componentOriginalQuantity = null;
                if ($isSynthesizedCrossOwnerComponent && array_key_exists('__component_quantity_per_bundle', $lineData)) {
                    $quantityPerBundle = (float) $lineData['__component_quantity_per_bundle'];
                    $parentSaleDetailId = (int) ($lineData['__bundle_parent_sale_detail_id'] ?? 0);
                    $parentRefundableQty = $this->quantityGuard->getRefundableQuantity($parentSaleDetailId);
                    $componentOriginalQuantity = $quantityPerBundle * $parentRefundableQty;
                }

                $returnableQty = $resolution === PosReturnLine::RESOLUTION_CASH_RETURN
                    ? $this->quantityGuard->getRefundableQuantity($saleDetail->id, null, $componentOriginalQuantity)
                    : $this->quantityGuard->getReturnableQuantity($saleDetail->dispatch_detail_id, $saleDetail->id);
                if (!$isSerial && $quantity > $returnableQty) {
                    throw new \Exception("Kuantitas retur melebihi batas yang diizinkan untuk produk {$saleDetail->product_name}.");
                }

                $isBundle = $saleDetail->bundleItems->isNotEmpty();
                $unitPrice = (float) $saleDetail->unit_price;
                if ($isBundle && $unitPrice === 0.0 && $saleDetail->quantity > 0) {
                    $unitPrice = (float) $checkoutSale->subtotal / $saleDetail->quantity;
                }

                // Task 3.9: For bundled serials, use the full original POS transaction line unit price
                // as the customer-facing price. This handles split-posting scenarios where the parent
                // Sale Detail price is the residual allocation, not the customer-visible bundle price.
                $ptl = null;
                $ptlIsBundle = false;
                if ($isSerial && !empty($lineData['pos_transaction_line_id'])) {
                    $ptl = \Modules\Pos\Entities\PosTransactionLine::find($lineData['pos_transaction_line_id']);
                    if ($ptl) {
                        $ptlIsBundle = !empty($ptl->line_meta['bundle_id'])
                            || !empty($ptl->line_meta['is_bundle'])
                            || !empty($ptl->line_meta['bundle_items']);
                        if ($ptlIsBundle && $ptl->unit_price > 0) {
                            $unitPrice = (float) $ptl->unit_price;
                            $isBundle = true;
                        }
                    }
                }

                // Corrections/2.1: component lines synthesized from a whole-bundle
                // cash_return carry expected_cash_amount = 0 — the parent line already
                // reflects the full customer-facing bundle price, so component lines
                // must not double-count refund value. See synthesizeBundleCashReturn().
                $isSynthesizedComponent = !empty($lineData['__synthesized_component']);

                $lineTotal = $quantity * $unitPrice;
                // For serials, qty=1, so line_total equals unit_price.
                if ($isSerial) {
                    $lineTotal = $unitPrice;
                }
                $expectedCashAmount = ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN && !$isSynthesizedComponent)
                    ? $lineTotal
                    : 0;

                $replacementSerialId = $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT ? ($lineData['replacement_serial_id'] ?? null) : null;

                // 4.6 Validate replacement serial
                if ($replacementSerialId) {
                    // Sequence 10 correction: use $effectiveProductId (the
                    // component's own product_id when this is a carrier-row
                    // component line — see above), never $saleDetail->product_id
                    // directly, which is the carrier row's own (parent's).
                    $this->replacementGuard->validateReplacementSerial(
                        $effectiveProductId,
                        $replacementSerialId,
                        $returnedSerialId
                    );
                }

                // Corrections/3: identity columns. Parent lines carry their own
                // sale_detail_id as bundle_parent_sale_detail_id (self-reference) so
                // parent + components can be queried by the same column consistently;
                // components carry the resolved parent id. bundle_group_key is the
                // stable per-bundle-instance grouping key shared by parent+components.
                $bundleParentSaleDetailId = $lineData['__bundle_parent_sale_detail_id'] ?? ($isBundle ? $saleDetail->id : null);
                $bundleGroupKey = $lineData['__bundle_group_key'] ?? ($isBundle ? ('bundle-' . $bundleParentSaleDetailId) : null);
                $bundleQuantity = $lineData['__bundle_quantity'] ?? ($isBundle && !$isSynthesizedComponent ? $quantity : null);
                $componentQuantityPerBundle = $lineData['__component_quantity_per_bundle'] ?? null;

                // Sequence 10 correction: an EXPLICIT (non-synthesized)
                // independent component product_replacement submitted
                // directly against a carrier row's sale_detail_id carries no
                // __component_quantity_per_bundle tag (synthesis never ran
                // for it). Persist it anyway from the catalog
                // ProductBundleItem, purely so downstream identity checks
                // (PosReturnApprovalPreviewPlannerService::isBundleComponentLine())
                // can recognize this as a genuine component line — never
                // used to infer product identity itself (that always comes
                // from $effectiveProductId, resolved above from the
                // returned serial's own product_id).
                if ($componentQuantityPerBundle === null && $isBundle && $effectiveProductId !== (int) $saleDetail->product_id) {
                    $componentQuantityPerBundle = $this->bundleComponentResolver
                        ->resolveCatalogBundleComponents((int) $saleDetail->product_id)
                        ->firstWhere('product_id', $effectiveProductId)
                        ?->quantity;
                }

                // Corrections/3 (and Corrections/10): sale_details has no
                // dispatch_detail_id column in this schema, so
                // $saleDetail->dispatch_detail_id is always null. Resolve the
                // line's own dispatch detail dynamically via
                // sale_id+product_id — the same convention already used by
                // PosReturnSnapshotService — so components never accidentally
                // end up with the parent's (equally-null) value.
                // Corrections/10: a synthesized cross-owner component already
                // carries its own resolved dispatch_detail_id explicitly
                // (scoped to the carrier row); use it directly rather than
                // re-deriving via sale_id+product_id alone (which the carrier
                // row's own sale_id would make ambiguous if the parent's own
                // product also shares that sale_id elsewhere).
                $resolvedDispatchDetailId = $isSynthesizedCrossOwnerComponent
                    ? ($lineData['__component_dispatch_detail_id'] ?? null)
                    : ($saleDetail->dispatch_detail_id
                        ?? \Modules\Sale\Entities\DispatchDetail::where('sale_id', $saleDetail->sale_id)
                            // Sequence 10 correction: use $effectiveProductId
                            // (the component's own product_id when this is an
                            // explicit, non-synthesized carrier-row component
                            // line), never $saleDetail->product_id directly,
                            // which is the carrier row's own (the PARENT's).
                            ->where('product_id', $effectiveProductId)
                            ->value('id'));

                // Create the return line using the specific source identity
                $returnLineData = [
                    'pos_return_id' => $posReturn->id,
                    'pos_checkout_sale_id' => $checkoutSale->id,
                    'sale_id' => $saleDetail->sale_id,
                    'sale_detail_id' => $saleDetail->id,
                    'dispatch_detail_id' => $resolvedDispatchDetailId,
                    'pos_transaction_line_id' => $lineData['pos_transaction_line_id'] ?? null,
                    // Corrections/2 (serial bundle components): a synthesized
                    // serial component line carries the CURRENT leaf serial's
                    // own owner/location lineage (resolved via
                    // PosReturnReplacementOwnerResolver in
                    // synthesizeSerialComponentLines()), since a cross-owner
                    // replacement may have moved the component away from the
                    // parent's original checkout-sale owner. Every other line
                    // keeps the original checkout sale owner.
                    'source_setting_id' => $lineData['__component_source_setting_id'] ?? $checkoutSale->source_setting_id,
                    'source_location_id' => $lineData['__component_source_location_id'] ?? $checkoutSale->source_location_id,
                    'tax_id' => $saleDetail->tax_id,
                    // Corrections/10: product_id/name/code must reflect the
                    // component's OWN identity for a synthesized cross-owner
                    // component — never the carrier row's own (parent's)
                    // product_id. See $effectiveProduct* resolution above.
                    'product_id' => $effectiveProductId,
                    'product_name' => $effectiveProductName,
                    'product_code' => $effectiveProductCode,
                    'quantity' => $isSerial ? 1 : $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'stock_behavior' => ($effectiveProduct->stock_managed ?? true) ? PosReturnLine::STOCK_BEHAVIOR_MANAGED : PosReturnLine::STOCK_BEHAVIOR_STOCKLESS,
                    'resolution' => $resolution,
                    'returned_serial_id' => $returnedSerialId,
                    'replacement_serial_id' => $replacementSerialId,
                    'replacement_quantity' => $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                        ? ($isSerial ? 1 : $quantity)
                        : null,
                    'expected_cash_amount' => $expectedCashAmount,
                    'bundle_group_key' => $bundleGroupKey,
                    'bundle_parent_sale_detail_id' => $bundleParentSaleDetailId,
                    'bundle_quantity' => $bundleQuantity,
                    'component_quantity_per_bundle' => $componentQuantityPerBundle,
                ];

                $returnLine = PosReturnLine::create($returnLineData);
                if ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                    $totalAmount += $expectedCashAmount;
                }

                // Corrections/10: persist the resolved sale_bundle_item_id for
                // a synthesized component into line_meta — traceability only;
                // downstream planning (PosReturnApprovalPreviewPlannerService)
                // independently re-resolves this from sale_id+product_id+
                // sale_detail_id and never reads it back from here, so this is
                // not load-bearing for the pipeline, only for debugging/audit.
                if ($isSynthesizedCrossOwnerComponent && !empty($lineData['__sale_bundle_item_id'])) {
                    $returnLine->update([
                        'line_meta' => array_merge($returnLine->line_meta ?? [], [
                            'sale_bundle_item_id' => (int) $lineData['__sale_bundle_item_id'],
                        ]),
                    ]);
                }

                // Task 3.7/3.9: Trace bundled components for actionable lines.
                // Task 3.10: Informational only — no stock reservation or mutation occurs here.
                $lineMetaUpdate = [];
                if ($isBundle && $resolution !== PosReturnLine::RESOLUTION_NONE) {
                    // Task 3.9: Use PTL bundle items as authoritative source when available.
                    $bundleId = $ptlIsBundle ? (int) ($ptl->line_meta['bundle_id'] ?? 0) : 0;

                    if ($ptlIsBundle && !empty($ptl->line_meta['bundle_items'])) {
                        $bundleTrace = array_map(function ($item) use ($quantity) {
                            return [
                                'product_id' => $item['product_id'] ?? null,
                                'quantity_per_bundle' => (float)($item['quantity'] ?? $item['qty'] ?? 0),
                                'total_component_quantity' => (float)($item['quantity'] ?? $item['qty'] ?? 0) * $quantity,
                            ];
                        }, $ptl->line_meta['bundle_items']);
                    } else {
                        $bundleTrace = $saleDetail->bundleItems->map(function ($bi) use ($quantity) {
                            return [
                                'product_id' => $bi->product_id,
                                'quantity_per_bundle' => $bi->quantity,
                                'total_component_quantity' => $bi->quantity * $quantity
                            ];
                        })->toArray();
                    }
                    if (!empty($bundleTrace)) {
                        $lineMetaUpdate['bundle_id'] = $bundleId > 0 ? $bundleId : null;
                        $lineMetaUpdate['bundle_trace'] = $bundleTrace;
                    }
                }

                // Task 2.5: Tag replacement lines with their execution mode so
                // later phases (preview/lifecycle) can read intent without
                // re-deriving it. cash_return / none lines never get this key.
                $executionMode = $this->resolveExecutionMode($resolution, $isSerial);
                if ($executionMode !== null) {
                    $lineMetaUpdate['execution_mode'] = $executionMode;
                }

                // Corrections/4: persist the replacement reason (optional for serial,
                // required for non-serial — enforced in validateDraftLines()).
                if ($resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT && !empty($lineData['replacement_reason'])) {
                    $lineMetaUpdate['replacement_reason'] = trim((string) $lineData['replacement_reason']);
                }

                if (!empty($lineMetaUpdate)) {
                    $returnLine->update(['line_meta' => array_filter($lineMetaUpdate, fn ($value) => $value !== null)]);
                }
            }

            $posReturn->update(['total_amount' => $totalAmount]);

            return $posReturn;
        });
    }

    public function update(PosReturn $posReturn, array $data)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.edit'), 403);
        
        if (!$posReturn->isRevisionEditable()) {
            throw new \Exception('Hanya retur draft atau ditolak yang dapat diubah.');
        }

        return DB::transaction(function () use ($posReturn, $data) {
            // Delete old associated draft lines
            $posReturn->lines()->delete();
            
            $transaction = $posReturn->posTransaction()->with(['customer', 'completedCheckout'])->firstOrFail();
            $currentSnapshot = $this->snapshotService->build($transaction->id);
            $validatedLines = $this->validateDraftLines(
                $data['lines'] ?? [],
                $currentSnapshot,
                $data['source_snapshot_hash'] ?? null,
                $posReturn->id,
                $data['return_option'] ?? $posReturn->return_option
            );

            $totalAmount = 0;
            $checkoutSales = PosCheckoutSale::where('pos_checkout_id', $posReturn->pos_checkout_id)
                ->get()
                ->keyBy('sale_id');

            foreach ($validatedLines as $lineData) {
                $resolution = $lineData['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
                $quantity = (float) ($lineData['quantity'] ?? 0);
                $returnedSerialId = $lineData['returned_serial_id'] ?? null;
                $isSerial = !empty($returnedSerialId);

                $saleDetail = SaleDetails::with(['bundleItems.product'])->findOrFail($lineData['sale_detail_id']);
                $checkoutSale = $checkoutSales->get($lineData['sale_id'] ?? $saleDetail->sale_id);

                // Corrections/10 (split-owner carrier-row correction): see
                // store() for full rationale — a synthesized bundle component
                // line's sale_detail_id points at the CARRIER row (product_id
                // = the PARENT's), so its own identity must come from the
                // explicit __component_* keys instead.
                $isSynthesizedCrossOwnerComponent = array_key_exists('__component_product_id', $lineData);
                $effectiveProductId = $isSynthesizedCrossOwnerComponent ? (int) $lineData['__component_product_id'] : (int) $saleDetail->product_id;
                $effectiveProduct = $isSynthesizedCrossOwnerComponent
                    ? \Modules\Product\Entities\Product::find($effectiveProductId)
                    : $saleDetail->product;
                $effectiveProductName = $isSynthesizedCrossOwnerComponent ? ($lineData['__component_product_name'] ?? $effectiveProduct?->product_name) : $saleDetail->product_name;
                $effectiveProductCode = $isSynthesizedCrossOwnerComponent ? ($lineData['__component_product_code'] ?? $effectiveProduct?->product_code) : $saleDetail->product_code;

                // Sequence 10 correction: see store() for rationale — an
                // explicit (non-synthesized) serial-tracked line's returned
                // serial always carries its own authoritative product_id.
                if (!$isSynthesizedCrossOwnerComponent && $isSerial && $returnedSerialId) {
                    $returnedSerialProductId = (int) (ProductSerialNumber::find($returnedSerialId)->product_id ?? 0);
                    if ($returnedSerialProductId > 0 && $returnedSerialProductId !== (int) $saleDetail->product_id) {
                        $effectiveProductId = $returnedSerialProductId;
                        $effectiveProduct = \Modules\Product\Entities\Product::find($effectiveProductId);
                        $effectiveProductName = $effectiveProduct?->product_name;
                        $effectiveProductCode = $effectiveProduct?->product_code;
                    }
                }

                // Semantic correction: see store() for rationale — cash_return
                // lines are checked against refundable (not resolution-agnostic
                // returned) quantity, so a prior product_replacement never
                // blocks a later cash_return for the same logical quantity.
                //
                // See store() for rationale: a synthesized cross-owner
                // component line's sale_detail_id is the carrier row, whose
                // own SaleDetails.quantity is always 0 — pass the component's
                // true original quantity explicitly instead.
                $componentOriginalQuantity = null;
                if ($isSynthesizedCrossOwnerComponent && array_key_exists('__component_quantity_per_bundle', $lineData)) {
                    $quantityPerBundle = (float) $lineData['__component_quantity_per_bundle'];
                    $parentSaleDetailId = (int) ($lineData['__bundle_parent_sale_detail_id'] ?? 0);
                    $parentRefundableQty = $this->quantityGuard->getRefundableQuantity($parentSaleDetailId, $posReturn->id);
                    $componentOriginalQuantity = $quantityPerBundle * $parentRefundableQty;
                }

                $returnableQty = $resolution === PosReturnLine::RESOLUTION_CASH_RETURN
                    ? $this->quantityGuard->getRefundableQuantity($saleDetail->id, $posReturn->id, $componentOriginalQuantity)
                    : $this->quantityGuard->getReturnableQuantity($saleDetail->dispatch_detail_id, $saleDetail->id, $posReturn->id);
                if (!$isSerial && $quantity > $returnableQty) {
                    throw new \Exception("Kuantitas retur melebihi batas yang diizinkan untuk produk {$saleDetail->product_name}.");
                }

                $isBundle = $saleDetail->bundleItems->isNotEmpty();
                $unitPrice = (float) $saleDetail->unit_price;
                if ($isBundle && $unitPrice === 0.0 && $saleDetail->quantity > 0) {
                    $unitPrice = (float) $checkoutSale->subtotal / $saleDetail->quantity;
                }

                // Task 3.9: For bundled serials, use the full original POS transaction line unit price.
                $ptl = null;
                $ptlIsBundle = false;
                if ($isSerial && !empty($lineData['pos_transaction_line_id'])) {
                    $ptl = \Modules\Pos\Entities\PosTransactionLine::find($lineData['pos_transaction_line_id']);
                    if ($ptl) {
                        $ptlIsBundle = !empty($ptl->line_meta['bundle_id'])
                            || !empty($ptl->line_meta['is_bundle'])
                            || !empty($ptl->line_meta['bundle_items']);
                        if ($ptlIsBundle && $ptl->unit_price > 0) {
                            $unitPrice = (float) $ptl->unit_price;
                            $isBundle = true;
                        }
                    }
                }

                $isSynthesizedComponent = !empty($lineData['__synthesized_component']);

                $lineTotal = $isSerial ? $unitPrice : ($quantity * $unitPrice);
                $expectedCashAmount = ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN && !$isSynthesizedComponent)
                    ? $lineTotal
                    : 0;

                $replacementSerialId = $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT ? ($lineData['replacement_serial_id'] ?? null) : null;

                // 4.6 Validate replacement serial
                if ($replacementSerialId) {
                    // Sequence 10 correction: use $effectiveProductId, never
                    // $saleDetail->product_id directly — see store().
                    $this->replacementGuard->validateReplacementSerial(
                        $effectiveProductId,
                        $replacementSerialId,
                        $returnedSerialId,
                        $posReturn->id
                    );
                }

                $bundleParentSaleDetailId = $lineData['__bundle_parent_sale_detail_id'] ?? ($isBundle ? $saleDetail->id : null);
                $bundleGroupKey = $lineData['__bundle_group_key'] ?? ($isBundle ? ('bundle-' . $bundleParentSaleDetailId) : null);
                $bundleQuantity = $lineData['__bundle_quantity'] ?? ($isBundle && !$isSynthesizedComponent ? $quantity : null);
                $componentQuantityPerBundle = $lineData['__component_quantity_per_bundle'] ?? null;

                // Sequence 10 correction: an EXPLICIT (non-synthesized)
                // independent component product_replacement submitted
                // directly against a carrier row's sale_detail_id carries no
                // __component_quantity_per_bundle tag (synthesis never ran
                // for it). Persist it anyway from the catalog
                // ProductBundleItem, purely so downstream identity checks
                // (PosReturnApprovalPreviewPlannerService::isBundleComponentLine())
                // can recognize this as a genuine component line — never
                // used to infer product identity itself (that always comes
                // from $effectiveProductId, resolved above from the
                // returned serial's own product_id).
                if ($componentQuantityPerBundle === null && $isBundle && $effectiveProductId !== (int) $saleDetail->product_id) {
                    $componentQuantityPerBundle = $this->bundleComponentResolver
                        ->resolveCatalogBundleComponents((int) $saleDetail->product_id)
                        ->firstWhere('product_id', $effectiveProductId)
                        ?->quantity;
                }

                // Corrections/3 (and Corrections/10): resolve the line's own
                // dispatch detail dynamically (see store() for rationale).
                $resolvedDispatchDetailId = $isSynthesizedCrossOwnerComponent
                    ? ($lineData['__component_dispatch_detail_id'] ?? null)
                    : ($saleDetail->dispatch_detail_id
                        ?? \Modules\Sale\Entities\DispatchDetail::where('sale_id', $saleDetail->sale_id)
                            // Sequence 10 correction: use $effectiveProductId
                            // (the component's own product_id when this is an
                            // explicit, non-synthesized carrier-row component
                            // line), never $saleDetail->product_id directly,
                            // which is the carrier row's own (the PARENT's).
                            ->where('product_id', $effectiveProductId)
                            ->value('id'));

                $returnLineData = [
                    'pos_return_id' => $posReturn->id,
                    'pos_checkout_sale_id' => $checkoutSale->id,
                    'sale_id' => $saleDetail->sale_id,
                    'sale_detail_id' => $saleDetail->id,
                    'dispatch_detail_id' => $resolvedDispatchDetailId,
                    'pos_transaction_line_id' => $lineData['pos_transaction_line_id'] ?? null,
                    // Corrections/2 (serial bundle components): a synthesized
                    // serial component line carries the CURRENT leaf serial's
                    // own owner/location lineage (resolved via
                    // PosReturnReplacementOwnerResolver in
                    // synthesizeSerialComponentLines()), since a cross-owner
                    // replacement may have moved the component away from the
                    // parent's original checkout-sale owner. Every other line
                    // keeps the original checkout sale owner.
                    'source_setting_id' => $lineData['__component_source_setting_id'] ?? $checkoutSale->source_setting_id,
                    'source_location_id' => $lineData['__component_source_location_id'] ?? $checkoutSale->source_location_id,
                    'tax_id' => $saleDetail->tax_id,
                    // Corrections/10: see store() — must never be re-derived
                    // from the carrier row's own (parent's) product_id.
                    'product_id' => $effectiveProductId,
                    'product_name' => $effectiveProductName,
                    'product_code' => $effectiveProductCode,
                    'quantity' => $isSerial ? 1 : $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'stock_behavior' => ($effectiveProduct->stock_managed ?? true) ? PosReturnLine::STOCK_BEHAVIOR_MANAGED : PosReturnLine::STOCK_BEHAVIOR_STOCKLESS,
                    'resolution' => $resolution,
                    'returned_serial_id' => $returnedSerialId,
                    'replacement_serial_id' => $replacementSerialId,
                    'replacement_quantity' => $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                        ? ($isSerial ? 1 : $quantity)
                        : null,
                    'expected_cash_amount' => $expectedCashAmount,
                    'bundle_group_key' => $bundleGroupKey,
                    'bundle_parent_sale_detail_id' => $bundleParentSaleDetailId,
                    'bundle_quantity' => $bundleQuantity,
                    'component_quantity_per_bundle' => $componentQuantityPerBundle,
                ];

                $returnLine = PosReturnLine::create($returnLineData);
                if ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                    $totalAmount += $expectedCashAmount;
                }

                // Corrections/10: persist the resolved sale_bundle_item_id for
                // a synthesized component into line_meta (traceability only —
                // see store() for why this is not load-bearing downstream).
                if ($isSynthesizedCrossOwnerComponent && !empty($lineData['__sale_bundle_item_id'])) {
                    $returnLine->update([
                        'line_meta' => array_merge($returnLine->line_meta ?? [], [
                            'sale_bundle_item_id' => (int) $lineData['__sale_bundle_item_id'],
                        ]),
                    ]);
                }

                // Task 3.7/3.9: Trace bundled components for actionable lines.
                // Task 3.10: Informational only — no stock reservation or mutation occurs here.
                $lineMetaUpdate = [];
                if ($isBundle && $resolution !== PosReturnLine::RESOLUTION_NONE) {
                    $bundleId = $ptlIsBundle ? (int) ($ptl->line_meta['bundle_id'] ?? 0) : 0;

                    if ($ptlIsBundle && !empty($ptl->line_meta['bundle_items'])) {
                        $bundleTrace = array_map(function ($item) use ($quantity) {
                            return [
                                'product_id' => $item['product_id'] ?? null,
                                'quantity_per_bundle' => (float)($item['quantity'] ?? $item['qty'] ?? 0),
                                'total_component_quantity' => (float)($item['quantity'] ?? $item['qty'] ?? 0) * $quantity,
                            ];
                        }, $ptl->line_meta['bundle_items']);
                    } else {
                        $bundleTrace = $saleDetail->bundleItems->map(function ($bi) use ($quantity) {
                            return [
                                'product_id' => $bi->product_id,
                                'quantity_per_bundle' => $bi->quantity,
                                'total_component_quantity' => $bi->quantity * $quantity
                            ];
                        })->toArray();
                    }
                    if (!empty($bundleTrace)) {
                        $lineMetaUpdate['bundle_id'] = $bundleId > 0 ? $bundleId : null;
                        $lineMetaUpdate['bundle_trace'] = $bundleTrace;
                    }
                }

                // Task 2.5: Tag replacement lines with their execution mode so
                // later phases (preview/lifecycle) can read intent without
                // re-deriving it. cash_return / none lines never get this key.
                $executionMode = $this->resolveExecutionMode($resolution, $isSerial);
                if ($executionMode !== null) {
                    $lineMetaUpdate['execution_mode'] = $executionMode;
                }

                // Corrections/4: persist the replacement reason.
                if ($resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT && !empty($lineData['replacement_reason'])) {
                    $lineMetaUpdate['replacement_reason'] = trim((string) $lineData['replacement_reason']);
                }

                if (!empty($lineMetaUpdate)) {
                    $returnLine->update(['line_meta' => array_filter($lineMetaUpdate, fn ($value) => $value !== null)]);
                }
            }

            $status = $posReturn->isRejectedEditable() ? PosReturn::STATUS_DRAFT : $posReturn->status;
            $approvalStatus = $posReturn->isRejectedEditable() ? PosReturn::APPROVAL_STATUS_DRAFT : $posReturn->approval_status;

            $posReturn->update([
                'total_amount' => $totalAmount,
                'status' => $status,
                'approval_status' => $approvalStatus,
                'source_snapshot' => $currentSnapshot,
                'source_snapshot_hash' => $currentSnapshot['hash'],
                'updated_by' => Auth::id(),
            ]);

            return $posReturn->refresh();
        });
    }

    public function submitDraftForApproval(PosReturn $posReturn): PosReturn
    {
        if (!$posReturn->isDraftSubmittable()) {
            throw new \Exception('Hanya retur draft yang dapat diajukan untuk persetujuan.');
        }

        return DB::transaction(function () use ($posReturn) {
            $lockedReturn = PosReturn::query()
                ->with('lines')
                ->whereKey($posReturn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$lockedReturn->isDraftSubmittable()) {
                throw new \Exception('Hanya retur draft yang dapat diajukan untuk persetujuan.');
            }

            $transaction = $lockedReturn->posTransaction()->with(['customer', 'completedCheckout'])->firstOrFail();
            $currentSnapshot = $this->snapshotService->build($transaction->id);

            $this->validateDraftLines(
                $lockedReturn->lines->map(function (PosReturnLine $line) {
                    return [
                        'sale_detail_id' => $line->sale_detail_id,
                        'sale_id' => $line->sale_id,
                        'pos_transaction_line_id' => $line->pos_transaction_line_id,
                        'returned_serial_id' => $line->returned_serial_id,
                        'resolution' => $line->resolution,
                        'quantity' => (float) $line->quantity,
                        'replacement_serial_id' => $line->replacement_serial_id,
                        // Corrections/4: re-validation must see the persisted
                        // reason, otherwise a previously-valid non-serial
                        // product_replacement line would spuriously fail here.
                        'replacement_reason' => data_get($line->line_meta, 'replacement_reason'),
                        // Sequence 10 correction: a previously-synthesized
                        // carrier-row component line, once persisted, is
                        // re-submitted here as a raw line. Without these
                        // identity tags, the pre-synthesis guard re-check
                        // below (see store()/validateDraftLines()) would
                        // treat its sale_detail_id as a standalone row and
                        // re-derive product/quantity identity from the
                        // CARRIER row's own (parent's) product_id and
                        // always-0 quantity — spuriously rejecting a valid
                        // resubmission. Carry the persisted component
                        // identity columns forward so re-validation
                        // recognizes it exactly as synthesis originally did.
                        '__component_product_id' => ($line->bundle_parent_sale_detail_id && (int) $line->bundle_parent_sale_detail_id !== (int) $line->sale_detail_id) ? $line->product_id : null,
                        '__component_product_name' => ($line->bundle_parent_sale_detail_id && (int) $line->bundle_parent_sale_detail_id !== (int) $line->sale_detail_id) ? $line->product_name : null,
                        '__component_product_code' => ($line->bundle_parent_sale_detail_id && (int) $line->bundle_parent_sale_detail_id !== (int) $line->sale_detail_id) ? $line->product_code : null,
                        '__component_quantity_per_bundle' => $line->component_quantity_per_bundle !== null ? (float) $line->component_quantity_per_bundle : null,
                        '__bundle_parent_sale_detail_id' => $line->bundle_parent_sale_detail_id,
                    ];
                })->all(),
                $currentSnapshot,
                $lockedReturn->source_snapshot_hash,
                $lockedReturn->id,
                null,
                true
            );

            $lockedReturn->update([
                'status' => PosReturn::STATUS_PENDING_APPROVAL,
                'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
                'source_snapshot' => $currentSnapshot,
                'source_snapshot_hash' => $currentSnapshot['hash'],
                'updated_by' => Auth::id(),
            ]);

            return $lockedReturn->refresh();
        });
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $currentSnapshot
     * @return array<int, array<string, mixed>>
     */
    protected function validateDraftLines(array $lines, array $currentSnapshot, ?string $expectedSnapshotHash, ?int $ignorePosReturnId = null, ?string $defaultReturnOption = null, bool $enforceReturnedSerialExclusivity = false): array
    {
        if (($currentSnapshot['hash'] ?? null) !== $expectedSnapshotHash) {
            throw new \Exception('Source snapshot is stale. Please refresh the page.');
        }

        $validatedLines = [];
        $usedReplacementSerialIds = [];

        foreach ($lines as $lineData) {
            $resolution = $lineData['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
            $quantity = (float) ($lineData['quantity'] ?? 0);
            $returnedSerialId = $lineData['returned_serial_id'] ?? null;
            $isSerial = !empty($returnedSerialId);
            $hasExplicitResolution = array_key_exists('resolution', $lineData)
                && $lineData['resolution'] !== null
                && $lineData['resolution'] !== '';

            if (!$hasExplicitResolution
                && $resolution === PosReturnLine::RESOLUTION_NONE
                && $quantity > 0
                && in_array($defaultReturnOption, [PosReturn::OPTION_CASH_RETURN, PosReturn::OPTION_PRODUCT_REPLACEMENT], true)) {
                $resolution = $defaultReturnOption;
            }

            if (!in_array($resolution, [PosReturnLine::RESOLUTION_NONE, PosReturnLine::RESOLUTION_CASH_RETURN, PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT], true)) {
                throw new \Exception('Resolusi retur tidak valid.');
            }

            if (!$isSerial && ($resolution === PosReturnLine::RESOLUTION_NONE || $quantity <= 0)) {
                continue;
            }

            if ($isSerial && $quantity <= 0) {
                $quantity = 1;
            }

            if ($enforceReturnedSerialExclusivity && $isSerial) {
                $this->assertReturnedSerialNotClaimedElsewhere((int) $returnedSerialId, $ignorePosReturnId);
            }

            $saleDetail = SaleDetails::with(['bundleItems.product', 'product'])->findOrFail($lineData['sale_detail_id']);

            // Sequence 10 correction: a line carrying __component_* identity
            // tags (either freshly synthesized this call, or a previously-
            // synthesized/persisted component line re-submitted on resubmit
            // — see submitDraftForApproval()) has its sale_detail_id pointing
            // at a carrier row whose own SaleDetails.quantity is always 0 —
            // pass the component's true original quantity explicitly.
            $isTaggedComponentLine = array_key_exists('__component_quantity_per_bundle', $lineData)
                && $lineData['__component_quantity_per_bundle'] !== null
                && array_key_exists('__bundle_parent_sale_detail_id', $lineData)
                && (int) ($lineData['__bundle_parent_sale_detail_id'] ?? 0) !== (int) $lineData['sale_detail_id'];
            $componentOriginalQuantity = null;
            if ($isTaggedComponentLine) {
                $quantityPerBundle = (float) $lineData['__component_quantity_per_bundle'];
                $parentSaleDetailId = (int) $lineData['__bundle_parent_sale_detail_id'];
                $parentRefundableQty = $this->quantityGuard->getRefundableQuantity($parentSaleDetailId, $ignorePosReturnId);
                $componentOriginalQuantity = $quantityPerBundle * $parentRefundableQty;
            }

            // Semantic correction: see store() for rationale — cash_return
            // lines are checked against refundable (not resolution-agnostic
            // returned) quantity, so a prior product_replacement never blocks
            // a later cash_return for the same logical quantity.
            $returnableQty = $resolution === PosReturnLine::RESOLUTION_CASH_RETURN
                ? $this->quantityGuard->getRefundableQuantity($saleDetail->id, $ignorePosReturnId, $componentOriginalQuantity)
                : $this->quantityGuard->getReturnableQuantity($saleDetail->dispatch_detail_id, $saleDetail->id, $ignorePosReturnId);

            if (!$isSerial && $quantity > $returnableQty) {
                throw new \Exception("Kuantitas retur melebihi batas yang diizinkan untuk produk {$saleDetail->product_name}.");
            }

            $replacementSerialId = $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                ? ($lineData['replacement_serial_id'] ?? null)
                : null;

            if ($resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT && $isSerial && empty($replacementSerialId)) {
                throw new \Exception('Serial pengganti harus diisi untuk penggantian produk.');
            }

            // Corrections/4: non-serial product_replacement (ordinary, parent, or
            // component) must carry a non-empty reason. Serial replacement leaves
            // it optional.
            if ($resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                && !$isSerial
                && trim((string) ($lineData['replacement_reason'] ?? '')) === '') {
                throw new \Exception('Alasan penggantian wajib diisi untuk penggantian produk non-serial.');
            }

            if ($replacementSerialId) {
                if (in_array($replacementSerialId, $usedReplacementSerialIds, true)) {
                    throw new \Exception('Serial pengganti tidak boleh digunakan lebih dari satu kali dalam retur yang sama.');
                }

                // Sequence 10 correction: $saleDetail->product_id is the
                // CARRIER row's own product_id (the bundle PARENT's) when
                // this line is an independent serial-tracked component
                // replacement submitted directly against a carrier row's
                // sale_detail_id — never re-derive component identity from
                // that column. The returned serial's OWN product_id is
                // always authoritative and explicit (never guessed): a
                // serial can only ever belong to the product it was issued
                // for, so it correctly identifies the component being
                // replaced regardless of which row carries it.
                $effectiveReplacementProductId = $isSerial && $returnedSerialId
                    ? (int) (ProductSerialNumber::find($returnedSerialId)->product_id ?? $saleDetail->product_id)
                    : (int) $saleDetail->product_id;

                $this->replacementGuard->validateReplacementSerial(
                    $effectiveReplacementProductId,
                    $replacementSerialId,
                    $returnedSerialId,
                    $ignorePosReturnId
                );

                $usedReplacementSerialIds[] = $replacementSerialId;
            }

            $lineData['quantity'] = $isSerial ? 1 : $quantity;
            $lineData['resolution'] = $resolution;
            $lineData['replacement_serial_id'] = $replacementSerialId;
            $validatedLines[] = $lineData;
        }

        $hasActionable = collect($validatedLines)->contains(function (array $lineData) {
            return in_array($lineData['resolution'] ?? PosReturnLine::RESOLUTION_NONE, [
                PosReturnLine::RESOLUTION_CASH_RETURN,
                PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            ], true);
        });

        if (!$hasActionable) {
            throw new \Exception('Minimal satu item harus dipilih untuk retur (ganti produk atau uang kembali).');
        }

        // Corrections/1: synthesize proportional component cash_return lines for
        // any parent-only bundle cash_return submission, using persisted
        // transaction lineage. This must run BEFORE the completeness check below
        // so a normal parent-only submission is never rejected for "missing"
        // components — after synthesis the set is already complete.
        $validatedLines = $this->synthesizeBundleCashReturnComponents($validatedLines, $usedReplacementSerialIds, $ignorePosReturnId);

        // 2.1/2.2: Cash-return on bundled merchandise must cover the whole bundle
        // quantity (parent + every originally fulfilled component), never a
        // parent-only or component-only slice. product_replacement is left
        // untouched by this check (2.3) — it is validated independently.
        $this->assertBundleCashReturnCompleteness($validatedLines);

        return $validatedLines;
    }

    /**
     * Corrections/1: Expand a parent-only bundle cash_return submission into
     * parent + one synthesized line per originally fulfilled component,
     * quantity = component's quantity_per_bundle * submitted parent quantity,
     * resolved to each component's own SaleDetail/DispatchDetail via the same
     * zero-unit-price sibling lookup convention used elsewhere in this class.
     *
     * If the caller ALSO explicitly submitted a cash_return component line for
     * an already-synthesizable parent, the explicit line is discarded in favor
     * of the synthesized one (dedupe by resolved component sale_detail_id) —
     * synthesis is always the source of truth for bundle cash_return components.
     *
     * product_replacement and component-only cash_return submissions (i.e. no
     * resolvable parent in the submitted set) are left untouched; the latter
     * still surfaces as rejected by assertBundleCashReturnCompleteness().
     *
     * Each synthesized component line's quantity is checked against
     * PosReturnQuantityGuard the same way an explicitly-submitted line is
     * (cumulative already-returned quantity for that component's own
     * sale_detail_id, across other active/consuming returns), so a repeated
     * whole-bundle cash return cannot cumulatively over-return a component
     * that a caller never directly selects.
     *
     * @param array<int, array<string, mixed>> $validatedLines
     * @param array<int, int> $usedReplacementSerialIds passed by reference is not
     *        needed here — synthesized lines are always cash_return, never
     *        product_replacement, so they never touch replacement serial claims.
     * @param int|null $ignorePosReturnId the draft being edited, excluded from
     *        the cumulative already-returned sum (mirrors every other quantity
     *        check in this class).
     * @return array<int, array<string, mixed>>
     */
    protected function synthesizeBundleCashReturnComponents(array $validatedLines, array $usedReplacementSerialIds, ?int $ignorePosReturnId = null): array
    {
        $cashReturnLines = collect($validatedLines)->filter(function (array $lineData) {
            return ($lineData['resolution'] ?? null) === PosReturnLine::RESOLUTION_CASH_RETURN;
        });

        if ($cashReturnLines->isEmpty()) {
            return $validatedLines;
        }

        $saleDetailIds = $cashReturnLines->pluck('sale_detail_id')->filter()->unique()->values();
        $saleIds = $cashReturnLines->pluck('sale_id')->filter()->unique()->values();
        if ($saleIds->isEmpty()) {
            $saleIds = SaleDetails::whereIn('id', $saleDetailIds)->pluck('sale_id')->unique()->values();
        }

        // Corrections/10 (split-owner carrier-row correction): a bundle
        // component's owner Sale may differ entirely from the parent's own
        // Sale (InlinePosCheckoutPostingAdapter split-owner posting). Every
        // Sale belonging to the SAME checkout as any referenced sale_id must
        // therefore be included in scope — otherwise a cross-owner
        // component's SaleBundleItem (which lives under its OWN Sale, never
        // the parent's) can never be found. Resolve the shared checkout via
        // PosCheckoutSale, then expand saleIds to every sale_id sharing that
        // checkout.
        $checkoutIds = PosCheckoutSale::whereIn('sale_id', $saleIds)->pluck('pos_checkout_id')->filter()->unique()->values();
        if ($checkoutIds->isNotEmpty()) {
            $saleIds = PosCheckoutSale::whereIn('pos_checkout_id', $checkoutIds)->pluck('sale_id')->filter()->unique()->values();
        }

        // Preload every sale detail for the referenced sales, keyed by
        // sale_id, so PARENT identity (which bundle each cash_return line
        // belongs to) resolves purely from persisted lineage. bundleItems is
        // still eager-loaded for the legacy/manual-data fallback below (no
        // catalog ProductBundle resolvable).
        $saleDetailsBySale = SaleDetails::query()
            ->with('bundleItems')
            ->whereIn('sale_id', $saleIds)
            ->get()
            ->groupBy('sale_id');

        // Build parent -> component list (authoritative "what components
        // does this bundle instance have" source), keyed by parent
        // sale_detail_id. Prefers the catalog ProductBundle/ProductBundleItem
        // definition (not $parentCandidate->bundleItems, which is empty for
        // cross-owner components) exactly mirroring
        // PosReturnSnapshotService::resolveCatalogBundleComponents() — but
        // falls back to the parent's own persisted SaleBundleItem rows when
        // no catalog ProductBundle is resolvable (legacy/hand-built test
        // data with no real ProductBundle header), matching the exact same
        // fallback PosReturnSnapshotService::buildLineSnapshots() applies.
        // A SaleBundleItem exposes the same accessor shape a
        // ProductBundleItem does (product_id/quantity/bundle_id/id/product),
        // so it can stand in as a "catalog item" for the loop below.
        $parentToCatalogComponents = [];
        foreach ($saleDetailsBySale as $saleId => $saleDetails) {
            foreach ($saleDetails as $parentCandidate) {
                $catalogItems = $this->resolveCatalogBundleComponents((int) $parentCandidate->product_id);

                if ($catalogItems->isEmpty()) {
                    $catalogItems = $parentCandidate->bundleItems;
                }

                $resolvableItems = $this->filterResolvableFallbackComponents($catalogItems, (int) $parentCandidate->id);

                if ($resolvableItems->isEmpty()) {
                    continue;
                }

                $parentToCatalogComponents[$parentCandidate->id] = [
                    'sale_id' => (int) $saleId,
                    'catalog_items' => $resolvableItems,
                ];
            }
        }

        $synthesized = [];
        $handledComponentSaleDetailIds = [];

        foreach ($cashReturnLines as $lineData) {
            $saleDetailId = (int) ($lineData['sale_detail_id'] ?? 0);
            $parentInfo = $parentToCatalogComponents[$saleDetailId] ?? null;

            if ($parentInfo === null) {
                continue;
            }

            $parentQuantity = (float) ($lineData['quantity'] ?? 0);
            $bundleGroupKey = 'bundle-' . $saleDetailId;
            $bundleId = $parentInfo['catalog_items']->first()->bundle_id ?? null;

            // Corrections/2 (serial bundle components): determine whether this
            // parent's cash_return quantity exhausts ALL remaining refundable
            // quantity for the parent sale_detail_id. This is the unambiguous
            // "full return" case for the multi-quantity ambiguity rule below.
            $parentRefundableQty = $this->quantityGuard->getRefundableQuantity($saleDetailId, $ignorePosReturnId);
            $isFullRemainingReturn = abs($parentQuantity - $parentRefundableQty) < 0.0001;

            foreach ($parentInfo['catalog_items'] as $catalogItem) {
                $componentProductId = (int) $catalogItem->product_id;

                // Corrections/10: resolve the component's SaleBundleItem.
                // Try the PARENT's own sale_id FIRST (narrow scope) — this
                // correctly disambiguates when the SAME bundle catalog is
                // purchased multiple times in one checkout (e.g. a
                // serialized parent with quantity > 1, one Sale per unit):
                // bundle_id/bundle_item_id/quantity are IDENTICAL across
                // those separate purchases, so widening the search to every
                // sale in the checkout up front would make every one of them
                // ambiguous. Only widen to the full checkout (the SAME
                // disambiguation order as PosReturnSnapshotService, shared
                // PosBundleComponentResolver) when the narrow scope finds
                // nothing at all — the genuine split-owner case, where the
                // component's SaleBundleItem never lives under the parent's
                // own sale_id to begin with.
                $bundleItem = $this->bundleComponentResolver->findComponentBundleItem(
                    [$parentInfo['sale_id']],
                    $componentProductId,
                    (int) $catalogItem->bundle_id,
                    (int) $catalogItem->id,
                    (float) $catalogItem->quantity
                ) ?? $this->bundleComponentResolver->findComponentBundleItem(
                    $saleIds,
                    $componentProductId,
                    (int) $catalogItem->bundle_id,
                    (int) $catalogItem->id,
                    (float) $catalogItem->quantity
                );

                if (!$bundleItem) {
                    // Component allocation not resolvable from persisted
                    // lineage (ambiguous or missing) — do not synthesize;
                    // assertBundleCashReturnCompleteness() will surface this
                    // as an incomplete bundle cash return.
                    continue;
                }

                $attachedDetail = $bundleItem->saleDetail;
                if (!$attachedDetail) {
                    continue;
                }

                $componentSaleId = (int) $bundleItem->sale_id;
                $quantityPerBundle = (float) $catalogItem->quantity;
                $componentQuantity = $quantityPerBundle * $parentQuantity;

                // Corrections/10: SaleBundleItem.sale_detail_id can mean one
                // of two different things depending on posting shape:
                //  - Same-owner (normal) posting: it points at the PARENT's
                //    own SaleDetails row (SaleDetails::bundleItems() is a
                //    hasMany keyed by sale_detail_id) — the actual component
                //    identity is a SEPARATE sibling SaleDetails row in the
                //    same Sale, keyed by the component's own product_id with
                //    unit_price = 0 (the long-standing convention used
                //    throughout this class/PosReturnQuantityGuard).
                //  - Split-owner posting: it points at a CARRIER row that is
                //    NOT the parent's own row (a different, zero-quantity
                //    residual line in the component owner's OWN Sale) — this
                //    carrier row itself IS the only real identity that exists
                //    for the component; no sibling row is ever created.
                // Disambiguate by checking whether the attached row equals
                // the bundle parent's own row.
                if ((int) $attachedDetail->id === $saleDetailId) {
                    $siblingDetail = SaleDetails::query()
                        ->where('sale_id', $componentSaleId)
                        ->where('product_id', $componentProductId)
                        ->where('unit_price', 0)
                        ->first();

                    if (!$siblingDetail) {
                        // Same-owner shape with no discoverable sibling
                        // allocation row at all: this preserves the
                        // long-standing legacy behavior — a component with
                        // no verifiable standalone allocation is silently
                        // excluded from synthesis entirely (never treated as
                        // "missing" by the completeness check, and never
                        // synthesized against the parent's own sale_detail_id
                        // — which would wrongly collide with the parent
                        // line's own identity).
                        continue;
                    }

                    $carrierDetail = $siblingDetail;
                } else {
                    $carrierDetail = $attachedDetail;
                }

                $componentDispatchDetailId = $this->bundleComponentResolver->findComponentDispatchDetailId(
                    $componentSaleId,
                    $componentProductId,
                    (int) $carrierDetail->id,
                    (int) $bundleItem->bundle_id
                );

                $componentProduct = $bundleItem->product ?? \Modules\Product\Entities\Product::find($componentProductId);
                $componentIsSerial = (bool) ($componentProduct->serial_number_required ?? false);

                // Dedupe key: the carrier row's id alone is NOT a unique
                // identity for a component (the same carrier row can host
                // multiple distinct components) — key by carrier id +
                // component product id instead.
                $handledComponentSaleDetailIds[] = $carrierDetail->id . ':' . $componentProductId;

                $componentInfo = [
                    'quantity_per_bundle' => $quantityPerBundle,
                    'sale_detail' => $carrierDetail,
                    'dispatch_detail_id' => $componentDispatchDetailId,
                    'is_serial' => $componentIsSerial,
                    'sale_bundle_item_id' => $bundleItem->id,
                    'bundle_id' => (int) $bundleItem->bundle_id,
                    'component_product_id' => $componentProductId,
                    'component_product_name' => $componentProduct->product_name ?? null,
                    'component_product_code' => $componentProduct->product_code ?? null,
                ];

                if ($componentIsSerial) {
                    $serialLines = $this->synthesizeSerialComponentLines(
                        $lineData,
                        $carrierDetail,
                        $componentInfo,
                        $saleDetailId,
                        $bundleGroupKey,
                        $parentQuantity,
                        $isFullRemainingReturn,
                        $ignorePosReturnId
                    );

                    foreach ($serialLines as $serialLine) {
                        $synthesized[] = $serialLine;
                    }

                    continue;
                }

                // Cumulative over-refund guard: a synthesized cash_return
                // component line must not exceed what's still REFUNDABLE for
                // its own sale_detail_id (the carrier row) — i.e. original
                // quantity (the bundle item's own quantity_per_bundle,
                // proportional to fully-refundable parent quantity) minus
                // quantity already consumed by another active cash_return.
                // A prior product_replacement on this component must NOT
                // reduce what's refundable: the customer is left holding an
                // equivalent replacement unit, so a later whole-bundle cash
                // return remains eligible for that same logical quantity.
                // Only an actual cash refund (or nothing, for rejected/
                // cancelled returns) exhausts refundable quantity — see
                // PosReturnQuantityGuard::getRefundableQuantity().
                //
                // A carrier row's OWN SaleDetails.quantity is always 0 (it
                // exists only to host the component's SaleBundleItem/
                // DispatchDetail), so it cannot be used as the "original
                // quantity" baseline. Pass the component's true original
                // quantity explicitly instead: quantity_per_bundle × the
                // parent's fully-refundable quantity ($parentRefundableQty,
                // already computed above from the parent's own
                // sale_detail_id) — not just this line's requested
                // $parentQuantity, so the guard reflects the component's
                // full remaining entitlement, not only what this particular
                // return request happens to ask for. Already-cash-returned
                // consumption is still scoped by $saleDetailId = the carrier
                // row's id, which remains a consistent accounting key since
                // every synthesized component cash_return line for this
                // component is persisted against that same carrier id.
                $componentOriginalQuantity = $quantityPerBundle * $parentRefundableQty;
                $componentRefundableQty = $this->quantityGuard->getRefundableQuantity(
                    $carrierDetail->id,
                    $ignorePosReturnId,
                    $componentOriginalQuantity
                );

                if ($componentQuantity > $componentRefundableQty) {
                    throw new \Exception("Kuantitas retur melebihi batas yang diizinkan untuk produk {$componentInfo['component_product_name']}.");
                }

                // Correction: a non-serial component must use its OWN
                // persisted DispatchDetail lineage (owner setting, source
                // location) rather than inheriting the parent's checkout-sale
                // owner. A component dispatched from a different owner/
                // location than the parent (e.g. parent from Setting A,
                // component sourced from Setting B) must resolve to its own
                // owner, or Phase 3 execution grouping would misattribute the
                // component's Sales Return to the wrong setting. Resolved via
                // the component's own dispatch_detail_id -> location_id ->
                // Location.setting_id, mirroring how the serial path resolves
                // owner via PosReturnReplacementOwnerResolver, but without
                // needing a serial (non-serial components have no
                // ProductSerialNumber row to resolve through).
                $componentOwnerSettingId = null;
                $componentOwnerLocationId = null;
                if ($componentDispatchDetailId) {
                    $componentDispatchDetail = \Modules\Sale\Entities\DispatchDetail::with('location')
                        ->find($componentDispatchDetailId);
                    if ($componentDispatchDetail && $componentDispatchDetail->location) {
                        $componentOwnerLocationId = $componentDispatchDetail->location->id;
                        $componentOwnerSettingId = $componentDispatchDetail->location->setting_id;
                    }
                }

                $synthesized[] = [
                    'sale_id' => $componentSaleId,
                    // Corrections/10: the CARRIER row's id — the only real
                    // SaleDetails identity that exists for this component in
                    // a split-owner checkout. product_id (below) is set
                    // explicitly to the component's OWN product_id so no
                    // downstream code needs to (or may) re-derive product
                    // identity from the carrier row's own product_id column
                    // (which is the PARENT's).
                    'sale_detail_id' => $carrierDetail->id,
                    'pos_transaction_line_id' => null,
                    'returned_serial_id' => null,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => $componentQuantity,
                    'replacement_serial_id' => null,
                    '__synthesized_component' => true,
                    '__bundle_parent_sale_detail_id' => $saleDetailId,
                    '__bundle_group_key' => $bundleGroupKey,
                    '__component_quantity_per_bundle' => $quantityPerBundle,
                    '__component_source_setting_id' => $componentOwnerSettingId,
                    '__component_source_location_id' => $componentOwnerLocationId,
                    '__component_product_id' => $componentProductId,
                    '__component_product_name' => $componentInfo['component_product_name'],
                    '__component_product_code' => $componentInfo['component_product_code'],
                    '__component_dispatch_detail_id' => $componentDispatchDetailId,
                    '__sale_bundle_item_id' => $bundleItem->id,
                ];
            }

            // Tag the parent line itself with identity columns so it shares the
            // same bundle_group_key as its synthesized components.
            $lineData['__bundle_parent_sale_detail_id'] = $saleDetailId;
            $lineData['__bundle_group_key'] = $bundleGroupKey;
            $lineData['__bundle_quantity'] = $parentQuantity;
        }

        if (empty($synthesized) && collect($validatedLines)->every(fn ($l) => empty($l['__bundle_group_key']))) {
            return $validatedLines;
        }

        // Reassemble: keep every original line except explicit cash_return
        // component lines whose (carrier sale_detail_id, product_id) pair we
        // are about to synthesize (dedupe — synthesis wins), re-tag parent
        // lines with identity keys, then append the synthesized component
        // lines.
        $result = [];
        foreach ($validatedLines as $lineData) {
            $saleDetailId = (int) ($lineData['sale_detail_id'] ?? 0);
            // Explicit submitted lines never carry product_id directly (it's
            // resolved later from sale_detail_id in store()/update()) — a
            // component-only explicit submission would key its sale_detail_id
            // to the carrier row directly, same as a synthesized line, so the
            // carrier id alone already disambiguates it from the parent line
            // (whose own sale_detail_id is itself, never the carrier).
            // A previously-synthesized/persisted component line re-submitted
            // on resubmit (see submitDraftForApproval()) carries its
            // component identity via __component_product_id, never
            // product_id directly — check that first so it dedupes against
            // this call's freshly re-synthesized line for the same
            // (carrier sale_detail_id, component product_id) pair.
            $productIdForDedupe = (int) ($lineData['__component_product_id'] ?? $lineData['product_id'] ?? SaleDetails::where('id', $saleDetailId)->value('product_id') ?? 0);
            $isExplicitCashReturn = ($lineData['resolution'] ?? null) === PosReturnLine::RESOLUTION_CASH_RETURN;
            $dedupeKey = $saleDetailId . ':' . $productIdForDedupe;

            if ($isExplicitCashReturn && in_array($dedupeKey, $handledComponentSaleDetailIds, true)) {
                // Redundant/explicit component submission alongside auto-synthesis
                // — dropped in favor of the synthesized line to avoid duplicates.
                continue;
            }

            if (isset($parentToCatalogComponents[$saleDetailId]) && $isExplicitCashReturn) {
                $bundleGroupKey = 'bundle-' . $saleDetailId;
                $lineData['__bundle_parent_sale_detail_id'] = $saleDetailId;
                $lineData['__bundle_group_key'] = $bundleGroupKey;
                $lineData['__bundle_quantity'] = (float) ($lineData['quantity'] ?? 0);
            }

            $result[] = $lineData;
        }

        foreach ($synthesized as $componentLine) {
            $result[] = $componentLine;
        }

        return $result;
    }

    /**
     * Corrections/2 (serial bundle components): resolve the current
     * held/leaf serial for a bundle component whose product is
     * serial-tracked, and synthesize one `cash_return` PosReturnLine per
     * resolved leaf serial.
     *
     * Algorithm:
     *  1. Find every serial ORIGINALLY dispatched with this specific bundle
     *     instance, via the component's own dispatch_detail_id (mirrors
     *     PosReturnSnapshotService::buildComponentBundleItemEntry()).
     *  2. Follow each serial's replacement lineage forward to its current
     *     leaf via resolveCurrentHeldSerial().
     *  3. Apply the full-vs-partial ambiguity rule: if this cash_return
     *     exhausts the parent's entire remaining refundable quantity, every
     *     leaf is unambiguous and included. Otherwise, only proceed if
     *     there is exactly one originally-dispatched serial for this
     *     component (quantity_per_bundle === 1, i.e. no possible ambiguity
     *     about which original serial pairs with a partial parent
     *     quantity) — a component with quantity_per_bundle > 1 has no
     *     persisted pairing tying a SPECIFIC original serial to a SPECIFIC
     *     fractional parent quantity, so a partial return is blocked.
     *
     * @param array<string, mixed> $parentLineData
     * @param SaleDetails $componentDetail
     * @param array<string, mixed> $componentInfo
     * @return array<int, array<string, mixed>>
     * @throws \Exception
     */
    protected function synthesizeSerialComponentLines(
        array $parentLineData,
        SaleDetails $componentDetail,
        array $componentInfo,
        int $bundleParentSaleDetailId,
        string $bundleGroupKey,
        float $parentQuantity,
        bool $isFullRemainingReturn,
        ?int $ignorePosReturnId
    ): array {
        $componentDispatchDetailId = $componentInfo['dispatch_detail_id'] ?? null;
        $quantityPerBundle = (float) ($componentInfo['quantity_per_bundle'] ?? 0);

        // Task: original serials dispatched with this specific bundle
        // instance — resolved via the component's own dispatch_detail_id,
        // the same lookup PosReturnSnapshotService::buildComponentBundleItemEntry()
        // already uses for the UI.
        $originalSerialIds = [];
        if ($componentDispatchDetailId) {
            $originalSerialIds = ProductSerialNumber::where('dispatch_detail_id', $componentDispatchDetailId)
                ->pluck('id')
                ->all();
        }

        if (empty($originalSerialIds) && !empty($componentDetail->serial_number_ids)) {
            $originalSerialIds = $componentDetail->serial_number_ids;
        }

        // Sequence 10 correction: a completed independent component
        // product_replacement moves the ORIGINAL serial's own
        // dispatch_detail_id away (it's received back as available stock),
        // so the live ProductSerialNumber::where('dispatch_detail_id', ...)
        // lookup above finds nothing once a replacement has already
        // executed for this component — even though the component's own
        // original quantity/refundability is unaffected by that replacement
        // (business rule #3). Fall back to the persisted lineage of a
        // COMPLETED replacement PosReturnLine for this exact
        // dispatch_detail_id — every PosReturnLine ever created for this
        // component (cash_return or product_replacement) always records the
        // component's own original dispatch_detail_id, so its
        // returned_serial_id is the authoritative original serial identity,
        // never guessed.
        if (empty($originalSerialIds) && $componentDispatchDetailId) {
            $originalSerialIds = PosReturnLine::where('dispatch_detail_id', $componentDispatchDetailId)
                ->where('resolution', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
                ->whereHas('posReturn', function ($q) {
                    $q->where('status', PosReturn::STATUS_COMPLETED);
                })
                ->pluck('returned_serial_id')
                ->filter()
                ->unique()
                ->all();
        }

        if (empty($originalSerialIds)) {
            // No persisted serial lineage for this component — cannot prove
            // any specific serial identity. Nothing to synthesize; the
            // completeness check below will surface this as incomplete.
            return [];
        }

        // Multi-quantity ambiguity guard: a partial parent return can only be
        // proven when this component has exactly one original serial per
        // bundle unit (quantity_per_bundle effectively 1-to-1 with the
        // originally-dispatched serial set). If quantity_per_bundle > 1 (or
        // more originally-dispatched serials exist than the parent quantity
        // implies) and the parent is NOT returning its full remaining
        // refundable quantity, there is no persisted pairing proving which
        // specific original serial(s) belong to the specific bundle unit(s)
        // being returned now — block rather than guess.
        if (!$isFullRemainingReturn && ($quantityPerBundle > 1.0 || count($originalSerialIds) > 1)) {
            throw new \Exception(
                "component_serial_partial_return_ambiguous: Retur sebagian untuk komponen bertipe serial tidak dapat ditentukan secara pasti untuk paket bundel ini."
            );
        }

        $lines = [];
        $seenLeafIds = [];

        foreach ($originalSerialIds as $originalSerialId) {
            $leafSerialId = $this->resolveCurrentHeldSerial((int) $originalSerialId);

            if (in_array($leafSerialId, $seenLeafIds, true)) {
                continue;
            }
            $seenLeafIds[] = $leafSerialId;

            // Claim-exclusivity: the leaf serial must not already be claimed
            // by another in-flight return, mirroring the same check applied
            // to explicitly-submitted serial lines.
            $this->assertReturnedSerialNotClaimedElsewhere($leafSerialId, $ignorePosReturnId);

            $leafSerial = ProductSerialNumber::find($leafSerialId);
            if (!$leafSerial) {
                throw new \Exception("Serial komponen bundel tidak ditemukan untuk produk {$componentDetail->product_name}.");
            }

            // Resolve the leaf serial's CURRENT owner/location lineage —
            // a cross-owner replacement may have moved the component to a
            // different owner/location than the parent's original checkout
            // sale, so this must not reuse the parent's source_setting_id.
            $ownerContext = $this->replacementOwnerResolver->resolve($leafSerial);

            $lines[] = [
                // Corrections/10: the component's own owning Sale (via the
                // matched SaleBundleItem), never the carrier's Sale id
                // resolved through the parent-keyed detail — though for a
                // carrier row these are actually the same Sale (the carrier
                // lives IN the component owner's Sale by definition), this
                // makes the intent explicit and immune to a future
                // shape change.
                'sale_id' => $componentDetail->sale_id,
                // The CARRIER row's id — the only real SaleDetails identity
                // that exists for this component in a split-owner checkout.
                'sale_detail_id' => $componentDetail->id,
                'pos_transaction_line_id' => null,
                'returned_serial_id' => $leafSerialId,
                'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                'quantity' => 1,
                'replacement_serial_id' => null,
                '__synthesized_component' => true,
                '__bundle_parent_sale_detail_id' => $bundleParentSaleDetailId,
                '__bundle_group_key' => $bundleGroupKey,
                '__component_quantity_per_bundle' => $quantityPerBundle,
                '__component_source_setting_id' => $ownerContext['owner_setting_id'] ?? null,
                '__component_source_location_id' => $ownerContext['location_id'] ?? null,
                // Explicit component identity — product_id must NEVER be
                // re-derived from the carrier row's own product_id (the
                // parent's) downstream; store()/update() must read this
                // explicit value instead for synthesized component lines.
                '__component_product_id' => $componentInfo['component_product_id'] ?? $leafSerial->product_id,
                '__component_product_name' => $componentInfo['component_product_name'] ?? $leafSerial->product?->product_name,
                '__component_product_code' => $componentInfo['component_product_code'] ?? $leafSerial->product?->product_code,
                '__component_dispatch_detail_id' => $leafSerial->dispatch_detail_id,
                '__sale_bundle_item_id' => $componentInfo['sale_bundle_item_id'] ?? null,
            ];
        }

        return $lines;
    }

    /**
     * Corrections/2: walk a serial's product_replacement lineage forward to
     * the current "held" leaf serial. Starting from the serial ORIGINALLY
     * dispatched with the bundle, follow a product_replacement edge to
     * replacement_serial_id ONLY once that replacement's own PosReturn has
     * reached STATUS_COMPLETED — the point at which
     * PosReturnLifecycleService::dispatchReplacement() (or the same-owner
     * synchronous dispatch path) has actually run and the customer is
     * physically holding the replacement unit. Approval alone
     * (STATUS_APPROVED) is not sufficient: a replacement can sit at
     * STATUS_AWAITING_DISPATCH indefinitely before its dispatch executes, and
     * during that window the customer still holds the OLD serial, not the
     * new one.
     *
     * If an in-flight replacement (any other consuming status —
     * STATUS_APPROVED, STATUS_AWAITING_RECEIVING, STATUS_AWAITING_SETTLEMENT,
     * STATUS_AWAITING_DISPATCH, STATUS_MANUAL_CORRECTION_REQUIRED) exists for
     * the current serial, this method BLOCKS rather than stopping at the
     * current serial: the current serial is itself mid-replacement (it will
     * be received back into stock by that other document once it completes)
     * and must not be synthesized into a second, overlapping whole-bundle
     * cash return.
     *
     * A max-iteration cap defends against a data anomaly creating a cycle;
     * this is not an expected state, just a safety net.
     *
     * @throws \Exception if an in-flight replacement blocks resolution
     */
    protected function resolveCurrentHeldSerial(int $originalSerialId): int
    {
        return $this->serialChainResolver->resolveCurrentHeldSerial($originalSerialId);
    }

    /**
     * Group submitted cash_return lines by their originating bundle purchase
     * (parent SaleDetail identity, resolved from persisted transaction
     * lineage — never the live bundle definition) and require every
     * originally fulfilled component, expanded proportionally to the
     * selected parent quantity, to also be present with resolution
     * cash_return. Parent-only or component-only bundle cash_return intent
     * is rejected. product_replacement lines are ignored entirely here.
     *
     * @param array<int, array<string, mixed>> $validatedLines
     * @throws \Exception
     */
    protected function assertBundleCashReturnCompleteness(array $validatedLines): void
    {
        $cashReturnLines = collect($validatedLines)->filter(function (array $lineData) {
            return ($lineData['resolution'] ?? null) === PosReturnLine::RESOLUTION_CASH_RETURN;
        });

        if ($cashReturnLines->isEmpty()) {
            return;
        }

        // Corrections/10 (split-owner carrier-row correction): a bundle
        // component's carrier SaleDetails row is keyed by the PARENT's own
        // product_id, so "is this sale_detail_id a bundle parent" can only be
        // resolved via the catalog ProductBundle definition (whether THIS
        // line's own product has a bundle), never by inspecting sibling rows
        // for a zero-priced same-product match (that convention can never
        // find a cross-owner component — no such sibling row exists).
        //
        // This method runs AFTER synthesizeBundleCashReturnComponents(), so
        // every catalog component is already expected to be present as its
        // own cash_return line, keyed by (carrier sale_detail_id, its own
        // product_id) — never by sale_detail_id alone, since one carrier row
        // can host more than one distinct component.
        $saleDetailIds = $cashReturnLines->pluck('sale_detail_id')->filter()->unique()->values();
        $saleDetails = SaleDetails::query()->with('bundleItems')->whereIn('id', $saleDetailIds)->get()->keyBy('id');

        // Map: parent_sale_detail_id => Collection<ProductBundleItem>
        //
        // Two distinct sources of "what components does this bundle
        // instance have", with DIFFERENT completeness semantics:
        //
        //  - A real catalog ProductBundle exists for this parent product
        //    (resolveCatalogBundleComponents() non-empty): every catalog
        //    component is authoritative and MUST be treated as "expected"
        //    in FULL, including one with no discoverable allocation. An
        //    unresolvable catalog component must surface via the `missing`
        //    diff below (rejected as an incomplete bundle cash return) —
        //    silently excluding it would let a "whole-bundle" cash return
        //    proceed without a component the catalog says must exist,
        //    violating the "every component proportionally" rule.
        //  - NO catalog ProductBundle exists at all (legacy/hand-built
        //    fixture whose only signal is the parent's own persisted
        //    SaleBundleItem rows, which may themselves be purely
        //    informational — e.g. referencing a bundle_id with no backing
        //    ProductBundle row): filterResolvableFallbackComponents()
        //    still filters down to only actually-resolvable allocations,
        //    preserving the long-standing legacy behavior for such
        //    fixtures (see POSReturnApprovalPreviewPlannerTest::
        //    it_reports_a_blocker_when_a_bundle_component_target_is_missing).
        $parentToCatalogComponents = [];
        foreach ($saleDetails as $saleDetail) {
            // A zero-quantity row is never itself a bundle PARENT line — it
            // is either a carrier row (hosting a cross-owner component,
            // keyed by the PARENT's product_id) or a same-owner zero-priced
            // component allocation sibling. Both happen to satisfy
            // resolveCatalogBundleComponents()/bundleItems lookups because
            // their product_id/persisted SaleBundleItem rows coincidentally
            // resolve a catalog bundle — but treating THEM as "the parent"
            // would wrongly expect their own components a second time. Only
            // a real (nonzero-quantity) bundle parent row is eligible here.
            if ((float) $saleDetail->quantity <= 0) {
                continue;
            }

            $catalogItems = $this->resolveCatalogBundleComponents((int) $saleDetail->product_id);
            $hasCatalogBundle = $catalogItems->isNotEmpty();
            if (!$hasCatalogBundle) {
                $catalogItems = $saleDetail->bundleItems;
            }

            $expectedItems = $hasCatalogBundle
                ? $catalogItems
                : $this->filterResolvableFallbackComponents($catalogItems, (int) $saleDetail->id);

            if ($expectedItems->isNotEmpty()) {
                $parentToCatalogComponents[$saleDetail->id] = $expectedItems;
            }
        }

        // Corrections/10: also detect a bare, un-synthesized component-only
        // submission (a raw cash_return line whose sale_detail_id is a
        // component's own sibling row, submitted with no parent line and no
        // __bundle_parent_sale_detail_id tag at all — synthesis only tags
        // lines it resolves TO a parent it can find in the current
        // submission). Any submitted product_id that is some catalog
        // bundle's component product, whose bundle parent isn't itself
        // present as a catalog parent in the submission's own resolved sale
        // details, is such an orphan and must be rejected too.
        $componentProductIdToBundleParentProductId = [];
        foreach ($saleDetails as $saleDetail) {
            $asComponentOfBundle = \Modules\Product\Entities\ProductBundleItem::query()
                ->where('product_id', $saleDetail->product_id)
                ->with('bundle')
                ->get();
            foreach ($asComponentOfBundle as $bundleItem) {
                if ($bundleItem->bundle) {
                    $componentProductIdToBundleParentProductId[(int) $saleDetail->product_id] = (int) $bundleItem->bundle->parent_product_id;
                }
            }

            // Legacy/hand-built data fallback: no catalog ProductBundleItem
            // row exists for this product, but a persisted SaleBundleItem
            // elsewhere in scope references it as a component — treat its
            // sale's own bundle parent product (resolved via the SaleDetails
            // row the SaleBundleItem is attached to) as the expected parent.
            if (!isset($componentProductIdToBundleParentProductId[(int) $saleDetail->product_id])) {
                $siblingBundleItem = \Modules\Sale\Entities\SaleBundleItem::query()
                    ->where('sale_id', $saleDetail->sale_id)
                    ->where('product_id', $saleDetail->product_id)
                    ->with('saleDetail')
                    ->first();
                if ($siblingBundleItem && $siblingBundleItem->saleDetail) {
                    $componentProductIdToBundleParentProductId[(int) $saleDetail->product_id] = (int) $siblingBundleItem->saleDetail->product_id;
                }
            }
        }

        if (empty($parentToCatalogComponents) && empty($componentProductIdToBundleParentProductId)) {
            return;
        }

        // Group submitted cash_return lines by which bundle parent they
        // belong to, using the persisted __bundle_parent_sale_detail_id
        // identity tag (set by synthesizeBundleCashReturnComponents() on
        // both the parent line itself and every synthesized component —
        // and, for an explicit non-synthesized component submission, by the
        // caller-supplied value). A line with no such tag and no catalog
        // bundle of its own is unrelated to bundle completeness.
        $groupedByParent = [];
        $orphanComponentLines = [];
        foreach ($cashReturnLines as $lineData) {
            $saleDetailId = (int) ($lineData['sale_detail_id'] ?? 0);
            $parentSaleDetailId = (int) ($lineData['__bundle_parent_sale_detail_id'] ?? 0);
            $isParentLine = isset($parentToCatalogComponents[$saleDetailId]) && $parentSaleDetailId === $saleDetailId;

            if ($isParentLine) {
                $groupedByParent[$saleDetailId]['parent_line'] = $lineData;
                continue;
            }

            if ($parentSaleDetailId > 0 && isset($parentToCatalogComponents[$parentSaleDetailId])) {
                $componentProductId = (int) ($lineData['__component_product_id'] ?? 0);
                $groupedByParent[$parentSaleDetailId]['component_lines'][$componentProductId][] = $lineData;
                continue;
            }

            // Untagged line: check whether it is itself a bare component
            // sibling row submitted directly (no synthesis ran because no
            // parent line accompanied it in this submission at all). Must be
            // an actual component ALLOCATION row (unit_price = 0, the
            // long-standing convention marking it as bundle-internal), never
            // a same-SKU commercial standalone sale of the same product —
            // product_id alone is never sufficient identity here (see
            // same_sku_standalone_and_component_are_disambiguated test).
            $lineSaleDetail = $saleDetails->get($saleDetailId);
            $lineProductId = (int) ($lineSaleDetail?->product_id ?? 0);
            $isZeroPricedAllocationRow = $lineSaleDetail && (float) $lineSaleDetail->unit_price === 0.0;
            if ($parentSaleDetailId === 0 && $isZeroPricedAllocationRow && isset($componentProductIdToBundleParentProductId[$lineProductId])) {
                $orphanComponentLines[] = $lineData;
            }
        }

        if (!empty($orphanComponentLines)) {
            throw new \Exception('Retur uang kembali untuk paket bundel harus mencakup seluruh unit bundel (produk utama dan semua komponennya).');
        }

        foreach ($parentToCatalogComponents as $parentSaleDetailId => $catalogItems) {
            $group = $groupedByParent[$parentSaleDetailId] ?? null;

            // No cash_return submitted at all for this bundle instance —
            // nothing to check (this parent isn't part of the current
            // submission).
            if ($group === null) {
                continue;
            }

            $parentLine = $group['parent_line'] ?? null;
            $componentLines = $group['component_lines'] ?? [];

            $expectedComponentProductIds = $catalogItems->pluck('product_id')->map(fn ($id) => (int) $id)->all();
            $missing = array_diff($expectedComponentProductIds, array_keys($componentLines));

            if ($parentLine === null || !empty($missing)) {
                throw new \Exception('Retur uang kembali untuk paket bundel harus mencakup seluruh unit bundel (produk utama dan semua komponennya).');
            }

            // Verify proportional quantities: component qty must equal
            // parent quantity * quantity_per_bundle for each component. For
            // a serial-tracked component, this is the COUNT of distinct
            // synthesized leaf-serial lines (each carrying quantity=1), not
            // a single line's quantity value.
            $parentQuantity = (float) ($parentLine['quantity'] ?? 0);
            foreach ($catalogItems as $catalogItem) {
                $componentProductId = (int) $catalogItem->product_id;
                $quantityPerBundle = (float) $catalogItem->quantity;
                $lines = $componentLines[$componentProductId];
                $isSerialComponent = collect($lines)->contains(fn ($l) => !empty($l['returned_serial_id']));

                $componentQuantity = $isSerialComponent
                    ? (float) count($lines)
                    : (float) ($lines[0]['quantity'] ?? 0);
                $expectedComponentQuantity = $parentQuantity * $quantityPerBundle;

                if (abs($componentQuantity - $expectedComponentQuantity) > 0.0001) {
                    throw new \Exception('Retur uang kembali untuk paket bundel harus mencakup seluruh unit bundel (produk utama dan semua komponennya).');
                }
            }
        }

        // Also catch component-only cash_return where the component's parent
        // bundle line was never even submitted (group exists for the parent
        // sale_detail_id but has no parent_line — an explicit component-only
        // submission never resolvable to a parent).
        foreach ($cashReturnLines as $lineData) {
            $parentSaleDetailId = (int) ($lineData['__bundle_parent_sale_detail_id'] ?? 0);
            if ($parentSaleDetailId > 0 && isset($parentToCatalogComponents[$parentSaleDetailId])) {
                if (empty($groupedByParent[$parentSaleDetailId]['parent_line'])) {
                    throw new \Exception('Retur uang kembali untuk paket bundel harus mencakup seluruh unit bundel (produk utama dan semua komponennya).');
                }
            }
        }
    }

    /**
     * A returned serial can be claimed by only one return in a consuming (or
     * about-to-consume) lifecycle state at a time. Draft/pending-approval
     * returns are non-consuming by design (`PosReturn::consumesReturnQuantity`),
     * so this exclusivity check runs only when a draft is being submitted,
     * mirroring PosReturnReplacementGuard's pattern for replacement_serial_id.
     * Applies to both parent-line and bundle-component returned serials —
     * component identity resolves to its own ProductSerialNumber row just
     * like a parent's, so this single check by serial id covers both.
     */
    /**
     * Corrections/10: resolve the catalog ProductBundleItem rows for a
     * bundle whose PARENT product is $parentProductId, via the ProductBundle
     * header (parent_product_id). Mirrors
     * PosReturnSnapshotService::resolveCatalogBundleComponents() exactly —
     * this is the authoritative "what components does this bundle instance
     * have" source, since a component's own SaleBundleItem may live under a
     * completely different owner Sale than the parent's in a split-owner
     * checkout.
     *
     * @return \Illuminate\Support\Collection<int, \Modules\Product\Entities\ProductBundleItem>
     */
    protected function resolveCatalogBundleComponents(int $parentProductId): \Illuminate\Support\Collection
    {
        return $this->bundleComponentResolver->resolveCatalogBundleComponents($parentProductId);
    }

    /**
     * Corrections/10: filter a bundle's component list (catalog
     * ProductBundleItem rows, or — when no catalog ProductBundle exists —
     * the parent's own persisted SaleBundleItem rows) down to only those
     * with an ACTUALLY-DISCOVERABLE component allocation, matching exactly
     * what synthesizeBundleCashReturnComponents() itself can resolve. A
     * component is resolvable when either:
     *  - Split-owner shape: its SaleBundleItem is attached to a genuine
     *    CARRIER row that is NOT the parent's own row (a distinct,
     *    zero-quantity residual line in the component owner's own Sale); or
     *  - Same-owner shape: its SaleBundleItem is attached to the parent's
     *    own row, AND a separate zero-priced sibling SaleDetails row exists
     *    in the same Sale keyed by the component's own product_id — the
     *    long-standing convention marking a verifiable standalone
     *    allocation.
     * A catalog/SaleBundleItem entry satisfying neither condition (e.g.
     * hand-built fixtures that link a SaleBundleItem without ever creating
     * the component's own persisted allocation) is excluded entirely — never
     * treated as "expected but missing." This preserves the pre-Corrections/10
     * behavior exactly for such fixtures.
     *
     * @param \Illuminate\Support\Collection $catalogItems ProductBundleItem or SaleBundleItem rows
     * @param int $parentSaleDetailId the bundle parent's own sale_detail_id, to distinguish a same-owner
     *        attached row (equals this id) from a genuine cross-owner carrier row (does not)
     * @return \Illuminate\Support\Collection
     */
    protected function filterResolvableFallbackComponents(\Illuminate\Support\Collection $catalogItems, int $parentSaleDetailId): \Illuminate\Support\Collection
    {
        if ($catalogItems->isEmpty()) {
            return $catalogItems;
        }

        $parentSaleDetail = SaleDetails::find($parentSaleDetailId);
        $parentOwnSaleId = $parentSaleDetail->sale_id ?? null;

        // A ProductBundleItem has no sale_id of its own — resolve the WIDE
        // (fallback) scope from every checkout sale sharing the parent's own
        // Sale's checkout, same as synthesizeBundleCashReturnComponents().
        $wideSaleIds = collect([$parentOwnSaleId])->filter();
        if ($parentSaleDetail) {
            $checkoutIds = PosCheckoutSale::where('sale_id', $parentOwnSaleId)->pluck('pos_checkout_id')->filter()->unique();
            $wideSaleIds = $checkoutIds->isNotEmpty()
                ? PosCheckoutSale::whereIn('pos_checkout_id', $checkoutIds)->pluck('sale_id')->filter()->unique()->values()
                : collect([$parentOwnSaleId]);
        }
        $narrowSaleIds = collect([$parentOwnSaleId])->filter();

        return $catalogItems->filter(function ($catalogItem) use ($narrowSaleIds, $wideSaleIds, $parentSaleDetailId) {
            // Corrections/10: try the parent's own sale_id FIRST (narrow
            // scope) before widening to the full checkout — see
            // synthesizeBundleCashReturnComponents() for why (disambiguating
            // repeated same-catalog bundle purchases within one checkout,
            // e.g. a serialized parent with quantity > 1).
            $bundleItem = $this->bundleComponentResolver->findComponentBundleItem(
                $narrowSaleIds,
                (int) $catalogItem->product_id,
                (int) $catalogItem->bundle_id,
                (int) $catalogItem->id,
                (float) $catalogItem->quantity
            ) ?? $this->bundleComponentResolver->findComponentBundleItem(
                $wideSaleIds,
                (int) $catalogItem->product_id,
                (int) $catalogItem->bundle_id,
                (int) $catalogItem->id,
                (float) $catalogItem->quantity
            );

            if (!$bundleItem || !$bundleItem->saleDetail) {
                return false;
            }

            if ((int) $bundleItem->saleDetail->id !== $parentSaleDetailId) {
                // Genuine cross-owner carrier row — always resolvable.
                return true;
            }

            // Same-owner shape: require an actual zero-priced sibling row.
            return SaleDetails::query()
                ->where('sale_id', $bundleItem->sale_id)
                ->where('product_id', $catalogItem->product_id)
                ->where('unit_price', 0)
                ->exists();
        })->values();
    }

    protected function assertReturnedSerialNotClaimedElsewhere(int $returnedSerialId, ?int $ignorePosReturnId = null): void
    {
        if ($returnedSerialId <= 0) {
            return;
        }

        $query = PosReturnLine::where('returned_serial_id', $returnedSerialId)
            ->whereHas('posReturn', function ($q) {
                $q->active()->whereIn('status', [
                    PosReturn::STATUS_PENDING_APPROVAL,
                    PosReturn::STATUS_APPROVED,
                    PosReturn::STATUS_AWAITING_RECEIVING,
                    PosReturn::STATUS_AWAITING_SETTLEMENT,
                    PosReturn::STATUS_AWAITING_DISPATCH,
                    PosReturn::STATUS_MANUAL_CORRECTION_REQUIRED,
                    PosReturn::STATUS_COMPLETED,
                ]);
            });

        if ($ignorePosReturnId) {
            $query->where('pos_return_id', '!=', $ignorePosReturnId);
        }

        if ($query->exists()) {
            throw new \Exception('Serial yang diretur sudah diklaim oleh retur lain yang sedang diproses atau selesai.');
        }
    }

    /**
     * Task 2.5: Resolve the execution_mode tag for a return line's resolution.
     * Only concerns product_replacement — cash_return and none lines never
     * carry this key so a whole-bundle cash return never gets tagged
     * non_serial_note_only.
     */
    protected function resolveExecutionMode(string $resolution, bool $isSerial): ?string
    {
        if ($resolution !== PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
            return null;
        }

        return $isSerial ? 'serial_inventory_replacement' : 'non_serial_note_only';
    }

    protected function generatePosReturnReference($settingId)
    {
        return $this->generateReference($settingId, 'POSRT');
    }

    protected function generateReference($settingId, $modulePrefix)
    {
        $setting = Setting::find($settingId);
        $docPrefix = optional($setting)->document_prefix;
        
        $prefix = ($docPrefix ? $docPrefix . '-' : '') . $modulePrefix;
        $year = now()->format('y');
        $month = now()->format('m');
        
        $count = DB::table('pos_returns')->where('setting_id', $settingId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();
            
        return sprintf("%s-%s%s-%04d", $prefix, $year, $month, $count + 1);
    }
}
