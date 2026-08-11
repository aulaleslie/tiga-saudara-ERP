<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use App\Services\Reports\Concerns\EffectiveSaleReportingDate;

class SaleByCustomerReportQueryService
{
    public function build(SaleByCustomerReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        $query = SaleDetails::with([
                'sale.customer',
                'product.unit',
                'product.baseUnit',
                'product.category',
            ])
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->select(
                'sale_details.*',
                'customers.customer_name',
                DB::raw(EffectiveSaleReportingDate::sqlExpression() . ' as sale_date')
            )
            ->where('sales.setting_id', $scopeSettingId)
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' >= ?', [$filter->startDate])
            ->whereRaw(EffectiveSaleReportingDate::sqlExpression() . ' <= ?', [$filter->endDate]);

        // Customer filter (OR semantics)
        if (!empty($filter->customerIds)) {
            $query->whereIn('sales.customer_id', $filter->customerIds);
        }

        // Tag filter
        if (!empty($filter->tagIds)) {
            if ($filter->tagLogic === 'Mencakup semua') {
                foreach ($filter->tagIds as $tagId) {
                    $query->whereHas('sale.tags', fn($q) => $q->where('tags.id', $tagId));
                }
            } else {
                $query->whereHas('sale.tags', fn($q) => $q->whereIn('tags.id', $filter->tagIds));
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

        if ($sortField === 'customer_total') {
            // Join a subquery that computes the customer total for the currently filtered dataset
            $baseQuery = clone $query;
            $baseQuery->setEagerLoads([]);
            $baseQuery->getQuery()->columns = [];
            $baseQuery->getQuery()->orders = [];
            
            $customerTotals = $baseQuery
                ->select('sales.customer_id', DB::raw('SUM(CASE WHEN sales.is_tax_included = 1 THEN sale_details.sub_total ELSE sale_details.sub_total + COALESCE(sale_details.product_tax_amount, 0) END) as total_nominal'))
                ->groupBy('sales.customer_id');

            $query->leftJoinSub($customerTotals, 'ct', 'ct.customer_id', '=', 'sales.customer_id')
                  ->orderBy('ct.total_nominal', $direction)
                  ->orderBy('customers.id', 'asc') // Tie-breaker to prevent interleaving
                  ->orderByRaw(EffectiveSaleReportingDate::sqlExpression() . ' ' . $direction);
        } elseif ($sortField === 'customer_name') {
            $query->orderBy('customers.customer_name', $direction)
                  ->orderBy('customers.id', 'asc') // Tie-breaker to prevent interleaving
                  ->orderByRaw(EffectiveSaleReportingDate::sqlExpression() . ' ' . $direction);
        } else {
            // For date sorting, group customers by their max/min date first, so their rows don't interleave
            $aggFunc = $direction === 'asc' ? 'MIN' : 'MAX';
            
            $baseQuery = clone $query;
            $baseQuery->setEagerLoads([]);
            $baseQuery->getQuery()->columns = [];
            $baseQuery->getQuery()->orders = [];
            
            $effectiveDateExpr = EffectiveSaleReportingDate::sqlExpression();
            $customerDates = $baseQuery
                ->select('sales.customer_id', DB::raw("{$aggFunc}({$effectiveDateExpr}) as group_date"))
                ->groupBy('sales.customer_id');

            $query->leftJoinSub($customerDates, 'cd', 'cd.customer_id', '=', 'sales.customer_id')
                  ->orderBy('cd.group_date', $direction)
                  ->orderBy('customers.id', 'asc') // Tie-breaker to prevent interleaving
                  ->orderByRaw($effectiveDateExpr . ' ' . $direction);
        }
        
        $query->orderBy('sales.id', $direction)->orderBy('sale_details.id', 'asc');
    }

    public static function mapRows(SaleDetails $detail, float $previousRunningTotal, bool $isLastDetail = false): array
    {
        $sale = $detail->sale;
        $rows = [];

        $subTotal = (float) $detail->sub_total;
        $taxAmount = (float) ($detail->product_tax_amount ?? 0);
        $unitPrice = (float) ($detail->unit_price ?? 0);

        if ($sale?->is_tax_included) {
            $subTotal -= $taxAmount;
            if ($detail->quantity > 0) {
                $unitPrice = $subTotal / $detail->quantity;
            }
        }

        $productTotal = $previousRunningTotal + $subTotal;
        $rows[] = [
            'Customer / Tanggal'    => $detail->customer_name ?: ($sale?->customer?->customer_name ?? '-'),
            'Tipe transaksi'        => 'Faktur Penjualan',
            'No. transaksi'         => $sale?->reference ?? '-',
            'Nama produk'           => $detail->product_name ?? '-',
            'Keterangan'            => $sale?->note ?? '-',
            'Qty'                   => $detail->quantity ?? 0,
            'Unit'                  => $detail->product?->unit?->short_name ?? $detail->product?->baseUnit?->short_name ?? $detail->product?->product_unit ?? '-',
            'Harga per unit'        => $unitPrice,
            'Nominal tagihan'       => $subTotal,
            'Total nominal tagihan' => $productTotal,
            'is_tax_row'            => false,
        ];

        if ($isLastDetail && $sale && $sale->discount_amount > 0) {
            $discountAmount = (float) $sale->discount_amount;
            $productTotal -= $discountAmount;
            $rows[] = [
                'Customer / Tanggal'    => $detail->customer_name ?: ($sale?->customer?->customer_name ?? '-'),
                'Tipe transaksi'        => 'Faktur Penjualan',
                'No. transaksi'         => $sale?->reference ?? '-',
                'Nama produk'           => 'Diskon',
                'Keterangan'            => $sale?->note ?? '-',
                'Qty'                   => '',
                'Unit'                  => '',
                'Harga per unit'        => 0,
                'Nominal tagihan'       => -$discountAmount,
                'Total nominal tagihan' => $productTotal,
                'is_tax_row'            => false,
            ];
        }

        if ($taxAmount > 0) {
            $taxTotal = $productTotal + $taxAmount;
            $rows[] = [
                'Customer / Tanggal'    => $detail->customer_name ?: ($sale?->customer?->customer_name ?? '-'),
                'Tipe transaksi'        => 'Faktur Penjualan',
                'No. transaksi'         => $sale?->reference ?? '-',
                'Nama produk'           => 'Pajak',
                'Keterangan'            => $sale?->note ?? '-',
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
    public static function mapRowsForExport(SaleDetails $detail, float $previousRunningTotal, bool $isLastDetail = false): array
    {
        $sale = $detail->sale;
        $rows = [];

        $subTotal = (float) $detail->sub_total;
        $taxAmount = (float) ($detail->product_tax_amount ?? 0);
        $unitPrice = (float) ($detail->unit_price ?? 0);

        if ($sale?->is_tax_included) {
            $subTotal -= $taxAmount;
            if ($detail->quantity > 0) {
                $unitPrice = $subTotal / $detail->quantity;
            }
        }

        $productTotal = $previousRunningTotal + $subTotal;
        $rows[] = [
            'Customer'              => $detail->customer_name ?: ($sale?->customer?->customer_name ?? '-'),
            'Tanggal'               => $sale?->effective_date ?? '-',
            'Tipe transaksi'        => 'Faktur Penjualan',
            'No. transaksi'         => $sale?->reference ?? '-',
            'Nama produk'           => $detail->product_name ?? '-',
            'Qty'                   => $detail->quantity ?? 0,
            'Unit'                  => $detail->product?->unit?->short_name ?? $detail->product?->baseUnit?->short_name ?? $detail->product?->product_unit ?? '-',
            'Harga per unit'        => $unitPrice,
            'Nominal tagihan'       => $subTotal,
            'Total nominal tagihan' => $productTotal,
            'is_tax_row'            => false,
        ];

        if ($isLastDetail && $sale && $sale->discount_amount > 0) {
            $discountAmount = (float) $sale->discount_amount;
            $productTotal -= $discountAmount;
            $rows[] = [
                'Customer'              => $detail->customer_name ?: ($sale?->customer?->customer_name ?? '-'),
                'Tanggal'               => $sale?->effective_date ?? '-',
                'Tipe transaksi'        => 'Faktur Penjualan',
                'No. transaksi'         => $sale?->reference ?? '-',
                'Nama produk'           => 'Diskon',
                'Qty'                   => '',
                'Unit'                  => '',
                'Harga per unit'        => 0,
                'Nominal tagihan'       => -$discountAmount,
                'Total nominal tagihan' => $productTotal,
                'is_tax_row'            => false,
            ];
        }

        if ($taxAmount > 0) {
            $taxTotal = $productTotal + $taxAmount;
            $rows[] = [
                'Customer'              => $detail->customer_name ?: ($sale?->customer?->customer_name ?? '-'),
                'Tanggal'               => $sale?->effective_date ?? '-',
                'Tipe transaksi'        => 'Faktur Penjualan',
                'No. transaksi'         => $sale?->reference ?? '-',
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
