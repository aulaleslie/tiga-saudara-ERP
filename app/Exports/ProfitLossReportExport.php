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
        $settingIds = $this->filters['settingIds'] ?? [session('setting_id')];
        $startDate = $this->filters['startDate'] ?? null;
        $endDate = $this->filters['endDate'] ?? null;

        $validatedSettingIds = $this->validateSettingIds($settingIds);

        $reportService = app(OperationalProfitLossReportService::class);
        $report = $reportService->generate($validatedSettingIds, $startDate, $endDate);

        $companyName = $this->getCompanyHeader($validatedSettingIds);

        $rows = [];

        $this->addRow($rows, [$companyName], true);
        $this->addRow($rows, ['Laporan Laba Rugi'], true);
        $this->addRow($rows, [$report->periodLabel], true);
        $this->addRow($rows, ['(dalam ' . $report->currencyCode . ')'], true);
        $this->addRow($rows, [''], true);
        $this->addRow($rows, []);
        $this->addRow($rows, ['Keterangan', '', $report->periodLabel], true);

        foreach ($report->getRows() as $row) {
            if ($row['type'] === 'header') {
                $this->addRow($rows, [$row['label']], true);
            } elseif ($row['type'] === 'row') {
                $this->addRow($rows, ['  ' . $row['label'], '', $row['value']], false, true);
            } elseif ($row['type'] === 'subtotal') {
                $this->addRow($rows, [$row['label'], '', $row['value']], true, true);
            } elseif ($row['type'] === 'empty') {
                $this->addRow($rows, [''], false);
            } elseif ($row['type'] === 'total') {
                $this->addRow($rows, [$row['label'], '', $row['value']], true, true);
            }
        }

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

    private function validateSettingIds(array $settingIds): array {
        $normalized = array_filter(
            array_map('intval', $settingIds),
            fn($id) => $id > 0
        );

        $validIds = \Modules\Setting\Entities\Setting::pluck('id')->toArray();
        return array_values(array_unique(array_intersect($normalized, $validIds)));
    }

    private function getCompanyHeader(array $validatedSettingIds): string {
        if (count($validatedSettingIds) === 1) {
            $setting = \Modules\Setting\Entities\Setting::find($validatedSettingIds[0]);
            return $setting?->company_name ?? 'Company';
        }

        $allSettings = \Modules\Setting\Entities\Setting::pluck('id')->toArray();
        $availableCount = count($allSettings);

        if (count($validatedSettingIds) === $availableCount && $availableCount > 0) {
            return 'Semua Perusahaan';
        }

        return 'Beberapa Perusahaan';
    }
}
