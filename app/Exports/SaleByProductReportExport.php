<?php

namespace App\Exports;

use App\Services\Reports\SaleByProductReportFilterData;
use App\Services\Reports\SaleByProductReportQueryService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Events\AfterSheet;

class SaleByProductReportExport implements FromArray, WithHeadings, WithEvents, WithColumnFormatting, WithCustomCsvSettings, WithStrictNullComparison
{
    private SaleByProductReportFilterData $filter;
    private bool $isCsv;
    private ?array $dataset;

    /**
     * @param  SaleByProductReportQueryService|array|Builder|null  $source
     */
    public function __construct($source, SaleByProductReportFilterData $filter, bool $isCsv = false)
    {
        $this->filter = $filter;
        $this->isCsv = $isCsv;

        if (is_array($source) && isset($source['rows'], $source['totals'])) {
            $this->dataset = $source;
        } elseif ($source instanceof SaleByProductReportQueryService) {
            $this->dataset = $source->getDataset($filter);
        } else {
            // Backward compatibility when a legacy Builder or null is passed.
            $this->dataset = (new SaleByProductReportQueryService())->getDataset($filter);
        }
    }

    public function array(): array
    {
        $rows = [];
        $queryResults = $this->dataset['rows'] ?? collect();
        $totals = $this->dataset['totals'] ?? [
            'sold_value' => 0.0,
            'sold_value_partial' => false,
            'total_cost' => 0.0,
            'total_cost_partial' => false,
        ];

        foreach ($queryResults as $row) {
            $soldValueCell = $row->sold_value !== null ? (float) $row->sold_value : '';
            $avgSalesCell = $row->average_sales_value !== null ? (float) $row->average_sales_value : '';
            $totalCostCell = $row->total_cost !== null ? (float) $row->total_cost : '';
            $avgCostCell = $row->average_cost !== null ? (float) $row->average_cost : '';

            $rows[] = [
                $row->product_code,
                $row->product_name,
                $row->sale_role_label ?? SaleByProductReportQueryService::ROLE_LABELS[$row->sale_role ?? 'direct'] ?? '',
                (float) $row->sold_quantity,
                $row->unit_name,
                $soldValueCell,
                $avgSalesCell,
                $this->formatSoldValueStatus($row->sold_value_status ?? 'complete'),
                $totalCostCell,
                $avgCostCell,
                $this->formatCostStatus($row->cost_status ?? 'complete'),
            ];
        }

        if (!empty($rows)) {
            $rows[] = [
                'Total Keseluruhan',
                '',
                '',
                '',
                '',
                (float) $totals['sold_value'],
                '',
                !empty($totals['sold_value_partial']) ? 'Parsial (sebagian nilai jual tidak tersedia)' : 'Lengkap',
                (float) $totals['total_cost'],
                '',
                !empty($totals['total_cost_partial']) ? 'Parsial (modal belum lengkap)' : 'Lengkap',
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'Kode Produk',
            'Nama Produk',
            'Dijual sebagai',
            'Kuantitas Terjual',
            'Satuan',
            'Total Nilai terjual',
            'Harga Penjualan Rata-rata',
            'Status Nilai Terjual',
            'Total Modal',
            'Modal Rata-rata/unit',
            'Status Modal',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'D' => '#,##0.00',
            'F' => '#,##0.00',
            'G' => '#,##0.00',
            'I' => '#,##0.00',
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

                $allSettings = \Modules\Setting\Entities\Setting::query()->orderBy('company_name')->get();
                $scopeSettingIds = !empty($this->filter->scopeSettingIds) ? $this->filter->scopeSettingIds : [(int) session('setting_id')];
                $count = count($scopeSettingIds);
                $totalCount = $allSettings->count();

                if ($count === 1) {
                    $setting = $allSettings->firstWhere('id', $scopeSettingIds[0]);
                    $companyName = $setting ? $setting->company_name : 'COMPANY NAME';
                } elseif ($count === $totalCount && $totalCount > 0) {
                    $companyName = 'Semua Perusahaan';
                } else {
                    $companyName = 'Beberapa Perusahaan';
                }

                $startDate = Carbon::parse($this->filter->startDate)->format('d/m/Y');
                $endDate = Carbon::parse($this->filter->endDate)->format('d/m/Y');
                $dateRange = "Periode: {$startDate} - {$endDate}";

                // Row 1
                $sheet->setCellValue('A1', $companyName);
                $sheet->mergeCells('A1:K1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

                // Row 2
                $sheet->setCellValue('A2', 'Penjualan dengan Produk');
                $sheet->mergeCells('A2:K2');
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

                // Row 3
                $sheet->setCellValue('A3', $dateRange);
                $sheet->mergeCells('A3:K3');

                // Row 4
                $sheet->setCellValue('A4', '(dalam IDR)');
                $sheet->mergeCells('A4:K4');

                // Bold headers (now row 6)
                $sheet->getStyle('A6:K6')->getFont()->setBold(true);

                // Bold subtotal row (the last row)
                $highestRow = $sheet->getHighestRow();
                $sheet->getStyle("A{$highestRow}:K{$highestRow}")->getFont()->setBold(true);
            },
        ];
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ',',
            'enclosure' => '"',
            'line_ending' => PHP_EOL,
            'use_bom' => true,
        ];
    }

    private function formatSoldValueStatus(string $status): string
    {
        return match ($status) {
            'complete' => 'Lengkap',
            'partial' => 'Parsial (rata-rata tidak tersedia)',
            'included' => 'Included in bundle',
            default => 'Tidak tersedia',
        };
    }

    private function formatCostStatus(string $status): string
    {
        return match ($status) {
            'complete' => 'Lengkap',
            'partial' => 'Parsial (Modal belum lengkap; rata-rata tidak tersedia)',
            default => 'Modal belum lengkap',
        };
    }
}
