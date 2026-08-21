<?php

namespace Modules\Pos\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Setting;

class PosReturnApprovalPlanPersistenceService
{
    public function synchronize(PosReturn $posReturn, array $plan): Collection
    {
        if (! empty($plan['blockers']) || ! empty($plan['warnings'])) {
            throw new \RuntimeException('Preview persetujuan terbaru belum siap dieksekusi menjadi Sales Return terhubung.');
        }

        $groups = collect($plan['groups'] ?? [])->values();
        if ($groups->isEmpty()) {
            throw new \RuntimeException('Preview persetujuan tidak memiliki target Sales Return yang dapat dipersist.');
        }

        $posReturn->loadMissing([
            'lines',
            'saleReturns.saleReturnDetails',
        ]);

        if ($posReturn->saleReturns->isEmpty()) {
            $this->assertPlanSerialsNotClaimedElsewhere($posReturn, $groups);
            $this->createSaleReturnsFromPlan($posReturn, $groups);
        } else {
            $this->assertExistingSaleReturnsMatchPlan($posReturn, $groups);
        }

        $posReturn->unsetRelation('saleReturns');
        $posReturn->load('saleReturns.saleReturnDetails');
        $this->syncPosReturnLineLinks($posReturn);

        return $posReturn->saleReturns;
    }

    /**
     * Enforce exclusive consumption of every serial this plan is about to
     * claim — parent (`returned_serial_id`) and bundle-component
     * (`component_serial_ids`, resolved by the planner from persisted
     * DispatchDetail lineage) alike — under the row lock this method's
     * caller already holds on `PosReturn` inside its enclosing transaction
     * (see PosReturnLifecycleService::approve/executeApprovalFromPreview).
     * This is the first point in the pipeline where a component's serial
     * identity is known, so it is also the first point exclusivity can be
     * enforced for it; a `PosReturnLine.returned_serial_id`-only check
     * (as exists at draft submission) cannot see component serials at all.
     *
     * @param  Collection<int, array<string, mixed>>  $groups
     */
    private function assertPlanSerialsNotClaimedElsewhere(PosReturn $posReturn, Collection $groups): void
    {
        $serialIds = collect();

        foreach ($groups as $group) {
            foreach (($group['planned_details'] ?? []) as $plannedDetail) {
                if (($plannedDetail['row_type'] ?? 'parent') === 'component') {
                    foreach ((array) ($plannedDetail['component_serial_ids'] ?? []) as $id) {
                        $serialIds->push((int) $id);
                    }
                    continue;
                }

                $line = $posReturn->lines->firstWhere('id', (int) ($plannedDetail['pos_return_line_id'] ?? 0));
                if ($line && (int) ($line->returned_serial_id ?? 0) > 0) {
                    $serialIds->push((int) $line->returned_serial_id);
                }
            }
        }

        $serialIds = $serialIds->filter(fn (int $id) => $id > 0)->unique()->sort()->values();

        if ($serialIds->isEmpty()) {
            return;
        }

        // Lock the candidate serial rows themselves (deterministic id order,
        // matching the checkout posting lock convention) so a concurrent
        // synchronize() call for a competing return targeting the same
        // serial(s) serializes behind this one instead of racing past the
        // exists() check below.
        ProductSerialNumber::query()
            ->whereIn('id', $serialIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // A competing return's plan is committed to a serial from the moment
        // synchronize() creates its SaleReturn/SaleReturnDetail rows onward —
        // that persistence happens while the return is still PENDING_APPROVAL,
        // before it ever reaches a `returnQuantityConsumingStatuses()` status,
        // so gating this check on those statuses alone would miss exactly the
        // race this guard exists to close (two pending-approval returns both
        // synchronizing a plan for the same serial). Any other *active*
        // return that has already synchronized a plan is therefore itself
        // sufficient evidence of a claim, regardless of its current status;
        // only a reversed/cancelled/rejected return releases its claim (see
        // `active()` scope and the reject/cancel lifecycle, which detach or
        // leave stale SaleReturn rows behind an inactive PosReturn).
        $claimed = PosReturnLine::query()
            ->where('pos_return_id', '!=', $posReturn->id)
            ->whereIn('returned_serial_id', $serialIds->all())
            ->whereHas('posReturn', function ($q) {
                $q->active()->whereNotIn('status', [PosReturn::STATUS_DRAFT, PosReturn::STATUS_REJECTED]);
            })
            ->exists();

        if ($claimed) {
            throw new \RuntimeException('Satu atau lebih serial pada rencana ini sudah diklaim oleh retur lain yang sedang diproses atau selesai.');
        }

        // Component serial ids are not stored on PosReturnLine (only the
        // parent's returned_serial_id is), so their exclusivity is checked
        // against persisted SaleReturnDetail.serial_number_ids from other
        // POS Returns' already-synchronized plans instead.
        $componentClaimed = SaleReturnDetail::query()
            ->whereHas('saleReturn', function ($q) use ($posReturn) {
                $q->where('pos_return_id', '!=', $posReturn->id)
                    ->whereHas('posReturn', function ($qq) {
                        $qq->active()->whereNotIn('status', [PosReturn::STATUS_DRAFT, PosReturn::STATUS_REJECTED]);
                    });
            })
            ->get(['id', 'sale_return_id', 'serial_number_ids'])
            ->contains(function (SaleReturnDetail $detail) use ($serialIds) {
                $detailSerialIds = collect($detail->serial_number_ids ?? []);

                return $detailSerialIds->intersect($serialIds)->isNotEmpty();
            });

        if ($componentClaimed) {
            throw new \RuntimeException('Satu atau lebih serial komponen pada rencana ini sudah diklaim oleh retur lain yang sedang diproses atau selesai.');
        }
    }

    private function createSaleReturnsFromPlan(PosReturn $posReturn, Collection $groups): void
    {
        $lines = $posReturn->lines->keyBy(fn (PosReturnLine $line) => (int) $line->id);
        $sales = Sale::query()
            ->whereIn('id', $groups->pluck('planned_header.sale_id')->map(fn ($id) => (int) $id)->all())
            ->get()
            ->keyBy(fn (Sale $sale) => (int) $sale->id);

        foreach ($groups as $group) {
            $header = $group['planned_header'] ?? [];
            $sale = $sales->get((int) ($header['sale_id'] ?? 0));
            if (! $sale) {
                throw new \RuntimeException('Sale sumber untuk persistensi approval preview tidak ditemukan.');
            }

            $saleReturn = SaleReturn::query()->create([
                'date' => now()->toDateString(),
                'reference' => $this->generateSaleReturnReference((int) ($header['setting_id'] ?? $posReturn->setting_id)),
                'sale_id' => (int) $sale->id,
                'sale_reference' => (string) $sale->reference,
                'customer_id' => $sale->customer_id,
                'customer_name' => $sale->customer_name,
                'setting_id' => (int) ($header['setting_id'] ?? $posReturn->setting_id),
                'location_id' => (int) ($header['location_id'] ?? 0) ?: null,
                'tax_percentage' => 0,
                'tax_amount' => 0,
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'total_amount' => $this->normalizeDecimal($header['total_amount'] ?? 0),
                'paid_amount' => 0,
                'due_amount' => $this->normalizeDecimal($header['total_amount'] ?? 0),
                'status' => 'PENDING APPROVAL',
                'payment_status' => 'PENDING',
                'payment_method' => $sale->payment_method,
                'note' => 'Generated from POS Return ' . $posReturn->reference,
                'pos_return_id' => $posReturn->id,
                'approval_status' => 'PENDING',
                'return_type' => $this->mapPlannedReturnTypeToSaleReturn((string) ($header['return_type'] ?? '')),
            ]);

            foreach (($group['planned_details'] ?? []) as $plannedDetail) {
                $line = $lines->get((int) ($plannedDetail['pos_return_line_id'] ?? 0));
                $componentBundleItemId = $this->nullableInt($plannedDetail['component_sale_bundle_item_id'] ?? null);
                $rowType = (string) ($plannedDetail['row_type'] ?? 'parent');

                $saleReturnDetail = $saleReturn->saleReturnDetails()->create([
                    'pos_return_line_id' => $plannedDetail['pos_return_line_id'] ?? null,
                    'sale_detail_id' => $plannedDetail['sale_detail_id'] ?? null,
                    'dispatch_detail_id' => $plannedDetail['dispatch_detail_id'] ?? null,
                    'product_id' => (int) ($plannedDetail['product_id'] ?? 0),
                    'product_name' => (string) ($plannedDetail['product_name'] ?? ''),
                    'product_code' => (string) ($plannedDetail['product_code'] ?? ''),
                    'quantity' => (int) round((float) ($plannedDetail['quantity'] ?? 0)),
                    'price' => $this->resolveUnitPrice($plannedDetail),
                    'unit_price' => $this->resolveUnitPrice($plannedDetail),
                    'sub_total' => $this->normalizeDecimal($plannedDetail['amount'] ?? 0),
                    'product_discount_amount' => 0,
                    'product_discount_type' => 'fixed',
                    'product_tax_amount' => 0,
                    'location_id' => $plannedDetail['source_location_id'] ?? null,
                    'tax_id' => $plannedDetail['tax_id'] ?? null,
                    'serial_number_ids' => $this->resolveSerialNumberIds($line, $plannedDetail),
                    'bundle_group_key' => $this->resolveBundleGroupKey($plannedDetail, $line),
                    'stock_behavior' => $this->resolveStockBehavior($plannedDetail, $line),
                    'component_sale_bundle_item_id' => $componentBundleItemId,
                    'cost_origin' => $rowType === 'component' && $componentBundleItemId
                        ? \Modules\SalesReturn\Entities\SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM
                        : \Modules\SalesReturn\Entities\SaleReturnDetail::COST_ORIGIN_SALE_DETAIL,
                    'execution_context' => $this->buildExecutionContext(
                        $plannedDetail,
                        $saleReturn,
                        (int) ($header['sale_id'] ?? $saleReturn->sale_id ?? 0)
                    ),
                ]);

                if (($plannedDetail['row_type'] ?? 'parent') === 'parent' && $line) {
                    $line->update([
                        'sale_return_id' => $saleReturn->id,
                        'sale_return_detail_id' => $saleReturnDetail->id,
                    ]);
                }
            }
        }
    }

    private function assertExistingSaleReturnsMatchPlan(PosReturn $posReturn, Collection $groups): void
    {
        $planned = $groups
            ->map(fn (array $group) => $this->normalizePlannedGroup($group, $posReturn))
            ->sortBy(fn (array $group) => $group['signature'])
            ->values()
            ->all();

        $existing = $posReturn->saleReturns
            ->map(fn (SaleReturn $saleReturn) => $this->normalizeExistingGroup($saleReturn))
            ->sortBy(fn (array $group) => $group['signature'])
            ->values()
            ->all();

        if (json_encode($planned) !== json_encode($existing)) {
            throw new \RuntimeException('Linked Sales Returns conflict with the latest approval preview plan.');
        }
    }

    private function normalizePlannedGroup(array $group, PosReturn $posReturn): array
    {
        $lines = $posReturn->lines->keyBy(fn (PosReturnLine $line) => (int) $line->id);
        $header = $group['planned_header'] ?? [];
        $taxId = $group['tax_context']['tax_id'] ?? null;

        $details = collect($group['planned_details'] ?? [])
            ->map(function (array $detail) use ($lines) {
                $line = $lines->get((int) ($detail['pos_return_line_id'] ?? 0));

                return [
                    'pos_return_line_id' => $this->nullableInt($detail['pos_return_line_id'] ?? null),
                    'sale_detail_id' => $this->nullableInt($detail['sale_detail_id'] ?? null),
                    'dispatch_detail_id' => $this->nullableInt($detail['dispatch_detail_id'] ?? null),
                    'product_id' => (int) ($detail['product_id'] ?? 0),
                    'quantity' => $this->normalizeDecimal($detail['quantity'] ?? 0),
                    'location_id' => $this->nullableInt($detail['source_location_id'] ?? null),
                    'tax_id' => $this->nullableInt($detail['tax_id'] ?? null),
                    'bundle_group_key' => $this->resolveBundleGroupKey($detail, $line),
                    'stock_behavior' => $this->canonicalStockBehavior($this->resolveStockBehavior($detail, $line)),
                    'replacement_execution' => $this->normalizeReplacementExecutionContext($detail),
                ];
            })
            ->sortBy(fn (array $detail) => json_encode($detail))
            ->values()
            ->all();

        $headerData = [
            'sale_id' => (int) ($header['sale_id'] ?? 0),
            'setting_id' => (int) ($header['setting_id'] ?? 0),
            'location_id' => $this->nullableInt($header['location_id'] ?? null),
            'tax_id' => $this->nullableInt($taxId),
            'return_type' => $this->canonicalReturnType($header['return_type'] ?? null),
            'total_amount' => $this->normalizeDecimal($header['total_amount'] ?? 0),
        ];

        return [
            'signature' => json_encode([$headerData, $details]),
            'header' => $headerData,
            'details' => $details,
        ];
    }

    private function normalizeExistingGroup(SaleReturn $saleReturn): array
    {
        $details = $saleReturn->saleReturnDetails
            ->map(function (SaleReturnDetail $detail) {
                return [
                    'pos_return_line_id' => $this->nullableInt($detail->pos_return_line_id),
                    'sale_detail_id' => $this->nullableInt($detail->sale_detail_id),
                    'dispatch_detail_id' => $this->nullableInt($detail->dispatch_detail_id),
                    'product_id' => (int) ($detail->product_id ?? 0),
                    'quantity' => $this->normalizeDecimal($detail->quantity ?? 0),
                    'location_id' => $this->nullableInt($detail->location_id),
                    'tax_id' => $this->nullableInt($detail->tax_id),
                    'bundle_group_key' => (string) ($detail->bundle_group_key ?? ''),
                    'stock_behavior' => $this->canonicalStockBehavior((string) ($detail->stock_behavior ?? PosReturnLine::STOCK_BEHAVIOR_MANAGED)),
                    'replacement_execution' => $this->normalizeReplacementExecutionContext(is_array($detail->execution_context) ? $detail->execution_context : []),
                ];
            })
            ->sortBy(fn (array $detail) => json_encode($detail))
            ->values()
            ->all();

        $taxIds = collect($details)->pluck('tax_id')->unique()->values();
        $taxId = $taxIds->count() === 1 ? $taxIds->first() : ($taxIds->isEmpty() ? null : 'mixed');

        $headerData = [
            'sale_id' => (int) ($saleReturn->sale_id ?? 0),
            'setting_id' => (int) ($saleReturn->setting_id ?? 0),
            'location_id' => $this->nullableInt($saleReturn->location_id),
            'tax_id' => $taxId,
            'return_type' => $this->canonicalReturnType($saleReturn->return_type),
            'total_amount' => $this->normalizeDecimal($saleReturn->total_amount ?? 0),
        ];

        return [
            'signature' => json_encode([$headerData, $details]),
            'header' => $headerData,
            'details' => $details,
        ];
    }

    private function syncPosReturnLineLinks(PosReturn $posReturn): void
    {
        $parentDetailsByLine = $posReturn->saleReturns
            ->flatMap(fn (SaleReturn $saleReturn) => $saleReturn->saleReturnDetails)
            ->filter(function (SaleReturnDetail $detail) use ($posReturn) {
                $line = $posReturn->lines->firstWhere('id', $detail->pos_return_line_id);
                if (! $line) {
                    return false;
                }

                return (int) ($detail->product_id ?? 0) === (int) ($line->product_id ?? 0)
                    && (int) ($detail->sale_detail_id ?? 0) === (int) ($line->sale_detail_id ?? 0);
            })
            ->keyBy(fn (SaleReturnDetail $detail) => (int) $detail->pos_return_line_id);

        foreach ($posReturn->lines as $line) {
            $parentDetail = $parentDetailsByLine->get((int) $line->id);
            if (! $parentDetail) {
                continue;
            }

            $line->update([
                'sale_return_id' => $parentDetail->sale_return_id,
                'sale_return_detail_id' => $parentDetail->id,
            ]);
        }
    }

    private function resolveSerialNumberIds(?PosReturnLine $line, array $plannedDetail = []): array
    {
        // A component row's fulfilling serial is a distinct physical unit from
        // the parent's returned_serial_id — resolved independently by the
        // planner from the component's own DispatchDetail lineage (see
        // PosReturnApprovalPreviewPlannerService::resolveComponentSerials).
        // Falling through to the parent's serial here would restore the wrong
        // unit at receiving time.
        if (($plannedDetail['row_type'] ?? 'parent') === 'component') {
            return collect($plannedDetail['component_serial_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->sort()
                ->values()
                ->all();
        }

        if (! $line) {
            return [];
        }

        $serialIds = collect($line->serial_number_ids ?? [])->map(fn ($id) => (int) $id)->filter();
        $returnedSerialId = (int) ($line->returned_serial_id ?? 0);

        if ($returnedSerialId > 0 && ! $serialIds->contains($returnedSerialId)) {
            $serialIds->push($returnedSerialId);
        }

        return $serialIds->sort()->values()->all();
    }

    private function resolveBundleGroupKey(array $plannedDetail, ?PosReturnLine $line): string
    {
        return (string) ($plannedDetail['component_line_group_key'] ?? $line?->bundle_group_key ?? '');
    }

    private function resolveStockBehavior(array $plannedDetail, ?PosReturnLine $line): string
    {
        if ($line && $line->stock_behavior) {
            return (string) $line->stock_behavior;
        }

        return str_contains((string) ($plannedDetail['stock_movement_intent'] ?? ''), 'tidak_ada_mutasi_stok')
            ? PosReturnLine::STOCK_BEHAVIOR_STOCKLESS
            : PosReturnLine::STOCK_BEHAVIOR_MANAGED;
    }

    private function canonicalStockBehavior(?string $value): string
    {
        return strtolower((string) $value);
    }

    private function resolveUnitPrice(array $plannedDetail): string
    {
        $quantity = (float) ($plannedDetail['quantity'] ?? 0);
        $amount = (float) ($plannedDetail['amount'] ?? 0);
        $unitPrice = $quantity > 0 ? ($amount / $quantity) : $amount;

        return $this->normalizeDecimal($unitPrice);
    }

    private function mapPlannedReturnTypeToSaleReturn(string $returnType): string
    {
        return match ($this->canonicalReturnType($returnType)) {
            PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT => 'Replacement',
            'mixed' => 'Mixed',
            default => 'Cash Return',
        };
    }

    private function canonicalReturnType(?string $value): string
    {
        return match (strtolower((string) $value)) {
            'product_replacement', 'replacement' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'mixed' => 'mixed',
            default => PosReturnLine::RESOLUTION_CASH_RETURN,
        };
    }

    private function generateSaleReturnReference(int $settingId): string
    {
        $setting = Setting::find($settingId);
        $docPrefix = optional($setting)->document_prefix;
        $returnPrefix = optional($setting)->sale_return_prefix_document ?: 'SLRN';
        $prefix = ($docPrefix ? $docPrefix . '-' : '') . $returnPrefix;
        $year = now()->format('y');
        $month = now()->format('m');

        $count = DB::table('sale_returns')
            ->where('setting_id', $settingId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        return sprintf('%s-%s%s-%04d', $prefix, $year, $month, $count + 1);
    }

    private function normalizeDecimal($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function buildExecutionContext(array $plannedDetail, ?SaleReturn $saleReturn, ?int $fallbackSourceSaleId = null): array
    {
        $dispatchDetailId = $this->nullableInt($plannedDetail['dispatch_detail_id'] ?? null);
        $saleDetailId = $this->nullableInt($plannedDetail['sale_detail_id'] ?? null);
        $componentBundleItemId = $this->nullableInt($plannedDetail['component_sale_bundle_item_id'] ?? null);

        $quantitySource = 'sale_detail';
        $commercialValueSource = 'sale_detail';

        if (($plannedDetail['row_type'] ?? 'parent') === 'component') {
            $quantitySource = $componentBundleItemId ? 'sale_bundle_item' : 'sale_detail';
            $commercialValueSource = $componentBundleItemId ? 'sale_bundle_item' : 'sale_detail';
        }

        return [
            'row_type' => (string) ($plannedDetail['row_type'] ?? 'parent'),
            'resolution' => (string) ($plannedDetail['resolution'] ?? ''),
            'dispatch_resolution' => (string) ($plannedDetail['dispatch_resolution'] ?? ''),
            'source_sale_id' => $this->nullableInt($saleReturn?->sale_id ?? $plannedDetail['source_sale_id'] ?? $plannedDetail['sale_id'] ?? $fallbackSourceSaleId),
            'source_sale_detail_id' => $this->nullableInt($plannedDetail['source_pos_sale_detail_id'] ?? null),
            'component_source_sale_detail_id' => $saleDetailId,
            'component_dispatch_detail_id' => $dispatchDetailId,
            'component_sale_bundle_item_id' => $componentBundleItemId,
            'component_line_group_key' => (string) ($plannedDetail['component_line_group_key'] ?? ''),
            'component_bundle_id' => $this->nullableInt($plannedDetail['component_bundle_id'] ?? null),
            'component_quantity_per_bundle' => $plannedDetail['component_quantity_per_bundle'] ?? null,
            'component_serial_ids' => collect($plannedDetail['component_serial_ids'] ?? [])->map(fn ($id) => (int) $id)->values()->all(),
            'quantity_source' => $quantitySource,
            'commercial_value_source' => $commercialValueSource,
            'cash_return_amount' => (float) ($plannedDetail['cash_return_amount'] ?? 0),
            'planned_amount' => (float) ($plannedDetail['amount'] ?? 0),
            'replacement_serial_owner_setting_id' => $this->nullableInt($plannedDetail['replacement_serial_owner_setting_id'] ?? null),
            'replacement_serial_location_id' => $this->nullableInt($plannedDetail['replacement_serial_location_id'] ?? null),
            'execution_mode' => $this->nullableString($plannedDetail['execution_mode'] ?? null),
            // `replacement_kind` is a DISTINCT field from `execution_mode` above:
            // `execution_mode` carries same_owner_replacement/cross_owner_replacement
            // (serial ownership routing), while `replacement_kind` carries the
            // planner's physical-execution classification
            // (serial_inventory_replacement | non_serial_note_only), sourced verbatim
            // from Phase 2's PosReturnLine.line_meta.execution_mode. Persisting both
            // under their own distinct keys avoids collapsing two different concepts
            // into one column.
            'replacement_kind' => $this->nullableString($plannedDetail['replacement_kind'] ?? null),
            'original_sale_correction_quantity' => $this->nullableDecimal($plannedDetail['original_sale_correction_quantity'] ?? null),
            'original_sale_correction_amount' => $this->nullableDecimal($plannedDetail['original_sale_correction_amount'] ?? null),
            'generated_replacement_sale_effects' => $this->normalizeGeneratedReplacementSaleEffects($plannedDetail['generated_replacement_sale_effects'] ?? null),
        ];
    }

    private function normalizeExecutionContext(array $context): array
    {
        return [
            'row_type' => (string) ($context['row_type'] ?? 'parent'),
            'resolution' => (string) ($context['resolution'] ?? ''),
            'dispatch_resolution' => (string) ($context['dispatch_resolution'] ?? ''),
            'source_sale_id' => $this->nullableInt($context['source_sale_id'] ?? null),
            'source_sale_detail_id' => $this->nullableInt($context['source_sale_detail_id'] ?? null),
            'component_source_sale_detail_id' => $this->nullableInt($context['component_source_sale_detail_id'] ?? null),
            'component_dispatch_detail_id' => $this->nullableInt($context['component_dispatch_detail_id'] ?? null),
            'component_sale_bundle_item_id' => $this->nullableInt($context['component_sale_bundle_item_id'] ?? null),
            'component_line_group_key' => (string) ($context['component_line_group_key'] ?? ''),
            'component_bundle_id' => $this->nullableInt($context['component_bundle_id'] ?? null),
            'component_quantity_per_bundle' => $this->nullableInt($context['component_quantity_per_bundle'] ?? null),
            'component_serial_ids' => collect($context['component_serial_ids'] ?? [])->map(fn ($id) => (int) $id)->values()->all(),
            'quantity_source' => (string) ($context['quantity_source'] ?? ''),
            'commercial_value_source' => (string) ($context['commercial_value_source'] ?? ''),
            'cash_return_amount' => $this->normalizeDecimal($context['cash_return_amount'] ?? 0),
            'planned_amount' => $this->normalizeDecimal($context['planned_amount'] ?? 0),
            'replacement_serial_owner_setting_id' => $this->nullableInt($context['replacement_serial_owner_setting_id'] ?? null),
            'replacement_serial_location_id' => $this->nullableInt($context['replacement_serial_location_id'] ?? null),
            'execution_mode' => $this->nullableString($context['execution_mode'] ?? null),
            'replacement_kind' => $this->nullableString($context['replacement_kind'] ?? null),
            'original_sale_correction_quantity' => $this->nullableDecimal($context['original_sale_correction_quantity'] ?? null),
            'original_sale_correction_amount' => $this->nullableDecimal($context['original_sale_correction_amount'] ?? null),
            'generated_replacement_sale_effects' => $this->normalizeGeneratedReplacementSaleEffects($context['generated_replacement_sale_effects'] ?? null),
        ];
    }

    private function normalizeReplacementExecutionContext(array $context): array
    {
        $normalized = $this->normalizeExecutionContext($context);

        if (($normalized['execution_mode'] ?? null) !== 'cross_owner_replacement') {
            return [];
        }

        return [
            'replacement_serial_owner_setting_id' => $normalized['replacement_serial_owner_setting_id'],
            'replacement_serial_location_id' => $normalized['replacement_serial_location_id'],
            'execution_mode' => $normalized['execution_mode'],
            'original_sale_correction_quantity' => $normalized['original_sale_correction_quantity'],
            'original_sale_correction_amount' => $normalized['original_sale_correction_amount'],
            'generated_replacement_sale_effects' => $normalized['generated_replacement_sale_effects'],
        ];
    }

    private function normalizeGeneratedReplacementSaleEffects($effects): ?array
    {
        if (! is_array($effects) || $effects === []) {
            return null;
        }

        return [
            'setting_id' => $this->nullableInt($effects['setting_id'] ?? null),
            'setting_name' => $this->nullableString($effects['setting_name'] ?? null),
            'location_id' => $this->nullableInt($effects['location_id'] ?? null),
            'location_name' => $this->nullableString($effects['location_name'] ?? null),
            'sale_reference' => $this->nullableString($effects['sale_reference'] ?? null),
            'customer_id' => $this->nullableInt($effects['customer_id'] ?? null),
            'customer_resolution_source' => $this->nullableString($effects['customer_resolution_source'] ?? null),
            'payment_amount' => $this->nullableDecimal($effects['payment_amount'] ?? null),
            'dispatch_quantity' => $this->nullableDecimal($effects['dispatch_quantity'] ?? null),
        ];
    }

    private function nullableDecimal($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->normalizeDecimal($value);
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function nullableInt($value): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}