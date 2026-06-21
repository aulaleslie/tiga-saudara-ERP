<?php

namespace App\Exports;

use App\Services\Reports\CustomerReceivablesReportFilterData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;

class CustomerReceivablesReportExport implements FromArray, WithHeadings, WithEvents, WithColumnFormatting
{
    private Builder $query;
    private CustomerReceivablesReportFilterData $filterData;
    private bool $isCsv;

    public function __construct(Builder $query, CustomerReceivablesReportFilterData $filterData, bool $isCsv = false)
    {
        $this->query = $query;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
    }

    public function array(): array
    {
        $rows = [];
        $queryResults = $this->query->get();
        $currentCustomerId = null;
        $runningTotal = 0.0;
        $grandTotal = 0.0;
        $prevCustomerName = '';
        
        $customerSalesCount = [];
        $customerSalesProcessed = [];
        foreach ($queryResults as $row) {
            $customerId = $row->customer_id;
            $customerSalesCount[$customerId] = ($customerSalesCount[$customerId] ?? 0) + 1;
        }

        foreach ($queryResults as $row) {
            $customerId = $row->customer_id;
            $customerName = $row->customer_name ?: ($row->customer?->customer_name ?? '-');
            
            if ($customerId !== $currentCustomerId) {
                if ($currentCustomerId !== null) {
                    $rows[] = [
                        '', '', '', '', '', '',
                        $prevCustomerName . ' | Total Piutang',
                        $runningTotal,
                    ];
                }
                
                $rows[] = [
                    $customerName,
                    '', '', '', '', '', ''
                ];
                
                $currentCustomerId = $customerId;
                $prevCustomerName = $customerName;
                $runningTotal = 0.0;
            }
            
            $customerSalesProcessed[$customerId] = ($customerSalesProcessed[$customerId] ?? 0) + 1;

            $mapped = \App\Services\Reports\CustomerReceivablesReportQueryService::mapRowsForExport($row);
            $rows[] = [
                $mapped['Pelanggan'],
                Carbon::parse($mapped['Tanggal'])->format('d/m/Y'),
                $mapped['Transaksi'],
                $mapped['No.'],
                $mapped['Jatuh Tempo'] !== '-' ? Carbon::parse($mapped['Jatuh Tempo'])->format('d/m/Y') : '-',
                $mapped['Deskripsi'],
                (float) $mapped['Jumlah'],
                (float) $mapped['Sisa Piutang'],
            ];
            $runningTotal += (float) $mapped['Sisa Piutang'];
            $grandTotal += (float) $mapped['Sisa Piutang'];
        }
        
        if ($currentCustomerId !== null) {
            $rows[] = [
                '', '', '', '', '', '',
                $prevCustomerName . ' | Total Piutang',
                $runningTotal,
            ];
            
            $rows[] = [
                'Grand Total', '', '', '', '', '', '', $grandTotal
            ];
        }
        
        return $rows;
    }

    public function headings(): array
    {
        return [
            'Pelanggan',
            'Tanggal',
            'Transaksi',
            'No.',
            'Jatuh Tempo',
            'Deskripsi',
            'Jumlah',
            'Sisa Piutang',
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
                $sheet->setCellValue('A2', 'Piutang Pelanggan');
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
