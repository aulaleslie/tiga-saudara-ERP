<?php

namespace App\Exports;

use App\Services\Reports\PurchaseBySupplierReportFilterData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;

class PurchaseBySupplierReportExport implements FromArray, WithHeadings, WithEvents, WithColumnFormatting
{
    private Builder $query;
    private PurchaseBySupplierReportFilterData $filterData;
    private bool $isCsv;

    public function __construct(Builder $query, PurchaseBySupplierReportFilterData $filterData, bool $isCsv = false)
    {
        $this->query = $query;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function array(): array
    {
        $rows = [];
        $queryResults = $this->query->get();
        $currentSupplierId = null;
        $runningTotal = 0.0;
        $grandTotal = 0.0;
        $prevSupplierName = '';
        
        foreach ($queryResults as $detail) {
            $supplierId = $detail->purchase?->supplier_id ?? 0;
            $supplierName = $detail->supplier_name ?: ($detail->purchase?->supplier?->supplier_name ?? '-');
            
            if ($supplierId !== $currentSupplierId) {
                if ($currentSupplierId !== null) {
                    // Subtotal row for previous supplier
                    $rows[] = [
                        '', '', '', '', '', '', '', '',
                        $prevSupplierName . ' | Total Pembelian',
                        $runningTotal,
                    ];
                }
                
                // Group Header row for NEW supplier
                $rows[] = [
                    $supplierName,
                    '', '', '', '', '', '', '', '', ''
                ];
                
                $currentSupplierId = $supplierId;
                $prevSupplierName = $supplierName;
                $runningTotal = 0.0;
            }
            
            $subTotal = (float) ($detail->sub_total ?? 0);
            $runningTotal += $subTotal;
            $grandTotal += $subTotal;
            $date = $detail->purchase?->date ? Carbon::parse($detail->purchase->date)->format('d/m/Y') : '-';
            
            $rows[] = [
                $date,
                'Faktur Pembelian',
                $detail->purchase?->reference ?? '-',
                $detail->product_name ?? '-',
                $detail->purchase?->note ?? '-',
                (float) ($detail->quantity ?? 0),
                $detail->product?->unit?->short_name ?? $detail->product?->baseUnit?->short_name ?? $detail->product?->product_unit ?? '-',
                (float) ($detail->unit_price ?? 0),
                $subTotal,
                $runningTotal,
            ];
        }
        
        // Push subtotal for the very last supplier
        if ($currentSupplierId !== null) {
            $rows[] = [
                '', '', '', '', '', '', '', '',
                $prevSupplierName . ' | Total Pembelian',
                $runningTotal,
            ];
            
            // Grand Total row
            $rows[] = [
                'Grand Total', '', '', '', '', '', '', '', '', $grandTotal
            ];
        }
        
        return $rows;
    }

    public function headings(): array
    {
        return [
            'Supplier / Tanggal',
            'Transaksi',
            'No',
            'Produk',
            'Keterangan',
            'Kuantitas',
            'Satuan',
            'Harga Satuan',
            'Jumlah Tagihan',
            'Total',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'H' => '#,##0.00',
            'I' => '#,##0.00',
            'J' => '#,##0.00',
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

                $setting = \Modules\Setting\Entities\Setting::find($this->filterData->scopeSettingId) 
                           ?? \Modules\Setting\Entities\Setting::first();
                $companyName = $setting ? $setting->company_name : 'COMPANY NAME';

                $startDate = Carbon::parse($this->filterData->startDate)->format('d/m/Y');
                $endDate = Carbon::parse($this->filterData->endDate)->format('d/m/Y');
                
                // Row 1
                $sheet->setCellValue('A1', $companyName);
                $sheet->mergeCells('A1:J1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                
                // Row 2
                $sheet->setCellValue('A2', 'Pembelian per Supplier');
                $sheet->mergeCells('A2:J2');
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
                
                // Row 3
                $sheet->setCellValue('A3', "{$startDate} - {$endDate}");
                $sheet->mergeCells('A3:J3');
                
                // Row 4
                $sheet->setCellValue('A4', '(dalam IDR)');
                $sheet->mergeCells('A4:J4');
                
                // Bold headers (now row 6)
                $sheet->getStyle('A6:J6')->getFont()->setBold(true);
                
                // Specific styling for group headers, subtotals, and grand total
                $highestRow = $sheet->getHighestRow();
                for ($row = 7; $row <= $highestRow; $row++) {
                    $aVal = $sheet->getCell("A{$row}")->getValue();
                    $bVal = $sheet->getCell("B{$row}")->getValue();
                    $iVal = $sheet->getCell("I{$row}")->getValue();
                    $jVal = $sheet->getCell("J{$row}")->getValue();
                    
                    if (empty($bVal)) {
                        if ($aVal === 'Grand Total') {
                            // Grand Total Row
                            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                            $sheet->getStyle("J{$row}")->getFont()->setBold(true);
                        } elseif (!empty($aVal) && empty($jVal)) {
                            // Group Header Row
                            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
                        } elseif (empty($aVal) && !empty($iVal)) {
                            // Subtotal Row
                            $sheet->getStyle("J{$row}")->getFont()->setBold(true);
                        }
                    }
                }
            },
        ];
    }
}
