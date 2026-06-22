<?php

namespace App\Exports;

use App\Services\Reports\PurchaseByProductReportFilterData;
use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Setting\Entities\Setting;

class PurchaseByProductReportExport implements FromCollection, WithHeadings, WithMapping, WithEvents
{
    private Builder $query;
    private PurchaseByProductReportFilterData $filter;
    private bool $isCsv;
    private $companyName;

    public function __construct(Builder $query, PurchaseByProductReportFilterData $filter, bool $isCsv = false)
    {
        $this->query = $query;
        $this->filter = $filter;
        $this->isCsv = $isCsv;
        
        $setting = Setting::find($this->filter->scopeSettingId);
        $this->companyName = $setting ? $setting->company_name : 'Unknown Company';
    }

    public function collection()
    {
        $data = collect($this->query->get());
        
        $totalPurchase = $data->sum('purchase_value');
        $totalReturn = $data->sum('return_value');
        
        $data->push((object)[
            'is_total_row' => true,
            'total_purchase' => $totalPurchase,
            'total_return' => $totalReturn,
        ]);

        return $data;
    }

    public function headings(): array
    {
        return [
            'Kode Produk / SKU',
            'Nama Produk',
            'Qty Pembelian',
            'Qty Retur',
            'Satuan',
            'Nilai Pembelian',
            'Nilai Retur',
            'Nilai Pembelian Rata-rata',
        ];
    }

    public function map($row): array
    {
        if (isset($row->is_total_row) && $row->is_total_row) {
            return [
                'Total Keseluruhan',
                '',
                '',
                '',
                '',
                round($row->total_purchase, 2),
                round($row->total_return, 2),
                '',
            ];
        }

        return [
            $row->product_code,
            $row->product_name,
            round($row->purchase_quantity, 2),
            round($row->return_quantity, 2),
            $row->unit_name,
            round($row->purchase_value, 2),
            round($row->return_value, 2),
            round($row->average_purchase_value, 2),
        ];
    }

    public function registerEvents(): array
    {
        if ($this->isCsv) {
            return [];
        }

        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                
                $sheet->insertNewRowBefore(1, 5);

                $sheet->setCellValue('A1', $this->companyName);
                $sheet->mergeCells('A1:H1');
                
                $sheet->setCellValue('A2', 'Pembelian dengan Produk');
                $sheet->mergeCells('A2:H2');
                
                $startDate = Carbon::parse($this->filter->startDate)->format('d/m/Y');
                $endDate = Carbon::parse($this->filter->endDate)->format('d/m/Y');
                $sheet->setCellValue('A3', "Periode: {$startDate} - {$endDate}");
                $sheet->mergeCells('A3:H3');

                $sheet->setCellValue('A4', "(dalam IDR)");
                $sheet->mergeCells('A4:H4');

                $sheet->getStyle('A1:A4')->getFont()->setBold(true);
                $sheet->getStyle('A1')->getFont()->setSize(14);
                $sheet->getStyle('A2')->getFont()->setSize(12);
                
                $headingRow = 6;
                $sheet->getStyle("A{$headingRow}:H{$headingRow}")->getFont()->setBold(true);

                $highestRow = $sheet->getHighestRow();
                $sheet->getStyle("A{$highestRow}:H{$highestRow}")->getFont()->setBold(true);

                foreach (range('A', 'H') as $columnID) {
                    $sheet->getColumnDimension($columnID)->setAutoSize(true);
                }
            },
        ];
    }
}
