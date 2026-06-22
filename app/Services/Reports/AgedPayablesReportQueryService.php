<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;

class AgedPayablesReportQueryService
{
    public function build(AgedPayablesReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        $paymentsSub = DB::table('purchase_payments')
            ->select('purchase_id', DB::raw('SUM(amount / 100.0) as paid_to_date'))
            ->where('status', 'ACTIVE')
            ->where('date', '<=', $filter->asOfDate)
            ->groupBy('purchase_id');

        $driver = DB::connection()->getDriverName();
        
        if ($filter->agingBasis === 'Tanggal Jatuh Tempo') {
            $dateField = "COALESCE(purchases.due_date, purchases.date)";
        } else {
            $dateField = "purchases.date";
        }

        $diffSql = $driver === 'sqlite'
            ? "CAST(julianday(?) - julianday({$dateField}) AS INTEGER)"
            : "DATEDIFF(?, {$dateField})";

        $query = Purchase::query()
            ->join('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->leftJoinSub($paymentsSub, 'payments', 'payments.purchase_id', '=', 'purchases.id')
            ->select(
                'purchases.supplier_id',
                'suppliers.supplier_name',
                DB::raw('SUM(ROUND(purchases.total_amount - COALESCE(payments.paid_to_date, 0), 2)) as total_balance')
            )
            ->selectRaw("SUM(CASE WHEN {$diffSql} <= 30 THEN ROUND(purchases.total_amount - COALESCE(payments.paid_to_date, 0), 2) ELSE 0 END) as bucket_1", [$filter->asOfDate])
            ->selectRaw("SUM(CASE WHEN {$diffSql} BETWEEN 31 AND 60 THEN ROUND(purchases.total_amount - COALESCE(payments.paid_to_date, 0), 2) ELSE 0 END) as bucket_2", [$filter->asOfDate])
            ->selectRaw("SUM(CASE WHEN {$diffSql} BETWEEN 61 AND 90 THEN ROUND(purchases.total_amount - COALESCE(payments.paid_to_date, 0), 2) ELSE 0 END) as bucket_3", [$filter->asOfDate])
            ->selectRaw("SUM(CASE WHEN {$diffSql} > 90 THEN ROUND(purchases.total_amount - COALESCE(payments.paid_to_date, 0), 2) ELSE 0 END) as bucket_4", [$filter->asOfDate])
            ->where('purchases.setting_id', $scopeSettingId)
            ->where('purchases.date', '<=', $filter->asOfDate)
            ->whereIn('purchases.status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY])
            ->whereRaw('ROUND(purchases.total_amount - COALESCE(payments.paid_to_date, 0), 2) > ?', [0])
            ->groupBy('purchases.supplier_id', 'suppliers.supplier_name')
            ->havingRaw('SUM(ROUND(purchases.total_amount - COALESCE(payments.paid_to_date, 0), 2)) > ?', [0]);

        // Supplier filter
        if (!empty($filter->supplierIds)) {
            $query->whereIn('purchases.supplier_id', $filter->supplierIds);
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
            $query->orderByRaw("SUM(ROUND(purchases.total_amount - COALESCE(payments.paid_to_date, 0), 2)) $direction")
                  ->orderBy('suppliers.id', 'asc'); // Tie-breaker
        } else {
            // fallback
            $query->orderBy('suppliers.supplier_name', $direction)
                  ->orderBy('suppliers.id', 'asc');
        }
    }

    public static function mapRows($row): array
    {
        return [
            'Vendor'                => $row->supplier_name ?? '-',
            'Total'                 => (float) $row->total_balance,
            '1 - 30 Hari'           => (float) $row->bucket_1,
            '31 - 60 Hari'          => (float) $row->bucket_2,
            '61 - 90 Hari'          => (float) $row->bucket_3,
            '> 90 Hari'             => (float) $row->bucket_4,
        ];
    }
}
