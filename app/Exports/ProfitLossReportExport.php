<?php

namespace App\Exports;

use App\Services\Reports\OperationalProfitLossReportService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

class ProfitLossReportExport implements FromArray, WithEvents, WithTitle
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
        $startDate = $this->parseDate($this->filters['startDate'] ?? null);
        $endDate = $this->parseDate($this->filters['endDate'] ?? null);

        if (! $startDate || ! $endDate) {
            return 'Laba Rugi';
        }

        return $startDate->format('d-m-Y') . '_' . $endDate->format('d-m-Y');
    }

    public function array(): array
    {
        $settingId = $this->filters['settingId'] ?? session('setting_id');
        $startDate = $this->filters['startDate'] ?? null;
        $endDate = $this->filters['endDate'] ?? null;

        $reportService = app(OperationalProfitLossReportService::class);
        $report = $reportService->generate($settingId, $startDate, $endDate);

        $setting = function_exists('settings') ? settings() : \Modules\Setting\Entities\Setting::find($settingId);
        $companyName = $setting?->company_name ?? 'Company';

        $rows = [];

        $this->addRow($rows, [$companyName], true);
        $this->addRow($rows, ['Laporan Laba Rugi'], true);
        $this->addRow($rows, [$report->periodLabel], true);
        $this->addRow($rows, ['(dalam ' . $report->currencyCode . ')'], true);
        $this->addRow($rows, [''], true);
        $this->addRow($rows, []);
        $this->addRow($rows, ['Keterangan', '', $report->periodLabel], true);

        // Pendapatan
        $this->addRow($rows, ['Pendapatan'], true);
        $this->addRow($rows, ['  Penjualan', '', $report->salesTotal], false, true);
        $this->addRow($rows, ['  Retur Penjualan', '', -$report->saleReturnsTotal], false, true);
        $this->addRow($rows, ['Total Pendapatan Bersih', '', $report->netRevenue], true, true);

        $this->addRow($rows, [''], false);

        // Biaya
        $this->addRow($rows, ['Biaya'], true);
        $this->addRow($rows, ['  Pembelian', '', $report->purchasesTotal], false, true);
        $this->addRow($rows, ['  Retur Pembelian', '', -$report->purchaseReturnsTotal], false, true);
        $this->addRow($rows, ['  Beban', '', $report->expensesTotal], false, true);
        $this->addRow($rows, ['Total Biaya', '', $report->totalCost], true, true);

        $this->addRow($rows, [''], false);

        // Laba (Rugi)
        $this->addRow($rows, ['Laba (Rugi)', '', $report->profitLoss], true, true);

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
                $sheet->getStyle('A1:C5')->getAlignment()->setHorizontal('center');

                foreach ($this->rowMeta['boldRows'] as $row) {
                    $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
                }

                foreach ($this->rowMeta['amountRows'] as $row) {
                    $sheet->getStyle("C{$row}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00;[Red](#,##0.00)');
                }

                $sheet->getColumnDimension('A')->setWidth(40.0);
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
