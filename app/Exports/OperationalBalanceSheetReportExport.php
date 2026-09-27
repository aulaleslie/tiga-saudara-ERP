<?php

namespace App\Exports;

use App\Services\Reports\OperationalBalanceSheetReportService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Setting\Entities\Setting;

class OperationalBalanceSheetReportExport implements FromArray, WithEvents, WithTitle
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
        $asOfDate = $this->parseDate($this->filters['asOfDate'] ?? null);

        if (! $asOfDate) {
            return 'Neraca';
        }

        return $asOfDate->format('d-m-Y');
    }

    public function array(): array
    {
        $settingIds = $this->filters['settingIds'] ?? [session('setting_id')];
        $scopeLabel = $this->filters['scopeLabel'] ?? '';
        $asOfDate = $this->filters['asOfDate'] ?? null;

        $reportService = app(OperationalBalanceSheetReportService::class);
        $report = $reportService->generate($settingIds, $asOfDate);

        $firstSettingId = $settingIds[0] ?? session('setting_id');
        $setting = Setting::find($firstSettingId);
        $companyName = $setting?->company_name ?? 'Company';

        $rows = [];

        $this->addRow($rows, [$companyName], true);
        $this->addRow($rows, ['Neraca (Operasional)'], true);
        if ($scopeLabel) {
            $this->addRow($rows, [$scopeLabel], true);
        }
        $this->addRow($rows, ['Per ' . Carbon::parse($report->asOfDate)->format('d M Y')], true);
        $this->addRow($rows, ['(dalam ' . $report->currencyCode . ')'], true);
        $this->addRow($rows, [''], true);
        $this->addRow($rows, []);
        $this->addRow($rows, ['Keterangan', '', 'Nilai'], true);

        // Aset
        $this->addRow($rows, [$report->assets->name], true);
        foreach ($report->assets->rows as $row) {
            $this->addRow($rows, ['  ' . $row->name, '', $row->amount], false, true);
        }
        $this->addRow($rows, ['Total ' . $report->assets->name, '', $report->assets->total], true, true);

        $this->addRow($rows, [''], false);

        // Liabilitas
        $this->addRow($rows, [$report->liabilities->name], true);
        foreach ($report->liabilities->rows as $row) {
            $this->addRow($rows, ['  ' . $row->name, '', $row->amount], false, true);
        }
        $this->addRow($rows, ['Total ' . $report->liabilities->name, '', $report->liabilities->total], true, true);

        $this->addRow($rows, [''], false);

        // Ekuitas
        $this->addRow($rows, [$report->equity->name], true);
        foreach ($report->equity->rows as $row) {
            $this->addRow($rows, ['  ' . $row->name, '', $row->amount], false, true);
        }
        $this->addRow($rows, ['Total ' . $report->equity->name, '', $report->equity->total], true, true);

        $this->addRow($rows, [''], false);
        
        $totalLiabilitiesEquity = $report->liabilities->total + $report->equity->total;
        $this->addRow($rows, ['Total ' . $report->liabilities->name . ' dan ' . $report->equity->name, '', $totalLiabilitiesEquity], true, true);
        
        $this->addRow($rows, [''], false);
        $this->addRow($rows, [$report->sourceNote], false);

        $this->rowMeta['lastRow'] = count($rows);

        return $rows;
    }

    public function registerEvents(): array
    {
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

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value);
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
