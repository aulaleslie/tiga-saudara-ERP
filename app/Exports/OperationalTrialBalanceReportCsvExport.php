<?php

namespace App\Exports;

use App\Services\Reports\OperationalTrialBalanceReportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class OperationalTrialBalanceReportCsvExport implements FromArray, WithHeadings
{
    public array $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function array(): array
    {
        $reportService = app(OperationalTrialBalanceReportService::class);
        
        $report = $reportService->generate(
            $this->filters['settingId'], 
            $this->filters['startDate'] ?? null,
            $this->filters['endDate'] ?? null
        );

        $data = [];

        foreach ($report->categories as $category) {
            foreach ($category->rows as $row) {
                $data[] = [
                    $category->categoryName,
                    $row->code,
                    $row->label,
                    $row->openingDebit,
                    $row->openingCredit,
                    $row->periodDebit,
                    $row->periodCredit,
                    $row->endingDebit,
                    $row->endingCredit,
                ];
            }
        }

        return $data;
    }

    public function headings(): array
    {
        return [
            'Kategori',
            'Kode Akun',
            'Nama Akun',
            'Saldo Awal Debit',
            'Saldo Awal Kredit',
            'Pergerakan Debit',
            'Pergerakan Kredit',
            'Saldo Akhir Debit',
            'Saldo Akhir Kredit',
        ];
    }
}
