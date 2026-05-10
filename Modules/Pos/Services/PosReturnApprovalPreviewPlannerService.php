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

        if ((int) $saleDetail->sale_id !== (int) $line->sale_id || (int) $saleDetail->product_id !== (int) $line->product_id) {
            $blockers[] = $this->message('source_identity_mismatch', 'Identitas sale detail sudah tidak cocok dengan baris retur tersimpan.', [
                'pos_return_line_id' => $line->id,
                'sale_detail_id' => $line->sale_detail_id,
            ]);

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

        $dispatchResolution = $this->resolveDispatchDetail($line, $saleDetail);
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
            }
        }

        if ($line->resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
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
        $detail = [
            'row_type' => 'parent',
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
            'amount' => (float) $line->line_total,
            'cash_return_amount' => (float) ($line->expected_cash_amount ?? 0),
            'returned_serial' => $line->returnedSerial?->serial_number,
            'replacement_serial' => $line->replacementSerial?->serial_number,
            'stock_movement_intent' => $this->stockMovementIntent($line),
            'serial_movement_intent' => $this->serialMovementIntent($line),
            'replacement_effect' => $line->resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                ? 'serial_pengganti_akan_dikirim_pada_fase_dispatch'
                : null,
            'bundle_trace' => collect(data_get($line->line_meta, 'bundle_trace', []))->values()->all(),
            'source_pos_product_id' => (int) $line->product_id,
            'source_pos_product_name' => (string) $line->product_name,
            'source_pos_product_code' => (string) $line->product_code,
            'source_pos_sale_detail_id' => (int) $line->sale_detail_id,
            'component_sale_bundle_item_id' => null,
            'component_line_group_key' => null,
            'component_bundle_id' => null,
            'component_quantity_per_bundle' => null,
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

        $componentExpansion = $this->buildComponentEntries($posReturn, $line, $saleDetail, $detail, $planningContext);
        $blockers = array_merge($blockers, $componentExpansion['blockers']);
        $warnings = array_merge($warnings, $componentExpansion['warnings']);
        $info = array_merge($info, $componentExpansion['info']);
        $entries = array_merge($entries, $componentExpansion['entries']);

        return [
            'blockers' => $blockers,
            'warnings' => $warnings,
            'info' => $info,
            'entries' => $entries,
        ];
    }

    private function buildComponentEntries(
        PosReturn $posReturn,
        PosReturnLine $line,
        SaleDetails $saleDetail,
        array $parentDetail,
        array $planningContext,
    ): array {
        $bundleTrace = collect(data_get($line->line_meta, 'bundle_trace', []))
            ->filter(fn ($trace) => (int) data_get($trace, 'product_id', 0) > 0)
            ->values();

        if ($bundleTrace->isEmpty()) {
            return ['blockers' => [], 'warnings' => [], 'info' => [], 'entries' => []];
        }

        /** @var Collection<int, PosCheckoutSale> $checkoutSales */
        $checkoutSales = $planningContext['checkout_sales'];
        /** @var Collection<int, SaleBundleItem> $componentBundleItems */
        $componentBundleItems = $planningContext['component_bundle_items'];

        $bundleIds = $this->resolveParentBundleIds($posReturn, $line, $saleDetail);

        $entries = [];
        $blockers = [];
        $warnings = [];
        $info = [];
        $parentLineShare = $this->resolveParentLineShare($line, $saleDetail);

        foreach ($bundleTrace as $traceIndex => $trace) {
            $componentProductId = (int) data_get($trace, 'product_id', 0);
            $componentQuantity = (float) data_get($trace, 'total_component_quantity', 0);
            $quantityPerBundle = (float) data_get($trace, 'quantity_per_bundle', 0);

            if ($componentProductId <= 0 || $componentQuantity <= 0) {
                continue;
            }

            $candidates = $componentBundleItems
                ->filter(function (SaleBundleItem $item) use ($bundleIds, $componentProductId, $componentQuantity, $line, $parentLineShare) {
                    if ((int) $item->product_id !== $componentProductId) {
                        return false;
                    }

                    if ($bundleIds->isNotEmpty() && ! $bundleIds->contains((int) $item->bundle_id)) {
                        return false;
                    }

                    return $this->quantitiesMatch((float) $item->quantity, $componentQuantity)
                        || $this->quantitiesMatch($this->apportionedComponentQuantity($item, $parentLineShare), $componentQuantity);
                })
                ->values();

            $posAnchoredCandidates = $this->filterCandidatesByPosLineage($candidates, $line, (int) $traceIndex, $componentQuantity);
            if ($posAnchoredCandidates->isNotEmpty()) {
                $candidates = $posAnchoredCandidates->values();
            } elseif ($checkoutSales->count() > 1) {
                $candidates = $candidates
                    ->reject(fn (SaleBundleItem $item) => (int) $item->sale_id === (int) $line->sale_id)
                    ->values();
            }

            if ($candidates->count() === 0) {
                if ($checkoutSales->count() > 1) {
                    $blockers[] = $this->message(
                        'component_target_missing',
                        'Target komponen bundle tidak dapat dipetakan ke Sale sumber yang unik.',
                        [
                            'pos_return_line_id' => $line->id,
                            'source_pos_product_id' => $line->product_id,
                            'component_product_id' => $componentProductId,
                        ]
                    );
                }

                continue;
            }

            if ($candidates->count() > 1) {
                $blockers[] = $this->message(
                    'component_target_ambiguous',
                    'Terdapat lebih dari satu target komponen bundle yang cocok sehingga preview tidak aman disimpulkan.',
                    [
                        'pos_return_line_id' => $line->id,
                        'source_pos_product_id' => $line->product_id,
                        'component_product_id' => $componentProductId,
                        'candidate_sale_ids' => $candidates->pluck('sale_id')->values()->all(),
                    ]
                );

                continue;
            }

            /** @var SaleBundleItem $componentItem */
            $componentItem = $candidates->first();
            $componentCheckoutSale = $checkoutSales->get((int) $componentItem->sale_id);
            if (! $componentCheckoutSale || ! $componentItem->sale) {
                $blockers[] = $this->message(
                    'component_target_missing_context',
                    'Konteks Sale sumber untuk komponen bundle tidak lengkap.',
                    [
                        'pos_return_line_id' => $line->id,
                        'component_product_id' => $componentProductId,
                        'sale_id' => $componentItem->sale_id,
                    ]
                );

                continue;
            }

            $sourceSettingId = (int) $componentCheckoutSale->source_setting_id;
            $sourceLocationId = (int) $componentCheckoutSale->source_location_id;
            $taxId = $componentItem->tax_id ? (int) $componentItem->tax_id : null;
            $linkedSaleReturnReferences = $posReturn->saleReturns
                ->where('sale_id', $componentItem->sale_id)
                ->pluck('reference')
                ->filter()
                ->values()
                ->all();

            $componentDetail = [
                'row_type' => 'component',
                'pos_return_line_id' => (int) $line->id,
                'resolution' => (string) $line->resolution,
                'resolution_label' => $this->resolutionLabel((string) $line->resolution),
                'sale_detail_id' => $componentItem->sale_detail_id ? (int) $componentItem->sale_detail_id : null,
                'dispatch_detail_id' => null,
                'dispatch_resolution' => 'sale_bundle_item',
                'source_setting_id' => $sourceSettingId,
                'source_setting_name' => $this->settingName($sourceSettingId),
                'source_location_id' => $sourceLocationId,
                'source_location_name' => $this->locationName($sourceLocationId),
                'tax_id' => $taxId,
                'tax_name' => $this->taxName($taxId),
                'product_id' => (int) $componentItem->product_id,
                'product_name' => (string) ($componentItem->product?->product_name ?? $componentItem->name),
                'product_code' => (string) ($componentItem->product?->product_code ?? ''),
                'quantity' => $componentQuantity,
                'amount' => $this->apportionedComponentAmount($componentItem, $componentQuantity),
                'cash_return_amount' => 0.0,
                'returned_serial' => $parentDetail['returned_serial'],
                'replacement_serial' => $parentDetail['replacement_serial'],
                'stock_movement_intent' => $this->componentStockMovementIntent($componentItem),
                'serial_movement_intent' => $this->componentSerialMovementIntent($componentItem, $line),
                'replacement_effect' => $parentDetail['replacement_effect'],
                'bundle_trace' => $parentDetail['bundle_trace'],
                'source_pos_product_id' => $parentDetail['source_pos_product_id'],
                'source_pos_product_name' => $parentDetail['source_pos_product_name'],
                'source_pos_product_code' => $parentDetail['source_pos_product_code'],
                'source_pos_sale_detail_id' => $parentDetail['source_pos_sale_detail_id'],
                'component_sale_bundle_item_id' => (int) $componentItem->id,
                'component_line_group_key' => $componentItem->line_group_key,
                'component_bundle_id' => (int) $componentItem->bundle_id,
                'component_quantity_per_bundle' => $quantityPerBundle,
            ];

            $entries[] = [
                'group' => $this->makePlannedGroup(
                    $componentItem->sale,
                    $sourceSettingId,
                    $sourceLocationId,
                    $taxId,
                    $linkedSaleReturnReferences
                ),
                'detail' => $componentDetail,
            ];
        }

        return [
            'blockers' => $blockers,
            'warnings' => $warnings,
            'info' => $info,
            'entries' => $entries,
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

    private function resolveParentBundleIds(PosReturn $posReturn, PosReturnLine $line, SaleDetails $saleDetail): Collection
    {
        $bundleIds = $saleDetail->bundleItems
            ->pluck('bundle_id')
            ->filter(fn ($bundleId) => (int) $bundleId > 0)
            ->map(fn ($bundleId) => (int) $bundleId)
            ->unique()
            ->values();

        if ($bundleIds->isNotEmpty()) {
            return $bundleIds;
        }

        $lineMetaBundleId = (int) data_get($line->line_meta, 'bundle_id', 0);
        if ($lineMetaBundleId > 0) {
            return collect([$lineMetaBundleId]);
        }

        $ptlBundleId = (int) data_get($line->posTransactionLine?->line_meta, 'bundle_id', 0);
        if ($ptlBundleId > 0) {
            return collect([$ptlBundleId]);
        }

        return collect(data_get($posReturn->source_snapshot, 'lines', []))
            ->filter(function (array $snapshotLine) use ($line) {
                if ((int) data_get($snapshotLine, 'sale_detail_id', 0) !== (int) $line->sale_detail_id) {
                    return false;
                }

                if ($line->returned_serial_id) {
                    return in_array((int) $line->returned_serial_id, array_map('intval', data_get($snapshotLine, 'serial_number_ids', [])), true);
                }

                if ($line->pos_transaction_line_id) {
                    return (int) data_get($snapshotLine, 'pos_transaction_line_id', 0) === (int) $line->pos_transaction_line_id;
                }

                return true;
            })
            ->pluck('bundle_id')
            ->filter(fn ($bundleId) => (int) $bundleId > 0)
            ->map(fn ($bundleId) => (int) $bundleId)
            ->unique()
            ->values();
    }

    private function filterCandidatesByPosLineage(Collection $candidates, PosReturnLine $line, int $traceIndex, float $componentQuantity): Collection
    {
        $bundleItems = collect(data_get($line->posTransactionLine?->line_meta, 'bundle_items', []))->values();
        $posBundleItem = $bundleItems->get($traceIndex);

        if (! is_array($posBundleItem)) {
            return collect();
        }

        $expectedProductId = (int) ($posBundleItem['product_id'] ?? 0);
        if ($expectedProductId <= 0) {
            return collect();
        }

        $filtered = $candidates->filter(function (SaleBundleItem $candidate) use ($traceIndex, $expectedProductId, $componentQuantity, $posBundleItem) {
            if ((int) $candidate->product_id !== $expectedProductId) {
                return false;
            }

            if (! preg_match('/-' . preg_quote((string) $traceIndex, '/') . '$/', (string) ($candidate->line_group_key ?? ''))) {
                return false;
            }

            $informationalPrice = (float) ($posBundleItem['informational_item_price'] ?? 0);
            if ($informationalPrice > 0) {
                return $this->quantitiesMatch(
                    $this->apportionedComponentAmount($candidate, $componentQuantity),
                    round($informationalPrice * $componentQuantity, 2)
                );
            }

            return true;
        });

        return $filtered->values();
    }

    private function resolveParentLineShare(PosReturnLine $line, SaleDetails $saleDetail): float
    {
        $parentQuantity = (float) $saleDetail->quantity;
        $returnedQuantity = (float) $line->quantity;

        if ($parentQuantity <= 0 || $returnedQuantity <= 0) {
            return 1.0;
        }

        return min(1.0, $returnedQuantity / $parentQuantity);
    }

    private function apportionedComponentQuantity(SaleBundleItem $componentItem, float $parentLineShare): float
    {
        return (float) $componentItem->quantity * $parentLineShare;
    }

    private function apportionedComponentAmount(SaleBundleItem $componentItem, float $componentQuantity): float
    {
        $storedQuantity = (float) $componentItem->quantity;

        if ($storedQuantity <= 0) {
            return (float) $componentItem->sub_total;
        }

        return round(((float) $componentItem->sub_total) * ($componentQuantity / $storedQuantity), 2);
    }

    private function quantitiesMatch(float $left, float $right): bool
    {
        return abs($left - $right) < 0.0001;
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

    private function stockMovementIntent(PosReturnLine $line): string
    {
        if ($line->stock_behavior === PosReturnLine::STOCK_BEHAVIOR_STOCKLESS) {
            return 'tidak_ada_mutasi_stok';
        }

        return 'stok_sumber_akan_bertambah_saat_receiving';
    }

    private function componentStockMovementIntent(SaleBundleItem $componentItem): string
    {
        if (! ($componentItem->product?->stock_managed ?? false)) {
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

    private function componentSerialMovementIntent(SaleBundleItem $componentItem, PosReturnLine $line): string
    {
        if (! ($componentItem->product?->serial_number_required ?? false)) {
            return 'tidak_ada_mutasi_serial';
        }

        return $this->serialMovementIntent($line);
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
}