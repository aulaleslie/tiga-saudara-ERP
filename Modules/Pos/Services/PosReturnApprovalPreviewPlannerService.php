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

        $lineResolutions = $actionableLines->pluck('resolution')->filter()->unique()->values();
        if ($lineResolutions->contains(PosReturnLine::RESOLUTION_CASH_RETURN)
            && $lineResolutions->contains(PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)) {
            $blockers[] = $this->message(
                'mixed_options',
                'Preview approval diblokir karena retur ini mencampur cash return dan product replacement dalam satu dokumen.'
            );
        } elseif ($lineResolutions->count() === 1
            && $posReturn->return_option
            && $lineResolutions->first() !== $posReturn->return_option) {
            $warnings[] = $this->message(
                'header_option_mismatch',
                'Header return_option berbeda dengan resolusi baris aksi. Preview memakai resolusi per baris sebagai sumber kebenaran.'
            );
        }

        $checkoutSales = PosCheckoutSale::query()
            ->where('pos_checkout_id', $posReturn->pos_checkout_id)
            ->get()
            ->keyBy('sale_id');

        foreach ($actionableLines as $line) {
            $linePlan = $this->buildLinePlan($posReturn, $line, $checkoutSales);

            $blockers = array_merge($blockers, $linePlan['blockers']);
            $warnings = array_merge($warnings, $linePlan['warnings']);
            $info = array_merge($info, $linePlan['info']);

            if (! $linePlan['group']) {
                continue;
            }

            $groupKey = $linePlan['group']['key'];
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = $linePlan['group'];
                unset($groups[$groupKey]['key']);
                continue;
            }

            $groups[$groupKey]['planned_header']['total_amount'] += $linePlan['detail']['amount'];
            $groups[$groupKey]['planned_header']['cash_return_total'] += $linePlan['detail']['cash_return_amount'];
            $groups[$groupKey]['planned_header']['line_count']++;
            $groups[$groupKey]['planned_details'][] = $linePlan['detail'];
            $groups[$groupKey]['linked_sale_return_references'] = array_values(array_unique(array_merge(
                $groups[$groupKey]['linked_sale_return_references'],
                $linePlan['group']['linked_sale_return_references']
            )));
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

    private function buildLinePlan(PosReturn $posReturn, PosReturnLine $line, Collection $checkoutSales): array
    {
        $blockers = [];
        $warnings = [];
        $info = [];

        $sale = Sale::query()->find($line->sale_id);
        if (! $sale) {
            $blockers[] = $this->message('source_sale_missing', 'Sale sumber untuk baris retur tidak ditemukan.', [
                'pos_return_line_id' => $line->id,
                'sale_id' => $line->sale_id,
            ]);

            return ['blockers' => $blockers, 'warnings' => $warnings, 'info' => $info, 'group' => null, 'detail' => null];
        }

        $saleDetail = SaleDetails::query()->with(['product', 'tax'])->find($line->sale_detail_id);
        if (! $saleDetail) {
            $blockers[] = $this->message('sale_detail_missing', 'Sale detail sumber untuk baris retur tidak ditemukan.', [
                'pos_return_line_id' => $line->id,
                'sale_detail_id' => $line->sale_detail_id,
            ]);

            return ['blockers' => $blockers, 'warnings' => $warnings, 'info' => $info, 'group' => null, 'detail' => null];
        }

        if ((int) $saleDetail->sale_id !== (int) $line->sale_id || (int) $saleDetail->product_id !== (int) $line->product_id) {
            $blockers[] = $this->message('source_identity_mismatch', 'Identitas sale detail sudah tidak cocok dengan baris retur tersimpan.', [
                'pos_return_line_id' => $line->id,
                'sale_detail_id' => $line->sale_detail_id,
            ]);

            return ['blockers' => $blockers, 'warnings' => $warnings, 'info' => $info, 'group' => null, 'detail' => null];
        }

        $checkoutSale = $checkoutSales->get($line->sale_id);
        if (! $checkoutSale) {
            $blockers[] = $this->message('checkout_sale_missing', 'Checkout sale sumber tidak ditemukan untuk baris retur ini.', [
                'pos_return_line_id' => $line->id,
                'sale_id' => $line->sale_id,
            ]);

            return ['blockers' => $blockers, 'warnings' => $warnings, 'info' => $info, 'group' => null, 'detail' => null];
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
        $groupKey = implode(':', [
            (int) $line->sale_id,
            $sourceSettingId,
            $sourceLocationId,
            $taxId ?? 'none',
        ]);

        $detail = [
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
        ];

        $linkedSaleReturnReferences = $posReturn->saleReturns
            ->where('sale_id', $line->sale_id)
            ->pluck('reference')
            ->filter()
            ->values()
            ->all();

        return [
            'blockers' => $blockers,
            'warnings' => $warnings,
            'info' => $info,
            'group' => [
                'key' => $groupKey,
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
                'linked_sale_return_references' => $linkedSaleReturnReferences,
                'planned_header' => [
                    'sale_id' => (int) $sale->id,
                    'sale_reference' => (string) $sale->reference,
                    'setting_id' => $sourceSettingId,
                    'setting_name' => $this->settingName($sourceSettingId),
                    'location_id' => $sourceLocationId,
                    'location_name' => $this->locationName($sourceLocationId),
                    'return_type' => (string) $line->resolution,
                    'line_count' => 1,
                    'total_amount' => (float) $line->line_total,
                    'cash_return_total' => (float) ($line->expected_cash_amount ?? 0),
                ],
                'planned_details' => [$detail],
            ],
            'detail' => $detail,
        ];
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
}