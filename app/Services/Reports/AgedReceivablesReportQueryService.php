<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;

class AgedReceivablesReportQueryService
{
    public function build(AgedReceivablesReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        $paymentsSub = DB::table('sale_payments')
            ->select('sale_id', DB::raw('SUM(amount) as paid_to_date'))
            ->where('status', 'ACTIVE')
            ->where('date', '<=', $filter->asOfDate)
            ->groupBy('sale_id');

        $driver = DB::connection()->getDriverName();
        $diffSql = $driver === 'sqlite'
            ? "CAST(julianday(?) - julianday(sales.date) AS INTEGER)"
            : "DATEDIFF(?, sales.date)";

        $query = Sale::query()
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->leftJoinSub($paymentsSub, 'payments', 'payments.sale_id', '=', 'sales.id')
            ->select(
                'sales.customer_id',
                'customers.customer_name',
                DB::raw('SUM(ROUND(sales.total_amount - COALESCE(payments.paid_to_date, 0), 2)) as total_balance')
            )
            ->selectRaw("SUM(CASE WHEN {$diffSql} BETWEEN 0 AND 30 THEN ROUND(sales.total_amount - COALESCE(payments.paid_to_date, 0), 2) ELSE 0 END) as bucket_1", [$filter->asOfDate])
            ->selectRaw("SUM(CASE WHEN {$diffSql} BETWEEN 31 AND 60 THEN ROUND(sales.total_amount - COALESCE(payments.paid_to_date, 0), 2) ELSE 0 END) as bucket_2", [$filter->asOfDate])
            ->selectRaw("SUM(CASE WHEN {$diffSql} BETWEEN 61 AND 90 THEN ROUND(sales.total_amount - COALESCE(payments.paid_to_date, 0), 2) ELSE 0 END) as bucket_3", [$filter->asOfDate])
            ->selectRaw("SUM(CASE WHEN {$diffSql} > 90 THEN ROUND(sales.total_amount - COALESCE(payments.paid_to_date, 0), 2) ELSE 0 END) as bucket_4", [$filter->asOfDate])
            ->where('sales.setting_id', $scopeSettingId)
            ->where('sales.date', '<=', $filter->asOfDate)
            ->whereRaw('ROUND(sales.total_amount - COALESCE(payments.paid_to_date, 0), 2) > ?', [0])
            ->groupBy('sales.customer_id', 'customers.customer_name')
            ->havingRaw('SUM(ROUND(sales.total_amount - COALESCE(payments.paid_to_date, 0), 2)) > ?', [0]);

        // Customer filter
        if (!empty($filter->customerIds)) {
            $query->whereIn('sales.customer_id', $filter->customerIds);
        }

        // Tag filter
        if (!empty($filter->tagIds)) {
            if ($filter->tagLogic === 'Mencakup semua') {
                foreach ($filter->tagIds as $tagId) {
                    $query->whereHas('tags', fn($q) => $q->where('tags.id', $tagId));
                }
            } else {
                $query->whereHas('tags', fn($q) => $q->whereIn('tags.id', $filter->tagIds));
            }
        }

        return $query;
    }

    public function applySort(Builder $query, string $sortField, string $sortDirection): void
    {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        if ($sortField === 'total_balance') {
            $query->orderByRaw("SUM(ROUND(sales.total_amount - COALESCE(payments.paid_to_date, 0), 2)) $direction")
                  ->orderBy('customers.id', 'asc'); // Tie-breaker
        } else {
            // fallback
            $query->orderBy('customers.customer_name', $direction)
                  ->orderBy('customers.id', 'asc');
        }
    }

    public static function mapRows($row): array
    {
        return [
            'Pelanggan'             => $row->customer_name ?? '-',
            'Total'                 => (float) $row->total_balance,
            '1 - 30 Hari'           => (float) $row->bucket_1,
            '31 - 60 Hari'          => (float) $row->bucket_2,
            '61 - 90 Hari'          => (float) $row->bucket_3,
            '> 90 Hari'             => (float) $row->bucket_4,
        ];
    }
}
