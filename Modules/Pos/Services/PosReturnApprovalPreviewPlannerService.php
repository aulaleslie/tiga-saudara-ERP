<?php

namespace Modules\Pos\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;

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
    ) {
    }

    public function plan(PosReturn $posReturn): array
    {
        $posReturn->loadMissing([
            'lines.returnedSerial',
            'lines.replacementSerial',
            'lines.posTransactionLine',
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

        if (($freshSnapshot['hash'] ?? null) !== $posReturn->source_snapshot_hash) {
            $blockers[] = $this->message(
                'snapshot_drift',
                'Snapshot sumber retur POS sudah berubah dibandingkan data yang diajukan untuk approval preview.'
            );
        } else {
            $info[] = $this->message('snapshot_hash_valid', 'Snapshot sumber masih sesuai dengan data retur yang diajukan.');
        }

        if ($actionableLines->isEmpty()) {
            $blockers[] = $this->message('no_actionable_lines', 'Retur POS ini tidak memiliki baris aksi yang dapat dipreview.');
        }

        $planningContext = $this->buildPlanningContext($posReturn, $actionableLines);

        foreach ($actionableLines as $line) {
            $linePlan = $this->buildLinePlan($posReturn, $line, $planningContext);

            $blockers = array_merge($blockers, $linePlan['blockers']);
            $warnings = array_merge($warnings, $linePlan['warnings']);
            $info = array_merge($info, $linePlan['info']);

            foreach ($linePlan['entries'] as $entry) {
                $this->addPlannedEntryToGroups($groups, $entry);
            }
        }

        $groups = array_values($groups);

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
        ];
    }

    private function buildPlanningContext(PosReturn $posReturn, Collection $actionableLines): array
    {
        $saleIds = $actionableLines->pluck('sale_id')->filter()->unique()->values();
        $saleDetailIds = $actionableLines->pluck('sale_detail_id')->filter()->unique()->values();

        $checkoutSales = PosCheckoutSale::query()
            ->with(['sourceSetting:id,company_name', 'sourceLocation:id,name'])
            ->where('pos_checkout_id', $posReturn->pos_checkout_id)
            ->get()
            ->keyBy(fn (PosCheckoutSale $checkoutSale) => (int) $checkoutSale->sale_id);

        $sales = Sale::query()
            ->whereIn('id', $saleIds)
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

        return [
            'checkout_sales' => $checkoutSales,
            'sales' => $sales,
            'sale_details' => $saleDetails,
            'component_bundle_items' => $componentBundleItems,
        ];
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
            } elseif ($line->resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                // 3.2: re-check at preview time whether the persisted
                // returned_serial_id is still the current leaf — a
                // replacement could have gone in-flight AFTER this whole-bundle
                // return was already submitted/persisted (e.g. a separate
                // PosReturn starts a component replacement on the same serial
                // while this return sits in pending_approval). Phase 2 already
                // enforced this at submission time using the identical
                // PosReturnSerialReplacementChainResolver algorithm; this is a
                // read-only re-run against fresh data, never a claim/lock.
                if ($this->serialChainResolver->hasInFlightReplacement((int) $line->returned_serial_id)) {
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
            'cash_return_amount' => (float) ($line->expected_cash_amount ?? 0),
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
        $componentBundleItems = $planningContext['component_bundle_items'];

        $matches = $componentBundleItems->filter(function (SaleBundleItem $item) use ($line) {
            if ((int) $item->sale_id !== (int) $line->sale_id) {
                return false;
            }

            if ((int) $item->product_id !== (int) $line->product_id) {
                return false;
            }

            if ($item->sale_detail_id && (int) $item->sale_detail_id !== (int) $line->sale_detail_id) {
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
}
