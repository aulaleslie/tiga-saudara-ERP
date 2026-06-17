<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Entities\Dispatch;

class SaleReportQueryService
{
    public function build(SaleReportFilterData $filter): Builder
    {
        if ($filter->reportMode === 'header') {
            return $this->buildHeaderQuery($filter);
        }

        return $this->buildDetailQuery($filter);
    }

    private function buildDetailQuery(SaleReportFilterData $filter): Builder
    {
        $activePaymentSub = $this->activePaymentSubquery();

        $gudangSub = DB::table('dispatch_details as dd')
            ->join('dispatches as d', 'd.id', '=', 'dd.dispatch_id')
            ->join('locations as loc', 'loc.id', '=', 'dd.location_id')
            ->where('d.status', Dispatch::STATUS_APPROVED)
            ->select('dd.sale_id', 'dd.product_id', DB::raw('GROUP_CONCAT(DISTINCT loc.name) as gudang'))
            ->groupBy('dd.sale_id', 'dd.product_id');

        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');
        $dateColumn = 'sales.date';
        $effectivePaidExpression = $this->effectivePaidExpression();

        $query = SaleDetails::query()->with([
                'sale.customer',
                'sale.tags',
                'tax',
                'product.unit',
                'product.baseUnit',
            ])
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->leftJoin('customers', 'sales.customer_id', '=', 'customers.id')
            ->leftJoinSub($activePaymentSub, 'ap', 'ap.sale_id', '=', 'sales.id')
            ->leftJoinSub($gudangSub, 'gd', function ($join) {
                $join->on('gd.sale_id', '=', 'sale_details.sale_id')
                     ->on('gd.product_id', '=', 'sale_details.product_id');
            })
            ->select(
                'sale_details.*',
                'gd.gudang',
                DB::raw($effectivePaidExpression . ' as derived_active_paid'),
            );

        return $this->applyCommonFilters($query, $filter, $scopeSettingId, $dateColumn, 'sale.tags');
    }

    private function buildHeaderQuery(SaleReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');
        $dateColumn = 'sales.date';
        $effectivePaidExpression = $this->effectivePaidExpression();

        $query = Sale::query()->with([
                'customer',
                'tags',
            ])
            ->leftJoin('customers', 'sales.customer_id', '=', 'customers.id')
            ->leftJoinSub($this->activePaymentSubquery(), 'ap', 'ap.sale_id', '=', 'sales.id')
            ->select(
                'sales.*',
                DB::raw($effectivePaidExpression . ' as derived_active_paid'),
            );

        return $this->applyCommonFilters($query, $filter, $scopeSettingId, $dateColumn, 'tags');
    }

    private function activePaymentSubquery()
    {
        return DB::table('sale_payments')
            ->select('sale_id')
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as active_paid',
                [SalePayment::STATUS_ACTIVE]
            )
            ->selectRaw('COUNT(*) as payment_count')
            ->groupBy('sale_id');
    }

    private function effectivePaidExpression(): string
    {
        return <<<'SQL'
CASE
    WHEN COALESCE(ap.payment_count, 0) > 0 THEN COALESCE(ap.active_paid, 0)
    WHEN COALESCE(sales.paid_amount, 0) > 0 THEN COALESCE(sales.paid_amount, 0)
    WHEN COALESCE(sales.total_amount, 0) - COALESCE(sales.due_amount, 0) > 0
        THEN COALESCE(sales.total_amount, 0) - COALESCE(sales.due_amount, 0)
    ELSE 0
END
SQL;
    }

    private function applyCommonFilters(
        Builder $query,
        SaleReportFilterData $filter,
        int $scopeSettingId,
        string $dateColumn,
        string $tagRelation
    ): Builder {
        $query
            ->when(!$filter->isGlobal, fn($builder) => $builder->where('sales.setting_id', $scopeSettingId))
            ->where($dateColumn, '>=', $filter->startDate)
            ->where($dateColumn, '<=', $filter->endDate);

        if (!empty($filter->customerIds)) {
            $query->whereIn('sales.customer_id', $filter->customerIds);
        }

        if (!empty($filter->tagIds)) {
            $query->whereHas($tagRelation, fn($builder) => $builder->whereIn('tags.id', $filter->tagIds));
        }

        if (!empty($filter->documentStatuses)) {
            $query->whereIn('sales.status', $filter->documentStatuses);
        }

        if (!empty($filter->paymentStatuses)) {
            $query->where(function (Builder $builder) use ($filter) {
                foreach ($filter->paymentStatuses as $status) {
                    match (strtoupper($status)) {
                        'UNPAID' => $builder->orWhere(function ($subQuery) {
                            $subQuery->whereRaw('(' . $this->effectivePaidExpression() . ') <= 0');
                        }),
                        'PARTIAL' => $builder->orWhere(function ($subQuery) {
                            $subQuery->whereRaw('(' . $this->effectivePaidExpression() . ') > 0')
                                ->whereRaw('(' . $this->effectivePaidExpression() . ') < sales.total_amount');
                        }),
                        'PAID' => $builder->orWhere(function ($subQuery) {
                            $subQuery->whereRaw('(' . $this->effectivePaidExpression() . ') >= sales.total_amount')
                                ->whereRaw('sales.total_amount > 0');
                        }),
                        default => null,
                    };
                }
            });
        }

        return $query;
    }

    public function applySort(Builder $query, string $sortField, string $sortDirection, string $reportMode = 'detail'): void
    {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        match ($sortField) {
            'date'                     => $query->orderBy('sales.date', $direction),
            'reference'                => $query->orderBy('sales.reference', $direction),
            'customer_name'            => $query->orderBy('customers.customer_name', $direction),
            'status'                   => $query->orderBy('sales.status', $direction),
            'payment_status'           => $query->orderByRaw('
                (CASE 
                    WHEN derived_active_paid <= 0 THEN 1 
                    WHEN sales.total_amount > 0 AND derived_active_paid >= sales.total_amount THEN 3 
                    ELSE 2 
                END) ' . $direction
            ),
            'total_amount'             => $query->orderBy('sales.total_amount', $direction),
            'product_name'             => $reportMode === 'detail' ? $query->orderBy('sale_details.product_name', $direction) : null,
            'product_code'             => $reportMode === 'detail' ? $query->orderBy('sale_details.product_code', $direction) : null,
            default                    => null,
        };

        $query->orderBy('sales.id', 'desc');

        if ($reportMode === 'detail') {
            $query->orderBy('sale_details.id', 'asc');
        }
    }

    public static function headingsFor(string $reportMode = 'detail'): array
    {
        if ($reportMode === 'header') {
            return [
                'Tanggal',
                'Nomor Transaksi',
                'Nama Pelanggan',
                'Status Dokumen',
                'Status Pembayaran',
                'Memo',
                'Total',
                'Sisa Tagihan',
                'Jumlah Kena Pajak',
                'Total Pajak',
                'Pembayaran',
                'Tag',
            ];
        }

        return [
            'Tanggal',
            'Nomor Transaksi',
            'Nama Pelanggan',
            'Status Dokumen',
            'Status Pembayaran',
            'Memo',
            'Total',
            'Sisa Tagihan',
            'Jumlah Kena Pajak',
            'Total Pajak',
            'Pembayaran',
            'Email',
            'Alamat',
            'Tag',
            'Gudang',
            'Nama Produk',
            'Kode Produk',
            'Deskripsi',
            'Kuantitas',
            'Satuan',
            'Harga per Unit',
            'Tarif Pajak',
            'Jumlah Pajak',
            'Jumlah Kena Pajak per Baris',
            'Jumlah Per Baris',
            'Pesan',
            'Biaya Pengiriman',
            'Diskon',
            'Sisa Tagihan Hari Ini',
            'Diskon %',
        ];
    }

    public static function mapRow(mixed $row, string $reportMode = 'detail'): array
    {
        if ($reportMode === 'header') {
            $sale = $row instanceof Sale ? $row : ($row?->sale);

            return $sale instanceof Sale
                ? self::mapHeaderRow($sale)
                : array_fill_keys(self::headingsFor('header'), '-');
        }

        return $row instanceof SaleDetails
            ? self::mapDetailRow($row)
            : array_fill_keys(self::headingsFor('detail'), '-');
    }

    private static function mapDetailRow(SaleDetails $detail): array
    {
        $sale = $detail->sale;
        $customer = $sale?->customer;
        $tax = $detail->tax;

        $activePaid = (float) ($detail->derived_active_paid ?? 0);
        $totalAmount = (float) ($sale?->total_amount ?? 0);

        return [
            'Tanggal'                     => self::formatDate($sale?->date),
            'Nomor Transaksi'             => $sale?->reference ?? '-',
            'Nama Pelanggan'              => $customer?->customer_name ?? '-',
            'Status Dokumen'              => self::documentStatusLabel($sale?->status),
            'Status Pembayaran'           => self::derivedPaymentStatus($activePaid, $totalAmount),
            'Memo'                        => $sale?->note ?? '-',
            'Total'                       => $totalAmount,
            'Sisa Tagihan'                => max(0, $totalAmount - $activePaid),
            'Jumlah Kena Pajak'           => max(0, $totalAmount - ($sale?->tax_amount ?? 0)),
            'Total Pajak'                 => $sale?->tax_amount ?? 0,
            'Pembayaran'                  => $activePaid,
            'Email'                       => $customer?->customer_email ?? '-',
            'Alamat'                      => $customer?->address ?? '-',
            'Tag'                         => self::tagNames($sale),
            'Gudang'                      => $detail->gudang ?? '-',
            'Nama Produk'                 => $detail->product_name ?? '-',
            'Kode Produk'                 => $detail->product_code ?? '-',
            'Deskripsi'                   => $detail->product?->description ?? '-',
            'Kuantitas'                   => $detail->quantity ?? 0,
            'Satuan'                      => $detail->product?->unit?->short_name ?? $detail->product?->baseUnit?->short_name ?? $detail->product?->product_unit ?? '-',
            'Harga per Unit'              => $detail->price ?? 0,
            'Tarif Pajak'                 => $tax?->tax_percentage ?? '-',
            'Jumlah Pajak'                => $detail->product_tax_amount ?? 0,
            'Jumlah Kena Pajak per Baris' => max(0, ($detail->sub_total ?? 0) - ($detail->product_tax_amount ?? 0)),
            'Jumlah Per Baris'            => $detail->sub_total ?? 0,
            'Pesan'                       => $sale?->note ?? '-',
            'Biaya Pengiriman'            => $sale?->shipping_amount ?? 0,
            'Diskon'                      => $sale?->discount_amount ?? 0,
            'Sisa Tagihan Hari Ini'       => max(0, $totalAmount - $activePaid),
            'Diskon %'                    => $sale?->discount_amount
                                                ? ($totalAmount > 0 ? round($sale->discount_amount / $totalAmount * 100, 2) : 0)
                                                : 0,
        ];
    }

    private static function mapHeaderRow(Sale $sale): array
    {
        $customer = $sale->customer;
        $activePaid = (float) ($sale->derived_active_paid ?? 0);
        $totalAmount = (float) ($sale->total_amount ?? 0);

        return [
            'Tanggal' => self::formatDate($sale->date),
            'Nomor Transaksi' => $sale->reference ?? '-',
            'Nama Pelanggan' => $customer?->customer_name ?? '-',
            'Status Dokumen' => self::documentStatusLabel($sale->status),
            'Status Pembayaran' => self::derivedPaymentStatus($activePaid, $totalAmount),
            'Memo' => $sale->note ?? '-',
            'Total' => $totalAmount,
            'Sisa Tagihan' => max(0, $totalAmount - $activePaid),
            'Jumlah Kena Pajak' => max(0, $totalAmount - ($sale->tax_amount ?? 0)),
            'Total Pajak' => $sale->tax_amount ?? 0,
            'Pembayaran' => $activePaid,
            'Tag' => self::tagNames($sale),
        ];
    }

    private static function derivedPaymentStatus(float $activePaid, float $totalAmount): string
    {
        if ($activePaid <= 0) {
            return 'Belum Dibayar';
        }

        if ($totalAmount > 0 && $activePaid >= $totalAmount) {
            return 'Lunas';
        }

        return 'Terbayar Sebagian';
    }

    private static function documentStatusLabel(?string $status): string
    {
        $documentStatusLabels = [
            Sale::STATUS_DRAFTED => 'Draf',
            Sale::STATUS_WAITING_APPROVAL => 'Menunggu Persetujuan',
            Sale::STATUS_APPROVED => 'Disetujui',
            Sale::STATUS_REJECTED => 'Ditolak',
            Sale::STATUS_DISPATCHED_PARTIALLY => 'Dikirim Sebagian',
            Sale::STATUS_DISPATCHED => 'Terkirim',
            Sale::STATUS_RETURNED_PARTIALLY => 'Diretur Sebagian',
            Sale::STATUS_RETURNED => 'Diretur',
        ];

        return $status ? ($documentStatusLabels[$status] ?? $status) : '-';
    }

    private static function formatDate(?string $value): string
    {
        return $value ? date('d/m/Y', strtotime($value)) : '-';
    }

    private static function tagNames(?Sale $sale): string
    {
        if (!$sale) {
            return '-';
        }

        $tagNames = $sale->tags
            ->map(function ($tag) {
                if (is_array($tag->name)) {
                    return $tag->name['en'] ?? reset($tag->name) ?: null;
                }

                return is_string($tag->name) ? $tag->name : null;
            })
            ->filter()
            ->implode(', ');

        return $tagNames !== '' ? $tagNames : '-';
    }
}
