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
use Modules\Sale\Entities\SaleBundleItem;

class PosReturnSubmissionService
{
    protected $snapshotService;
    protected $quantityGuard;
    protected $replacementGuard;
    protected $replacementOwnerResolver;
    protected $serialChainResolver;
    protected $bundleComponentResolver;

    /** @var array<string,list<string>> "carrierSaleDetailId:componentProductId" => proposed serial texts (whole-bundle components) */
    protected array $componentPendingSerials = [];

    /** @var array<string,list<int>> carrierDetailId:componentProductId => replacement serial ids, one per registered component unit */
    protected array $componentReplacementSerials = [];

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
        $this->componentPendingSerials = $this->normalizeComponentPending($data['component_pending_serials'] ?? []);
        $this->componentReplacementSerials = $this->normalizeComponentReplacements($data['component_replacement_serials'] ?? []);

        return DB::transaction(function () use ($data) {
            $transaction = PosTransaction::with(['customer', 'completedCheckout'])
                ->findOrFail($data['pos_transaction_id']);
            $checkout = $transaction->completedCheckout;

            if (!$checkout) {
                throw new \Exception("Transaction has no completed checkout.");
            }

            $this->assertNoMixedWorkflowHistory((int) $checkout->id);

            if (!empty($data['source_snapshot']) && !empty($data['source_snapshot_hash'])) {
                if ($this->snapshotService->hash($data['source_snapshot']) !== $data['source_snapshot_hash']) {
                    throw new \Exception('Source snapshot is stale. Please refresh the page.');
                }
            }

            $currentSnapshot = $this->snapshotService->build($transaction->id);
            $validatedLines = $this->validateDraftLines(
                $data['lines'] ?? [],
                $currentSnapshot,
                $data['source_snapshot_hash'] ?? null,
                null,
                $data['return_option'] ?? null
            );

            $snapshotToSave = !empty($data['source_snapshot']) ? $data['source_snapshot'] : $currentSnapshot;
            $snapshotHashToSave = !empty($data['source_snapshot_hash']) ? $data['source_snapshot_hash'] : $currentSnapshot['hash'];

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
                'source_snapshot' => $snapshotToSave,
                'source_snapshot_hash' => $snapshotHashToSave,
                'total_amount' => 0, // Will be updated
                'created_by' => Auth::id(),
            ]);

            $totalAmount = 0;
            $checkoutSales = PosCheckoutSale::where('pos_checkout_id', $checkout->id)
                ->get()
                ->keyBy('sale_id');

            $valuationSelections = $this->buildNormalizedValuationSelections($data, $validatedLines);
            $valuationResult = app(\Modules\Pos\Services\PosReturnRoundingValuationService::class)->evaluateDraftValuation(
                $snapshotToSave,
                $valuationSelections,
                null
            );

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
                $isSynthesizedCrossOwnerComponent = !empty($lineData['__component_product_id']);
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

                // A registered split-owner component replacement is also measured against the component's own
                // allocation (the carrier row's nominal quantity is always 0).
                $componentReplacement = $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                    && $componentOriginalQuantity !== null;
                $effectiveLineProductId = $isSynthesizedCrossOwnerComponent
                    ? ((int) ($lineData['__component_product_id'] ?? 0) ?: (int) ($lineData['product_id'] ?? 0))
                    : (int) ($saleDetail->product_id ?? $lineData['product_id'] ?? 0);
                $returnableQty = ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN || $componentReplacement)
                    ? $this->quantityGuard->getRefundableQuantity(
                        $saleDetail->id,
                        null,
                        $componentOriginalQuantity,
                        $effectiveLineProductId ?: null
                    )
                    : $this->quantityGuard->getReturnableQuantity(
                        $saleDetail->dispatch_detail_id,
                        $saleDetail->id,
                        null,
                        $effectiveLineProductId ?: null
                    );
                if (!$isSerial && $quantity > $returnableQty) {
                    throw new \Exception("Kuantitas retur melebihi batas yang diizinkan untuk produk {$saleDetail->product_name}.");
                }

                $bundleCtx = $this->bundleComponentResolver->resolveOriginalBundleContext($saleDetail, $checkoutSales, $transaction);
                $isBundle = ($bundleCtx && !empty($bundleCtx['is_bundle'])) || $saleDetail->bundleItems->isNotEmpty();
                $unitPrice = (float) $saleDetail->unit_price;
                if ($isBundle && $unitPrice === 0.0 && $saleDetail->quantity > 0) {
                    $unitPrice = (float) $checkoutSale->subtotal / $saleDetail->quantity;
                }

                $isSynthesizedComponent = !empty($lineData['__synthesized_component'])
                    || (PosReturnLine::isComponentLine($lineData, $saleDetail) && $resolution === PosReturnLine::RESOLUTION_CASH_RETURN);

                // Determine bundle return scope before unit price calculation
                $bundleReturnScope = $lineData['bundle_return_scope'] ?? null;
                if ($isBundle && !$isSynthesizedComponent && $resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                    $bundleReturnScope = $bundleReturnScope ?: PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE;
                } else {
                    $bundleReturnScope = null;
                }

                // Task 3.9: For whole_bundle cash return, use the full original POS transaction line unit price
                // as the customer-facing price. For parent_only, retain the parent's own allocated unit price.
                $ptl = null;
                $ptlIsBundle = false;
                $ptlId = $lineData['pos_transaction_line_id'] ?? ($bundleCtx['pos_transaction_line_id'] ?? null);
                if (!empty($ptlId)) {
                    $ptl = \Modules\Pos\Entities\PosTransactionLine::find($ptlId);
                    if ($ptl) {
                        $ptlIsBundle = !empty($ptl->line_meta['bundle_id'])
                            || !empty($ptl->line_meta['is_bundle'])
                            || !empty($ptl->line_meta['bundle_items']);
                    }
                }

                if ($ptlIsBundle) {
                    $isBundle = true;
                    if ($bundleReturnScope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY) {
                        $unitPrice = (float) $saleDetail->unit_price;
                    } elseif ($ptl && $ptl->unit_price > 0) {
                        $unitPrice = (float) $ptl->unit_price;
                    }
                }

                if ($isSynthesizedComponent && $resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                    $unitPrice = 0.0;
                    $bundleReturnScope = null;
                }

                $lineTotal = $quantity * $unitPrice;
                // For serials, qty=1, so line_total equals unit_price.
                if ($isSerial) {
                    $lineTotal = $unitPrice;
                }
                $valuationErrorMessage = null;
                $expectedCashAmount = ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN && !$isSynthesizedComponent)
                    ? $lineTotal
                    : 0;

                if ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN && !$isSynthesizedComponent) {
                    $lineKey = !empty($returnedSerialId)
                        ? (($lineData['pos_transaction_line_id'] ?? $lineData['sale_detail_id']) . '-' . $returnedSerialId)
                        : (string) $lineData['sale_detail_id'];

                    if (isset($valuationResult['lines'][$lineKey])) {
                        $expectedCashAmount = (float) $valuationResult['lines'][$lineKey]['refund_amount'];
                        if (trim((string) ($lineData['pending_serial'] ?? '')) !== '') {
                            // The valuation covers the key's whole quantity; split it across the per-unit lines.
                            $unitCount = max(1, (int) collect($validatedLines)->filter(fn ($l) => trim((string) ($l['pending_serial'] ?? '')) !== ''
                                && (int) $l['sale_detail_id'] === (int) $lineData['sale_detail_id'])->count());
                            $pendingCashSeen[$lineKey] = ($pendingCashSeen[$lineKey] ?? 0) + 1;
                            $perUnit = round($expectedCashAmount / $unitCount, 2);
                            $expectedCashAmount = $pendingCashSeen[$lineKey] < $unitCount
                                ? $perUnit
                                : round($expectedCashAmount - $perUnit * ($unitCount - 1), 2);
                        }
                        $lineTotal = $expectedCashAmount;
                        $valuationErrorMessage = $valuationResult['lines'][$lineKey]['valuation_error'] ?? null;
                    } elseif ($saleDetail) {
                        try {
                            $valuationService = app(\Modules\Pos\Services\PosReturnRoundingValuationService::class);
                            $effectivePtl = ($bundleReturnScope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY) ? null : $ptl;
                            $baseline = $valuationService->resolveCommercialBaseline($saleDetail, $effectivePtl);
                            $sourceSale = $saleDetail->sale ?? \Modules\Sale\Entities\Sale::find($saleDetail->sale_id);
                            $increment = $valuationService->resolveEffectiveIncrement($sourceSale, $transaction);
                            $lineState = $valuationService->getOrSeedCommercialLineState($sourceSale, $baseline['commercial_line_key'], $baseline);
                            $prevQty = (float) ($lineState['committed_quantity'] ?? 0);
                            $prevAmt = (float) ($lineState['committed_amount'] ?? 0);
                            $storedRes = ($isBundle && $bundleReturnScope !== PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY)
                                ? (float) $baseline['stored_residual']
                                : (float) ($saleDetail->sub_total ?? $baseline['stored_residual']);

                            $cumQty = min((float) $baseline['baseline_quantity'], $prevQty + $quantity);

                            $val = $valuationService->calculateCumulativeValuation(
                                $baseline['baseline_returnable_amount'],
                                $baseline['baseline_quantity'],
                                $cumQty,
                                $prevAmt,
                                $increment,
                                $storedRes
                            );
                            $expectedCashAmount = $val['current_return_amount'];
                            $lineTotal = $expectedCashAmount;
                        } catch (\Throwable $e) {
                            // Never persist a silently substituted amount. The draft is
                            // kept (duplicate claims are rejected at submit/approval), but
                            // is flagged with line_meta.valuation_error, and submission is blocked.
                            \Illuminate\Support\Facades\Log::warning('POS return draft valuation unavailable', [
                                'sale_detail_id' => $lineData['sale_detail_id'] ?? null,
                                'error' => $e->getMessage(),
                            ]);
                            $expectedCashAmount = 0;
                            $lineTotal = 0;
                            $valuationErrorMessage = $e->getMessage();
                        }
                    }
                }

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
                    : ($lineData['__resolved_dispatch_detail_id']
                        ?? $saleDetail->dispatch_detail_id
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
                    'bundle_return_scope' => $bundleReturnScope,
                    'bundle_quantity' => $bundleQuantity,
                    'component_quantity_per_bundle' => $componentQuantityPerBundle,
                ];

                $returnLine = PosReturnLine::create($returnLineData);
                $this->persistPendingRegistration($returnLine, $lineData, $preservedClaimUuids ?? []);
                if ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                    $totalAmount += $expectedCashAmount;
                }

                // Corrections/10: persist the resolved sale_bundle_item_id and
                // allocated component price into line_meta without overwriting existing keys.
                // Task 3.7/3.9: Trace bundled components for actionable lines.
                // Task 3.10: Informational only — no stock reservation or mutation occurs here.
                $lineMetaUpdate = $returnLine->line_meta ?? [];
                if ($isSynthesizedCrossOwnerComponent && !empty($lineData['__sale_bundle_item_id'])) {
                    $lineMetaUpdate['sale_bundle_item_id'] = (int) $lineData['__sale_bundle_item_id'];
                }
                if ($isSynthesizedComponent && array_key_exists('__component_allocated_price', $lineData) && $lineData['__component_allocated_price'] !== null) {
                    $lineMetaUpdate['allocated_price'] = (float) $lineData['__component_allocated_price'];
                }
                if ($valuationErrorMessage !== null) {
                    $lineMetaUpdate['valuation_error'] = $valuationErrorMessage;
                }
                if ($isBundle && !$isSynthesizedComponent && $resolution !== PosReturnLine::RESOLUTION_NONE) {
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
                $executionMode = $this->resolveExecutionMode($resolution, $isSerial || trim((string) ($lineData['pending_serial'] ?? '')) !== '');
                if ($executionMode !== null) {
                    $lineMetaUpdate['execution_mode'] = $executionMode;
                }

                // Corrections/4: persist the replacement reason (optional for serial,
                // required for non-serial — enforced in validateDraftLines()).
                if ($resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT && !empty($lineData['replacement_reason'])) {
                    $lineMetaUpdate['replacement_reason'] = trim((string) $lineData['replacement_reason']);
                }

                if (!empty($lineMetaUpdate)) {
                    $returnLine->update([
                        'line_meta' => array_filter(
                            array_merge($returnLine->line_meta ?? [], $lineMetaUpdate),
                            fn ($value) => $value !== null
                        ),
                    ]);
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

        $this->componentPendingSerials = $this->normalizeComponentPending($data['component_pending_serials'] ?? []);
        $this->componentReplacementSerials = $this->normalizeComponentReplacements($data['component_replacement_serials'] ?? []);

        return DB::transaction(function () use ($posReturn, $data) {
            // Keep claim identity stable across edits (read server-side), then drop the old draft lines
            // (their unregistered claims cascade with them).
            $preservedClaimUuids = \Modules\SalesReturn\Entities\ReturnSerialRegistration::query()
                ->where('workflow', 'pos')->where('pos_return_id', $posReturn->id)
                ->whereNull('registered_serial_id')->pluck('claim_uuid', 'proposed_serial')->all();

            // Delete old associated draft lines
            $posReturn->lines()->delete();
            
            $transaction = $posReturn->posTransaction()->with(['customer', 'completedCheckout'])->firstOrFail();
            $this->assertNoMixedWorkflowHistory((int) $posReturn->pos_checkout_id);
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

            $valuationSelections = $this->buildNormalizedValuationSelections($data, $validatedLines);
            $valuationResult = app(\Modules\Pos\Services\PosReturnRoundingValuationService::class)->evaluateDraftValuation(
                $currentSnapshot,
                $valuationSelections,
                $posReturn->id
            );

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
                $isSynthesizedCrossOwnerComponent = !empty($lineData['__component_product_id']);
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

                $componentReplacement = $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                    && $componentOriginalQuantity !== null;
                $effectiveLineProductId = $isSynthesizedCrossOwnerComponent
                    ? ((int) ($lineData['__component_product_id'] ?? 0) ?: (int) ($lineData['product_id'] ?? 0))
                    : (int) ($saleDetail->product_id ?? $lineData['product_id'] ?? 0);
                $returnableQty = ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN || $componentReplacement)
                    ? $this->quantityGuard->getRefundableQuantity(
                        $saleDetail->id,
                        $posReturn->id,
                        $componentOriginalQuantity,
                        $effectiveLineProductId ?: null
                    )
                    : $this->quantityGuard->getReturnableQuantity(
                        $saleDetail->dispatch_detail_id,
                        $saleDetail->id,
                        $posReturn->id,
                        $effectiveLineProductId ?: null
                    );
                if (!$isSerial && $quantity > $returnableQty) {
                    throw new \Exception("Kuantitas retur melebihi batas yang diizinkan untuk produk {$saleDetail->product_name}.");
                }

                $bundleCtx = $this->bundleComponentResolver->resolveOriginalBundleContext($saleDetail, $checkoutSales, $transaction);
                $isBundle = ($bundleCtx && !empty($bundleCtx['is_bundle'])) || $saleDetail->bundleItems->isNotEmpty();
                $unitPrice = (float) $saleDetail->unit_price;
                if ($isBundle && $unitPrice === 0.0 && $saleDetail->quantity > 0) {
                    $unitPrice = (float) $checkoutSale->subtotal / $saleDetail->quantity;
                }

                $isSynthesizedComponent = !empty($lineData['__synthesized_component'])
                    || (PosReturnLine::isComponentLine($lineData, $saleDetail) && $resolution === PosReturnLine::RESOLUTION_CASH_RETURN);

                // Determine bundle return scope before unit price calculation
                $bundleReturnScope = $lineData['bundle_return_scope'] ?? null;
                if ($isBundle && !$isSynthesizedComponent && $resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                    $bundleReturnScope = $bundleReturnScope ?: PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE;
                } else {
                    $bundleReturnScope = null;
                }

                // Task 3.9: For whole_bundle cash return, use the full original POS transaction line unit price.
                // For parent_only, retain the parent's own allocated unit price.
                $ptl = null;
                $ptlIsBundle = false;
                $ptlId = $lineData['pos_transaction_line_id'] ?? ($bundleCtx['pos_transaction_line_id'] ?? null);
                if (!empty($ptlId)) {
                    $ptl = \Modules\Pos\Entities\PosTransactionLine::find($ptlId);
                    if ($ptl) {
                        $ptlIsBundle = !empty($ptl->line_meta['bundle_id'])
                            || !empty($ptl->line_meta['is_bundle'])
                            || !empty($ptl->line_meta['bundle_items']);
                    }
                }

                if ($ptlIsBundle) {
                    $isBundle = true;
                    if ($bundleReturnScope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY) {
                        $unitPrice = (float) $saleDetail->unit_price;
                    } elseif ($ptl && $ptl->unit_price > 0) {
                        $unitPrice = (float) $ptl->unit_price;
                    }
                }

                if ($isSynthesizedComponent && $resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                    $unitPrice = 0.0;
                    $bundleReturnScope = null;
                }

                $lineTotal = $isSerial ? $unitPrice : ($quantity * $unitPrice);
                $valuationErrorMessage = null;
                $expectedCashAmount = ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN && !$isSynthesizedComponent)
                    ? $lineTotal
                    : 0;

                if ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN && !$isSynthesizedComponent) {
                    $lineKey = !empty($returnedSerialId)
                        ? (($lineData['pos_transaction_line_id'] ?? $lineData['sale_detail_id']) . '-' . $returnedSerialId)
                        : (string) $lineData['sale_detail_id'];

                    if (isset($valuationResult['lines'][$lineKey])) {
                        $expectedCashAmount = (float) $valuationResult['lines'][$lineKey]['refund_amount'];
                        if (trim((string) ($lineData['pending_serial'] ?? '')) !== '') {
                            // The valuation covers the key's whole quantity; split it across the per-unit lines.
                            $unitCount = max(1, (int) collect($validatedLines)->filter(fn ($l) => trim((string) ($l['pending_serial'] ?? '')) !== ''
                                && (int) $l['sale_detail_id'] === (int) $lineData['sale_detail_id'])->count());
                            $pendingCashSeen[$lineKey] = ($pendingCashSeen[$lineKey] ?? 0) + 1;
                            $perUnit = round($expectedCashAmount / $unitCount, 2);
                            $expectedCashAmount = $pendingCashSeen[$lineKey] < $unitCount
                                ? $perUnit
                                : round($expectedCashAmount - $perUnit * ($unitCount - 1), 2);
                        }
                        $lineTotal = $expectedCashAmount;
                        $valuationErrorMessage = $valuationResult['lines'][$lineKey]['valuation_error'] ?? null;
                    } elseif ($saleDetail) {
                        try {
                            $valuationService = app(\Modules\Pos\Services\PosReturnRoundingValuationService::class);
                            $effectivePtl = ($bundleReturnScope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY) ? null : $ptl;
                            $baseline = $valuationService->resolveCommercialBaseline($saleDetail, $effectivePtl);
                            $sourceSale = $saleDetail->sale ?? \Modules\Sale\Entities\Sale::find($saleDetail->sale_id);
                            $increment = $valuationService->resolveEffectiveIncrement($sourceSale, $transaction);
                            $lineState = $valuationService->getOrSeedCommercialLineState($sourceSale, $baseline['commercial_line_key'], $baseline, $posReturn->id);
                            $prevQty = (float) ($lineState['committed_quantity'] ?? 0);
                            $prevAmt = (float) ($lineState['committed_amount'] ?? 0);
                            $storedRes = ($isBundle && $bundleReturnScope !== PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY)
                                ? (float) $baseline['stored_residual']
                                : (float) ($saleDetail->sub_total ?? $baseline['stored_residual']);

                            $cumQty = min((float) $baseline['baseline_quantity'], $prevQty + $quantity);

                            $val = $valuationService->calculateCumulativeValuation(
                                $baseline['baseline_returnable_amount'],
                                $baseline['baseline_quantity'],
                                $cumQty,
                                $prevAmt,
                                $increment,
                                $storedRes
                            );
                            $expectedCashAmount = $val['current_return_amount'];
                            $lineTotal = $expectedCashAmount;
                        } catch (\Throwable $e) {
                            // Never persist a silently substituted amount. The draft is
                            // kept (duplicate claims are rejected at submit/approval), but
                            // is flagged with line_meta.valuation_error, and submission is blocked.
                            \Illuminate\Support\Facades\Log::warning('POS return draft valuation unavailable', [
                                'sale_detail_id' => $lineData['sale_detail_id'] ?? null,
                                'error' => $e->getMessage(),
                            ]);
                            $expectedCashAmount = 0;
                            $lineTotal = 0;
                            $valuationErrorMessage = $e->getMessage();
                        }
                    }
                }

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
                    : ($lineData['__resolved_dispatch_detail_id']
                        ?? $saleDetail->dispatch_detail_id
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
                    'bundle_return_scope' => $bundleReturnScope,
                    'bundle_quantity' => $bundleQuantity,
                    'component_quantity_per_bundle' => $componentQuantityPerBundle,
                ];

                $returnLine = PosReturnLine::create($returnLineData);
                $this->persistPendingRegistration($returnLine, $lineData, $preservedClaimUuids ?? []);
                if ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                    $totalAmount += $expectedCashAmount;
                }

                // Corrections/10: persist the resolved sale_bundle_item_id and
                // allocated component price into line_meta without overwriting existing keys.
                // Task 3.7/3.9: Trace bundled components for actionable lines.
                // Task 3.10: Informational only — no stock reservation or mutation occurs here.
                $lineMetaUpdate = $returnLine->line_meta ?? [];
                if ($isSynthesizedCrossOwnerComponent && !empty($lineData['__sale_bundle_item_id'])) {
                    $lineMetaUpdate['sale_bundle_item_id'] = (int) $lineData['__sale_bundle_item_id'];
                }
                if ($isSynthesizedComponent && array_key_exists('__component_allocated_price', $lineData) && $lineData['__component_allocated_price'] !== null) {
                    $lineMetaUpdate['allocated_price'] = (float) $lineData['__component_allocated_price'];
                }
                if ($valuationErrorMessage !== null) {
                    $lineMetaUpdate['valuation_error'] = $valuationErrorMessage;
                }
                if ($isBundle && !$isSynthesizedComponent && $resolution !== PosReturnLine::RESOLUTION_NONE) {
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
                $executionMode = $this->resolveExecutionMode($resolution, $isSerial || trim((string) ($lineData['pending_serial'] ?? '')) !== '');
                if ($executionMode !== null) {
                    $lineMetaUpdate['execution_mode'] = $executionMode;
                }

                // Corrections/4: persist the replacement reason.
                if ($resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT && !empty($lineData['replacement_reason'])) {
                    $lineMetaUpdate['replacement_reason'] = trim((string) $lineData['replacement_reason']);
                }

                if (!empty($lineMetaUpdate)) {
                    $returnLine->update([
                        'line_meta' => array_filter(
                            array_merge($returnLine->line_meta ?? [], $lineMetaUpdate),
                            fn ($value) => $value !== null
                        ),
                    ]);
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
            // Serialize competing submissions against the SAME source. This must be the FIRST statement: under
            // REPEATABLE READ any earlier plain read would pin a snapshot that predates the winner's commit,
            // and the claim re-check below would then miss the winner's quantities.
            \Modules\Pos\Entities\PosTransaction::query()->whereKey($posReturn->pos_transaction_id)->lockForUpdate()->first();

            $lockedReturn = PosReturn::query()
                ->with('lines')
                ->whereKey($posReturn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$lockedReturn->isDraftSubmittable()) {
                throw new \Exception('Hanya retur draft yang dapat diajukan untuk persetujuan.');
            }

            if ($lockedReturn->source_snapshot && !empty($lockedReturn->source_snapshot_hash)) {
                if ($this->snapshotService->hash($lockedReturn->source_snapshot) !== $lockedReturn->source_snapshot_hash) {
                    throw new \Exception('Source snapshot is stale. Please refresh the page.');
                }
            }

            $unvalued = $lockedReturn->lines->first(fn (PosReturnLine $l) => !empty(data_get($l->line_meta, 'valuation_error')));
            if ($unvalued) {
                throw new \Exception('Nilai retur kas tidak dapat dihitung untuk produk ' . ($unvalued->product_name ?? '') . ': '
                    . data_get($unvalued->line_meta, 'valuation_error') . ' Perbarui draft retur setelah data sumber diperbaiki.');
            }

            $transaction = $lockedReturn->posTransaction()->with(['customer', 'completedCheckout'])->firstOrFail();
            $this->assertNoMixedWorkflowHistory((int) $lockedReturn->pos_checkout_id);
            $currentSnapshot = $this->snapshotService->build($transaction->id);

            $pendingClaims = \Modules\SalesReturn\Entities\ReturnSerialRegistration::query()
                ->where('workflow', 'pos')->where('pos_return_id', $lockedReturn->id)
                ->whereNull('registered_serial_id')->pluck('proposed_serial', 'pos_return_line_id');

            // Whole-bundle component proposals are re-synthesized from the parent line: feed them from the persisted claims.
            $isComponentLine = fn (PosReturnLine $l) => PosReturnLine::isComponentLine($l);
            $this->componentPendingSerials = [];
            foreach ($lockedReturn->lines as $l) {
                if ($isComponentLine($l) && $pendingClaims->has($l->id)) {
                    $this->componentPendingSerials[$l->sale_detail_id . ':' . $l->product_id][] = $pendingClaims->get($l->id);
                }
            }

            $this->validateDraftLines(
                $lockedReturn->lines->map(function (PosReturnLine $line) use ($pendingClaims, $isComponentLine) {
                    $lineIsComponent = $isComponentLine($line);

                    return [
                        'pending_serial' => $lineIsComponent ? null : $pendingClaims->get($line->id),
                        'sale_detail_id' => $line->sale_detail_id,
                        'sale_id' => $line->sale_id,
                        'pos_transaction_line_id' => $line->pos_transaction_line_id,
                        'returned_serial_id' => $line->returned_serial_id,
                        'resolution' => $line->resolution,
                        'bundle_return_scope' => $line->bundle_return_scope,
                        'quantity' => (float) $line->quantity,
                        'replacement_serial_id' => $line->replacement_serial_id,
                        // Corrections/4: re-validation must see the persisted
                        // reason, otherwise a previously-valid non-serial
                        // product_replacement line would spuriously fail here.
                        'replacement_reason' => data_get($line->line_meta, 'replacement_reason'),
                        // Carry persisted component identity forward so
                        // re-validation recognizes carrier-row component lines
                        // on both sibling carriers and parent carriers.
                        '__component_product_id' => $lineIsComponent ? $line->product_id : null,
                        '__component_product_name' => $lineIsComponent ? $line->product_name : null,
                        '__component_product_code' => $lineIsComponent ? $line->product_code : null,
                        '__component_dispatch_detail_id' => $lineIsComponent ? $line->dispatch_detail_id : null,
                        '__component_allocated_price' => $lineIsComponent ? data_get($line->line_meta, 'allocated_price') : null,
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

            $updatePayload = [
                'status' => PosReturn::STATUS_PENDING_APPROVAL,
                'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
                'updated_by' => Auth::id(),
            ];

            if (empty($lockedReturn->source_snapshot)) {
                $updatePayload['source_snapshot'] = $currentSnapshot;
                $updatePayload['source_snapshot_hash'] = $currentSnapshot['hash'];
            }

            $lockedReturn->update($updatePayload);

            return $lockedReturn->refresh();
        });
    }

    /**
     * Historical mixed standard/POS claims use incompatible quantity models; new POS
     * entitlement is blocked (never rewritten) until an administrator audits the history.
     */
    protected function assertNoMixedWorkflowHistory(int $checkoutId): void
    {
        if ($checkoutId <= 0) {
            return;
        }

        $resolver = app(\App\Support\SalesReturn\SalesReturnWorkflowResolver::class);
        $saleIds = PosCheckoutSale::where('pos_checkout_id', $checkoutId)->pluck('sale_id')
            ->merge(\Modules\Pos\Entities\PosCheckout::where('id', $checkoutId)->pluck('sale_id'))
            ->filter()->unique();

        foreach ($saleIds as $saleId) {
            if ($resolver->classifySale((int) $saleId) === \App\Support\SalesReturn\SalesReturnWorkflowResolver::MIXED) {
                throw new \Exception('Penjualan sumber memiliki riwayat retur campuran (POS dan standar) sehingga klaim retur baru diblokir. Data lama tidak diubah; hubungi administrator untuk audit.');
            }
        }
    }

    /**
     * Build valuation selections from the normalized, validated line intent
     * (header-default resolutions already applied; explicit "none" preserved)
     * so valuation, persistence and totals agree on every line's resolution.
     *
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $validatedLines
     * @return array<string, array<string, mixed>>
     */
    protected function buildNormalizedValuationSelections(array $data, array $validatedLines): array
    {
        $selections = $data['line_selections'] ?? [];

        foreach ($validatedLines as $l) {
            $key = !empty($l['returned_serial_id'])
                ? (($l['pos_transaction_line_id'] ?? $l['sale_detail_id']) . '-' . $l['returned_serial_id'])
                : (string) $l['sale_detail_id'];

            // Per-unit proposed-serial lines share their source key: their quantities add up.
            $isPendingUnit = trim((string) ($l['pending_serial'] ?? '')) !== '';
            $carried = ($isPendingUnit && isset($selections[$key]['__pending_total'])) ? (float) $selections[$key]['__pending_total'] : 0.0;

            $selections[$key] = [
                'resolution' => $l['resolution'] ?? PosReturnLine::RESOLUTION_NONE,
                'quantity' => ($l['quantity'] ?? 0) + $carried,
                'bundle_return_scope' => $l['bundle_return_scope'] ?? null,
            ] + ($isPendingUnit ? ['__pending_total' => ($l['quantity'] ?? 0) + $carried] : []);
        }

        return $selections;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $currentSnapshot
     * @return array<int, array<string, mixed>>
     */
    protected function validateDraftLines(array $lines, array $currentSnapshot, ?string $expectedSnapshotHash, ?int $ignorePosReturnId = null, ?string $defaultReturnOption = null, bool $enforceReturnedSerialExclusivity = false): array
    {
        $validatedLines = [];
        $usedReplacementSerialIds = [];
        $pendingProposals = [];
        $pendingPerSaleDetail = [];
        $chainClaims = [];

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

            $scope = $lineData['bundle_return_scope'] ?? null;
            if ($scope !== null && !in_array($scope, [PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE, PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY], true)) {
                throw new \Exception('Scope retur bundle tidak valid.');
            }

            if (!$isSerial && ($resolution === PosReturnLine::RESOLUTION_NONE || $quantity <= 0)) {
                continue;
            }

            if ($isSerial && $quantity <= 0) {
                $quantity = 1;
            }

            $saleDetail = SaleDetails::with(['bundleItems.product', 'product'])->find($lineData['sale_detail_id']);
            if (! $saleDetail) {
                throw new \Exception("Sale detail dengan ID {$lineData['sale_detail_id']} tidak ditemukan.");
            }

            $checkoutId = $currentSnapshot['header']['checkout_id'] ?? null;
            if ($checkoutId) {
                $belongsToCheckout = PosCheckoutSale::where('pos_checkout_id', $checkoutId)
                    ->where('sale_id', $saleDetail->sale_id)
                    ->exists();
                if (! $belongsToCheckout) {
                    throw new \Exception('Sale detail tidak sesuai dengan transaksi.');
                }
            }

            $effectiveProductId = !empty($lineData['__component_product_id'])
                ? (int) $lineData['__component_product_id']
                : (!empty($lineData['product_id'])
                    ? (int) $lineData['product_id']
                    : ($isSerial && $returnedSerialId
                        ? (int) (ProductSerialNumber::find($returnedSerialId)->product_id ?? $saleDetail->product_id)
                        : (int) $saleDetail->product_id));

            $isSynthesizedComponentLine = PosReturnLine::isComponentLine($lineData, $saleDetail);

            $resolvedDispatchDetailId = null;
            if (!empty($lineData['__component_dispatch_detail_id']) && $isSynthesizedComponentLine) {
                $candidateDispatchId = (int) $lineData['__component_dispatch_detail_id'];
                $dispatchCandidate = \Modules\Sale\Entities\DispatchDetail::where('id', $candidateDispatchId)
                    ->where('sale_id', $saleDetail->sale_id)
                    ->where('product_id', $effectiveProductId)
                    ->first();

                if ($dispatchCandidate) {
                    if ($isSerial && $returnedSerialId) {
                        $serialObj = ProductSerialNumber::find($returnedSerialId);
                        if ($serialObj) {
                            $snList = is_array($dispatchCandidate->serial_numbers)
                                ? $dispatchCandidate->serial_numbers
                                : (is_string($dispatchCandidate->serial_numbers) ? (json_decode($dispatchCandidate->serial_numbers, true) ?: explode(',', $dispatchCandidate->serial_numbers)) : []);

                            $serialMatchesDispatch = in_array($serialObj->serial_number, $snList, true)
                                || (int) $dispatchCandidate->id === (int) $serialObj->dispatch_detail_id
                                || PosReturnLine::where('dispatch_detail_id', $dispatchCandidate->id)
                                    ->where(function ($q) use ($serialObj) {
                                        $q->where('returned_serial_id', $serialObj->id)
                                          ->orWhere('replacement_serial_id', $serialObj->id);
                                    })
                                    ->whereHas('posReturn', fn ($q) => $q->where('status', PosReturn::STATUS_COMPLETED))
                                    ->exists();

                            if ($serialMatchesDispatch) {
                                $resolvedDispatchDetailId = (int) $dispatchCandidate->id;
                            }
                        }
                    } else {
                        $resolvedDispatchDetailId = (int) $dispatchCandidate->id;
                    }
                }
            }

            if (empty($resolvedDispatchDetailId) && !empty($currentSnapshot['lines'])) {
                // Find matching line in trusted snapshot
                foreach ($currentSnapshot['lines'] as $snapLine) {
                    if ((int) ($snapLine['sale_detail_id'] ?? 0) === (int) $saleDetail->id) {
                        if ($isSerial && $returnedSerialId) {
                            $snIds = $snapLine['serial_number_ids'] ?? [];
                            if (in_array($returnedSerialId, $snIds, true) || in_array((int) $returnedSerialId, array_map('intval', (array) $snIds), true)) {
                                $resolvedDispatchDetailId = (int) ($snapLine['dispatch_detail_id'] ?? 0);
                                break;
                            }
                        } else {
                            $resolvedDispatchDetailId = (int) ($snapLine['dispatch_detail_id'] ?? 0);
                            break;
                        }
                    }
                }
            }

            if (empty($resolvedDispatchDetailId)) {
                // Fallback: Check if serial itself was dispatched in this specific sale
                if ($isSerial && $returnedSerialId) {
                    $serialObj = ProductSerialNumber::find($returnedSerialId);
                    if ($serialObj) {
                        $dispatchDetails = \Modules\Sale\Entities\DispatchDetail::where('sale_id', $saleDetail->sale_id)
                            ->where('product_id', $effectiveProductId)
                            ->get();

                        foreach ($dispatchDetails as $dd) {
                            $snList = is_array($dd->serial_numbers)
                                ? $dd->serial_numbers
                                : (is_string($dd->serial_numbers) ? (json_decode($dd->serial_numbers, true) ?: explode(',', $dd->serial_numbers)) : []);

                            if (in_array($serialObj->serial_number, $snList, true) ||
                                (int) $dd->id === (int) $serialObj->dispatch_detail_id ||
                                PosReturnLine::where('dispatch_detail_id', $dd->id)
                                    ->where(function ($q) use ($serialObj) {
                                        $q->where('returned_serial_id', $serialObj->id)
                                          ->orWhere('replacement_serial_id', $serialObj->id);
                                    })
                                    ->whereHas('posReturn', fn ($q) => $q->where('status', PosReturn::STATUS_COMPLETED))
                                    ->exists()) {
                                $resolvedDispatchDetailId = (int) $dd->id;
                                break;
                            }
                        }
                    }
                }

                // Non-serialized lines may fall back to the sale detail or first product dispatch
                if (!$isSerial && empty($resolvedDispatchDetailId)) {
                    $resolvedDispatchDetailId = (int) ($saleDetail->dispatch_detail_id
                        ?? \Modules\Sale\Entities\DispatchDetail::where('sale_id', $saleDetail->sale_id)
                            ->where('product_id', $effectiveProductId)
                            ->value('id') ?? 0);
                }
            }

            // Unknown-serial registration: eligibility is derived from persisted fulfillment of the resolved
            // dispatch line (never from client flags). One proposed serial == one unit == one line.
            $pendingText = trim((string) ($lineData['pending_serial'] ?? ''));
            $isPending = $pendingText !== '';
            $registration = $this->registrationEligibility($effectiveProductId, (int) $resolvedDispatchDetailId);
            if ($isPending) {
                if ($isSerial) {
                    throw new \Exception('Nomor seri terdaftar dan nomor seri baru tidak dapat digabung pada satu baris retur.');
                }
                if ($isSynthesizedComponentLine || $this->isBundleComponentAllocationRow($saleDetail)) {
                    throw new \Exception('Nomor seri komponen bundel didaftarkan melalui retur seluruh paket.');
                }
                if (! $registration['eligible']) {
                    throw new \Exception("Nomor seri baru hanya dapat didaftarkan untuk penjualan tanpa nomor seri tercatat pada produk yang kini wajib bernomor seri ({$saleDetail->product_name}).");
                }
                $quantity = 1;
                $normalizedPending = \Modules\Product\Entities\ProductSerialNumber::normalize($pendingText);
                $pendingProposals[] = ['product_id' => $effectiveProductId, 'serial' => $normalizedPending, 'key' => count($pendingProposals)];
                $lineData['pending_serial'] = $normalizedPending;
            } elseif (! $isSerial && $registration['eligible'] && ! $isSynthesizedComponentLine && ! $this->isBundleComponentAllocationRow($saleDetail)) {
                throw new \Exception("Nomor seri wajib diisi untuk setiap unit {$saleDetail->product_name} yang diretur (produk kini wajib bernomor seri).");
            }

            if ($isSerial) {
                if ($resolvedDispatchDetailId <= 0) {
                    throw new \Exception('Lineage pengiriman sumber untuk serial tidak valid.');
                }
                $this->assertReturnedSerialIsCurrentHeldOccurrence(
                    (int) $returnedSerialId,
                    $resolvedDispatchDetailId,
                    $checkoutSale->pos_checkout_id ?? null,
                    $saleDetail->sale->pos_transaction_id ?? null
                );
                if ($enforceReturnedSerialExclusivity) {
                    $this->assertReturnedSerialNotClaimedElsewhere((int) $returnedSerialId, $resolvedDispatchDetailId, $ignorePosReturnId);
                }
            }

            // Sequence 10 correction: a line carrying __component_* identity
            // tags (either freshly synthesized this call, or a previously-
            // synthesized/persisted component line re-submitted on resubmit
            // — see submitDraftForApproval()) has its sale_detail_id pointing
            // at a carrier row whose own SaleDetails.quantity is always 0 —
            // pass the component's true original quantity explicitly.
            $isTaggedComponentLine = PosReturnLine::isComponentLine($lineData, $saleDetail)
                && ($lineData['__component_quantity_per_bundle'] ?? $lineData['component_quantity_per_bundle'] ?? null) !== null
                && !empty($lineData['__bundle_parent_sale_detail_id'] ?? $lineData['bundle_parent_sale_detail_id'] ?? null);
            $componentOriginalQuantity = null;
            if ($isTaggedComponentLine) {
                $quantityPerBundle = (float) ($lineData['__component_quantity_per_bundle'] ?? $lineData['component_quantity_per_bundle']);
                $parentSaleDetailId = (int) ($lineData['__bundle_parent_sale_detail_id'] ?? $lineData['bundle_parent_sale_detail_id']);
                $parentRefundableQty = $this->quantityGuard->getRefundableQuantity($parentSaleDetailId, $ignorePosReturnId);
                $componentOriginalQuantity = $quantityPerBundle * $parentRefundableQty;
            }

            // Semantic correction: see store() for rationale — cash_return
            // lines are checked against refundable (not resolution-agnostic
            // returned) quantity, so a prior product_replacement never blocks
            // a later cash_return for the same logical quantity.
            // A registered split-owner component replacement is measured against the component's own allocation.
            $componentReplacement = $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                && $componentOriginalQuantity !== null;
            $effectiveLineProductId = $isTaggedComponentLine
                ? ((int) ($lineData['__component_product_id'] ?? 0) ?: (int) ($lineData['product_id'] ?? 0))
                : (int) ($saleDetail->product_id ?? $lineData['product_id'] ?? 0);
            $returnableQty = ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN || $componentReplacement)
                ? $this->quantityGuard->getRefundableQuantity(
                    $saleDetail->id,
                    $ignorePosReturnId,
                    $componentOriginalQuantity,
                    $effectiveLineProductId ?: null
                )
                : $this->quantityGuard->getReturnableQuantity(
                    $saleDetail->dispatch_detail_id,
                    $saleDetail->id,
                    $ignorePosReturnId,
                    $effectiveLineProductId ?: null
                );

            if (!$isSerial && $quantity > $returnableQty) {
                throw new \Exception("Kuantitas retur melebihi batas yang diizinkan untuk produk {$saleDetail->product_name}.");
            }

            // A customer-held occurrence that originates from return registration is one unit of its quantity-based
            // source: it must fit the source's remaining entitlement together with every other claim (counted once).
            if ($isSerial && $this->isRegisteredChainOccurrence((int) $returnedSerialId, (int) $resolvedDispatchDetailId, $currentSnapshot['header']['checkout_id'] ?? null)) {
                $chainKey = $saleDetail->id . ':' . $resolution;
                $chainClaims[$chainKey] = ($chainClaims[$chainKey] ?? 0) + 1;
                if ($chainClaims[$chainKey] + ($pendingPerSaleDetail[$chainKey] ?? 0) > $returnableQty) {
                    throw new \Exception("Kuantitas retur melebihi batas yang diizinkan untuk produk {$saleDetail->product_name}.");
                }
            }

            if ($isPending) {
                $pendingKey = $saleDetail->id . ':' . $resolution;
                $pendingPerSaleDetail[$pendingKey] = ($pendingPerSaleDetail[$pendingKey] ?? 0) + 1;
                if ($pendingPerSaleDetail[$pendingKey] + ($chainClaims[$pendingKey] ?? 0) > $returnableQty) {
                    throw new \Exception("Jumlah nomor seri baru melebihi sisa kuantitas yang dapat diretur untuk produk {$saleDetail->product_name}.");
                }
            }

            $replacementSerialId = $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                ? ($lineData['replacement_serial_id'] ?? null)
                : null;

            if ($resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT && ($isSerial || $isPending) && empty($replacementSerialId)) {
                throw new \Exception('Serial pengganti harus diisi untuk penggantian produk.');
            }

            // Corrections/4: non-serial product_replacement (ordinary, parent, or
            // component) must carry a non-empty reason. Serial replacement leaves
            // it optional.
            if ($resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                && !$isSerial
                && !$isPending
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
                    : (int) (($isTaggedComponentLine && ! empty($lineData['__component_product_id'])) ? $lineData['__component_product_id'] : $saleDetail->product_id);

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
            $lineData['__resolved_dispatch_detail_id'] = $resolvedDispatchDetailId;
            $validatedLines[] = $lineData;
        }

        if ($pendingProposals !== []) {
            $errors = app(\App\Support\SalesReturn\SerialRegistration\PendingSerialValidator::class)->validate($pendingProposals);
            if ($errors !== []) {
                $reason = match (reset($errors)) {
                    \App\Support\SalesReturn\SerialRegistration\PendingSerialValidator::ERROR_ALREADY_REGISTERED => 'sudah terdaftar; gunakan pencarian nomor seri yang ada',
                    \App\Support\SalesReturn\SerialRegistration\PendingSerialValidator::ERROR_DUPLICATE => 'duplikat',
                    \App\Support\SalesReturn\SerialRegistration\PendingSerialValidator::ERROR_TOO_LONG => 'terlalu panjang',
                    default => 'tidak valid',
                };
                $bad = $pendingProposals[array_key_first($errors)]['serial'] ?? '';
                throw new \Exception("Nomor seri baru {$bad} {$reason}.");
            }
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
            if (($lineData['resolution'] ?? null) === PosReturnLine::RESOLUTION_CASH_RETURN) {
                return true;
            }

            // A whole-bundle REPLACEMENT may replace registered components unit by unit (see componentReplacementSerials).
            return ($lineData['resolution'] ?? null) === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                && ($this->componentReplacementSerials !== [] || $this->componentPendingSerials !== [])
                && ($lineData['bundle_return_scope'] ?? null) === PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE;
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
        // sale_detail_id. Only populated when persisted bundle evidence exists
        // (PTL bundle metadata, direct SaleBundleItem, or checkout SaleBundleItem referencing parent).
        // Prefers the catalog ProductBundle/ProductBundleItem definition
        // (scoped to exact bundle_id when known) — but falls back to the parent's
        // own persisted SaleBundleItem rows when no catalog ProductBundle is
        // resolvable (legacy/hand-built test data with no real ProductBundle header),
        // matching the exact same fallback PosReturnSnapshotService applies.
        $allSaleBundleItems = SaleBundleItem::whereIn('sale_id', $saleIds)->get();
        $posTransactionId = 0;
        if (!empty($checkoutIds)) {
            $posTransactionId = (int) (\Modules\Pos\Entities\PosCheckout::whereIn('id', $checkoutIds)->value('pos_transaction_id') ?? 0);
        }
        $transactionPtls = $posTransactionId > 0
            ? \Modules\Pos\Entities\PosTransactionLine::where('pos_transaction_id', $posTransactionId)->get()
            : collect();

        $allCheckoutSales = PosCheckoutSale::with(['sale.saleDetails.bundleItems.product'])
            ->whereIn('pos_checkout_id', $checkoutIds)
            ->get();
        $posTransaction = $posTransactionId > 0 ? PosTransaction::with('lines')->find($posTransactionId) : null;

        $parentToCatalogComponents = [];
        foreach ($saleDetailsBySale as $saleId => $saleDetails) {
            foreach ($saleDetails as $parentCandidate) {
                if ((float) $parentCandidate->quantity <= 0) {
                    continue;
                }
                $bundleContext = $this->bundleComponentResolver->resolveOriginalBundleContext($parentCandidate, $allCheckoutSales, $posTransaction);
                if (!$bundleContext || empty($bundleContext['is_bundle'])) {
                    continue;
                }
                if (!empty($bundleContext['is_ambiguous'])) {
                    throw new \Exception($bundleContext['error'] ?? 'Ambiguous bundle resolution.');
                }

                $resolvableItems = collect($bundleContext['components'])->map(fn ($c) => (object) [
                    'product_id' => $c['product_id'],
                    'quantity' => $c['quantity_per_bundle'],
                    'bundle_id' => $bundleContext['bundle_id'],
                    'id' => $c['bundle_item_id'],
                    'carrier_sale_detail_id' => $c['carrier_sale_detail_id'],
                    'sale_id' => $c['sale_id'],
                    'sale_bundle_item_id' => $c['sale_bundle_item_id'],
                    'dispatch_detail_id' => $c['dispatch_detail_id'],
                    'product_name' => $c['product_name'],
                    'product_code' => $c['product_code'],
                    'lineage_status' => $c['lineage_status'] ?? 'resolved',
                    'allocated_price' => (float) ($c['allocated_price'] ?? 0.0),
                ]);

                if ($resolvableItems->isEmpty()) {
                    continue;
                }

                $parentToCatalogComponents[$parentCandidate->id] = [
                    'sale_id' => (int) $saleId,
                    'catalog_items' => $resolvableItems,
                    'bundle_context' => $bundleContext,
                ];
            }
        }

        $synthesized = [];
        $pendingComponentProposals = [];
        $handledComponentSaleDetailIds = [];

        foreach ($cashReturnLines as $lineData) {
            $saleDetailId = (int) ($lineData['sale_detail_id'] ?? 0);
            $parentInfo = $parentToCatalogComponents[$saleDetailId] ?? null;

            if ($parentInfo === null) {
                continue;
            }

            // Task 2.2: If explicit parent-only scope, do not synthesize components!
            $scope = $lineData['bundle_return_scope'] ?? null;
            if ($scope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY) {
                continue;
            }

            $replacementMode = ($lineData['resolution'] ?? null) === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT;
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
                if (($catalogItem->lineage_status ?? 'resolved') !== 'resolved') {
                    continue;
                }

                $componentProductId = (int) $catalogItem->product_id;

                // Corrections/10: resolve the component's SaleBundleItem.
                // Prefer the resolver's disambiguated sale_bundle_item_id first, then fall back to
                // the PARENT's own sale_id (narrow scope) before widening to the full checkout.
                $bundleItem = (!empty($catalogItem->sale_bundle_item_id)
                    ? \Modules\Sale\Entities\SaleBundleItem::find((int) $catalogItem->sale_bundle_item_id)
                    : null)
                    ?? $this->bundleComponentResolver->findComponentBundleItem(
                        [$parentInfo['sale_id']],
                        $componentProductId,
                        (int) $catalogItem->bundle_id,
                        (int) $catalogItem->id,
                        (float) $catalogItem->quantity,
                        $catalogItem->carrier_sale_detail_id ?? null
                    )
                    ?? $this->bundleComponentResolver->findComponentBundleItem(
                        $saleIds,
                        $componentProductId,
                        (int) $catalogItem->bundle_id,
                        (int) $catalogItem->id,
                        (float) $catalogItem->quantity,
                        $catalogItem->carrier_sale_detail_id ?? null
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

                // Disambiguate whether the attached row equals the parent's own row:
                // prefer an explicit zero-price sibling row when present, otherwise use
                // the parent's SaleDetails row itself as the carrier row.
                if ((int) $attachedDetail->id === $saleDetailId) {
                    $siblingDetail = SaleDetails::query()
                        ->where('sale_id', $componentSaleId)
                        ->where('product_id', $componentProductId)
                        ->where('unit_price', 0)
                        ->first();

                    $carrierDetail = $siblingDetail ?: $attachedDetail;
                } else {
                    $carrierDetail = $attachedDetail;
                }

                $componentDispatchDetailId = !empty($catalogItem->dispatch_detail_id)
                    ? (int) $catalogItem->dispatch_detail_id
                    : $this->bundleComponentResolver->findComponentDispatchDetailId(
                        $componentSaleId,
                        $componentProductId,
                        (int) $carrierDetail->id,
                        (int) $bundleItem->bundle_id
                    );

                $componentProduct = $bundleItem->product ?? \Modules\Product\Entities\Product::find($componentProductId);
                $componentIsSerial = (bool) ($componentProduct->serial_number_required ?? false);

                // A component that now requires tracking but whose fulfillment recorded no serials is a quantity-only
                // source: it keeps its own allocation quantity and registers one proposed serial per unit instead.
                $registerComponent = $componentIsSerial
                    && $this->registrationEligibility($componentProductId, (int) $componentDispatchDetailId)['eligible'];
                if ($registerComponent) {
                    $componentIsSerial = false;
                }

                // Replacement mode synthesizes ONLY the registered components the user chose to replace; every other
                // component keeps the parent line's existing (unchanged) replacement semantics.
                $replacementKey = $carrierDetail->id . ':' . $componentProductId;
                if ($replacementMode && ! isset($this->componentReplacementSerials[$replacementKey]) && empty($this->componentPendingSerials[$replacementKey])) {
                    continue;
                }
                if ($replacementMode && ! $registerComponent) {
                    throw new \Exception("Komponen {$componentProduct->product_name} tidak memenuhi syarat penggantian dengan nomor seri baru.");
                }

                // Dedupe key: the carrier row's id alone is NOT a unique
                // identity for a component (the same carrier row can host
                // multiple distinct components) — key by carrier id +
                // component product id instead.
                $handledComponentSaleDetailIds[] = $carrierDetail->id . ':' . $componentProductId;

                $allocatedPrice = (float) ($catalogItem->allocated_price ?? 0.0);
                if ($allocatedPrice <= 0.0 && $bundleItem) {
                    $allocatedPrice = (float) ($bundleItem->price > 0
                        ? $bundleItem->price
                        : ($bundleItem->sub_total > 0 ? $bundleItem->sub_total / max(1, (float) $bundleItem->quantity) : 0.0));
                }

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
                    'allocated_price' => $allocatedPrice,
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
                    $componentOriginalQuantity,
                    $componentProductId
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

                $componentTexts = $registerComponent
                    ? ($this->componentPendingSerials[$carrierDetail->id . ':' . $componentProductId] ?? [])
                    : [];
                if ($registerComponent && abs(count($componentTexts) - $componentQuantity) > 0.0001) {
                    throw new \Exception("Nomor seri wajib diisi untuk setiap unit komponen {$componentInfo['component_product_name']} ({$componentQuantity} unit).");
                }

                $componentReplacements = ($registerComponent && $replacementMode)
                    ? ($this->componentReplacementSerials[$replacementKey] ?? [])
                    : [];
                if ($replacementMode && count($componentReplacements) !== count($componentTexts)) {
                    throw new \Exception("Setiap unit komponen {$componentInfo['component_product_name']} yang diganti membutuhkan tepat satu serial pengganti.");
                }
                foreach ($componentReplacements as $replacementId) {
                    if (in_array($replacementId, $usedReplacementSerialIds, true)) {
                        throw new \Exception('Serial pengganti tidak boleh digunakan lebih dari satu kali dalam retur yang sama.');
                    }
                    // Same SKU / owner / stock checks as every other serialized replacement, against the COMPONENT product.
                    $this->replacementGuard->validateReplacementSerial($componentProductId, (int) $replacementId, null, $ignorePosReturnId);
                    $usedReplacementSerialIds[] = $replacementId;
                }

                foreach ($registerComponent ? $componentTexts : [null] as $componentUnitIndex => $componentText) {
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
                    '__component_allocated_price' => $allocatedPrice,
                ];
                if ($componentText !== null) {
                    $last = array_key_last($synthesized);
                    $synthesized[$last]['quantity'] = 1;
                    if ($componentReplacements !== []) {
                        // Registered original replaced from the component owner's own serialized stock: the replacement
                        // must be a serial of the SAME component product (validated with the other replacement checks).
                        $synthesized[$last]['resolution'] = PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT;
                        $synthesized[$last]['replacement_serial_id'] = $componentReplacements[$componentUnitIndex];
                        $synthesized[$last]['replacement_reason'] = $lineData['replacement_reason'] ?? null;
                    }
                    $synthesized[$last]['pending_serial'] = $componentText;
                    $pendingComponentProposals[] = ['product_id' => $componentProductId, 'serial' => $componentText, 'key' => count($pendingComponentProposals)];
                }
                }
            }

            // Tag the parent line itself with identity columns so it shares the
            // same bundle_group_key as its synthesized components.
            $lineData['__bundle_parent_sale_detail_id'] = $saleDetailId;
            $lineData['__bundle_group_key'] = $bundleGroupKey;
            $lineData['__bundle_quantity'] = $parentQuantity;
        }

        if ($pendingComponentProposals !== []) {
            // Component proposals must be distinct from each other and from every other proposal of this request.
            $all = $pendingComponentProposals;
            foreach ($validatedLines as $vl) {
                $text = trim((string) ($vl['pending_serial'] ?? ''));
                if ($text !== '' && empty($vl['__component_product_id'])) {
                    $all[] = ['product_id' => (int) SaleDetails::query()->whereKey($vl['sale_detail_id'])->value('product_id'), 'serial' => $text, 'key' => count($all)];
                }
            }
            $errors = app(\App\Support\SalesReturn\SerialRegistration\PendingSerialValidator::class)->validate($all);
            if ($errors !== []) {
                $bad = $all[array_key_first($errors)]['serial'] ?? '';
                throw new \Exception("Nomor seri baru {$bad} " . (reset($errors) === \App\Support\SalesReturn\SerialRegistration\PendingSerialValidator::ERROR_ALREADY_REGISTERED ? 'sudah terdaftar; gunakan pencarian nomor seri yang ada' : 'tidak valid atau duplikat') . '.');
            }
        }

        $hasBundleParents = collect($validatedLines)->contains(function ($l) use ($parentToCatalogComponents) {
            return ($l['resolution'] ?? null) === PosReturnLine::RESOLUTION_CASH_RETURN
                && isset($parentToCatalogComponents[(int) ($l['sale_detail_id'] ?? 0)]);
        });

        if (empty($synthesized) && !$hasBundleParents) {
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

            if (isset($parentToCatalogComponents[$saleDetailId]) && $isExplicitCashReturn && !PosReturnLine::isComponentLine($lineData)) {
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
            $leafSerialId = $this->resolveCurrentHeldSerial(
                (int) $originalSerialId,
                $componentDetail->sale?->posCheckoutSales?->first()?->pos_checkout_id ?? null,
                null,
                (int) ($componentDispatchDetailId ?? 0)
            );

            if (in_array($leafSerialId, $seenLeafIds, true)) {
                continue;
            }
            $seenLeafIds[] = $leafSerialId;

            $leafSerial = ProductSerialNumber::find($leafSerialId);
            if (!$leafSerial) {
                throw new \Exception("Serial komponen bundel tidak ditemukan untuk produk {$componentDetail->product_name}.");
            }

            $sourceDispatchDetailId = (int) ($componentDispatchDetailId ?? 0);
            if ($sourceDispatchDetailId <= 0) {
                throw new \Exception("Lineage pengiriman sumber untuk komponen serial tidak valid.");
            }

            // Claim-exclusivity: the leaf serial must not already be claimed
            // by another in-flight return, mirroring the same check applied
            // to explicitly-submitted serial lines.
            $this->assertReturnedSerialNotClaimedElsewhere($leafSerialId, $sourceDispatchDetailId, $ignorePosReturnId);

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
                '__component_dispatch_detail_id' => $sourceDispatchDetailId,
                '__sale_bundle_item_id' => $componentInfo['sale_bundle_item_id'] ?? null,
                '__component_allocated_price' => (float) ($componentInfo['allocated_price'] ?? 0.0),
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
    protected function resolveCurrentHeldSerial(
        int $originalSerialId,
        ?int $posCheckoutId = null,
        ?int $posTransactionId = null,
        ?int $dispatchDetailId = null
    ): int {
        return $this->serialChainResolver->resolveCurrentHeldSerial($originalSerialId, $posCheckoutId, $posTransactionId, $dispatchDetailId);
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

        // Map: parent_sale_detail_id => Collection of components captured at
        // checkout (PTL bundle_items or persisted SaleBundleItem rows). Every
        // captured component is expected in full; one whose source allocation
        // cannot be resolved is rejected by name, never filtered out, and the
        // current catalog is never substituted for the original composition.
        $parentToCatalogComponents = [];
        $parentComponentContexts = [];
        $saleIdsInScope = $saleDetails->pluck('sale_id')->filter()->unique()->values();
        $checkoutIds = PosCheckoutSale::whereIn('sale_id', $saleIdsInScope)->pluck('pos_checkout_id')->filter()->unique()->values();
        if ($checkoutIds->isNotEmpty()) {
            $allSaleIds = PosCheckoutSale::whereIn('pos_checkout_id', $checkoutIds)->pluck('sale_id')->filter()->unique()->values();
            if ($allSaleIds->isNotEmpty()) {
                $saleIdsInScope = $allSaleIds;
            }
        }

        $allSaleBundleItems = SaleBundleItem::whereIn('sale_id', $saleIdsInScope)->get();
        $posTransactionId = 0;
        $firstPtlId = $cashReturnLines->pluck('pos_transaction_line_id')->filter()->first();
        if ($firstPtlId) {
            $posTransactionId = (int) (\Modules\Pos\Entities\PosTransactionLine::where('id', $firstPtlId)->value('pos_transaction_id') ?? 0);
        }
        if ($posTransactionId === 0) {
            $firstSaleId = $saleIdsInScope->first();
            if ($firstSaleId) {
                $checkoutSale = PosCheckoutSale::where('sale_id', $firstSaleId)->first();
                if ($checkoutSale && $checkoutSale->pos_checkout_id) {
                    $posTransactionId = (int) (\Modules\Pos\Entities\PosCheckout::where('id', $checkoutSale->pos_checkout_id)->value('pos_transaction_id') ?? 0);
                }
            }
        }
        $transactionPtls = $posTransactionId > 0
            ? \Modules\Pos\Entities\PosTransactionLine::where('pos_transaction_id', $posTransactionId)->get()
            : collect();

        $allCheckoutSales = $checkoutIds->isNotEmpty()
            ? PosCheckoutSale::with(['sale.saleDetails.bundleItems.product'])->whereIn('pos_checkout_id', $checkoutIds)->get()
            : collect();
        $posTransaction = $posTransactionId > 0 ? PosTransaction::with('lines')->find($posTransactionId) : null;

        foreach ($saleDetails as $saleDetail) {
            if ((float) $saleDetail->quantity <= 0) {
                continue;
            }

            $bundleContext = $this->bundleComponentResolver->resolveOriginalBundleContext($saleDetail, $allCheckoutSales, $posTransaction);
            if (!$bundleContext || empty($bundleContext['is_bundle'])) {
                continue;
            }
            if (!empty($bundleContext['is_ambiguous'])) {
                throw new \Exception($bundleContext['error'] ?? 'Ambiguous bundle resolution.');
            }

            $rawItems = collect($bundleContext['components'])->map(fn ($c) => (object) [
                'product_id' => $c['product_id'],
                'quantity' => $c['quantity_per_bundle'],
                'bundle_id' => $bundleContext['bundle_id'],
                'id' => $c['bundle_item_id'],
            ]);

            // The resolver only returns components captured at checkout, which are
            // authoritative over a catalog that may have been edited since.
            // Components with unresolved lineage are kept, never skipped, so the
            // completeness check below rejects them by name.
            if ($rawItems->isNotEmpty()) {
                $parentToCatalogComponents[$saleDetail->id] = $rawItems;
                $parentComponentContexts[$saleDetail->id] = [
                    'parent_name' => $saleDetail->product_name,
                    'components' => collect($bundleContext['components'])->keyBy(fn ($c) => (int) $c['product_id']),
                ];
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
            $isParentLine = isset($parentToCatalogComponents[$saleDetailId])
                && $parentSaleDetailId === $saleDetailId
                && !PosReturnLine::isComponentLine($lineData, $saleDetails->get($saleDetailId));

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
            // an actual component ALLOCATION row proven by persisted bundle
            // lineage, never a same-SKU commercial standalone sale of the
            // same product — product_id alone is never sufficient identity
            // here (see same_sku_standalone_and_component_are_disambiguated test).
            $lineSaleDetail = $saleDetails->get($saleDetailId);
            $lineProductId = (int) ($lineSaleDetail?->product_id ?? 0);
            if ($parentSaleDetailId === 0
                && isset($componentProductIdToBundleParentProductId[$lineProductId])
                && $this->isBundleComponentAllocationRow($lineSaleDetail)) {
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

            // Task 2.2: If explicit parent-only scope, component completeness is satisfied
            if ($parentLine && ($parentLine['bundle_return_scope'] ?? null) === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY) {
                continue;
            }

            $expectedComponentProductIds = $catalogItems->pluck('product_id')->map(fn ($id) => (int) $id)->all();
            $missing = array_diff($expectedComponentProductIds, array_keys($componentLines));

            foreach ($missing as $missingProductId) {
                $component = $parentComponentContexts[$parentSaleDetailId]['components']->get((int) $missingProductId);
                if ($component && ($component['lineage_status'] ?? 'resolved') !== 'resolved') {
                    throw new \Exception($this->bundleComponentResolver->describeUnresolvedComponent(
                        $component,
                        $parentComponentContexts[$parentSaleDetailId]['parent_name']
                    ));
                }
            }

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
                $isSerialComponent = collect($lines)->contains(fn ($l) => !empty($l['returned_serial_id']) || !empty($l['pending_serial']));

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
    protected function resolveCatalogBundleComponents(int $parentProductId, ?int $bundleId = null): \Illuminate\Support\Collection
    {
        return $this->bundleComponentResolver->resolveCatalogBundleComponents($parentProductId, $bundleId);
    }

    /**
     * Whether a component-product SaleDetails row is a bundle component
     * ALLOCATION row (bundle-internal) rather than an ordinary standalone
     * sale of the same product, judged only by persisted bundle lineage:
     *  - Same-owner convention: zero-priced sibling row; or
     *  - Dispatch lineage: a DispatchDetail for this exact row carries a
     *    bundle_id; or
     *  - Split-owner lineage: the same Sale has a zero-quantity carrier row
     *    whose SaleBundleItem for this product captured exactly this row's
     *    quantity and allocated unit price.
     * A standalone sale sitting beside a bundle whose SaleBundleItem is
     * attached to a real (positive-quantity) parent row satisfies none.
     *
     * Known limitation: the split-owner quantity/price match is a fallback,
     * not proven component identity. A standalone sale in a split-owner Sale
     * with exactly the carrier item's quantity and allocated price is
     * classified as a component row, so its standalone cash return is
     * rejected (fails safe). Accepted pending manual review.
     */
    protected function isBundleComponentAllocationRow(?SaleDetails $saleDetail): bool
    {
        if (! $saleDetail) {
            return false;
        }

        if ((float) $saleDetail->unit_price === 0.0) {
            return true;
        }

        $hasBundleDispatch = DispatchDetail::query()
            ->where('sale_detail_id', $saleDetail->id)
            ->whereNotNull('bundle_id')
            ->exists();
        if ($hasBundleDispatch) {
            return true;
        }

        return SaleBundleItem::query()
            ->where('sale_id', $saleDetail->sale_id)
            ->where('product_id', $saleDetail->product_id)
            ->whereHas('saleDetail', fn ($q) => $q->where('quantity', '<=', 0))
            ->get()
            ->contains(function ($sbi) use ($saleDetail) {
                $allocatedUnitPrice = (float) $sbi->price > 0
                    ? (float) $sbi->price
                    : ((float) $sbi->quantity > 0 ? (float) $sbi->sub_total / (float) $sbi->quantity : 0.0);

                return abs((float) $sbi->quantity - (float) $saleDetail->quantity) < 0.0001
                    && abs($allocatedUnitPrice - (float) $saleDetail->unit_price) < 0.01;
            });
    }

    public function resolveOriginalSerialIdsForDispatch(int $dispatchDetailId): array
    {
        $dd = \Modules\Sale\Entities\DispatchDetail::find($dispatchDetailId);
        if (! $dd) {
            return [];
        }

        $serialIds = [];

        if (! empty($dd->serial_numbers)) {
            $rawValues = is_array($dd->serial_numbers)
                ? $dd->serial_numbers
                : (json_decode($dd->serial_numbers, true) ?: explode(',', $dd->serial_numbers));

            $explicitIds = [];
            $serialNumberStrings = [];

            foreach ((array) $rawValues as $val) {
                if (is_array($val)) {
                    if (isset($val['id']) && is_numeric($val['id'])) {
                        $explicitIds[] = (int) $val['id'];
                    }
                    if (isset($val['serial_number']) && trim((string) $val['serial_number']) !== '') {
                        $serialNumberStrings[] = trim((string) $val['serial_number']);
                    }
                } elseif (is_int($val)) {
                    $explicitIds[] = $val;
                } elseif (is_string($val)) {
                    $trimmed = trim($val);
                    if ($trimmed !== '') {
                        $serialNumberStrings[] = $trimmed;
                    }
                }
            }

            if (! empty($explicitIds) || ! empty($serialNumberStrings)) {
                $query = ProductSerialNumber::query();
                if ($dd->product_id) {
                    $query->where('product_id', $dd->product_id);
                }

                $query->where(function ($q) use ($explicitIds, $serialNumberStrings) {
                    if (! empty($explicitIds) && ! empty($serialNumberStrings)) {
                        $q->whereIn('id', $explicitIds)
                            ->orWhereIn('serial_number', $serialNumberStrings);
                    } elseif (! empty($explicitIds)) {
                        $q->whereIn('id', $explicitIds);
                    } else {
                        $q->whereIn('serial_number', $serialNumberStrings);
                    }
                });

                $serialIds = $query->pluck('id')->all();
            }
        }

        if (empty($serialIds)) {
            $serialIds = ProductSerialNumber::where('dispatch_detail_id', $dispatchDetailId)
                ->pluck('id')
                ->all();
        }

        // Serials created by return registration are return-time lineage, never original fulfillment evidence.
        $registered = app(\App\Support\SalesReturn\SerialRegistration\HistoricalFulfillmentClassifier::class)->returnCreatedSerialIdsForSource(array_map('intval', $serialIds), (int) $dispatchDetailId);
        if ($registered !== []) {
            $serialIds = array_values(array_diff(array_map('intval', $serialIds), $registered));
        }

        if (empty($serialIds)) {
            $firstRepl = PosReturnLine::query()
                ->join('pos_returns', 'pos_return_lines.pos_return_id', '=', 'pos_returns.id')
                ->where('pos_return_lines.dispatch_detail_id', $dispatchDetailId)
                ->where('pos_return_lines.resolution', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
                ->where('pos_returns.status', PosReturn::STATUS_COMPLETED)
                ->where('pos_returns.is_reversed', false)
                ->orderBy('pos_return_lines.id', 'ASC')
                ->first();

            if ($firstRepl && $firstRepl->returned_serial_id
                && ! in_array((int) $firstRepl->returned_serial_id, app(\App\Support\SalesReturn\SerialRegistration\HistoricalFulfillmentClassifier::class)->returnCreatedSerialIdsForSource([(int) $firstRepl->returned_serial_id], (int) $dispatchDetailId), true)) {
                $serialIds = [(int) $firstRepl->returned_serial_id];
            }
        }

        return array_values(array_unique(array_filter($serialIds)));
    }

    protected function assertReturnedSerialIsCurrentHeldOccurrence(
        int $returnedSerialId,
        int $dispatchDetailId,
        ?int $posCheckoutId = null,
        ?int $posTransactionId = null
    ): void {
        if ($returnedSerialId <= 0 || $dispatchDetailId <= 0) {
            return;
        }

        $originalSerialIds = $this->resolveOriginalSerialIdsForDispatch($dispatchDetailId);

        if (! empty($originalSerialIds)) {
            $heldSerialIds = [];
            foreach ($originalSerialIds as $origId) {
                try {
                    $heldSerialIds[] = $this->serialChainResolver->resolveCurrentHeldSerial(
                        (int) $origId,
                        $posCheckoutId,
                        $posTransactionId,
                        $dispatchDetailId
                    );
                } catch (\Exception $e) {
                }
            }

            if (! empty($heldSerialIds) && ! in_array($returnedSerialId, $heldSerialIds, true)) {
                throw new \Exception('Serial yang diretur sudah diganti oleh serial lain dan tidak lagi dipegang oleh pelanggan.');
            }
        } else {
            $hasBeenReplaced = PosReturnLine::query()
                ->join('pos_returns', 'pos_return_lines.pos_return_id', '=', 'pos_returns.id')
                ->where('pos_return_lines.returned_serial_id', $returnedSerialId)
                ->where('pos_return_lines.dispatch_detail_id', $dispatchDetailId)
                ->where('pos_return_lines.resolution', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
                ->where('pos_returns.status', PosReturn::STATUS_COMPLETED)
                ->where('pos_returns.is_reversed', false)
                ->exists();

            if ($hasBeenReplaced) {
                throw new \Exception('Serial yang diretur sudah diganti oleh serial lain dan tidak lagi dipegang oleh pelanggan.');
            }
        }
    }

    protected function assertReturnedSerialNotClaimedElsewhere(int $returnedSerialId, int $dispatchDetailId, ?int $ignorePosReturnId = null): void
    {
        if ($returnedSerialId <= 0 || $dispatchDetailId <= 0) {
            return;
        }

        $query = PosReturnLine::where('returned_serial_id', $returnedSerialId)
            ->where('dispatch_detail_id', $dispatchDetailId)
            ->where(function ($lineQuery) {
                $lineQuery->whereHas('posReturn', function ($q) {
                    $q->active()->whereIn('status', [
                        PosReturn::STATUS_PENDING_APPROVAL,
                        PosReturn::STATUS_APPROVED,
                        PosReturn::STATUS_AWAITING_RECEIVING,
                        PosReturn::STATUS_AWAITING_SETTLEMENT,
                        PosReturn::STATUS_AWAITING_DISPATCH,
                        PosReturn::STATUS_MANUAL_CORRECTION_REQUIRED,
                    ]);
                })->orWhere(function ($compQuery) {
                    $compQuery->where('resolution', PosReturnLine::RESOLUTION_CASH_RETURN)
                        ->whereHas('posReturn', function ($q) {
                            $q->active()->where('status', PosReturn::STATUS_COMPLETED);
                        });
                });
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
    /** @return array<string,list<int>> */
    protected function normalizeComponentReplacements(array $input): array
    {
        $out = [];
        foreach ($input as $key => $ids) {
            $clean = collect((array) $ids)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->values()->all();
            if ($clean !== []) {
                $out[(string) $key] = $clean;
            }
        }

        return $out;
    }

    /** @return array<string,list<string>> */
    protected function normalizeComponentPending(array $input): array
    {
        $out = [];
        foreach ($input as $key => $texts) {
            $clean = collect((array) $texts)->map(fn ($t) => trim((string) $t))->filter(fn ($t) => $t !== '')
                ->map(fn ($t) => ProductSerialNumber::normalize($t))->values()->all();
            if ($clean !== []) {
                $out[(string) $key] = $clean;
            }
        }

        return $out;
    }

    /** True when $serialId is the current customer-held end of a chain rooted at a serial registered by a POS return on this dispatch line. */
    public function isRegisteredChainOccurrence(int $serialId, int $dispatchDetailId, $posCheckoutId = null): bool
    {
        if ($serialId <= 0 || $dispatchDetailId <= 0) {
            return false;
        }
        $lineIds = PosReturnLine::query()->where('dispatch_detail_id', $dispatchDetailId)->pluck('id');
        if ($lineIds->isEmpty()) {
            return false;
        }
        $roots = \Modules\SalesReturn\Entities\ReturnSerialRegistration::query()
            ->where('workflow', 'pos')->whereNotNull('registered_serial_id')
            ->whereIn('pos_return_line_id', $lineIds->all())->pluck('registered_serial_id');
        foreach ($roots as $root) {
            try {
                if ($this->serialChainResolver->resolveCurrentHeldSerial((int) $root, $posCheckoutId ? (int) $posCheckoutId : null, null, $dispatchDetailId) === $serialId) {
                    return true;
                }
            } catch (\Throwable $e) {
            }
        }

        return false;
    }

    /**
     * @return array{eligible: bool, classification: ?string}
     */
    protected function registrationEligibility(int $productId, int $dispatchDetailId): array
    {
        if ($productId <= 0 || $dispatchDetailId <= 0) {
            return ['eligible' => false, 'classification' => null];
        }
        $dispatch = \Modules\Sale\Entities\DispatchDetail::query()->find($dispatchDetailId);
        if (! $dispatch || (int) $dispatch->product_id !== $productId) {
            return ['eligible' => false, 'classification' => null];
        }
        $class = app(\App\Support\SalesReturn\SerialRegistration\HistoricalFulfillmentClassifier::class)->forDispatchDetail($dispatch);
        $tracked = (bool) \Modules\Product\Entities\Product::query()->whereKey($productId)->value('serial_number_required');

        return ['eligible' => $tracked && $class->allowsUnknownSerialRegistration(), 'classification' => $class->type];
    }

    /** Persist a proposed serial for a just-created line (no inventory; preserves claim identity on edit). */
    protected function persistPendingRegistration(PosReturnLine $line, array $lineData, array $preservedClaimUuids = []): void
    {
        $text = trim((string) ($lineData['pending_serial'] ?? ''));
        if ($text === '' || ! empty($line->returned_serial_id)) {
            return;
        }
        app(\App\Support\SalesReturn\SerialRegistration\ReturnSerialRegistrationService::class)
            ->savePosProposal($line, $text, $preservedClaimUuids);
    }

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
