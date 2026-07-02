<?php

namespace App\Exports;

use App\Services\Reports\OperationalCashFlowReportFilterData;
use App\Services\Reports\OperationalCashFlowReportService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Setting\Entities\Setting;

class OperationalCashFlowReportExport implements FromArray, WithEvents, WithTitle
{
    protected array $filters;
    protected array $rowMeta = [
        'boldRows' => [],
        'amountRows' => [],
        'lastRow' => 0,
    ];

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        $startDate = $this->filters['startDate'] ?? null;
        $endDate = $this->filters['endDate'] ?? null;

        if (! $startDate || ! $endDate) {
            return 'Arus Kas';
        }

        return Carbon::parse($startDate)->format('d-m-y') . ' sd ' . Carbon::parse($endDate)->format('d-m-y');
    }

    public function array(): array
    {
        $settingIds = $this->filters['settingIds'] ?? [session('setting_id')];
        $isCsv = $this->filters['isCsv'] ?? false;
        
        $filterData = new OperationalCashFlowReportFilterData(
            $this->filters['startDate'] ?? null,
            $this->filters['endDate'] ?? null
        );

        $reportService = app(OperationalCashFlowReportService::class);
        $report = $reportService->generate($settingIds, $filterData);

        if ($isCsv) {
            return $this->generateCsvArray($report);
        }
        
        return $this->generateXlsxArray($report, $settingIds);
    }
    
    protected function generateCsvArray($report): array
    {
        $rows = [];
        
        // CSV Headers
        $rows[] = ['Tipe Aktivitas', 'Nama Label', $report->periodLabel];
        
        // Operating Activities
        foreach ($report->operatingActivities->rows as $row) {
            $rows[] = [$report->operatingActivities->name, $row->name, $row->amount];
        }
        
        // Investing Activities
        foreach ($report->investingActivities->rows as $row) {
            $rows[] = [$report->investingActivities->name, $row->name, $row->amount];
        }
        
        // Financing Activities
        foreach ($report->financingActivities->rows as $row) {
            $rows[] = [$report->financingActivities->name, $row->name, $row->amount];
        }
        
        // Summary Rows
        $rows[] = ['', $report->netCashIncrease->name, $report->netCashIncrease->amount];
        $rows[] = ['', $report->bankRevaluation->name, $report->bankRevaluation->amount];
        $rows[] = ['', $report->openingCash->name, $report->openingCash->amount];
        $rows[] = ['', $report->endingCash->name, $report->endingCash->amount];
        
        return $rows;
    }
    
    protected function generateXlsxArray($report, $settingIds): array
    {
        $firstSettingId = $settingIds[0] ?? session('setting_id');
        $setting = Setting::find($firstSettingId);
        $companyName = $setting?->company_name ?? 'Company';
        $scopeLabel = $this->filters['scopeLabel'] ?? '';

        $rows = [];

        $this->addRow($rows, [$companyName], true);
        $this->addRow($rows, ['Arus Kas (Operasional)'], true);
        if ($scopeLabel) {
            $this->addRow($rows, [$scopeLabel], true);
        }
        $this->addRow($rows, ['Periode: ' . $report->periodLabel], true);
        $this->addRow($rows, ['(dalam ' . $report->currencyCode . ')'], true);
        $this->addRow($rows, [''], true);
        $this->addRow($rows, []);
        $this->addRow($rows, ['Keterangan', '', 'Nilai'], true);

        // Operating Activities
        $this->addRow($rows, [$report->operatingActivities->name], true);
        foreach ($report->operatingActivities->rows as $row) {
            $this->addRow($rows, ['  ' . $row->name, '', $row->amount], false, true);
        }
        $this->addRow($rows, ['Net ' . $report->operatingActivities->name, '', $report->operatingActivities->total], true, true);
        $this->addRow($rows, [''], false);

        // Investing Activities
        $this->addRow($rows, [$report->investingActivities->name], true);
        foreach ($report->investingActivities->rows as $row) {
            $this->addRow($rows, ['  ' . $row->name, '', $row->amount], false, true);
        }
        $this->addRow($rows, ['Net ' . $report->investingActivities->name, '', $report->investingActivities->total], true, true);
        $this->addRow($rows, [''], false);

        // Financing Activities
        $this->addRow($rows, [$report->financingActivities->name], true);
        foreach ($report->financingActivities->rows as $row) {
            $this->addRow($rows, ['  ' . $row->name, '', $row->amount], false, true);
        }
        $this->addRow($rows, ['Net ' . $report->financingActivities->name, '', $report->financingActivities->total], true, true);
        $this->addRow($rows, [''], false);

        // Summary Rows
        $this->addRow($rows, [$report->netCashIncrease->name, '', $report->netCashIncrease->amount], true, true);
        $this->addRow($rows, [$report->bankRevaluation->name, '', $report->bankRevaluation->amount], true, true);
        $this->addRow($rows, [$report->openingCash->name, '', $report->openingCash->amount], true, true);
        $this->addRow($rows, [$report->endingCash->name, '', $report->endingCash->amount], true, true);

        $this->addRow($rows, [''], false);
        $this->addRow($rows, [$report->sourceNote], false);

        $this->rowMeta['lastRow'] = count($rows);

        return $rows;
    }

    public function registerEvents(): array
    {
        $isCsv = $this->filters['isCsv'] ?? false;
        
        if ($isCsv) {
            return [];
        }
        
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $this->rowMeta['lastRow'] ?? 0;

                if ($lastRow === 0) {
                    return;
                }

                $sheet->getStyle("A1:C{$lastRow}")
                    ->getFont()
                    ->setName('Arial')
                    ->setSize(12);

                $sheet->mergeCells('A1:C1');
                $sheet->mergeCells('A2:C2');
                $sheet->mergeCells('A3:C3');
                $sheet->mergeCells('A4:C4');
                $sheet->mergeCells('A5:C5');
                $sheet->mergeCells('A6:C6');
                $sheet->getStyle('A1:C6')->getAlignment()->setHorizontal('center');

                foreach ($this->rowMeta['boldRows'] as $row) {
                    $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
                }

                foreach ($this->rowMeta['amountRows'] as $row) {
                    $sheet->getStyle("C{$row}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00;[Red](#,##0.00)');
                }

                $sheet->getColumnDimension('A')->setWidth(50.0);
                $sheet->getColumnDimension('B')->setWidth(5.0);
                $sheet->getColumnDimension('C')->setWidth(30.0);
            },
        ];
    }

    private function addRow(array &$rows, array $cells, bool $bold = false, bool $amount = false): void
    {
        $rows[] = $this->padRow($cells);
        $rowIndex = count($rows);

        if ($bold) {
            $this->rowMeta['boldRows'][] = $rowIndex;
        }

        if ($amount) {
            $this->rowMeta['amountRows'][] = $rowIndex;
        }
    }

    private function padRow(array $cells, int $length = 3): array
    {
        return array_pad($cells, $length, '');
    }
}
