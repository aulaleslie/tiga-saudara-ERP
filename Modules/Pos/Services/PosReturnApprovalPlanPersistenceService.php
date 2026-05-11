<?php

namespace Modules\Pos\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
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
            $this->createSaleReturnsFromPlan($posReturn, $groups);
        } else {
            $this->assertExistingSaleReturnsMatchPlan($posReturn, $groups);
        }

        $posReturn->unsetRelation('saleReturns');
        $posReturn->load('saleReturns.saleReturnDetails');
        $this->syncPosReturnLineLinks($posReturn);

        return $posReturn->saleReturns;
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
                    'serial_number_ids' => $this->resolveSerialNumberIds($line),
                    'bundle_group_key' => $this->resolveBundleGroupKey($plannedDetail, $line),
                    'stock_behavior' => $this->resolveStockBehavior($plannedDetail, $line),
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

    private function resolveSerialNumberIds(?PosReturnLine $line): array
    {
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
            'quantity_source' => $quantitySource,
            'commercial_value_source' => $commercialValueSource,
            'cash_return_amount' => (float) ($plannedDetail['cash_return_amount'] ?? 0),
            'planned_amount' => (float) ($plannedDetail['amount'] ?? 0),
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
            'quantity_source' => (string) ($context['quantity_source'] ?? ''),
            'commercial_value_source' => (string) ($context['commercial_value_source'] ?? ''),
            'cash_return_amount' => $this->normalizeDecimal($context['cash_return_amount'] ?? 0),
            'planned_amount' => $this->normalizeDecimal($context['planned_amount'] ?? 0),
        ];
    }

    private function nullableInt($value): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}