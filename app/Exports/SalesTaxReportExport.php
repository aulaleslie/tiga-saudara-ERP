<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use App\Services\Reports\SalesTaxReportFilterData;
use Modules\Setting\Entities\Setting;
use Illuminate\Support\Collection;

class SalesTaxReportExport implements FromCollection, WithHeadings, WithMapping, WithCustomStartCell, WithStyles
{
    protected $query;
    protected $filter;
    protected $isCsv;

    public function __construct(Collection $query, SalesTaxReportFilterData $filter, bool $isCsv = false)
    {
        $this->query = $query;
        $this->filter = $filter;
        $this->isCsv = $isCsv;
    }

    public function collection()
    {
        $rows = [];
        
        $groupedByTax = $this->query->groupBy('tax_name');

        foreach ($groupedByTax as $taxGroup => $items) {
            if (!$this->isCsv) {
                // Group Header for Excel
                $rows[] = [
                    'is_header' => true,
                    'tax_group' => $taxGroup,
                ];
            }

            $taxTotal = 0;

            foreach (['Penjualan', 'Pembelian'] as $type) {
                $item = $items->firstWhere('transaction_type', $type);
                if ($item) {
                    if ($this->isCsv) {
                        $rows[] = [
                            'is_row' => true,
                            'tax_name' => $taxGroup,
                            'type' => $type,
                            'dpp' => $item->dpp,
                            'tax_rate' => floatval($item->tax_rate),
                            'total_tax' => $item->total_tax,
                        ];
                    } else {
                        $rows[] = [
                            'is_row' => true,
                            'type' => $type,
                            'dpp' => $item->dpp,
                            'tax_rate' => floatval($item->tax_rate),
                            'total_tax' => $item->total_tax,
                        ];
                    }

                    if ($type === 'Penjualan') {
                        $taxTotal += $item->total_tax;
                    } else {
                        $taxTotal -= $item->total_tax;
                    }
                }
            }

            if (!$this->isCsv) {
                // Subtotal for Excel
                $rows[] = [
                    'is_subtotal' => true,
                    'amount' => $taxTotal,
                ];
                // Blank Separator for Excel
                $rows[] = [
                    'is_separator' => true,
                ];
            }
        }

        return collect($rows);
    }

    public function headings(): array
    {
        if ($this->isCsv) {
            return [
                'Nama Pajak',
                'Transaksi',
                'DPP',
                'Rate Pajak',
                'Total Pajak',
            ];
        }

        return [
            'Tanggal',
            'DPP',
            'Rate Pajak',
            'Total Pajak',
        ];
    }

    public function map($row): array
    {
        if ($this->isCsv) {
            return [
                $row['tax_name'],
                $row['type'],
                $row['dpp'],
                number_format($row['tax_rate'], 1, '.', ''),
                $row['total_tax'],
            ];
        }

        if (isset($row['is_header'])) {
            return [
                $row['tax_group'],
                '',
                '',
                '',
            ];
        }

        if (isset($row['is_row'])) {
            return [
                $row['type'],
                $row['dpp'],
                $row['tax_rate'],
                $row['total_tax'],
            ];
        }

        if (isset($row['is_subtotal'])) {
            return [
                '',
                '',
                '',
                $row['amount'],
            ];
        }

        if (isset($row['is_separator'])) {
            return [
                '',
                '',
                '',
                '',
            ];
        }

        return [];
    }

    public function startCell(): string
    {
        return $this->isCsv ? 'A1' : 'A6';
    }

    public function styles(Worksheet $sheet)
    {
        if ($this->isCsv) {
            return [];
        }

        $lastRow = $sheet->getHighestRow();

        $setting = Setting::find($this->filter->scopeSettingId);
        $companyName = $setting ? $setting->company_name : 'Tiga Saudara ERP';

        $sheet->mergeCells('A1:D1');
        $sheet->setCellValue('A1', $companyName);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

        $sheet->mergeCells('A2:D2');
        $sheet->setCellValue('A2', 'Laporan Pajak Penjualan');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);

        $sheet->mergeCells('A3:D3');
        $sheet->setCellValue('A3', 'Periode: ' . $this->filter->startDate . ' s/d ' . $this->filter->endDate);
        
        $sheet->mergeCells('A4:D4');
        $sheet->setCellValue('A4', '(dalam IDR)');
        $sheet->getStyle('A4')->getFont()->setItalic(true);

        $sheet->getStyle('A6:D6')->getFont()->setBold(true);

        $sheet->getStyle('B7:B' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('C7:C' . $lastRow)->getNumberFormat()->setFormatCode('0.0');
        $sheet->getStyle('D7:D' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
        
        // Loop through data to apply bold to specific rows (group headers, subtotals, grand totals)
        // Since headers start at A6, data starts at A7
        $dataRows = $this->collection();
        $currentRow = 7;
        foreach ($dataRows as $data) {
            if (isset($data['is_header']) || isset($data['is_subtotal'])) {
                $sheet->getStyle("A{$currentRow}:D{$currentRow}")->getFont()->setBold(true);
            }
            $currentRow++;
        }

        return [];
    }
}
