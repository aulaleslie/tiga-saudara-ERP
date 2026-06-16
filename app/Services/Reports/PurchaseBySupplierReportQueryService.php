<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;

class PurchaseBySupplierReportQueryService
{
    public function build(PurchaseBySupplierReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        $query = PurchaseDetail::with([
                'purchase.supplier',
                'product.unit',
                'product.baseUnit',
                'product.category',
            ])
            ->join('purchases', 'purchase_details.purchase_id', '=', 'purchases.id')
            ->join('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->select(
                'purchase_details.*',
                'suppliers.supplier_name',
                'purchases.date as purchase_date'
            )
            ->where('purchases.setting_id', $scopeSettingId)
            ->where('purchases.date', '>=', $filter->startDate)
            ->where('purchases.date', '<=', $filter->endDate);

        // Supplier filter (OR semantics)
        if (!empty($filter->supplierIds)) {
            $query->whereIn('purchases.supplier_id', $filter->supplierIds);
        }

        // Tag filter
        if (!empty($filter->tagIds)) {
            if ($filter->tagLogic === 'Mencakup semua') {
                foreach ($filter->tagIds as $tagId) {
                    $query->whereHas('purchase.tags', fn($q) => $q->where('tags.id', $tagId));
                }
            } else {
                $query->whereHas('purchase.tags', fn($q) => $q->whereIn('tags.id', $filter->tagIds));
            }
        }

        // Category filter
        if (!empty($filter->categoryIds)) {
            if ($filter->categoryLogic === 'Mencakup semua') {
                foreach ($filter->categoryIds as $categoryId) {
                    $query->whereHas('product', fn($q) => $q->where('category_id', $categoryId));
                }
            } else {
                $query->whereHas('product', fn($q) => $q->whereIn('category_id', $filter->categoryIds));
            }
        }

        return $query;
    }

    public function applySort(Builder $query, string $sortField, string $sortDirection): void
    {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        if ($sortField === 'supplier_total') {
            // Join a subquery that computes the supplier total for the currently filtered dataset
            $baseQuery = clone $query;
            $baseQuery->setEagerLoads([]);
            $baseQuery->getQuery()->columns = [];
            $baseQuery->getQuery()->orders = [];
            
            $supplierTotals = $baseQuery
                ->select('purchases.supplier_id', DB::raw('SUM(purchase_details.sub_total + COALESCE(purchase_details.product_tax_amount, 0)) as total_nominal'))
                ->groupBy('purchases.supplier_id');

            $query->leftJoinSub($supplierTotals, 'st', 'st.supplier_id', '=', 'purchases.supplier_id')
                  ->orderBy('st.total_nominal', $direction)
                  ->orderBy('suppliers.id', 'asc') // Tie-breaker to prevent interleaving
                  ->orderBy('purchases.date', 'desc');
        } elseif ($sortField === 'supplier_name') {
            $query->orderBy('suppliers.supplier_name', $direction)
                  ->orderBy('suppliers.id', 'asc') // Tie-breaker to prevent interleaving
                  ->orderBy('purchases.date', 'desc');
        } else {
            // For date sorting, group suppliers by their max/min date first, so their rows don't interleave
            $aggFunc = $direction === 'asc' ? 'MIN' : 'MAX';
            
            $baseQuery = clone $query;
            $baseQuery->setEagerLoads([]);
            $baseQuery->getQuery()->columns = [];
            $baseQuery->getQuery()->orders = [];
            
            $supplierDates = $baseQuery
                ->select('purchases.supplier_id', DB::raw("{$aggFunc}(purchases.date) as group_date"))
                ->groupBy('purchases.supplier_id');

            $query->leftJoinSub($supplierDates, 'sd', 'sd.supplier_id', '=', 'purchases.supplier_id')
                  ->orderBy('sd.group_date', $direction)
                  ->orderBy('suppliers.id', 'asc') // Tie-breaker to prevent interleaving
                  ->orderBy('purchases.date', 'desc');
        }
        
        $query->orderBy('purchases.id', 'desc')->orderBy('purchase_details.id', 'asc');
    }

    public static function mapRows(PurchaseDetail $detail, float $previousRunningTotal): array
    {
        $purchase = $detail->purchase;
        $rows = [];

        $subTotal = (float) $detail->sub_total;
        $taxAmount = (float) ($detail->product_tax_amount ?? 0);

        $productTotal = $previousRunningTotal + $subTotal;
        $rows[] = [
            'Supplier / Tanggal'    => $detail->supplier_name ?: ($purchase?->supplier?->supplier_name ?? '-'),
            'Tipe transaksi'        => 'Faktur Pembelian',
            'No. transaksi'         => $purchase?->reference ?? '-',
            'Nama produk'           => $detail->product_name ?? '-',
            'Keterangan'            => $purchase?->note ?? '-',
            'Qty'                   => $detail->quantity ?? 0,
            'Unit'                  => $detail->product?->unit?->short_name ?? $detail->product?->baseUnit?->short_name ?? $detail->product?->product_unit ?? '-',
            'Harga per unit'        => $detail->unit_price ?? 0,
            'Nominal tagihan'       => $subTotal,
            'Total nominal tagihan' => $productTotal,
            'is_tax_row'            => false,
        ];

        if ($taxAmount > 0) {
            $taxTotal = $productTotal + $taxAmount;
            $rows[] = [
                'Supplier / Tanggal'    => $detail->supplier_name ?: ($purchase?->supplier?->supplier_name ?? '-'),
                'Tipe transaksi'        => 'Faktur Pembelian',
                'No. transaksi'         => $purchase?->reference ?? '-',
                'Nama produk'           => 'Pajak',
                'Keterangan'            => $purchase?->note ?? '-',
                'Qty'                   => '',
                'Unit'                  => '',
                'Harga per unit'        => 0,
                'Nominal tagihan'       => $taxAmount,
                'Total nominal tagihan' => $taxTotal,
                'is_tax_row'            => true,
            ];
        }

        return $rows;
    }
    public static function mapRowsForExport(PurchaseDetail $detail, float $previousRunningTotal): array
    {
        $purchase = $detail->purchase;
        $rows = [];

        $subTotal = (float) $detail->sub_total;
        $taxAmount = (float) ($detail->product_tax_amount ?? 0);

        $productTotal = $previousRunningTotal + $subTotal;
        $rows[] = [
            'Supplier'              => $detail->supplier_name ?: ($purchase?->supplier?->supplier_name ?? '-'),
            'Tanggal'               => $purchase?->date ?? '-',
            'Tipe transaksi'        => 'Faktur Pembelian',
            'No. transaksi'         => $purchase?->reference ?? '-',
            'Nama produk'           => $detail->product_name ?? '-',
            'Qty'                   => $detail->quantity ?? 0,
            'Unit'                  => $detail->product?->unit?->short_name ?? $detail->product?->baseUnit?->short_name ?? $detail->product?->product_unit ?? '-',
            'Harga per unit'        => $detail->unit_price ?? 0,
            'Nominal tagihan'       => $subTotal,
            'Total nominal tagihan' => $productTotal,
            'is_tax_row'            => false,
        ];

        if ($taxAmount > 0) {
            $taxTotal = $productTotal + $taxAmount;
            $rows[] = [
                'Supplier'              => $detail->supplier_name ?: ($purchase?->supplier?->supplier_name ?? '-'),
                'Tanggal'               => $purchase?->date ?? '-',
                'Tipe transaksi'        => 'Faktur Pembelian',
                'No. transaksi'         => $purchase?->reference ?? '-',
                'Nama produk'           => 'Pajak',
                'Qty'                   => '',
                'Unit'                  => '',
                'Harga per unit'        => 0,
                'Nominal tagihan'       => $taxAmount,
                'Total nominal tagihan' => $taxTotal,
                'is_tax_row'            => true,
            ];
        }

        return $rows;
    }
}
