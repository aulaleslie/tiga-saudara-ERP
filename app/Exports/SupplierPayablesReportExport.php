<?php

namespace App\Exports;

use App\Services\Reports\SupplierPayablesReportFilterData;
use App\Services\Reports\SupplierPayablesReportQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;

class SupplierPayablesReportExport implements FromArray, WithHeadings, WithEvents, WithColumnFormatting
{
    private Builder $query;
    private SupplierPayablesReportFilterData $filterData;
    private bool $isCsv;

    public function __construct(Builder $query, SupplierPayablesReportFilterData $filterData, bool $isCsv = false)
    {
        $this->query = $query;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
    }

    public function array(): array
    {
        $rows = [];
        $queryResults = $this->query->get();
        $currentSupplierId = null;
        $runningTotal = 0.0;
        $runningJumlah = 0.0;
        $grandTotal = 0.0;
        $grandTotalJumlah = 0.0;
        $prevSupplierName = '';
        
        $supplierPurchaseCount = [];
        $supplierPurchaseProcessed = [];
        foreach ($queryResults as $row) {
            $supplierId = $row->supplier_id;
            $supplierPurchaseCount[$supplierId] = ($supplierPurchaseCount[$supplierId] ?? 0) + 1;
        }

        foreach ($queryResults as $row) {
            $supplierId = $row->supplier_id;
            $supplierName = $row->supplier?->supplier_name ?? '-';

            if ($supplierId !== $currentSupplierId) {
                if ($currentSupplierId !== null) {
                    $rows[] = [
                        '', '', '', '', '',
                        $prevSupplierName . ' | Total Hutang',
                        $runningJumlah,
                        $runningTotal,
                    ];
                }

                $rows[] = [
                    $supplierName,
                    '', '', '', '', '', '', ''
                ];

                $currentSupplierId = $supplierId;
                $prevSupplierName = $supplierName;
                $runningTotal = 0.0;
                $runningJumlah = 0.0;
            }

            $supplierPurchaseProcessed[$supplierId] = ($supplierPurchaseProcessed[$supplierId] ?? 0) + 1;

            $mapped = SupplierPayablesReportQueryService::mapRowsForExport($row);
            $rows[] = [
                $mapped['Supplier'],
                Carbon::parse($mapped['Date'])->format('d/m/Y'),
                $mapped['Transaksi'],
                $mapped['No.'],
                $mapped['Jatuh Tempo'] !== '-' ? Carbon::parse($mapped['Jatuh Tempo'])->format('d/m/Y') : '-',
                $mapped['Keterangan'],
                (float) $mapped['Jumlah'],
                (float) $mapped['Saldo'],
            ];
            $runningTotal += (float) $mapped['Saldo'];
            $runningJumlah += (float) $mapped['Jumlah'];
            $grandTotal += (float) $mapped['Saldo'];
            $grandTotalJumlah += (float) $mapped['Jumlah'];
        }

        if ($currentSupplierId !== null) {
            $rows[] = [
                '', '', '', '', '',
                $prevSupplierName . ' | Total Hutang',
                $runningJumlah,
                $runningTotal,
            ];

            $rows[] = [
                '', '', '', '', '', 'Grand Total', $grandTotalJumlah, $grandTotal
            ];
        }
        
        return $rows;
    }

    public function headings(): array
    {
        return [
            'Supplier',
            'Date',
            'Transaksi',
            'No.',
            'Jatuh Tempo',
            'Keterangan',
            'Jumlah',
            'Saldo',
        ];
    }

    public function columnFormats(): array
    {
        return [
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

                $setting = \Modules\Setting\Entities\Setting::find($this->filterData->scopeSettingId) 
                           ?? \Modules\Setting\Entities\Setting::first();
                $companyName = $setting ? $setting->company_name : 'COMPANY NAME';

                $endDate = Carbon::parse($this->filterData->endDate)->format('d/m/Y');
                
                // Row 1
                $sheet->setCellValue('A1', $companyName);
                $sheet->mergeCells('A1:H1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                
                // Row 2
                $sheet->setCellValue('A2', 'Hutang Supplier');
                $sheet->mergeCells('A2:H2');
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
                
                // Row 3
                $sheet->setCellValue('A3', "As of: {$endDate}");
                $sheet->mergeCells('A3:H3');
                
                // Row 4
                $sheet->setCellValue('A4', '(dalam IDR)');
                $sheet->mergeCells('A4:H4');
                
                // Bold headers (now row 6)
                $sheet->getStyle('A6:H6')->getFont()->setBold(true);
                
                // Specific styling for group headers, subtotals, and grand total
                $highestRow = $sheet->getHighestRow();
                for ($row = 7; $row <= $highestRow; $row++) {
                    $aVal = $sheet->getCell("A{$row}")->getValue();
                    $bVal = $sheet->getCell("B{$row}")->getValue();
                    $gVal = $sheet->getCell("G{$row}")->getValue();
                    $hVal = $sheet->getCell("H{$row}")->getValue();
                    
                    if (empty($bVal)) {
                        if ($aVal === 'Grand Total') {
                            $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
                        } elseif (!empty($aVal) && empty($hVal)) {
                            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
                        } elseif (empty($aVal) && !empty($gVal) && !empty($hVal)) {
                            $sheet->getStyle("G{$row}:H{$row}")->getFont()->setBold(true);
                        }
                    }
                }
            },
        ];
    }
}
