<?php

namespace App\Exports;

use App\Services\Reports\ExpenseDetailsReportFilterData;
use App\Services\Reports\ExpenseDetailsReportQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Setting\Entities\Setting;

class ExpenseDetailsReportExport implements FromArray, WithHeadings, WithEvents, WithColumnFormatting
{
    private Builder $query;
    private ExpenseDetailsReportFilterData $filterData;
    private bool $isCsv;

    public function __construct(Builder $query, ExpenseDetailsReportFilterData $filterData, bool $isCsv = false)
    {
        $this->query = $query;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
    }

    public function array(): array
    {
        $rows = [];
        $queryResults = $this->query->get();
        $groupedData = ExpenseDetailsReportQueryService::groupAndSummarize($queryResults);

        if ($this->isCsv) {
            // CSV: Only flat transaction rows, no category headers, no subtotals, no grand total.
            foreach ($groupedData['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    $rows[] = [
                        $row['Kategori / Tanggal'],
                        $row['Transaksi'],
                        $row['Nomor'],
                        $row['Keterangan'],
                        (float) $row['Jumlah'],
                    ];
                }
            }
        } else {
            // XLSX / PDF: Grouped rows, category subtotals, grand total
            foreach ($groupedData['groups'] as $group) {
                // Category header row
                $rows[] = [
                    $group['category_name'], '', '', '', ''
                ];
                
                // Transaction rows
                foreach ($group['rows'] as $row) {
                    $rows[] = [
                        $row['Kategori / Tanggal'],
                        $row['Transaksi'],
                        $row['Nomor'],
                        $row['Keterangan'],
                        (float) $row['Jumlah'],
                    ];
                }
                
                // Subtotal row
                $rows[] = [
                    '', '', '', 'Total ' . $group['category_name'], (float) $group['subtotal']
                ];
            }
            
            // Grand Total row
            if (!empty($groupedData['groups'])) {
                $rows[] = [
                    '', '', '', 'Grand Total Biaya', (float) $groupedData['grandTotal']
                ];
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'Kategori / Tanggal',
            'Transaksi',
            'Nomor',
            'Keterangan',
            'Jumlah',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'E' => '#,##0.00',
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

                $setting = Setting::find($this->filterData->scopeSettingId) ?? Setting::first();
                $companyName = $setting ? $setting->company_name : 'COMPANY NAME';

                $startDate = Carbon::parse($this->filterData->startDate)->format('d/m/Y');
                $endDate = Carbon::parse($this->filterData->endDate)->format('d/m/Y');
                
                // Row 1
                $sheet->setCellValue('A1', $companyName);
                $sheet->mergeCells('A1:E1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                
                // Row 2
                $sheet->setCellValue('A2', 'Rincian Biaya');
                $sheet->mergeCells('A2:E2');
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
                
                // Row 3
                $sheet->setCellValue('A3', "{$startDate} - {$endDate}");
                $sheet->mergeCells('A3:E3');
                
                // Row 4
                $sheet->setCellValue('A4', '(dalam IDR)');
                $sheet->mergeCells('A4:E4');
                
                // Bold headers (now row 6)
                $sheet->getStyle('A6:E6')->getFont()->setBold(true);
                
                // Bold category headers, subtotals, and grand total
                $highestRow = $sheet->getHighestRow();
                for ($row = 7; $row <= $highestRow; $row++) {
                    $dVal = $sheet->getCell("D{$row}")->getValue();
                    $bVal = $sheet->getCell("B{$row}")->getValue();
                    
                    // Category Header (Col A has value, Col B is empty)
                    if (empty($bVal) && !empty($sheet->getCell("A{$row}")->getValue())) {
                        $sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
                        $sheet->mergeCells("A{$row}:E{$row}");
                    }
                    // Subtotal or Grand Total
                    elseif (str_starts_with((string)$dVal, 'Total ') || $dVal === 'Grand Total Biaya') {
                        $sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
                    }
                }
            },
        ];
    }
}
