<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;

class CustomerReceivablesReportQueryService
{
    public function build(CustomerReceivablesReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        // Subquery for paid_to_date up to endDate
        $paymentsSub = DB::table('sale_payments')
            ->select('sale_id', DB::raw('SUM(amount) as paid_to_date'))
            ->where('status', 'ACTIVE')
            ->where('date', '<=', $filter->endDate)
            ->groupBy('sale_id');

        $query = Sale::with(['customer', 'tags'])
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->leftJoinSub($paymentsSub, 'payments', 'payments.sale_id', '=', 'sales.id')
            ->select(
                // sales.* already includes the denormalized sales.customer_name column.
                // Do NOT also select customers.customer_name: when this query is wrapped
                // as a derived table (grand-total / total_balance sort), MySQL rejects the
                // duplicate column name (SQLSTATE 42S21). Sorting references
                // customers.customer_name directly in the ORDER BY clause instead.
                'sales.*',
                DB::raw('ROUND(sales.total_amount - COALESCE(payments.paid_to_date, 0), 2) as sisa_piutang')
            )
            ->where('sales.setting_id', $scopeSettingId)
            ->where('sales.date', '<=', $filter->endDate)
            ->whereRaw('ROUND(sales.total_amount - COALESCE(payments.paid_to_date, 0), 2) > ?', [0]);

        // Optional filter: dueDateUntil
        if (!empty($filter->dueDateUntil)) {
            $query->where('sales.due_date', '<=', $filter->dueDateUntil);
        }

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
            // Join a subquery that computes the customer total for the currently filtered dataset
            $baseQuery = clone $query;
            $baseQuery->setEagerLoads([]);
            $baseQuery->getQuery()->orders = [];

            $customerTotals = DB::table(DB::raw("({$baseQuery->toSql()}) as sub"))
                ->mergeBindings($baseQuery->getQuery())
                ->select('sub.customer_id', DB::raw('SUM(sub.sisa_piutang) as total_nominal'))
                ->groupBy('sub.customer_id');

            $query->leftJoinSub($customerTotals, 'ct', 'ct.customer_id', '=', 'sales.customer_id')
                  ->orderBy('ct.total_nominal', $direction)
                  ->orderBy('customers.id', 'asc') // Tie-breaker to prevent interleaving
                  ->orderBy('sales.date', 'asc'); // Always sort sales within customer by date
        } elseif ($sortField === 'customer_name') {
            $query->orderBy('customers.customer_name', $direction)
                  ->orderBy('customers.id', 'asc') // Tie-breaker to prevent interleaving
                  ->orderBy('sales.date', 'asc');
        } else {
            // fallback
            $query->orderBy('customers.customer_name', 'asc')
                  ->orderBy('customers.id', 'asc')
                  ->orderBy('sales.date', 'asc');
        }
    }

    public static function mapRows(Sale $sale): array
    {
        return [
            'Pelanggan / Tanggal'   => $sale->customer_name ?: ($sale->customer?->customer_name ?? '-'),
            'Tipe transaksi'        => 'Faktur Penjualan',
            'No. transaksi'         => $sale->reference ?? '-',
            'Jatuh Tempo'           => $sale->due_date ?? '-',
            'Deskripsi'             => $sale->note ?? '-',
            'Jumlah'                => (float) $sale->total_amount,
            'Sisa Piutang'          => (float) $sale->sisa_piutang,
        ];
    }

    public static function mapRowsForExport(Sale $sale): array
    {
        return [
            'Pelanggan'             => $sale->customer_name ?: ($sale->customer?->customer_name ?? '-'),
            'Tanggal'               => $sale->date ?? '-',
            'Transaksi'             => 'Sales Invoice', // using sample CSV value
            'No.'                   => $sale->reference ?? '-',
            'Jatuh Tempo'           => $sale->due_date ?? '-',
            'Deskripsi'             => $sale->note ?? '-',
            'Jumlah'                => (float) $sale->total_amount,
            'Sisa Piutang'          => (float) $sale->sisa_piutang,
        ];
    }
}
