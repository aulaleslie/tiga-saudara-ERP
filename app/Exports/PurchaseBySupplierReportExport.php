<?php

namespace App\Exports;

use App\Services\Reports\PurchaseBySupplierReportFilterData;
use App\Services\Reports\PurchaseBySupplierReportQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class PurchaseBySupplierReportExport implements FromQuery, WithHeadings, WithMapping, WithEvents
{
    private Builder $query;
    private PurchaseBySupplierReportFilterData $filterData;
    private bool $isCsv;
    private array $runningTotals = [];

    public function __construct(Builder $query, PurchaseBySupplierReportFilterData $filterData, bool $isCsv = false)
    {
        $this->query = $query;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
    }

    public function query()
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'Supplier',
            'Tanggal',
            'Tipe transaksi',
            'No. transaksi',
            'Nama produk',
            'Keterangan',
            'Qty',
            'Unit',
            'Harga per unit',
            'Nominal tagihan',
            'Total nominal tagihan',
        ];
    }

    public function map($row): array
    {
        $supplierId = $row->purchase?->supplier_id ?? 0;
        
        if (!isset($this->runningTotals[$supplierId])) {
            $this->runningTotals[$supplierId] = 0.0;
        }
        
        $this->runningTotals[$supplierId] += (float) ($row->sub_total ?? 0);

        return PurchaseBySupplierReportQueryService::mapRowForExport($row, $this->runningTotals[$supplierId]);
    }

    public function registerEvents(): array
    {
        if ($this->isCsv) {
            return [];
        }

        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                
                $sheet->insertNewRowBefore(1, 2);

                $startDate = Carbon::parse($this->filterData->startDate)->format('d/m/Y');
                $endDate = Carbon::parse($this->filterData->endDate)->format('d/m/Y');
                $periodText = "Periode: {$startDate} - {$endDate}";

                // Set title
                $sheet->setCellValue('A1', 'LAPORAN PEMBELIAN PER SUPPLIER');
                $sheet->mergeCells('A1:K1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Set period
                $sheet->setCellValue('A2', $periodText);
                $sheet->mergeCells('A2:K2');
                $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Bold headers
                $sheet->getStyle('A3:K3')->getFont()->setBold(true);
            },
        ];
    }
}
