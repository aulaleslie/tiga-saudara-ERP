<?php

namespace App\Exports;

use App\Services\Reports\OperationalBalanceSheetReportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class OperationalBalanceSheetReportCsvExport implements FromArray, WithHeadings
{
    public array $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function array(): array
    {
        $reportService = app(OperationalBalanceSheetReportService::class);
        
        $settingIds = $this->filters['settingIds'] ?? [session('setting_id')];
        $asOfDate = $this->filters['asOfDate'] ?? null;
        
        $report = $reportService->generate($settingIds, $asOfDate);

        $data = [];

        // Aset
        foreach ($report->assets->rows as $row) {
            $data[] = [
                $report->assets->name,
                $row->name,
                $row->amount,
            ];
        }

        // Liabilitas
        foreach ($report->liabilities->rows as $row) {
            $data[] = [
                $report->liabilities->name,
                $row->name,
                $row->amount,
            ];
        }

        // Ekuitas
        foreach ($report->equity->rows as $row) {
            $data[] = [
                $report->equity->name,
                $row->name,
                $row->amount,
            ];
        }

        return $data;
    }

    public function headings(): array
    {
        return [
            'Bagian',
            'Keterangan',
            'Nilai',
        ];
    }
}
