<?php

namespace App\Exports;

use App\Exports\Concerns\NormalizesSettingIds;
use App\Services\Reports\InventorySummaryReportFilterData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Setting\Entities\Setting;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class InventorySummaryReportExport implements FromCollection, WithHeadings, WithMapping, WithEvents, WithTitle
{
    use NormalizesSettingIds;

    private Collection $data;
    private InventorySummaryReportFilterData $filterData;
    private bool $isCsv;
    private float $totalValue;
    private array $settingIds;
    private Collection $settings;

    public function __construct(Collection $data, InventorySummaryReportFilterData $filterData, bool $isCsv = false, array $settingIds = [])
    {
        $this->data = $data;
        $this->filterData = $filterData;
        $this->isCsv = $isCsv;
        $this->totalValue = (float) $data->sum('value');

        $dataSettingIds = !empty($data->first()['businesses']) ? array_keys($data->first()['businesses']) : null;
        $this->settingIds = $this->normalizeSettingIds(
            $settingIds,
            $filterData->selectedSettingIds ?? null,
            $filterData->settingId ?? null,
            $dataSettingIds
        );
        $this->settings = Setting::query()
            ->whereIn('id', $this->settingIds)
            ->orderBy('company_name')
            ->get();
    }

    public function title(): string
    {
        return 'Inventory Summary';
    }

    public function collection()
    {
        return $this->data;
    }

    public function headings(): array
    {
        if (count($this->settingIds) <= 1) {
            return [
                'Kode Produk',
                'Nama Produk',
                'Stok di tangan',
                'Batas Minimum',
                'Satuan',
                'Harga Rata-rata',
                'Nilai',
            ];
        }

        $headings = [
            'Kode Produk',
            'Nama Produk',
            'Satuan',
            'Total — Stok',
            'Total — Nilai',
        ];

        foreach ($this->settings as $setting) {
            $company = $setting->company_name;
            $headings[] = "{$company} — Stok";
            $headings[] = "{$company} — Biaya Rata-rata";
            $headings[] = "{$company} — Nilai";
        }

        return $headings;
    }

    public function map($row): array
    {
        if (count($this->settingIds) <= 1) {
            return [
                $row['product_code'] ?: '',
                $row['product_name'],
                (float) $row['stock'],
                (float) $row['minimum_stock'],
                $row['product_unit'],
                (float) $row['average_cost'],
                (float) $row['value'],
            ];
        }

        $rowMap = [
            $row['product_code'] ?: '',
            $row['product_name'],
            $row['product_unit'],
            (float) ($row['stock'] ?? $row['total_stock'] ?? 0),
            (float) ($row['value'] ?? $row['total_value'] ?? 0),
        ];

        foreach ($this->settings as $setting) {
            $b = $row['businesses'][$setting->id] ?? ['stock' => 0, 'average_cost' => 0, 'value' => 0];
            $rowMap[] = (float) ($b['stock'] ?? 0);
            $rowMap[] = (float) ($b['average_cost'] ?? 0);
            $rowMap[] = (float) ($b['value'] ?? 0);
        }

        return $rowMap;
    }

    public function registerEvents(): array
    {
        if ($this->isCsv) {
            return [];
        }

        $asOfDate = $this->filterData->asOfDate;
        $headings = $this->headings();
        $columnCount = count($headings);
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $totalValue = $this->totalValue;
        $sortCol = $this->filterData->sortColumn ?? 'product_name';
        $sortDir = $this->filterData->sortDirection ?? 'asc';
        $isMulti = count($this->settingIds) > 1;
        $settings = $this->settings;

        $settingId = $this->settingIds[0] ?? null;
        $companyName = $isMulti
            ? 'Konsolidasi'
            : ($settingId ? ($this->settings->firstWhere('id', $settingId)?->company_name ?? Setting::find($settingId)?->company_name) : 'Tiga Saudara ERP');

        $sortColMap = [
            'product_code' => 'Kode Produk',
            'product_name' => 'Nama Produk',
            'stock' => 'Stok di tangan',
            'average_cost' => 'Harga Rata-rata',
            'value' => 'Nilai',
        ];
        $sortColFormatted = $sortColMap[$sortCol] ?? $sortCol;
        $sortDirFormatted = strtolower($sortDir) === 'asc' ? 'A-Z' : 'Z-A';

        return [
            AfterSheet::class => function (AfterSheet $event) use ($asOfDate, $lastColumn, $totalValue, $companyName, $sortColFormatted, $sortDirFormatted, $isMulti, $settings) {
                $sheet = $event->sheet->getDelegate();

                $sheet->insertNewRowBefore(1, 6);

                $sheet->setCellValue('A1', $companyName ?: 'Tiga Saudara ERP');
                $sheet->setCellValue('A2', 'Ringkasan Persediaan Barang');
                $sheet->setCellValue('A3', 'As Of: ' . ($asOfDate ? $asOfDate->format('d/m/Y') : '-'));
                $sheet->setCellValue('A4', '(dalam IDR)');
                $sheet->setCellValue('A5', "Sorted by: {$sortColFormatted}, {$sortDirFormatted}");

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle('7:7')->getFont()->setBold(true);

                $highestRow = $sheet->getHighestRow();
                $totalRow = $highestRow + 1;

                if (! $isMulti) {
                    $sheet->setCellValue("F{$totalRow}", 'Total Nilai:');
                    $sheet->setCellValue("G{$totalRow}", $totalValue);
                    $sheet->getStyle("F{$totalRow}:G{$totalRow}")->getFont()->setBold(true);
                } else {
                    $sheet->setCellValue("C{$totalRow}", 'Total:');
                    $sheet->setCellValue("E{$totalRow}", $totalValue);

                    // Per-business values in the total row
                    $colIndex = 6;
                    foreach ($settings as $setting) {
                        $colIndex += 2; // skip stock and avg cost, point to value column
                        $valColLetter = Coordinate::stringFromColumnIndex($colIndex);
                        $bTotalValue = (float) $this->data->sum(function ($row) use ($setting) {
                            return (float) ($row['businesses'][$setting->id]['value'] ?? 0);
                        });
                        $sheet->setCellValue("{$valColLetter}{$totalRow}", $bTotalValue);
                        $colIndex += 1;
                    }
                    $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->getFont()->setBold(true);
                }
            },
        ];
    }
}
