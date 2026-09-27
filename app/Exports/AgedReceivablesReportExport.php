<?php

namespace App\Exports;

use App\Services\Reports\AgedReceivablesReportFilterData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;

class AgedReceivablesReportExport implements FromArray, WithHeadings, WithEvents, WithColumnFormatting
{
    private Builder $query;
    private AgedReceivablesReportFilterData $filterData;
    private bool $isCsv;

    public function __construct(Builder $query, AgedReceivablesReportFilterData $filterData, bool $isCsv = false)
    {
        $this->query = $query;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
    }

    public function array(): array
    {
        $rows = [];
        $queryResults = $this->query->get();
        
        $grandTotal = 0.0;
        $grandBucket1 = 0.0;
        $grandBucket2 = 0.0;
        $grandBucket3 = 0.0;
        $grandBucket4 = 0.0;
        
        foreach ($queryResults as $row) {
            $mapped = \App\Services\Reports\AgedReceivablesReportQueryService::mapRows($row);
            
            $rows[] = [
                $mapped['Pelanggan'],
                (float) $mapped['Total'],
                (float) $mapped['1 - 30 Hari'],
                (float) $mapped['31 - 60 Hari'],
                (float) $mapped['61 - 90 Hari'],
                (float) $mapped['> 90 Hari'],
            ];
            
            $grandTotal += (float) $mapped['Total'];
            $grandBucket1 += (float) $mapped['1 - 30 Hari'];
            $grandBucket2 += (float) $mapped['31 - 60 Hari'];
            $grandBucket3 += (float) $mapped['61 - 90 Hari'];
            $grandBucket4 += (float) $mapped['> 90 Hari'];
        }
        
        if (!$this->isCsv && !empty($rows)) {
            $rows[] = [
                'Total Piutang (semua pelanggan)',
                $grandTotal,
                $grandBucket1,
                $grandBucket2,
                $grandBucket3,
                $grandBucket4,
            ];
        }
        
        return $rows;
    }

    public function headings(): array
    {
        return [
            'Customer',
            'Total',
            '1 - 30 Hari',
            '31 - 60 Hari',
            '61 - 90 Hari',
            '> 90 Hari',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => '#,##0.00',
            'C' => '#,##0.00',
            'D' => '#,##0.00',
            'E' => '#,##0.00',
            'F' => '#,##0.00',
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

                $asOfDate = Carbon::parse($this->filterData->asOfDate)->format('d/m/Y');
                
                // Row 1
                $sheet->setCellValue('A1', $companyName);
                $sheet->mergeCells('A1:F1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                
                // Row 2
                $sheet->setCellValue('A2', 'Piutang');
                $sheet->mergeCells('A2:F2');
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
                
                // Row 3
                $sheet->setCellValue('A3', "As of: {$asOfDate}");
                $sheet->mergeCells('A3:F3');
                
                // Row 4
                $sheet->setCellValue('A4', '(dalam IDR)');
                $sheet->mergeCells('A4:F4');
                
                // Bold headers (now row 6)
                $sheet->getStyle('A6:F6')->getFont()->setBold(true);
                
                // Bold subtotal row (the last row)
                $highestRow = $sheet->getHighestRow();
                $sheet->getStyle("A{$highestRow}:F{$highestRow}")->getFont()->setBold(true);
            },
        ];
    }
}
