<?php

namespace App\Exports;

use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Events\AfterSheet;
use Carbon\Carbon;
use App\Services\Reports\SaleByProductReportFilterData;

class SaleByProductReportExport implements FromArray, WithHeadings, WithEvents, WithColumnFormatting, WithCustomCsvSettings
{
    private $query;
    private $filter;
    private $isCsv;

    public function __construct(Builder $query, SaleByProductReportFilterData $filter, bool $isCsv = false)
    {
        $this->query = $query;
        $this->filter = $filter;
        $this->isCsv = $isCsv;
    }

    public function array(): array
    {
        $rows = [];
        $queryResults = $this->query->get();
        
        $grandTotalSold = 0.0;
        $grandTotalReturn = 0.0;
        
        foreach ($queryResults as $row) {
            $rows[] = [
                $row->product_code,
                $row->product_name,
                (float) $row->sold_quantity,
                (float) $row->return_quantity,
                $row->unit_name,
                (float) $row->sold_value,
                (float) $row->return_value,
                (float) $row->average_sales_value,
            ];
            
            $grandTotalSold += (float) $row->sold_value;
            $grandTotalReturn += (float) $row->return_value;
        }
        
        if (!empty($rows)) {
            $rows[] = [
                'Total Keseluruhan',
                '',
                '',
                '',
                '',
                $grandTotalSold,
                $grandTotalReturn,
                '',
            ];
        }
        
        return $rows;
    }

    public function headings(): array
    {
        return [
            'Kode Produk',
            'Nama Produk',
            'Kuantitas Terjual',
            'Kuantitas Retur',
            'Satuan',
            'Total Nilai terjual',
            'Total Nilai Retur',
            'Harga Penjualan Rata-rata',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => '#,##0.00',
            'D' => '#,##0.00',
            'F' => '#,##0.00',
            'G' => '#,##0.00',
            'H' => '#,##0.00',
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

                $setting = \Modules\Setting\Entities\Setting::find($this->filter->scopeSettingId) 
                           ?? \Modules\Setting\Entities\Setting::first();
                $companyName = $setting ? $setting->company_name : 'COMPANY NAME';

                $startDate = Carbon::parse($this->filter->startDate)->format('d/m/Y');
                $endDate = Carbon::parse($this->filter->endDate)->format('d/m/Y');
                $dateRange = "Periode: {$startDate} - {$endDate}";
                
                // Row 1
                $sheet->setCellValue('A1', $companyName);
                $sheet->mergeCells('A1:H1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                
                // Row 2
                $sheet->setCellValue('A2', 'Penjualan dengan Produk');
                $sheet->mergeCells('A2:H2');
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
                
                // Row 3
                $sheet->setCellValue('A3', $dateRange);
                $sheet->mergeCells('A3:H3');
                
                // Row 4
                $sheet->setCellValue('A4', '(dalam IDR)');
                $sheet->mergeCells('A4:H4');
                
                // Bold headers (now row 6)
                $sheet->getStyle('A6:H6')->getFont()->setBold(true);
                
                // Bold subtotal row (the last row)
                $highestRow = $sheet->getHighestRow();
                $sheet->getStyle("A{$highestRow}:H{$highestRow}")->getFont()->setBold(true);
            },
        ];
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ',',
            'enclosure' => '"',
            'line_ending' => PHP_EOL,
            'use_bom' => true,
        ];
    }
}
