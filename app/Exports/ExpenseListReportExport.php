<?php

namespace App\Exports;

use App\Services\Reports\ExpenseListReportFilterData;
use App\Services\Reports\ExpenseListReportQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\AfterSheet;

class ExpenseListReportExport implements FromArray, WithHeadings, WithEvents, WithColumnFormatting
{
    private Builder $query;
    private ExpenseListReportFilterData $filterData;
    private bool $isCsv;

    public function __construct(Builder $query, ExpenseListReportFilterData $filterData, bool $isCsv = false)
    {
        $this->query = $query;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
    }

    public function array(): array
    {
        $rows = [];
        $queryResults = $this->query->get();
        $totalJumlah = 0.0;
        $totalTax = 0.0;

        foreach ($queryResults as $expense) {
            if ($this->filterData->detailMode) {
                $mappedRows = ExpenseListReportQueryService::mapDetailRows($expense);
            } else {
                $mappedRows = [ExpenseListReportQueryService::mapSummaryRow($expense)];
            }

            foreach ($mappedRows as $row) {
                $rows[] = [
                    Carbon::parse($row['Tanggal'])->format('d/m/Y'),
                    $row['Transaksi'],
                    $row['Nomor'],
                    $row['Kategori'],
                    $row['Deskripsi'],
                    $row['Supplier'],
                    (float) $row['Jumlah'],
                    (float) $row['Tax'],
                    $row['Status'],
                    (float) $row['Sisa Tagihan'],
                ];
            }

            // Totals are always based on header amounts to avoid double-counting
            $totalJumlah += (float) $expense->amount;
            $totalTax += ExpenseListReportQueryService::calculateTotalTax($expense);
        }

        // Add total row
        if (!empty($rows)) {
            $rows[] = [
                '', '', '', '', '', 'Total Biaya',
                $totalJumlah,
                $totalTax,
                '',
                0,
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'Tanggal',
            'Transaksi',
            'Nomor',
            'Kategori',
            'Deskripsi',
            'Supplier',
            'Jumlah',
            'Tax',
            'Status',
            'Sisa Tagihan',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'G' => '#,##0.00',
            'H' => '#,##0.00',
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
                $sheet->setCellValue('A2', 'Daftar Pengeluaran');
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
                
                // Bold total row
                $highestRow = $sheet->getHighestRow();
                for ($row = 7; $row <= $highestRow; $row++) {
                    $fVal = $sheet->getCell("F{$row}")->getValue();
                    if ($fVal === 'Total Biaya') {
                        $sheet->getStyle("A{$row}:J{$row}")->getFont()->setBold(true);
                        break;
                    }
                }
            },
        ];
    }
}
