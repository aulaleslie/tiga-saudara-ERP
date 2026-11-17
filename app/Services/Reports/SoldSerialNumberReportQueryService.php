<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;

class SoldSerialNumberReportQueryService
{
    public function __construct(
        protected ?SoldSerialNumberOutcomeResolver $outcomeResolver = null,
    ) {
        $this->outcomeResolver ??= new SoldSerialNumberOutcomeResolver();
    }

    /**
     * Build the ordered projection of sold serial occurrences.
     *
     * Each returned item has:
     * - product_name (string)
     * - sn (string)
     * - day (int)
     * - month (int)
     * - year (int)
     *
     * @return Collection<int, array{product_name: string, sn: string, day: int, month: int, year: int}>
     */
    public function project(SoldSerialNumberReportFilterData $filter): Collection
    {
        if ($filter->scopeSettingIds === [] || $filter->productIds === []) {
            return collect();
        }

        $candidates = $this->loadApprovedCandidates($filter);
        if ($candidates->isEmpty()) {
            return collect();
        }

        $resolved = $this->outcomeResolver->resolve($candidates);
        if ($resolved->isEmpty()) {
            return collect();
        }

        $productNames = $this->loadCurrentProductNames(
            $resolved->pluck('product_id')->filter()->unique()->values()->all()
        );

        $rows = $resolved->map(function (array $item) use ($productNames): array {
            $productId = (int) $item['product_id'];
            $productName = $productNames[$productId]
                ?? ($item['fallback_product_name'] ?: 'Unknown Product');

            [$year, $month, $day] = $this->parseDateParts((string) $item['sale_date']);

            return [
                'product_name' => $productName,
                'sn' => (string) $item['sn'],
                'day' => $day,
                'month' => $month,
                'year' => $year,
                '_sort_date' => (string) $item['sale_date'],
                '_sort_sale_id' => (int) $item['sale_id'],
                '_sort_detail_id' => (int) $item['dispatch_detail_id'],
                '_sort_occ' => (int) $item['occurrence_index'],
            ];
        })->values()->all();

        usort($rows, function (array $a, array $b): int {
            return $a['_sort_date'] <=> $b['_sort_date']
                ?: strcasecmp($a['product_name'], $b['product_name'])
                ?: strcmp($a['product_name'], $b['product_name'])
                ?: strcmp($a['sn'], $b['sn'])
                ?: $a['_sort_sale_id'] <=> $b['_sort_sale_id']
                ?: $a['_sort_detail_id'] <=> $b['_sort_detail_id']
                ?: $a['_sort_occ'] <=> $b['_sort_occ'];
        });

        return collect($rows)->map(fn (array $row): array => [
            'product_name' => $row['product_name'],
            'sn' => $row['sn'],
            'day' => $row['day'],
            'month' => $row['month'],
            'year' => $row['year'],
        ])->values();
    }

    /**
     * Load approved dispatch serial occurrences for the scoped sales and date range.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function loadApprovedCandidates(SoldSerialNumberReportFilterData $filter): Collection
    {
        $productIds = $filter->productIds;
        $bundleIds = \Illuminate\Support\Facades\Schema::hasTable('product_bundles')
            ? DB::table('product_bundles')
                ->whereIn('parent_product_id', $productIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all()
            : [];

        $query = DispatchDetail::query()
            ->join('dispatches', 'dispatches.id', '=', 'dispatch_details.dispatch_id')
            ->join('sales', 'sales.id', '=', 'dispatch_details.sale_id')
            ->leftJoin('sale_details', 'sale_details.id', '=', 'dispatch_details.sale_detail_id')
            ->whereIn('sales.setting_id', $filter->scopeSettingIds)
            ->whereDate('sales.date', '>=', $filter->startDate)
            ->whereDate('sales.date', '<=', $filter->endDate)
            ->where('dispatches.status', Dispatch::STATUS_APPROVED)
            ->whereNotNull('dispatch_details.serial_numbers')
            ->where(function ($q) use ($productIds, $bundleIds): void {
                $q->whereIn('dispatch_details.product_id', $productIds)
                    ->orWhereIn('sale_details.product_id', $productIds);

                if ($bundleIds !== []) {
                    $q->orWhereIn('dispatch_details.bundle_id', $bundleIds);
                }
            })
            ->select([
                'dispatch_details.id as dispatch_detail_id',
                'dispatch_details.dispatch_id',
                'dispatch_details.sale_id',
                'dispatch_details.sale_detail_id',
                'dispatch_details.product_id',
                'dispatch_details.bundle_id',
                'dispatch_details.serial_numbers',
                'dispatch_details.pos_return_line_id',
                'dispatch_details.replacement_of_dispatch_detail_id',
                'dispatch_details.replacement_returned_serial_id',
                'dispatch_details.created_at as detail_created_at',
                'dispatches.approved_at as dispatch_approved_at',
                'dispatches.created_at as dispatch_created_at',
                'sales.date as sale_date',
                'sales.setting_id as sale_setting_id',
                'sale_details.product_name as fallback_product_name',
            ])
            ->orderBy('sales.date')
            ->orderBy('dispatch_details.sale_id')
            ->orderBy('dispatch_details.id');

        $occurrences = [];
        $seenKeys = [];

        $query->chunk(500, function ($details) use (&$occurrences, &$seenKeys): void {
            foreach ($details as $detail) {
                $serials = $this->parseSerialNumbers($detail->serial_numbers);
                if ($serials === []) {
                    continue;
                }

                $saleDate = substr((string) $detail->sale_date, 0, 10);

                foreach ($serials as $index => $sn) {
                    $normalizedSn = $this->normalizeSerial($sn);
                    $key = $detail->sale_id . '|' . $detail->dispatch_detail_id . '|' . $detail->product_id . '|' . $normalizedSn;

                    if (isset($seenKeys[$key])) {
                        continue;
                    }
                    $seenKeys[$key] = true;

                    $occurrences[] = [
                        'occurrence_key' => $key,
                        'occurrence_index' => $index,
                        'sale_id' => (int) $detail->sale_id,
                        'sale_setting_id' => (int) $detail->sale_setting_id,
                        'sale_date' => $saleDate,
                        'dispatch_id' => (int) $detail->dispatch_id,
                        'dispatch_detail_id' => (int) $detail->dispatch_detail_id,
                        'sale_detail_id' => $detail->sale_detail_id ? (int) $detail->sale_detail_id : null,
                        'product_id' => (int) $detail->product_id,
                        'bundle_id' => $detail->bundle_id ? (int) $detail->bundle_id : null,
                        'sn' => $sn,
                        'normalized_sn' => $normalizedSn,
                        'pos_return_line_id' => $detail->pos_return_line_id ? (int) $detail->pos_return_line_id : null,
                        'replacement_of_dispatch_detail_id' => $detail->replacement_of_dispatch_detail_id ? (int) $detail->replacement_of_dispatch_detail_id : null,
                        'replacement_returned_serial_id' => $detail->replacement_returned_serial_id ? (int) $detail->replacement_returned_serial_id : null,
                        'fallback_product_name' => $detail->fallback_product_name ? (string) $detail->fallback_product_name : '',
                        'dispatch_time' => (string) ($detail->dispatch_approved_at ?? $detail->dispatch_created_at ?? $detail->detail_created_at ?? ''),
                    ];
                }
            }
        });

        return collect($occurrences);
    }

    /**
     * @return array<int, string>
     */
    protected function parseSerialNumbers(mixed $raw): array
    {
        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $items = $decoded;
            } else {
                $items = preg_split('/[\r\n,]+/', $trimmed) ?: [];
            }
        } else {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $sn = trim((string) $item);
            if ($sn !== '') {
                $result[] = $sn;
            }
        }

        return $result;
    }

    protected function normalizeSerial(string $sn): string
    {
        return strtoupper(trim($sn));
    }

    /**
     * @param  array<int>  $productIds
     * @return array<int, string>
     */
    protected function loadCurrentProductNames(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $productIds)
            ->pluck('product_name', 'id')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    protected function parseDateParts(string $date): array
    {
        $parts = explode('-', substr($date, 0, 10));
        if (count($parts) !== 3) {
            return [0, 0, 0];
        }

        return [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
    }
}
