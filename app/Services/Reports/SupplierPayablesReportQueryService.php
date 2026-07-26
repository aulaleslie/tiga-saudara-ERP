<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;

class SupplierPayablesReportQueryService
{
    public function build(SupplierPayablesReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        // Subquery for paid_to_date up to endDate using purchase_payments
        $paymentsSub = DB::table('purchase_payments')
            ->select('purchase_id', DB::raw('SUM(amount) as paid_to_date'))
            ->where('status', 'ACTIVE')
            ->where('date', '<=', $filter->endDate)
            ->groupBy('purchase_id');

        $query = Purchase::with(['supplier', 'tags'])
            ->join('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->leftJoinSub($paymentsSub, 'payments', 'payments.purchase_id', '=', 'purchases.id')
            ->select(
                'purchases.*',
                DB::raw('ROUND(purchases.total_amount - COALESCE(payments.paid_to_date, 0), 2) as saldo')
            )
            ->where('purchases.setting_id', $scopeSettingId)
            ->whereIn('purchases.status', [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY])
            ->where('purchases.date', '<=', $filter->endDate)
            ->whereRaw('ROUND(purchases.total_amount - COALESCE(payments.paid_to_date, 0), 2) > ?', [0]);

        // Optional filter: dueDateUntil
        if (!empty($filter->dueDateUntil)) {
            $query->where('purchases.due_date', '<=', $filter->dueDateUntil);
        }

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
            // Join a subquery that computes the supplier total for the currently filtered dataset
            $baseQuery = clone $query;
            $baseQuery->setEagerLoads([]);
            $baseQuery->getQuery()->orders = [];

            $supplierTotals = DB::table(DB::raw("({$baseQuery->toSql()}) as sub"))
                ->mergeBindings($baseQuery->getQuery())
                ->select('sub.supplier_id', DB::raw('SUM(sub.saldo) as total_nominal'))
                ->groupBy('sub.supplier_id');

            $query->leftJoinSub($supplierTotals, 'st', 'st.supplier_id', '=', 'purchases.supplier_id')
                  ->orderBy('st.total_nominal', $direction)
                  ->orderBy('suppliers.id', 'asc') // Tie-breaker to prevent interleaving
                  ->orderBy('purchases.date', 'asc'); // Always sort purchases within supplier by date
        } elseif ($sortField === 'supplier_name') {
            $query->orderBy('suppliers.supplier_name', $direction)
                  ->orderBy('suppliers.id', 'asc') // Tie-breaker to prevent interleaving
                  ->orderBy('purchases.date', 'asc');
        } else {
            // fallback
            $query->orderBy('suppliers.supplier_name', 'asc')
                  ->orderBy('suppliers.id', 'asc')
                  ->orderBy('purchases.date', 'asc');
        }
    }

    public static function mapRows(Purchase $purchase): array
    {
        return [
            'Supplier / Tanggal' => $purchase->supplier?->supplier_name ?? '-',
            'Tipe transaksi'     => 'Purchase Invoice',
            'No. transaksi'      => $purchase->reference ?? '-',
            'Jatuh Tempo'        => $purchase->due_date ?? '-',
            'Keterangan'         => $purchase->note ?? '-',
            'Jumlah'             => (float) $purchase->total_amount,
            'Saldo'              => (float) $purchase->saldo,
        ];
    }

    public static function mapRowsForExport(Purchase $purchase): array
    {
        return [
            'Supplier'       => $purchase->supplier?->supplier_name ?? '-',
            'Date'           => $purchase->date ?? '-',
            'Transaksi'      => 'Purchase Invoice',
            'No.'            => $purchase->reference ?? '-',
            'Jatuh Tempo'    => $purchase->due_date ?? '-',
            'Keterangan'     => $purchase->note ?? '-',
            'Jumlah'         => (float) $purchase->total_amount,
            'Saldo'          => (float) $purchase->saldo,
        ];
    }
}
