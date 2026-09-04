<?php

namespace App\Exports;

use App\Services\Reports\CrossBusinessStockInventoryFilterData;
use App\Services\Reports\CrossBusinessStockInventoryQueryService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CrossBusinessStockInventoryExport implements FromArray, WithStyles, ShouldAutoSize
{
    private Collection $rows;
    private Collection $businesses;
    private CrossBusinessStockInventoryFilterData $filterData;
    private int $lastRowIndex = 0;
    private string $lastColumnLetter = 'C';

    public function __construct(
        Collection $rows,
        Collection $businesses,
        CrossBusinessStockInventoryFilterData $filterData
    ) {
        $this->rows = $rows;
        $this->businesses = $businesses;
        $this->filterData = $filterData;
    }

    public function array(): array
    {
        $exportRows = [];

        // Header Row 1: Fixed product columns + Per-business colspan headers
        $headerRow1 = [
            'Produk',
            'Kategori',
            'Merek',
        ];

        // Header Row 2: Sub-headers (Good / Bad for each location)
        $headerRow2 = [
            '',
            '',
            '',
        ];

        foreach ($this->businesses as $b) {
            $locs = $b['locations'];
            if (empty($locs)) {
                $headerRow1[] = $b['company_name'];
                $headerRow1[] = '';
                $headerRow2[] = 'Bagus';
                $headerRow2[] = 'Rusak';
            } else {
                foreach ($locs as $loc) {
                    $headerRow1[] = $b['company_name'] . ' - ' . $loc['name'];
                    $headerRow1[] = '';
                    $headerRow2[] = 'Bagus';
                    $headerRow2[] = 'Rusak';
                }
            }
        }

        $exportRows[] = $headerRow1;
        $exportRows[] = $headerRow2;

        foreach ($this->rows as $product) {
            $row = [
                $product['product_name'] . ' (' . $product['product_code'] . ')',
                $product['category_name'],
                $product['brand_name'],
            ];

            foreach ($this->businesses as $b) {
                $bData = $product['businesses'][$b['setting_id']] ?? null;
                $locs = $b['locations'];

                if (empty($locs)) {
                    $row[] = $bData['good'] ?? 0;
                    $row[] = $bData['bad'] ?? 0;
                } else {
                    foreach ($locs as $loc) {
                        $locData = $bData['locations'][$loc['id']] ?? null;
                        $row[] = $locData['good'] ?? 0;
                        $row[] = $locData['bad'] ?? 0;
                    }
                }
            }

            $exportRows[] = $row;
        }

        $this->lastRowIndex = count($exportRows);

        return $exportRows;
    }

    public function styles(Worksheet $sheet)
    {
        $colIndex = 4; // starting after Produk (1), Kategori (2), Merek (3)

        // Merge headers for fixed columns across row 1 and row 2
        $sheet->mergeCells('A1:A2');
        $sheet->mergeCells('B1:B2');
        $sheet->mergeCells('C1:C2');

        foreach ($this->businesses as $b) {
            $locs = $b['locations'];
            $locCount = max(1, count($locs));

            for ($i = 0; $i < $locCount; $i++) {
                $fromCol = $this->getColumnLetter($colIndex);
                $toCol = $this->getColumnLetter($colIndex + 1);
                $sheet->mergeCells("{$fromCol}1:{$toCol}1");
                $colIndex += 2;
            }
        }

        $this->lastColumnLetter = $this->getColumnLetter(max(3, $colIndex - 1));

        $styles = [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF2A363B'],
                ],
            ],
            2 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF3F525B'],
                ],
            ],
        ];

        // Apply borders and styling to table
        if ($this->lastRowIndex >= 2) {
            $sheet->getStyle("A1:{$this->lastColumnLetter}{$this->lastRowIndex}")->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['argb' => 'FFD0D7DE'],
                    ],
                ],
            ]);
        }

        return $styles;
    }

    private function getColumnLetter(int $colNumber): string
    {
        $dividend = $colNumber;
        $columnName = '';

        while ($dividend > 0) {
            $modulo = ($dividend - 1) % 26;
            $columnName = chr(65 + $modulo) . $columnName;
            $dividend = (int) (($dividend - $modulo) / 26);
        }

        return $columnName;
    }
}
