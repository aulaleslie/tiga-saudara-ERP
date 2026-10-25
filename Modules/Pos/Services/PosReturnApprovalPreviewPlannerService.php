<?php

namespace Modules\Pos\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use App\Support\PosReturn\PosReturnQuantityGuard;

class PosReturnApprovalPreviewPlannerService
{
    private array $settingNames = [];

    private array $locationNames = [];

    private array $taxNames = [];

    public function __construct(
        private readonly PosReturnSnapshotService $snapshotService,
        private readonly PosReturnReplacementGuard $replacementGuard,
        private readonly PosCheckoutGroupCustomerResolverService $groupCustomerResolver,
        private readonly PosReturnSerialReplacementChainResolver $serialChainResolver,
        private readonly ?PosReturnQuantityGuard $quantityGuard = null,
        private readonly ?PosBundleComponentResolver $bundleComponentResolver = null,
        private readonly ?PosReturnRoundingValuationService $roundingValuationService = null,
    ) {
    }

    public array $lastValuationErrors = [];

    public function plan(PosReturn $posReturn): array
    {
        $posReturn->loadMissing([
            'lines.returnedSerial',
            'lines.replacementSerial',
            'lines.posTransactionLine',
            'posTransaction.lines',
            'saleReturns.saleReturnDetails',
        ]);

        $freshSnapshot = $this->snapshotService->build($posReturn->pos_transaction_id);
        $actionableLines = $posReturn->lines
            ->filter(fn (PosReturnLine $line) => in_array((string) $line->resolution, [
                PosReturnLine::RESOLUTION_CASH_RETURN,
                PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            ], true))
            ->values();

        $blockers = [];
        $warnings = [];
        $info = [];
        $groups = [];

        $sourceHeader = $posReturn->source_snapshot['header'] ?? null;
        $freshHeader = $freshSnapshot['header'] ?? null;
        $headerDrift = false;

        if ($sourceHeader && $freshHeader) {
            $receiptDrift = isset($sourceHeader['receipt_number'], $freshHeader['receipt_number']) &&
                $sourceHeader['receipt_number'] !== $freshHeader['receipt_number'];
            $totalDrift = isset($sourceHeader['grand_total'], $freshHeader['grand_total']) &&
                abs((float) $sourceHeader['grand_total'] - (float) $freshHeader['grand_total']) > 0.01;
            $checkoutDrift = isset($sourceHeader['checkout_id'], $freshHeader['checkout_id']) &&
                (int) $sourceHeader['checkout_id'] !== (int) $freshHeader['checkout_id'];

            if ($receiptDrift || $totalDrift || $checkoutDrift) {
                $headerDrift = true;
                $blockers[] = $this->message(
                    'snapshot_drift',
                    'Snapshot sumber retur POS sudah berubah dibandingkan data yang diajukan untuk approval preview.'
                );
            }
        }

        if (($freshSnapshot['hash'] ?? null) === $posReturn->source_snapshot_hash) {
            $info[] = $this->message('snapshot_hash_valid', 'Snapshot sumber masih sesuai dengan data retur yang diajukan.');
        } elseif (! $headerDrift) {
            $info[] = $this->message('snapshot_hash_valid', 'Snapshot sumber divalidasi terhadap entitas dan ketersediaan baris retur.');
        }

        if ($actionableLines->isEmpty()) {
            $blockers[] = $this->message('no_actionable_lines', 'Retur POS ini tidak memiliki baris aksi yang dapat dipreview.');
        }

        $planningContext = $this->buildPlanningContext($posReturn, $actionableLines, $freshSnapshot);

        foreach ($actionableLines as $line) {
            $linePlan = $this->buildLinePlan($posReturn, $line, $planningContext);

            $blockers = array_merge($blockers, $linePlan['blockers']);
            $warnings = array_merge($warnings, $linePlan['warnings']);
            $info = array_merge($info, $linePlan['info']);

            foreach ($linePlan['entries'] as $entry) {
                $this->addPlannedEntryToGroups($groups, $entry);
            }
        }

        $totalRetainedCharges = 0.0;
        foreach ($groups as $groupKey => $group) {
            $saleId = (int) ($group['source_sale']['id'] ?? 0);
            $sale = $planningContext['sales']->get($saleId);

            $commercialReduction = (float) ($group['planned_header']['total_amount'] ?? 0);
            $actualRefund = 0.0;
            $debtReduction = 0.0;
            $remainingDue = 0.0;

            if ($sale) {
                $activePayments = $sale->salePayments->filter(fn ($p) => method_exists($p, 'isActive') ? $p->isActive() : true);
                $activePaidAmount = round((float) $activePayments->sum(fn ($p) => (float) $p->amount), 2);
                if ($activePaidAmount <= 0 && $activePayments->isEmpty()) {
                    $activePaidAmount = round((float) ($sale->paid_amount ?? 0), 2);
                }

                $saleTotal = round((float) ($sale->total_amount ?? 0), 2);
                $previousDue = round(max((float) ($sale->due_amount ?? 0), $saleTotal - $activePaidAmount), 2);
                $newSaleTotal = round(max(0, $saleTotal - $commercialReduction), 2);
                $correctedPaid = round(min($activePaidAmount, $newSaleTotal), 2);
                $actualRefund = round(max(0, $activePaidAmount - $newSaleTotal), 2);
                $correctedDue = round(max(0, $newSaleTotal - $correctedPaid), 2);
                $debtReduction = round(max(0, $previousDue - $correctedDue), 2);
                $remainingDue = $correctedDue;
            }

            $groupRetained = collect($group['planned_details'] ?? [])->sum(fn ($d) => (float) ($d['retained_charges_total'] ?? 0));
            $totalRetainedCharges += $groupRetained;

            $groups[$groupKey]['planned_header']['commercial_reduction'] = $commercialReduction;
            $groups[$groupKey]['planned_header']['actual_refund'] = $actualRefund;
            $groups[$groupKey]['planned_header']['debt_reduction'] = $debtReduction;
            $groups[$groupKey]['planned_header']['remaining_due'] = $remainingDue;
            $groups[$groupKey]['planned_header']['retained_charges'] = $groupRetained;
        }

        $groups = array_values($groups);

        $totalCommercialReduction = collect($groups)->sum(fn ($g) => (float) ($g['planned_header']['commercial_reduction'] ?? 0));
        $totalActualRefund = collect($groups)->sum(fn ($g) => (float) ($g['planned_header']['actual_refund'] ?? 0));
        $totalDebtReduction = collect($groups)->sum(fn ($g) => (float) ($g['planned_header']['debt_reduction'] ?? 0));
        $allRetainedComponents = collect($groups)->flatMap(fn ($g) => $g['planned_details'])->flatMap(fn ($d) => $d['retained_components'] ?? [])->values()->all();
        $allIncludedComponents = collect($groups)->flatMap(fn ($g) => $g['planned_details'])->flatMap(fn ($d) => $d['included_components'] ?? [])->values()->all();

        if ($posReturn->saleReturns->isEmpty() && $groups !== []) {
            $info[] = $this->message(
                'no_linked_sale_returns',
                'Belum ada Sales Return terhubung. Preview tetap ditampilkan karena target dapat direncanakan dari baris retur dan data sumber saat ini.'
            );
        }

        return [
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'is_blocked' => $blockers !== [],
            'blockers' => array_values($blockers),
            'warnings' => array_values($warnings),
            'info' => array_values($info),
            'groups' => $groups,
            'commercial_reduction' => $totalCommercialReduction,
            'actual_refund' => $totalActualRefund,
            'debt_reduction' => $totalDebtReduction,
            'retained_charges' => $totalRetainedCharges,
            'retained_components' => $allRetainedComponents,
            'included_components' => $allIncludedComponents,
            'summary' => [
                'commercial_reduction' => $totalCommercialReduction,
                'actual_refund' => $totalActualRefund,
                'debt_reduction' => $totalDebtReduction,
                'retained_charges' => $totalRetainedCharges,
                'retained_components' => $allRetainedComponents,
                'included_components' => $allIncludedComponents,
                'rounding_context' => [
                    'policy_version' => PosReturnRoundingValuationService::POLICY_VERSION,
                    'provisional_increment' => ($this->roundingValuationService ?? app(PosReturnRoundingValuationService::class))->resolveEffectiveIncrement($planningContext['sales']->first() ?? new Sale(), $posReturn->posTransaction),
                    'is_increment_frozen' => ! empty($planningContext['sales']->first()?->return_rounding_context['frozen_increment']),
                ],
            ],
        ];
    }

    private function buildPlanningContext(PosReturn $posReturn, Collection $actionableLines, ?array $freshSnapshot = null): array
    {
        $saleIds = $actionableLines->pluck('sale_id')->filter()->unique()->values();
        $saleDetailIds = $actionableLines->pluck('sale_detail_id')->filter()->unique()->values();

        $checkoutSales = PosCheckoutSale::query()
            ->with(['sourceSetting:id,company_name', 'sourceLocation:id,name'])
            ->where('pos_checkout_id', $posReturn->pos_checkout_id)
            ->get()
            ->keyBy(fn (PosCheckoutSale $checkoutSale) => (int) $checkoutSale->sale_id);

        $saleIdsToLoad = $checkoutSales->keys()->merge($saleIds)->unique()->filter()->values();
        $sales = Sale::query()
            ->with(['salePayments', 'saleDetails', 'bundleItems.saleDetail'])
            ->whereIn('id', $saleIdsToLoad)
            ->get()
            ->keyBy(fn (Sale $sale) => (int) $sale->id);

        $saleDetails = SaleDetails::query()
            ->with(['product', 'tax', 'bundleItems'])
            ->whereIn('id', $saleDetailIds)
            ->get()
            ->keyBy(fn (SaleDetails $saleDetail) => (int) $saleDetail->id);

        $componentBundleItems = SaleBundleItem::query()
            ->with(['sale:id,reference,status', 'saleDetail:id,sale_id,quantity', 'product', 'tax'])
            ->whereIn('sale_id', $checkoutSales->keys())
            ->get();

        foreach ($checkoutSales as $checkoutSale) {
            $settingId = (int) $checkoutSale->source_setting_id;
            $locationId = (int) $checkoutSale->source_location_id;

            if ($settingId > 0 && $checkoutSale->relationLoaded('sourceSetting')) {
                $this->settingNames[$settingId] = $checkoutSale->sourceSetting?->company_name;
            }

            if ($locationId > 0 && $checkoutSale->relationLoaded('sourceLocation')) {
                $this->locationNames[$locationId] = $checkoutSale->sourceLocation?->name;
            }
        }

        foreach ($saleDetails as $saleDetail) {
            if ($saleDetail->tax_id) {
                $this->taxNames[(int) $saleDetail->tax_id] = $saleDetail->tax?->name;
            }
        }

        foreach ($componentBundleItems as $componentBundleItem) {
            if ($componentBundleItem->tax_id) {
                $this->taxNames[(int) $componentBundleItem->tax_id] = $componentBundleItem->tax?->name;
            }
        }

        $context = [
            'checkout_sales' => $checkoutSales,
            'sales' => $sales,
            'sale_details' => $saleDetails,
            'component_bundle_items' => $componentBundleItems,
            'fresh_snapshot' => $freshSnapshot,
            'valuation_errors' => [],
        ];

        $context['planned_cash_amounts'] = $this->calculatePlannedCashReturnAmounts($posReturn, $actionableLines, $context);
        $context['valuation_errors'] = $this->lastValuationErrors;

        return $context;
    }

    private function buildLinePlan(PosReturn $posReturn, PosReturnLine $line, array $planningContext): array
    {
        $blockers = [];
        $warnings = [];
        $info = [];

        /** @var Collection<int, PosCheckoutSale> $checkoutSales */
        $checkoutSales = $planningContext['checkout_sales'];
        /** @var Collection<int, Sale> $sales */
        $sales = $planningContext['sales'];
        /** @var Collection<int, SaleDetails> $saleDetails */
        $saleDetails = $planningContext['sale_details'];

        $sale = $sales->get((int) $line->sale_id);
        if (! $sale) {
            $blockers[] = $this->message('source_sale_missing', 'Sale sumber untuk baris retur tidak ditemukan.', [
                'pos_return_line_id' => $line->id,
                'sale_id' => $line->sale_id,
            ]);

            return ['blockers' => $blockers, 'warnings' => $warnings, 'info' => $info, 'entries' => []];
        }

        if (! empty($planningContext['valuation_errors'][$line->id])) {
            $blockers[] = $this->message(
                'rounding_valuation_failed',
                'Perhitungan pembulatan retur tidak valid: ' . $planningContext['valuation_errors'][$line->id],
                [
                    'pos_return_line_id' => $line->id,
                    'error' => $planningContext['valuation_errors'][$line->id],
                ]
            );

            return ['blockers' => $blockers, 'warnings' => $warnings, 'info' => $info, 'entries' => []];
        }

        $saleDetail = $saleDetails->get((int) $line->sale_detail_id);
        if (! $saleDetail) {
            $blockers[] = $this->message('sale_detail_missing', 'Sale detail sumber untuk baris retur tidak ditemukan.', [
                'pos_return_line_id' => $line->id,
                'sale_detail_id' => $line->sale_detail_id,
            ]);

            return ['blockers' => $blockers, 'warnings' => $warnings, 'info' => $info, 'entries' => []];
        }

        // Split-owner carrier-row correction: for a bundle COMPONENT line,
        // sale_detail_id points at the CARRIER SaleDetails row (the only
        // SaleDetails identity that exists for the component in a
        // split-owner checkout — see InlinePosCheckoutPostingAdapter). That
        // carrier row's own product_id is the PARENT's, not the component's,
        // by design — so product_id is deliberately excluded from this
        // identity check for component lines. sale_id must still match
        // (the carrier row and the persisted line always share one Sale).
        $isComponentLineForIdentityCheck = $this->isBundleComponentLine($line);
        $identityMismatch = (int) $saleDetail->sale_id !== (int) $line->sale_id
            || (! $isComponentLineForIdentityCheck && (int) $saleDetail->product_id !== (int) $line->product_id);

        if ($identityMismatch) {
            $blockers[] = $this->message('source_identity_mismatch', 'Identitas sale detail sudah tidak cocok dengan baris retur tersimpan.', [
                'pos_return_line_id' => $line->id,
                'sale_detail_id' => $line->sale_detail_id,
            ]);

            return ['blockers' => $blockers, 'warnings' => $warnings, 'info' => $info, 'entries' => []];
        }

        // Financial basis validation: check if sale detail monetary value drifted
        $freshSnapshot = $planningContext['fresh_snapshot'] ?? null;
        if ($freshSnapshot && !empty($posReturn->source_snapshot['lines'])) {
            $sourceSnapLine = collect($posReturn->source_snapshot['lines'] ?? [])
                ->first(function ($sl) use ($line) {
                    if ((int) ($sl['sale_detail_id'] ?? 0) !== (int) $line->sale_detail_id) {
                        return false;
                    }
                    if ($line->returned_serial_id) {
                        return in_array((int) $line->returned_serial_id, array_map('intval', (array) ($sl['serial_number_ids'] ?? [])), true);
                    }
                    return true;
                });

            $freshSnapLine = collect($freshSnapshot['lines'] ?? [])
                ->first(function ($fl) use ($line) {
                    if ((int) ($fl['sale_detail_id'] ?? 0) !== (int) $line->sale_detail_id) {
                        return false;
                    }
                    if ($line->returned_serial_id) {
                        return in_array((int) $line->returned_serial_id, array_map('intval', (array) ($fl['serial_number_ids'] ?? [])), true);
                    }
                    return true;
                });

            if ($sourceSnapLine && $freshSnapLine) {
                $qtyDrift = abs((float) ($sourceSnapLine['original_quantity'] ?? 0) - (float) ($freshSnapLine['original_quantity'] ?? 0)) > 0.001;

                if ($qtyDrift) {
                    $blockers[] = $this->message(
                        'snapshot_drift',
                        'Snapshot sumber retur POS sudah berubah dibandingkan data yang diajukan untuk approval preview.',
                        [
                            'pos_return_line_id' => $line->id,
                            'sale_detail_id' => $line->sale_detail_id,
                        ]
                    );
                }
            }
        }

        $currentEffectiveUnitPrice = $this->resolveEffectiveUnitFinancialBasis($saleDetail, $line, $checkoutSales, $posReturn);
        $expectedBasis = (float) ($line->line_meta['reviewed_unit_price'] ?? $line->unit_price);

        if (abs($currentEffectiveUnitPrice - $expectedBasis) > 0.01) {
            $blockers[] = $this->message('snapshot_drift', 'Nilai moneter pada dokumen sumber telah berubah (harga, diskon, atau alokasi pajak). Diperlukan peninjauan ulang sebelum persetujuan.', [
                'pos_return_line_id' => $line->id,
                'sale_detail_id' => $line->sale_detail_id,
                'submitted_unit_price' => $expectedBasis,
                'current_unit_price' => $currentEffectiveUnitPrice,
                'renewed_review_required' => true,
            ]);
        }

        // Quantity guard validation for non-serial lines
        if (! $line->returned_serial_id) {
            $guard = $this->quantityGuard ?? app(PosReturnQuantityGuard::class);
            $isTaggedComponentLine = (bool) $line->bundle_parent_sale_detail_id && (int) $line->bundle_parent_sale_detail_id !== (int) $line->sale_detail_id;
            $componentOriginalQuantity = null;
            if ($isTaggedComponentLine && $line->component_quantity_per_bundle !== null) {
                $parentRefundableQty = $guard->getRefundableQuantity((int) $line->bundle_parent_sale_detail_id, $posReturn->id);
                $componentOriginalQuantity = (float) $line->component_quantity_per_bundle * $parentRefundableQty;
            } elseif ((int) $line->product_id !== (int) $saleDetail->product_id) {
                $bundleItem = \Modules\Sale\Entities\SaleBundleItem::where('sale_detail_id', $saleDetail->id)
                    ->where('product_id', $line->product_id)
                    ->first();
                if ($bundleItem) {
                    $componentOriginalQuantity = (float) $bundleItem->quantity;
                }
            }

            $availableQty = $line->resolution === PosReturnLine::RESOLUTION_CASH_RETURN
                ? $guard->getRefundableQuantity($line->sale_detail_id, $posReturn->id, $componentOriginalQuantity)
                : $guard->getReturnableQuantity($line->dispatch_detail_id, $line->sale_detail_id, $posReturn->id);

            if ((float) $line->quantity > $availableQty) {
                $blockers[] = $this->message(
                    'quantity_exceeded',
                    "Kuantitas retur melebihi batas yang diizinkan untuk produk {$saleDetail->product_name}.",
                    [
                        'pos_return_line_id' => $line->id,
                        'requested_quantity' => (float) $line->quantity,
                        'available_quantity' => $availableQty,
                    ]
                );
            }
        }

        // Policy: refundability follows the customer-facing bundle, so a
        // component cash_return line still requires its parent to be present
        // in the same POS return — but Phase 2's synthesizeBundleCashReturnComponents()
        // already guarantees this at draft-submission time for every
        // synthesized component, so this is a defensive re-check, not a live
        // blocker path for normal flows. Replaceability follows the physical
        // product: a component product_replacement line is independently
        // valid and must NOT require a parent line to exist.
        if ($this->isBundleComponentLine($line)
            && $line->resolution === PosReturnLine::RESOLUTION_CASH_RETURN
            && ! $this->hasBundleParentLine($posReturn, $line)) {
            $blockers[] = $this->message(
                'bundle_parent_missing',
                'Komponen bundle tidak dapat dieksekusi tanpa baris parent bundle pada retur POS yang sama.',
                [
                    'pos_return_line_id' => $line->id,
                    'bundle_group_key' => $line->bundle_group_key,
                    'bundle_parent_sale_detail_id' => $line->bundle_parent_sale_detail_id,
                ]
            );

            return ['blockers' => $blockers, 'warnings' => $warnings, 'info' => $info, 'entries' => []];
        }

        $isBundleParentLine = ! $this->isBundleComponentLine($line)
            && ($this->isBundledSourceLine($line, $saleDetail)
                || (int) $line->bundle_parent_sale_detail_id === (int) $line->sale_detail_id
                || ! empty($line->bundle_group_key));

        if ($isBundleParentLine && $line->resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
            if (empty($line->bundle_return_scope)) {
                $blockers[] = $this->message(
                    'bundle_scope_missing',
                    'Lingkup retur bundel (seluruh paket atau produk utama saja) belum ditentukan. Draft perlu ditolak/diedit untuk memilih lingkup sebelum disetujui.',
                    [
                        'pos_return_line_id' => $line->id,
                        'sale_detail_id' => $line->sale_detail_id,
                    ]
                );
            } elseif (! in_array($line->bundle_return_scope, [PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE, PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY], true)) {
                $blockers[] = $this->message(
                    'bundle_scope_invalid',
                    "Nilai lingkup retur bundel '{$line->bundle_return_scope}' tidak valid.",
                    [
                        'pos_return_line_id' => $line->id,
                        'bundle_return_scope' => $line->bundle_return_scope,
                    ]
                );
            }
        }

        $bundleScopeResult = [
            'included_components' => [],
            'retained_components' => [],
            'retained_charges_total' => 0.0,
        ];

        if ($isBundleParentLine
            && $line->resolution === PosReturnLine::RESOLUTION_CASH_RETURN
            && in_array($line->bundle_return_scope, [PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE, PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY], true)) {
            $bundleScopeResult = $this->resolveBundleScopeComposition(
                $posReturn,
                $line,
                $saleDetail,
                $planningContext
            );
            $blockers = array_merge($blockers, $bundleScopeResult['blockers']);
        }

        $checkoutSale = $checkoutSales->get((int) $line->sale_id);
        if (! $checkoutSale) {
            $blockers[] = $this->message('checkout_sale_missing', 'Checkout sale sumber tidak ditemukan untuk baris retur ini.', [
                'pos_return_line_id' => $line->id,
                'sale_id' => $line->sale_id,
            ]);

            return ['blockers' => $blockers, 'warnings' => $warnings, 'info' => $info, 'entries' => []];
        }

        // 3.1/3.3: a non-serial product_replacement line is note-only —
        // nothing physical will ever move for it, so a missing/unresolved
        // dispatch target must never block preview. Skip dispatch resolution
        // entirely for note-only lines rather than resolving then discarding
        // any blocker it would have produced.
        $physicalExecutionMode = (string) data_get($line->line_meta, 'execution_mode', '');
        $isNoteOnlyReplacement = $line->resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
            && $physicalExecutionMode === 'non_serial_note_only';

        if ($isNoteOnlyReplacement) {
            $dispatchResolution = ['dispatch_detail' => null, 'source' => 'not_applicable_note_only', 'blockers' => [], 'info' => []];
        } else {
            $dispatchResolution = $this->resolveDispatchDetail($line, $saleDetail);
        }
        $blockers = array_merge($blockers, $dispatchResolution['blockers']);
        $info = array_merge($info, $dispatchResolution['info']);
        $dispatchDetail = $dispatchResolution['dispatch_detail'];

        if ($line->returned_serial_id) {
            $returnedSerial = $line->returnedSerial;

            if (! $returnedSerial) {
                $blockers[] = $this->message('returned_serial_missing', 'Serial yang diretur tidak ditemukan.', [
                    'pos_return_line_id' => $line->id,
                    'returned_serial_id' => $line->returned_serial_id,
                ]);
            } elseif ((int) $returnedSerial->product_id !== (int) $line->product_id) {
                $blockers[] = $this->message('returned_serial_product_mismatch', 'Serial yang diretur tidak sesuai dengan SKU baris retur.', [
                    'pos_return_line_id' => $line->id,
                    'returned_serial_id' => $line->returned_serial_id,
                ]);
            } else {
                if ($line->dispatch_detail_id) {
                    $originalSerialIds = app(\Modules\Pos\Services\PosReturnSubmissionService::class)->resolveOriginalSerialIdsForDispatch((int) $line->dispatch_detail_id);
                    if (! empty($originalSerialIds)) {
                        $heldSerialIds = [];
                        foreach ($originalSerialIds as $origId) {
                            try {
                                $heldSerialIds[] = $this->serialChainResolver->resolveCurrentHeldSerial(
                                    (int) $origId,
                                    $posReturn->pos_checkout_id,
                                    $posReturn->pos_transaction_id,
                                    (int) $line->dispatch_detail_id
                                );
                            } catch (\Exception $e) {
                            }
                        }
                        if (! empty($heldSerialIds) && ! in_array((int) $line->returned_serial_id, $heldSerialIds, true)) {
                            $blockers[] = $this->message('serial_already_replaced', 'Serial yang diretur sudah diganti oleh serial lain dan tidak lagi dipegang oleh pelanggan.', [
                                'pos_return_line_id' => $line->id,
                                'returned_serial_id' => $line->returned_serial_id,
                            ]);
                        }
                    }
                }

                if ($line->resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                    if ($this->serialChainResolver->hasInFlightReplacement((int) $line->returned_serial_id, null, $posReturn->pos_checkout_id, (int) $line->dispatch_detail_id)) {
                        $blockers[] = $this->message(
                            'component_replacement_in_flight',
                            'Komponen bertipe serial memiliki penggantian yang belum selesai (belum dikirim ke pelanggan) — retur seluruh paket bundel tidak dapat diproses sampai penggantian tersebut selesai.',
                            [
                                'pos_return_line_id' => $line->id,
                                'returned_serial_id' => $line->returned_serial_id,
                            ]
                        );
                    }
                }
            }
        }

        // A non-serial (note-only) product_replacement never carries a
        // replacement_serial_id by design (2.5) — it has no replacement
        // inventory identity to validate, so this guard only applies to
        // serial-tracked replacement lines.
        if ($line->resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT && ! $isNoteOnlyReplacement) {
            if (! $line->replacementSerial) {
                $blockers[] = $this->message('replacement_serial_missing', 'Serial pengganti belum tersedia untuk baris product replacement.', [
                    'pos_return_line_id' => $line->id,
                ]);
            } else {
                try {
                    $this->replacementGuard->validateReplacementSerial(
                        (int) $line->product_id,
                        (int) $line->replacement_serial_id,
                        $line->returned_serial_id ? (int) $line->returned_serial_id : null,
                        $posReturn->id,
                    );
                } catch (\Throwable $throwable) {
                    $blockers[] = $this->message('replacement_serial_invalid', $throwable->getMessage(), [
                        'pos_return_line_id' => $line->id,
                        'replacement_serial_id' => $line->replacement_serial_id,
                    ]);
                }
            }
        }

        $sourceSettingId = (int) ($line->source_setting_id ?: $checkoutSale->source_setting_id);
        $sourceLocationId = (int) ($dispatchDetail?->location_id ?: $line->source_location_id ?: $checkoutSale->source_location_id);
        $taxId = $line->tax_id ?? $dispatchDetail?->tax_id ?? $saleDetail->tax_id;
        $replacementPreview = $this->buildReplacementPreviewContext(
            $posReturn,
            $line,
            $sale,
            $saleDetail,
            $sourceSettingId,
            $sourceLocationId,
        );
        $blockers = array_merge($blockers, $replacementPreview['blockers']);

        // Bundled product replacements use the source sale detail commercial amount
        // because the POS return snapshot may still carry the original bundle list price.
        $detailAmount = ($line->resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
            ? $this->resolveReplacementCommercialAmount($line, $saleDetail)
            : (float) $line->line_total;

        // 3.1: a component line (real, persisted PosReturnLine created by
        // Phase 2's synthesizeBundleCashReturnComponents()/
        // synthesizeSerialComponentLines()) is now indistinguishable in shape
        // from any other PosReturnLine — it flows through this SAME
        // buildLinePlan() path, no separate re-derivation needed. Detect it
        // purely from persisted identity columns.
        $isComponentLine = $this->isBundleComponentLine($line);
        $componentBundleItem = $isComponentLine
            ? $this->resolveOwnBundleItem($line, $planningContext)
            : null;

        $plannedCashAmount = $planningContext['planned_cash_amounts'][$line->id] ?? null;

        if ($plannedCashAmount !== null) {
            $detailAmount = (float) $plannedCashAmount;
        } elseif ($isComponentLine && $componentBundleItem && (float) ($componentBundleItem->price ?? 0) > 0) {
            $detailAmount = round((float) $componentBundleItem->price * (float) $line->quantity, 2);
        } elseif ($isBundleParentLine
            && $line->resolution === PosReturnLine::RESOLUTION_CASH_RETURN
            && $line->bundle_return_scope === PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE
            && (float) $saleDetail->unit_price > 0
            && ((float) $saleDetail->unit_price * (float) $line->quantity) < (float) $line->line_total) {
            $detailAmount = round((float) $saleDetail->unit_price * (float) $line->quantity, 2);
        } else {
            $detailAmount = (float) ($line->expected_cash_amount ?? $line->line_total ?? 0);
        }

        // 3.3/note-only zero-effects: for a non-serial replacement, nothing
        // physical will ever move — override the otherwise dispatch/serial-
        // oriented intents with explicit "no movement, note only" values, and
        // force cash_return_amount to 0 (already true for replacement lines;
        // asserted here defensively) so no HPP/refund/movement effect is ever
        // implied for this row.
        $stockMovementIntent = $isNoteOnlyReplacement
            ? 'tidak_ada_mutasi_stok_catatan_saja'
            : $replacementPreview['stock_movement_intent'];
        $serialMovementIntent = $isNoteOnlyReplacement
            ? 'tidak_ada_mutasi_serial_catatan_saja'
            : $replacementPreview['serial_movement_intent'];

        $detail = [
            'row_type' => $isComponentLine ? 'component' : 'parent',
            'pos_return_line_id' => (int) $line->id,
            'resolution' => (string) $line->resolution,
            'resolution_label' => $this->resolutionLabel((string) $line->resolution),
            'bundle_return_scope' => $line->bundle_return_scope,
            'bundle_return_scope_label' => $this->bundleScopeLabel($line->bundle_return_scope),
            'included_components' => $bundleScopeResult['included_components'] ?? [],
            'retained_components' => $bundleScopeResult['retained_components'] ?? [],
            'retained_charges_total' => (float) ($bundleScopeResult['retained_charges_total'] ?? 0),
            'sale_detail_id' => (int) $line->sale_detail_id,
            'dispatch_detail_id' => $dispatchDetail?->id,
            'dispatch_resolution' => $dispatchResolution['source'],
            'source_setting_id' => $sourceSettingId,
            'source_setting_name' => $this->settingName($sourceSettingId),
            'source_location_id' => $sourceLocationId,
            'source_location_name' => $this->locationName($sourceLocationId),
            'tax_id' => $taxId,
            'tax_name' => $this->taxName($taxId),
            'product_id' => (int) $line->product_id,
            'product_name' => (string) $line->product_name,
            'product_code' => (string) $line->product_code,
            'quantity' => (float) $line->quantity,
            'amount' => $detailAmount,
            // 3.4: component allocations remain internal and never appear as
            // a separate customer-facing refund — expected_cash_amount is
            // already persisted as 0 for every synthesized component line
            // (Phase 2), and 0 for every product_replacement line. Only a
            // whole-bundle parent cash_return line carries the real amount.
            'cash_return_amount' => $line->resolution === PosReturnLine::RESOLUTION_CASH_RETURN ? $detailAmount : 0.0,
            'returned_serial' => $line->returnedSerial?->serial_number,
            'replacement_serial' => $line->replacementSerial?->serial_number,
            'replacement_serial_owner_setting_id' => $replacementPreview['replacement_serial_owner_setting_id'],
            'replacement_serial_owner_setting_name' => $replacementPreview['replacement_serial_owner_setting_name'],
            'replacement_serial_location_id' => $replacementPreview['replacement_serial_location_id'],
            'replacement_serial_location_name' => $replacementPreview['replacement_serial_location_name'],
            // Naming-conflict resolution (see design note in class docblock):
            // `execution_mode` keeps its existing meaning of same-owner vs
            // cross-owner SERIAL replacement routing (only ever set when
            // resolution === product_replacement AND a replacement_serial_id
            // is present). `replacement_kind` is a NEW field carrying Phase
            // 2's line_meta.execution_mode verbatim
            // (serial_inventory_replacement | non_serial_note_only), i.e.
            // "what physically happens to this replacement line" — the two
            // concepts are never conflated.
            'execution_mode' => $replacementPreview['execution_mode'],
            'execution_mode_label' => $replacementPreview['execution_mode_label'],
            'replacement_kind' => $physicalExecutionMode !== '' ? $physicalExecutionMode : null,
            'original_sale_correction_quantity' => $replacementPreview['original_sale_correction_quantity'],
            'original_sale_correction_amount' => $replacementPreview['original_sale_correction_amount'],
            'generated_replacement_sale_effects' => $replacementPreview['generated_replacement_sale_effects'],
            'stock_movement_intent' => $stockMovementIntent,
            'serial_movement_intent' => $serialMovementIntent,
            'replacement_effect' => $isNoteOnlyReplacement
                ? 'catatan_audit_saja_tanpa_mutasi_fisik'
                : $replacementPreview['replacement_effect'],
            'bundle_trace' => collect(data_get($line->line_meta, 'bundle_trace', []))->values()->all(),
            'source_pos_product_id' => (int) $line->product_id,
            'source_pos_product_name' => (string) $line->product_name,
            'source_pos_product_code' => (string) $line->product_code,
            'source_pos_sale_detail_id' => (int) $line->sale_detail_id,
            'component_sale_bundle_item_id' => $componentBundleItem?->id,
            'component_line_group_key' => $componentBundleItem?->line_group_key,
            'component_bundle_id' => $componentBundleItem ? (int) $componentBundleItem->bundle_id : null,
            'component_quantity_per_bundle' => $line->component_quantity_per_bundle,
            'component_serial_ids' => $line->returned_serial_id ? [(int) $line->returned_serial_id] : [],
            'component_serial_numbers' => $line->returnedSerial?->serial_number
                ? [(string) $line->returnedSerial->serial_number]
                : [],
        ];

        $entries = [[
            'group' => $this->makePlannedGroup(
                $sale,
                $sourceSettingId,
                $sourceLocationId,
                $taxId,
                $posReturn->saleReturns->where('sale_id', $line->sale_id)->pluck('reference')->filter()->values()->all()
            ),
            'detail' => $detail,
        ]];

        // 3.3: surface a clear, once-per-note-only-line instruction that
        // physical exchange/breakage handling is manual — nothing here is
        // automated.
        if ($isNoteOnlyReplacement) {
            $info[] = $this->message(
                'note_only_manual_exchange_required',
                'Penggantian ini tercatat sebagai catatan audit saja; staf harus menangani pertukaran fisik dan pencatatan kerusakan secara manual.',
                [
                    'pos_return_line_id' => $line->id,
                    'product_id' => $line->product_id,
                ]
            );
        } elseif ($line->resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
            && ! $isComponentLine
            && collect(data_get($line->line_meta, 'bundle_trace', []))->isNotEmpty()) {
            $info[] = $this->message(
                'replacement_bundle_components_informational',
                'Komponen bundle hanya menjadi konteks informasi untuk product replacement; mutasi retur dan dispatch pengganti dijalankan pada produk parent saja.',
                [
                    'pos_return_line_id' => $line->id,
                    'component_count' => collect(data_get($line->line_meta, 'bundle_trace', []))->count(),
                ]
            );
        }

        return [
            'blockers' => $blockers,
            'warnings' => $warnings,
            'info' => $info,
            'entries' => $entries,
        ];
    }

    private function resolveBundleScopeComposition(
        PosReturn $posReturn,
        PosReturnLine $line,
        SaleDetails $saleDetail,
        array $planningContext
    ): array {
        $blockers = [];
        $includedComponents = [];
        $retainedComponents = [];
        $retainedChargesTotal = 0.0;

        /** @var Collection<int, PosCheckoutSale> $checkoutSales */
        $checkoutSales = $planningContext['checkout_sales'];
        /** @var Collection<int, Sale> $sales */
        $sales = $planningContext['sales'];
        /** @var Collection<int, SaleBundleItem> $componentBundleItems */
        $componentBundleItems = $planningContext['component_bundle_items'];

        $bundleContext = $this->bundleComponentResolver->resolveOriginalBundleContext(
            $saleDetail,
            $checkoutSales,
            $posReturn->posTransaction
        );

        if (! $bundleContext || empty($bundleContext['is_bundle'])) {
            return [
                'blockers' => $blockers,
                'included_components' => $includedComponents,
                'retained_components' => $retainedComponents,
                'retained_charges_total' => $retainedChargesTotal,
            ];
        }

        if (! empty($bundleContext['is_ambiguous'])) {
            $blockers[] = $this->message(
                'bundle_lineage_unresolved',
                (string) ($bundleContext['error'] ?? "Identitas paket bundel {$saleDetail->product_name} tidak dapat dipastikan."),
                [
                    'pos_return_line_id' => $line->id,
                    'sale_detail_id' => $saleDetail->id,
                    'renewed_review_required' => true,
                ]
            );

            return [
                'blockers' => $blockers,
                'included_components' => $includedComponents,
                'retained_components' => $retainedComponents,
                'retained_charges_total' => $retainedChargesTotal,
            ];
        }

        $allComponents = collect($bundleContext['components'] ?? []);
        $actionableLines = $posReturn->lines->filter(fn (PosReturnLine $l) => $l->resolution !== PosReturnLine::RESOLUTION_NONE);

        foreach ($allComponents as $comp) {
            $compBundleItem = $componentBundleItems->first(function (SaleBundleItem $sbi) use ($comp) {
                if (! empty($comp['sale_bundle_item_id'])) {
                    return (int) $sbi->id === (int) $comp['sale_bundle_item_id'];
                }
                return (int) $sbi->sale_id === (int) $comp['sale_id']
                    && (int) $sbi->product_id === (int) $comp['product_id'];
            });

            $compQty = (float) ($comp['quantity_per_bundle'] ?? 1) * (float) $line->quantity;
            $unitPrice = (float) ($compBundleItem?->price ?? 0);
            $allocatedAmount = round($compQty * $unitPrice, 2);

            $compSaleId = (int) ($comp['sale_id'] ?? 0);
            $compCheckoutSale = $checkoutSales->get($compSaleId);
            $compSale = $sales->get($compSaleId);

            $compSettingId = (int) ($compCheckoutSale?->source_setting_id ?? $compSale?->setting_id ?? 0);
            $compLocationId = (int) ($compCheckoutSale?->source_location_id ?? $compSale?->location_id ?? 0);

            $compEntry = [
                'product_id' => (int) $comp['product_id'],
                'product_name' => (string) ($comp['product_name'] ?? ''),
                'product_code' => (string) ($comp['product_code'] ?? ''),
                'quantity' => $compQty,
                'allocated_amount' => $allocatedAmount,
                'owner_setting_id' => $compSettingId,
                'owner_setting_name' => $this->settingName($compSettingId),
                'owner_location_id' => $compLocationId,
                'owner_location_name' => $this->locationName($compLocationId),
                'sale_id' => $compSaleId,
                'sale_reference' => (string) ($compSale?->reference ?? $compCheckoutSale?->sale?->reference ?? ''),
                'sale_bundle_item_id' => $compBundleItem?->id ?? $comp['sale_bundle_item_id'] ?? null,
            ];

            if ($line->bundle_return_scope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY) {
                $retainedComponents[] = $compEntry;
                $retainedChargesTotal += $allocatedAmount;
            } else {
                $includedComponents[] = $compEntry;
            }
        }

        if ($line->bundle_return_scope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY) {
            $extraneousLines = $actionableLines->filter(function (PosReturnLine $candidate) use ($line) {
                if ((int) $candidate->id === (int) $line->id) {
                    return false;
                }
                return (int) ($candidate->bundle_parent_sale_detail_id ?? 0) === (int) $line->sale_detail_id
                    || (! empty($line->bundle_group_key) && $candidate->bundle_group_key === $line->bundle_group_key);
            });

            if ($extraneousLines->isNotEmpty()) {
                $blockers[] = $this->message(
                    'bundle_component_extraneous',
                    'Baris komponen tidak boleh disertakan saat lingkup retur dipilih hanya produk utama.',
                    [
                        'pos_return_line_id' => $line->id,
                        'extraneous_line_ids' => $extraneousLines->pluck('id')->all(),
                    ]
                );
            }
        } elseif ($line->bundle_return_scope === PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE) {
            foreach ($allComponents as $comp) {
                if (($comp['lineage_status'] ?? 'resolved') !== 'resolved') {
                    $blockers[] = $this->message(
                        'bundle_component_allocation_unresolved',
                        $this->bundleComponentResolver->describeUnresolvedComponent($comp, $saleDetail->product_name),
                        [
                            'pos_return_line_id' => $line->id,
                            'product_id' => (int) $comp['product_id'],
                            'lineage_status' => $comp['lineage_status'],
                        ]
                    );

                    continue;
                }

                $compQty = (float) ($comp['quantity_per_bundle'] ?? 1) * (float) $line->quantity;
                $matchingLine = $actionableLines->first(function (PosReturnLine $candidate) use ($line, $comp) {
                    if ((int) $candidate->id === (int) $line->id) {
                        return false;
                    }
                    $matchesParent = (int) ($candidate->bundle_parent_sale_detail_id ?? 0) === (int) $line->sale_detail_id
                        || (! empty($line->bundle_group_key) && $candidate->bundle_group_key === $line->bundle_group_key);
                    return $matchesParent && (int) $candidate->product_id === (int) $comp['product_id'];
                });

                if (! $matchingLine || (float) $matchingLine->quantity < $compQty) {
                    $blockers[] = $this->message(
                        'bundle_component_missing',
                        "Komponen bundel '{$comp['product_name']}' belum lengkap dalam permohonan retur seluruh paket.",
                        [
                            'pos_return_line_id' => $line->id,
                            'product_id' => (int) $comp['product_id'],
                            'expected_quantity' => $compQty,
                            'actual_quantity' => (float) ($matchingLine?->quantity ?? 0),
                        ]
                    );
                }
            }
        }

        return [
            'blockers' => $blockers,
            'included_components' => $includedComponents,
            'retained_components' => $retainedComponents,
            'retained_charges_total' => round($retainedChargesTotal, 2),
        ];
    }

    private function bundleScopeLabel(?string $scope): ?string
    {
        return match ($scope) {
            PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE => 'Seluruh Paket',
            PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY => 'Produk Utama Saja',
            default => null,
        };
    }

    /**
     * 3.2: resolve the component's OWN SaleBundleItem row (for display
     * fields only — component_sale_bundle_item_id/line_group_key/bundle_id —
     * never used to re-derive quantity/identity, which already lives on the
     * persisted PosReturnLine itself). Matched by sale_id + product_id +
     * sale_detail_id when available, mirroring the same convention Phase 2's
     * synthesis and the snapshot service already use.
     */
    private function resolveOwnBundleItem(PosReturnLine $line, array $planningContext): ?SaleBundleItem
    {
        /** @var Collection<int, SaleBundleItem> $componentBundleItems */
        $componentBundleItems = $planningContext['component_bundle_items'] ?? null;
        if (! $componentBundleItems) {
            $componentBundleItems = \Modules\Sale\Entities\SaleBundleItem::query()
                ->where('sale_id', (int) $line->sale_id)
                ->where('product_id', (int) $line->product_id)
                ->get();
        }

        if (! empty($line->line_meta['sale_bundle_item_id'])) {
            $byMeta = $componentBundleItems->firstWhere('id', (int) $line->line_meta['sale_bundle_item_id']);
            if ($byMeta) {
                return $byMeta;
            }
        }

        $matches = $componentBundleItems->filter(function (SaleBundleItem $item) use ($line) {
            if ((int) $item->sale_id !== (int) $line->sale_id) {
                return false;
            }

            if ((int) $item->product_id !== (int) $line->product_id) {
                return false;
            }

            if ($item->sale_detail_id
                && (int) $item->sale_detail_id !== (int) $line->sale_detail_id
                && (int) $item->sale_detail_id !== (int) $line->bundle_parent_sale_detail_id) {
                return false;
            }

            return true;
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function buildReplacementPreviewContext(
        PosReturn $posReturn,
        PosReturnLine $line,
        Sale $sale,
        SaleDetails $saleDetail,
        int $sourceSettingId,
        int $sourceLocationId,
    ): array {
        $default = [
            'blockers' => [],
            'replacement_serial_owner_setting_id' => null,
            'replacement_serial_owner_setting_name' => null,
            'replacement_serial_location_id' => null,
            'replacement_serial_location_name' => null,
            'execution_mode' => null,
            'execution_mode_label' => null,
            'original_sale_correction_quantity' => null,
            'original_sale_correction_amount' => null,
            'generated_replacement_sale_effects' => null,
            'stock_movement_intent' => $this->stockMovementIntent($line),
            'serial_movement_intent' => $this->serialMovementIntent($line),
            'replacement_effect' => $line->resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                ? 'serial_pengganti_akan_dikirim_pada_fase_dispatch'
                : null,
        ];

        if ($line->resolution !== PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT || ! $line->replacement_serial_id) {
            return $default;
        }

        $resolved = $this->replacementGuard->resolveReplacementSerialContext((int) $line->replacement_serial_id);
        $replacementOwnerSettingId = isset($resolved['owner_setting_id']) ? (int) $resolved['owner_setting_id'] : 0;
        $replacementLocationId = isset($resolved['location_id']) ? (int) $resolved['location_id'] : 0;

        if ($replacementOwnerSettingId <= 0 || $replacementLocationId <= 0) {
            return $default;
        }

        $executionMode = $replacementOwnerSettingId === $sourceSettingId
            ? 'same_owner_replacement'
            : 'cross_owner_replacement';

        // Use canonical commercial amount from source sale detail for bundled replacement lines.
        $canonicalAmount = $this->resolveReplacementCommercialAmount($line, $saleDetail);

        $generatedSaleEffects = null;
        $blockers = [];

        if ($executionMode === 'cross_owner_replacement') {
            try {
                $customerResolution = $this->groupCustomerResolver->resolve(
                    (int) $posReturn->setting_id,
                    $replacementOwnerSettingId,
                    $sale->customer_id ? (int) $sale->customer_id : null,
                );

                $generatedSaleEffects = [
                    'setting_id' => $replacementOwnerSettingId,
                    'setting_name' => $this->settingName($replacementOwnerSettingId),
                    'location_id' => $replacementLocationId,
                    'location_name' => $this->locationName($replacementLocationId),
                    'sale_reference' => 'generated_on_approval',
                    'customer_id' => (int) ($customerResolution['customer_id'] ?? 0),
                    'customer_resolution_source' => (string) ($customerResolution['resolution_source'] ?? 'unknown'),
                    'payment_amount' => $canonicalAmount,
                    'dispatch_quantity' => (float) $line->quantity,
                ];
            } catch (\Throwable $throwable) {
                $blockers[] = $this->message(
                    'replacement_sale_prerequisite_missing',
                    'Generated Sale owner pengganti belum dapat direncanakan karena customer target belum dapat dipetakan.',
                    [
                        'pos_return_line_id' => $line->id,
                        'replacement_owner_setting_id' => $replacementOwnerSettingId,
                        'replacement_location_id' => $replacementLocationId,
                    ]
                );
            }
        }

        return [
            'blockers' => $blockers,
            'replacement_serial_owner_setting_id' => $replacementOwnerSettingId,
            'replacement_serial_owner_setting_name' => $this->settingName($replacementOwnerSettingId),
            'replacement_serial_location_id' => $replacementLocationId,
            'replacement_serial_location_name' => $this->locationName($replacementLocationId),
            'execution_mode' => $executionMode,
            'execution_mode_label' => $this->executionModeLabel($executionMode),
            'original_sale_correction_quantity' => $executionMode === 'cross_owner_replacement' ? (float) $line->quantity : null,
            'original_sale_correction_amount' => $executionMode === 'cross_owner_replacement' ? $canonicalAmount : null,
            'generated_replacement_sale_effects' => $generatedSaleEffects,
            'stock_movement_intent' => $executionMode === 'cross_owner_replacement'
                ? 'stok_retur_kembali_ke_owner_asal_dan_serial_pengganti_keluar_dari_owner_pengganti'
                : $this->stockMovementIntent($line),
            'serial_movement_intent' => $executionMode === 'cross_owner_replacement'
                ? 'serial_retur_kembali_ke_sale_asal_dan_serial_pengganti_dikirim_dari_owner_pengganti'
                : $this->serialMovementIntent($line),
            'replacement_effect' => $executionMode === 'cross_owner_replacement'
                ? 'sale_asal_dikoreksi_dan_sale_owner_pengganti_akan_dibuat_saat_approval'
                : 'serial_pengganti_akan_dikirim_pada_fase_dispatch',
        ];
    }

    private function makePlannedGroup(Sale $sale, int $sourceSettingId, int $sourceLocationId, ?int $taxId, array $linkedSaleReturnReferences): array
    {
        return [
            'key' => $this->groupKey((int) $sale->id, $sourceSettingId, $sourceLocationId, $taxId),
            'source_sale' => [
                'id' => (int) $sale->id,
                'reference' => (string) $sale->reference,
                'status' => (string) $sale->status,
            ],
            'source_owner' => [
                'setting_id' => $sourceSettingId,
                'name' => $this->settingName($sourceSettingId),
            ],
            'source_location' => [
                'location_id' => $sourceLocationId,
                'name' => $this->locationName($sourceLocationId),
            ],
            'tax_context' => [
                'tax_id' => $taxId,
                'tax_name' => $this->taxName($taxId),
            ],
            'linked_sale_return_references' => array_values(array_unique($linkedSaleReturnReferences)),
            'planned_header' => [
                'sale_id' => (int) $sale->id,
                'sale_reference' => (string) $sale->reference,
                'setting_id' => $sourceSettingId,
                'setting_name' => $this->settingName($sourceSettingId),
                'location_id' => $sourceLocationId,
                'location_name' => $this->locationName($sourceLocationId),
                'return_type' => null,
                'line_count' => 0,
                'parent_line_count' => 0,
                'component_line_count' => 0,
                'cash_return_line_count' => 0,
                'product_replacement_line_count' => 0,
                'resolution_labels' => [],
                'total_amount' => 0.0,
                'cash_return_total' => 0.0,
            ],
            'planned_details' => [],
        ];
    }

    private function addPlannedEntryToGroups(array &$groups, array $entry): void
    {
        $groupKey = $entry['group']['key'];

        if (! isset($groups[$groupKey])) {
            $groups[$groupKey] = $entry['group'];
            unset($groups[$groupKey]['key']);
        }

        $detail = $entry['detail'];
        $groups[$groupKey]['planned_header']['total_amount'] += (float) ($detail['amount'] ?? 0);
        $groups[$groupKey]['planned_header']['cash_return_total'] += (float) ($detail['cash_return_amount'] ?? 0);
        $groups[$groupKey]['planned_header']['line_count']++;

        if (($detail['row_type'] ?? 'parent') === 'component') {
            $groups[$groupKey]['planned_header']['component_line_count']++;
        } else {
            $groups[$groupKey]['planned_header']['parent_line_count']++;
        }

        if (($detail['resolution'] ?? null) === PosReturnLine::RESOLUTION_CASH_RETURN) {
            $groups[$groupKey]['planned_header']['cash_return_line_count']++;
        }

        if (($detail['resolution'] ?? null) === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
            $groups[$groupKey]['planned_header']['product_replacement_line_count']++;
        }

        $groups[$groupKey]['planned_header']['resolution_labels'] = array_values(array_unique(array_merge(
            $groups[$groupKey]['planned_header']['resolution_labels'],
            [
                (string) ($detail['resolution_label'] ?? $this->resolutionLabel((string) ($detail['resolution'] ?? ''))),
            ]
        )));

        $groups[$groupKey]['planned_header']['return_type'] = match (true) {
            $groups[$groupKey]['planned_header']['cash_return_line_count'] > 0
                && $groups[$groupKey]['planned_header']['product_replacement_line_count'] > 0 => 'mixed',
            $groups[$groupKey]['planned_header']['product_replacement_line_count'] > 0 => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            default => PosReturnLine::RESOLUTION_CASH_RETURN,
        };

        $groups[$groupKey]['planned_details'][] = $detail;
        $groups[$groupKey]['linked_sale_return_references'] = array_values(array_unique(array_merge(
            $groups[$groupKey]['linked_sale_return_references'],
            $entry['group']['linked_sale_return_references']
        )));
    }

    private function groupKey(int $saleId, int $sourceSettingId, int $sourceLocationId, ?int $taxId): string
    {
        return implode(':', [
            $saleId,
            $sourceSettingId,
            $sourceLocationId,
            $taxId ?? 'none',
        ]);
    }

    private function resolveDispatchDetail(PosReturnLine $line, SaleDetails $saleDetail): array
    {
        $blockers = [];
        $info = [];

        if ($line->stock_behavior === PosReturnLine::STOCK_BEHAVIOR_STOCKLESS) {
            return [
                'dispatch_detail' => null,
                'source' => 'stockless',
                'blockers' => [],
                'info' => [],
            ];
        }

        if ($line->returnedSerial instanceof ProductSerialNumber && $line->returnedSerial->dispatch_detail_id) {
            $dispatchDetail = DispatchDetail::query()->find($line->returnedSerial->dispatch_detail_id);
            if (! $dispatchDetail) {
                $blockers[] = $this->message('dispatch_missing', 'Dispatch detail sumber dari serial yang diretur tidak ditemukan.', [
                    'pos_return_line_id' => $line->id,
                    'dispatch_detail_id' => $line->returnedSerial->dispatch_detail_id,
                ]);
            } elseif (! $this->dispatchMatchesLine($dispatchDetail, $line)) {
                $blockers[] = $this->message('dispatch_context_mismatch', 'Dispatch detail dari serial yang diretur tidak cocok dengan sale atau produk sumber.', [
                    'pos_return_line_id' => $line->id,
                    'dispatch_detail_id' => $dispatchDetail->id,
                ]);
            } else {
                $info[] = $this->message('dispatch_resolved_from_serial', 'Dispatch detail ditentukan dari product_serial_numbers.dispatch_detail_id.', [
                    'pos_return_line_id' => $line->id,
                    'dispatch_detail_id' => $dispatchDetail->id,
                ]);

                return [
                    'dispatch_detail' => $dispatchDetail,
                    'source' => 'returned_serial.dispatch_detail_id',
                    'blockers' => $blockers,
                    'info' => $info,
                ];
            }
        }

        if ($line->dispatch_detail_id) {
            $dispatchDetail = DispatchDetail::query()->find($line->dispatch_detail_id);
            if (! $dispatchDetail) {
                $blockers[] = $this->message('dispatch_missing', 'Dispatch detail tersimpan pada baris retur tidak ditemukan.', [
                    'pos_return_line_id' => $line->id,
                    'dispatch_detail_id' => $line->dispatch_detail_id,
                ]);
            } elseif (! $this->dispatchMatchesLine($dispatchDetail, $line)) {
                $blockers[] = $this->message('dispatch_context_mismatch', 'Dispatch detail tersimpan tidak cocok dengan sale atau produk sumber.', [
                    'pos_return_line_id' => $line->id,
                    'dispatch_detail_id' => $dispatchDetail->id,
                ]);
            } else {
                return [
                    'dispatch_detail' => $dispatchDetail,
                    'source' => 'pos_return_line.dispatch_detail_id',
                    'blockers' => $blockers,
                    'info' => $info,
                ];
            }
        }

        $saleDetailDispatchId = data_get($saleDetail, 'dispatch_detail_id');
        if ($saleDetailDispatchId) {
            $dispatchDetail = DispatchDetail::query()->find($saleDetailDispatchId);
            if ($dispatchDetail && $this->dispatchMatchesLine($dispatchDetail, $line)) {
                return [
                    'dispatch_detail' => $dispatchDetail,
                    'source' => 'sale_detail.dispatch_detail_id',
                    'blockers' => $blockers,
                    'info' => $info,
                ];
            }
        }

        if (Schema::hasColumn('dispatch_details', 'sale_detail_id')) {
            $saleDetailMatches = DispatchDetail::query()
                ->where('sale_id', $line->sale_id)
                ->where('product_id', $line->product_id)
                ->where('sale_detail_id', $line->sale_detail_id)
                ->get();

            if ($saleDetailMatches->count() === 1) {
                return [
                    'dispatch_detail' => $saleDetailMatches->first(),
                    'source' => 'dispatch_details.sale_detail_id',
                    'blockers' => $blockers,
                    'info' => $info,
                ];
            }

            if ($saleDetailMatches->count() > 1) {
                $blockers[] = $this->message('dispatch_ambiguous', 'Terdapat lebih dari satu dispatch detail yang cocok untuk sale detail sumber.', [
                    'pos_return_line_id' => $line->id,
                    'sale_detail_id' => $line->sale_detail_id,
                ]);

                return [
                    'dispatch_detail' => null,
                    'source' => 'dispatch_details.sale_detail_id',
                    'blockers' => $blockers,
                    'info' => $info,
                ];
            }
        }

        $productMatches = DispatchDetail::query()
            ->where('sale_id', $line->sale_id)
            ->where('product_id', $line->product_id)
            ->get();

        if ($productMatches->count() === 1) {
            return [
                'dispatch_detail' => $productMatches->first(),
                'source' => 'dispatch_details.sale_id+product_id',
                'blockers' => $blockers,
                'info' => $info,
            ];
        }

        if ($productMatches->count() > 1) {
            $blockers[] = $this->message('dispatch_ambiguous', 'Dispatch detail untuk produk non-serial masih ambigu dan tidak aman dipilih otomatis.', [
                'pos_return_line_id' => $line->id,
                'sale_id' => $line->sale_id,
                'product_id' => $line->product_id,
            ]);
        } else {
            $blockers[] = $this->message('dispatch_missing', 'Dispatch detail belum dapat ditentukan untuk baris retur stock-managed ini.', [
                'pos_return_line_id' => $line->id,
                'sale_id' => $line->sale_id,
                'product_id' => $line->product_id,
            ]);
        }

        return [
            'dispatch_detail' => null,
            'source' => 'unresolved',
            'blockers' => $blockers,
            'info' => $info,
        ];
    }

    private function dispatchMatchesLine(DispatchDetail $dispatchDetail, PosReturnLine $line): bool
    {
        if ((int) $dispatchDetail->sale_id !== (int) $line->sale_id) {
            return false;
        }

        if ((int) $dispatchDetail->product_id !== (int) $line->product_id) {
            return false;
        }

        if ($dispatchDetail->sale_detail_id && (int) $dispatchDetail->sale_detail_id !== (int) $line->sale_detail_id) {
            return false;
        }

        return true;
    }

    private function isBundleComponentLine(PosReturnLine $line): bool
    {
        $parentSaleDetailId = (int) ($line->bundle_parent_sale_detail_id ?? 0);
        $saleDetailId = (int) ($line->sale_detail_id ?? 0);

        if ($saleDetailId <= 0) {
            return false;
        }

        if ($parentSaleDetailId > 0 && $parentSaleDetailId !== $saleDetailId) {
            return (float) ($line->component_quantity_per_bundle ?? 0) > 0 || (string) ($line->bundle_group_key ?? '') !== '';
        }

        // Sequence 10 correction: an INDEPENDENT component product_replacement
        // (never synthesized from a whole-bundle cash return, so it carries
        // no distinct bundle_parent_sale_detail_id — see store(), which
        // self-references bundle_parent_sale_detail_id = sale_detail_id for
        // any line whose sale_detail_id resolves to a row with bundleItems,
        // including a carrier row acting as its OWN component identity) is
        // still a genuine bundle-component line: its persisted product_id
        // (the component's own, resolved and pinned at submission time) will
        // differ from the CARRIER row's own product_id (the bundle
        // PARENT's). component_quantity_per_bundle being set confirms this
        // was resolved through synthesis/submission identity, not a
        // coincidental self-reference on an ordinary parent line.
        if ((float) ($line->component_quantity_per_bundle ?? 0) > 0) {
            $saleDetail = SaleDetails::find($saleDetailId);

            return $saleDetail && (int) $saleDetail->product_id !== (int) $line->product_id;
        }

        return false;
    }

    private function hasBundleParentLine(PosReturn $posReturn, PosReturnLine $line): bool
    {
        $parentSaleDetailId = (int) ($line->bundle_parent_sale_detail_id ?? 0);
        if ($parentSaleDetailId <= 0) {
            return false;
        }

        return $posReturn->lines->contains(function (PosReturnLine $candidate) use ($line, $parentSaleDetailId) {
            if ((int) $candidate->id === (int) $line->id) {
                return false;
            }

            if ((int) ($candidate->sale_detail_id ?? 0) !== $parentSaleDetailId) {
                return false;
            }

            if ((int) ($candidate->bundle_parent_sale_detail_id ?? 0) !== $parentSaleDetailId) {
                return false;
            }

            if ($this->isBundleComponentLine($candidate)) {
                return false;
            }

            $lineGroupKey = (string) ($line->bundle_group_key ?? '');
            $candidateGroupKey = (string) ($candidate->bundle_group_key ?? '');

            return $lineGroupKey === '' || $candidateGroupKey === '' || $lineGroupKey === $candidateGroupKey;
        });
    }

    private function message(string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'code' => $code,
            'message' => $message,
        ], $extra);
    }

    private function resolutionLabel(string $resolution): string
    {
        return match ($resolution) {
            PosReturnLine::RESOLUTION_CASH_RETURN => 'Cash Return',
            PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT => 'Product Replacement',
            default => 'Tidak Ada Aksi',
        };
    }

    private function executionModeLabel(?string $executionMode): ?string
    {
        return match ($executionMode) {
            'same_owner_replacement' => 'Same-owner replacement',
            'cross_owner_replacement' => 'Cross-owner replacement',
            default => null,
        };
    }

    private function stockMovementIntent(PosReturnLine $line): string
    {
        if ($line->stock_behavior === PosReturnLine::STOCK_BEHAVIOR_STOCKLESS) {
            return 'tidak_ada_mutasi_stok';
        }

        return 'stok_sumber_akan_bertambah_saat_receiving';
    }

    private function serialMovementIntent(PosReturnLine $line): string
    {
        if (! $line->returned_serial_id) {
            return 'tidak_ada_mutasi_serial';
        }

        return $line->resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
            ? 'serial_retur_dilepas_dari_dispatch_dan_serial_pengganti_dikirim_pada_fase_dispatch'
            : 'serial_retur_dilepas_dari_dispatch_saat_receiving';
    }

    private function settingName(?int $settingId): ?string
    {
        if (! $settingId) {
            return null;
        }

        if (! array_key_exists($settingId, $this->settingNames)) {
            $this->settingNames[$settingId] = Setting::query()->find($settingId)?->company_name;
        }

        return $this->settingNames[$settingId];
    }

    private function locationName(?int $locationId): ?string
    {
        if (! $locationId) {
            return null;
        }

        if (! array_key_exists($locationId, $this->locationNames)) {
            $this->locationNames[$locationId] = Location::query()->find($locationId)?->name;
        }

        return $this->locationNames[$locationId];
    }

    private function taxName(?int $taxId): ?string
    {
        if (! $taxId) {
            return null;
        }

        if (! array_key_exists($taxId, $this->taxNames)) {
            $this->taxNames[$taxId] = Tax::query()->find($taxId)?->name;
        }

        return $this->taxNames[$taxId];
    }

    /**
     * Resolve the canonical replacement commercial amount for a bundled return line.
     *
     * For bundled source sale details, the source sale detail's commercial amount
     * (unit_price * returned quantity) is preferred over the POS return snapshot line_total
     * because the snapshot may contain the original POS bundle list price rather than the
     * owner-specific parent residual amount after split decomposition.
     *
     * For non-bundled lines, falls back to the POS return line_total.
     */
    private function resolveReplacementCommercialAmount(PosReturnLine $line, SaleDetails $saleDetail): float
    {
        $lineTotal = (float) $line->line_total;

        if (! $this->isBundledSourceLine($line, $saleDetail)) {
            return $lineTotal;
        }

        $returnedQuantity = (float) $line->quantity;
        $saleDetailUnitPrice = (float) ($saleDetail->unit_price ?? $saleDetail->price ?? 0);

        if ($saleDetailUnitPrice > 0 && $returnedQuantity > 0) {
            $saleDetailCommercialAmount = round($saleDetailUnitPrice * $returnedQuantity, 2);

            if (abs($saleDetailCommercialAmount - $lineTotal) > 0.01) {
                return $saleDetailCommercialAmount;
            }
        }

        return $lineTotal;
    }

    private function isBundledSourceLine(PosReturnLine $line, SaleDetails $saleDetail): bool
    {
        if (collect(data_get($line->line_meta, 'bundle_trace', []))->isNotEmpty()) {
            return true;
        }

        if ((string) ($line->bundle_group_key ?? '') !== '') {
            return true;
        }

        if ($saleDetail->relationLoaded('bundleItems')) {
            return $saleDetail->bundleItems->isNotEmpty();
        }

        return $saleDetail->bundleItems()->exists();
    }

    public function resolveEffectiveUnitFinancialBasis(
        SaleDetails $saleDetail,
        ?PosReturnLine $line = null,
        mixed $checkoutSales = null,
        ?PosReturn $posReturn = null
    ): float {
        if (! $posReturn && $line) {
            if ($line->relationLoaded('posReturn')) {
                $posReturn = $line->posReturn;
            } elseif ($line->pos_return_id) {
                $posReturn = PosReturn::with(['posTransaction.lines'])->find($line->pos_return_id);
            }
        }

        if ($line
            && $line->resolution === PosReturnLine::RESOLUTION_CASH_RETURN
            && $line->bundle_return_scope === PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE
            && ! $this->isBundleComponentLine($line)) {

            $parentBasis = $this->calculateStandardSaleDetailBasis($saleDetail, $checkoutSales, $posReturn, $line);

            $bundleCtx = $this->bundleComponentResolver?->resolveOriginalBundleContext(
                $saleDetail,
                $checkoutSales,
                $posReturn?->posTransaction
            );

            if ($bundleCtx && ! empty($bundleCtx['components'])) {
                $componentsBasisSum = 0.0;
                foreach ($bundleCtx['components'] as $comp) {
                    if ((int) ($comp['sale_id'] ?? 0) === (int) $saleDetail->sale_id) {
                        continue;
                    }
                    $compQtyPerBundle = (float) ($comp['quantity_per_bundle'] ?? 1);
                    $compPrice = 0.0;
                    if (! empty($comp['sale_bundle_item_id'])) {
                        $sbi = SaleBundleItem::find($comp['sale_bundle_item_id']);
                        if ($sbi) {
                            $compPrice = (float) ($sbi->price > 0 ? $sbi->price : ($sbi->quantity > 0 ? $sbi->sub_total / $sbi->quantity : 0));
                        }
                    } elseif (! empty($comp['allocated_price'])) {
                        $compPrice = (float) $comp['allocated_price'];
                    }
                    $componentsBasisSum += ($compPrice * $compQtyPerBundle);
                }

                return round($parentBasis + $componentsBasisSum, 2);
            }

            return $parentBasis;
        }

        return $this->calculateStandardSaleDetailBasis($saleDetail, $checkoutSales, $posReturn, $line);
    }

    public function calculateStandardSaleDetailBasis(
        SaleDetails $saleDetail,
        mixed $checkoutSales = null,
        ?PosReturn $posReturn = null,
        ?PosReturnLine $line = null
    ): float {
        if ($saleDetail->bundleItems->isNotEmpty() && (float) $saleDetail->unit_price == 0.0 && (float) $saleDetail->quantity > 0) {
            $checkoutSale = null;
            if ($checkoutSales instanceof \Illuminate\Support\Collection) {
                $checkoutSale = $checkoutSales->get((int) ($line?->sale_id ?? $saleDetail->sale_id));
            } elseif ($posReturn?->pos_checkout_id) {
                $checkoutSale = PosCheckoutSale::where('pos_checkout_id', $posReturn->pos_checkout_id)
                    ->where('sale_id', $saleDetail->sale_id)
                    ->first();
            }
            if ($checkoutSale) {
                return round((float) $checkoutSale->subtotal / (float) $saleDetail->quantity, 2);
            }
        }

        $qty = (float) $saleDetail->quantity;
        if ($qty <= 0.0001) {
            return (float) $saleDetail->unit_price;
        }

        $sale = $saleDetail->relationLoaded('sale') ? $saleDetail->sale : \Modules\Sale\Entities\Sale::find($saleDetail->sale_id);
        if ($sale && is_array($sale->return_rounding_context) && ! empty($sale->return_rounding_context['commercial_lines'])) {
            $commKey = $line?->pos_transaction_line_id ? 'ptl_' . $line->pos_transaction_line_id : 'detail_' . $saleDetail->id;
            if (isset($sale->return_rounding_context['commercial_lines'][$commKey])) {
                $bLine = $sale->return_rounding_context['commercial_lines'][$commKey];
                $bQty = (float) ($bLine['baseline_quantity'] ?? 0);
                $bAmt = (float) ($bLine['baseline_returnable_amount'] ?? 0);
                if ($bQty > 0 && $bAmt > 0) {
                    return round($bAmt / $bQty, 2);
                }
            }
        }

        $lineSubtotal = (float) ($saleDetail->sub_total ?? 0);

        $globalDiscountShare = 0.0;
        if ($sale && ((float) $sale->discount_amount > 0 || (float) $sale->discount_percentage > 0)) {
            $totalSaleSubtotal = (float) \Modules\Sale\Entities\SaleDetails::query()
                ->where('sale_id', $sale->id)
                ->where('quantity', '>', 0.0001)
                ->sum('sub_total');
            if ($totalSaleSubtotal <= 0) {
                $totalSaleSubtotal = (float) \Modules\Sale\Entities\SaleDetails::query()
                    ->where('sale_id', $sale->id)
                    ->sum('sub_total');
            }

            $globalDiscountAmount = (float) $sale->discount_amount > 0
                ? (float) $sale->discount_amount
                : round($totalSaleSubtotal * ((float) $sale->discount_percentage / 100), 2);

            if ($totalSaleSubtotal > 0) {
                $globalDiscountShare = round($globalDiscountAmount * ($lineSubtotal / $totalSaleSubtotal), 2);
            }
        }

        $effectiveSubtotal = max(0, $lineSubtotal - $globalDiscountShare);

        return round($effectiveSubtotal / $qty, 2);
    }

    public function calculatePlannedCashReturnAmounts(
        PosReturn $posReturn,
        Collection $actionableLines,
        array $planningContext = []
    ): array {
        $this->lastValuationErrors = [];
        $cashLines = $actionableLines->filter(fn (PosReturnLine $l) => (string) $l->resolution === PosReturnLine::RESOLUTION_CASH_RETURN);
        if ($cashLines->isEmpty()) {
            return [];
        }

        $valuationService = $this->roundingValuationService ?? app(PosReturnRoundingValuationService::class);
        $sales = $planningContext['sales'];
        $saleDetails = $planningContext['sale_details'];

        $groupedByCommercial = $cashLines->groupBy(fn (PosReturnLine $line) => $valuationService->resolveCommercialLineKey($line, $cashLines));

        $lineAmounts = [];

        foreach ($groupedByCommercial as $commKey => $linesInComm) {
            $parentLine = $linesInComm->first(fn ($l) => ! $this->isBundleComponentLine($l)) ?? $linesInComm->first();
            $sale = $sales->get((int) $parentLine->sale_id);
            if (! $sale) {
                foreach ($linesInComm as $l) {
                    $lineAmounts[$l->id] = (float) ($l->expected_cash_amount ?? $l->line_total ?? 0);
                }
                continue;
            }

            $ptl = $posReturn->posTransaction?->lines?->firstWhere('id', (int) $parentLine->pos_transaction_line_id)
                ?? $posReturn->posTransaction?->lines?->firstWhere('product_id', (int) $parentLine->product_id);
            $saleDetail = $saleDetails->get((int) $parentLine->sale_detail_id);

            $isParentOnly = ($parentLine->bundle_return_scope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY);
            $effectivePtl = $isParentOnly ? null : $ptl;

            $baselineError = null;
            try {
                $baseline = $valuationService->resolveCommercialBaseline($parentLine, $effectivePtl);
            } catch (\Throwable $e) {
                $baseline = null;
                $baselineError = $e;
            }

            $increment = $valuationService->resolveEffectiveIncrement($sale, $posReturn->posTransaction);
            $lineState = $baseline ? $valuationService->getOrSeedCommercialLineState($sale, $commKey, $baseline) : [];

            $prevQty = (float) ($lineState['committed_quantity'] ?? 0);
            $prevAmt = (float) ($lineState['committed_amount'] ?? 0);

            $requestedQty = (float) $parentLine->quantity;
            $baselineQuantity = $baseline ? (float) $baseline['baseline_quantity'] : $requestedQty;
            $cumulativeQty = min($baselineQuantity, $prevQty + $requestedQty);

            $totalBaselineAmount = $baseline
                ? (float) $baseline['baseline_returnable_amount']
                : (float) $linesInComm->sum(fn ($l) => (float) ($l->expected_cash_amount ?? $l->line_total ?? 0));

            $storedResidual = (float) $linesInComm
                ->groupBy('sale_id')
                ->sum(function (\Illuminate\Support\Collection $saleLines) use ($saleDetails, $planningContext) {
                    $parentInSale = $saleLines->first(fn ($l) => ! $this->isBundleComponentLine($l));
                    $parentAmount = 0.0;
                    if ($parentInSale) {
                        $sd = $saleDetails->get((int) $parentInSale->sale_detail_id);
                        $parentAmount = (float) ($sd?->sub_total ?? 0);
                    }
                    $componentsAmount = (float) $saleLines
                        ->filter(fn ($l) => $this->isBundleComponentLine($l))
                        ->sum(function (PosReturnLine $l) use ($saleDetails, $planningContext) {
                            $sbi = $this->resolveOwnBundleItem($l, $planningContext);
                            if ($sbi) {
                                return (float) ($sbi->sub_total ?? $sbi->price ?? 0);
                            }
                            $sd = $saleDetails->get((int) $l->sale_detail_id);
                            return (float) ($sd?->sub_total ?? 0);
                        });

                    return $parentAmount + $componentsAmount;
                });
            if ($storedResidual <= 0 && $baseline) {
                $storedResidual = (float) $baseline['stored_residual'];
            }

            $valuationError = null;
            try {
                $val = $valuationService->calculateCumulativeValuation(
                    baselineAmount: $totalBaselineAmount,
                    baselineQuantity: $baselineQuantity,
                    cumulativeReturnedQuantity: $cumulativeQty,
                    previouslyCommittedAmount: $prevAmt,
                    increment: $increment,
                    storedResidual: $storedResidual
                );
                $totalCommAmount = $val['current_return_amount'];
            } catch (\Throwable $e) {
                $totalCommAmount = 0.0;
                $valuationError = $e;
            }

            if ($baselineError || $valuationError) {
                $err = $baselineError ?: $valuationError;
                if (! empty($planningContext['throw_on_error'])) {
                    throw new \RuntimeException($err->getMessage(), 0, $err);
                }
                foreach ($linesInComm as $l) {
                    $this->lastValuationErrors[$l->id] = $err->getMessage();
                    $lineAmounts[$l->id] = (float) ($l->expected_cash_amount ?? $l->line_total ?? 0);
                }
                continue;
            }

            if ($linesInComm->count() === 1) {
                $lineAmounts[$parentLine->id] = $totalCommAmount;
            } else {
                $fragments = $linesInComm->mapWithKeys(function (PosReturnLine $l) use ($saleDetails, $planningContext) {
                    $basis = 0.0;
                    if ($this->isBundleComponentLine($l)) {
                        $sbi = $this->resolveOwnBundleItem($l, $planningContext);
                        $basis = (float) ($sbi?->sub_total ?? $sbi?->price ?? 0);
                    }
                    if ($basis <= 0) {
                        $sd = $saleDetails->get((int) $l->sale_detail_id);
                        $basis = (float) ($sd?->sub_total ?? $l->quantity);
                    }
                    return [$l->id => ['id' => $l->id, 'basis' => $basis]];
                });
                $allocated = $valuationService->allocateCommercialAmountAcrossFragments($totalCommAmount, $fragments);
                foreach ($allocated as $lineId => $amt) {
                    $lineAmounts[$lineId] = $amt;
                }
            }
        }

        return $lineAmounts;
    }
}
