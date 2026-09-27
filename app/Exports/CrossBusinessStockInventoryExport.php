<?php

namespace App\Exports;

use App\Services\Reports\CrossBusinessStockInventoryFilterData;
use App\Services\Reports\CrossBusinessStockInventoryQueryService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CrossBusinessStockInventoryExport implements FromArray, WithStyles
{
    private Collection $rows;
    private Collection $businesses;
    private CrossBusinessStockInventoryFilterData $filterData;
    private int $lastRowIndex = 0;
    private string $lastColumnLetter = 'C';
    private array $columnMaxWidths = [];
    private int $row2MaxLines = 1;
    private int $row3MaxLines = 1;

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

        // Track max content length per column index (1-based)
        $this->columnMaxWidths = [
            1 => 15, // Produk
            2 => 12, // Kategori
            3 => 10, // Merek
            4 => 11, // Total Bagus
            5 => 11, // Total Rusak
        ];

        // Header Row 1: Title
        // Header Row 2: Business Names (merged across all locations of that business)
        // Header Row 3: Location Names (merged across Good / Bad for each location)
        // Header Row 4: Good / Bad subheaders ('Bagus' / 'Rusak')

        $headerRow2 = ['Produk', 'Kategori', 'Merek', 'Total Bagus', 'Total Rusak'];
        $headerRow3 = ['', '', '', '', ''];
        $headerRow4 = ['', '', '', '', ''];

        $colIdx = 6;
        foreach ($this->businesses as $b) {
            $locs = $b['locations'];
            $locCount = max(1, count($locs));

            // Populate Row 2 with business name and empty cells for merge
            $headerRow2[] = $b['company_name'];
            for ($i = 1; $i < $locCount * 2; $i++) {
                $headerRow2[] = '';
            }

            // Estimate business name line wrapping across its merged columns span (each pair is ~20+ width)
            $totalSpanWidth = $locCount * 22;
            $bLines = max(1, (int) ceil(mb_strlen($b['company_name']) / max(1, $totalSpanWidth)));
            $this->row2MaxLines = max($this->row2MaxLines, $bLines);

            if (empty($locs)) {
                $headerRow3[] = '—';
                $headerRow3[] = '';
                $headerRow4[] = 'Bagus';
                $headerRow4[] = 'Rusak';
                $this->columnMaxWidths[$colIdx] = max($this->columnMaxWidths[$colIdx] ?? 0, 10);
                $this->columnMaxWidths[$colIdx + 1] = max($this->columnMaxWidths[$colIdx + 1] ?? 0, 10);
                $colIdx += 2;
            } else {
                foreach ($locs as $loc) {
                    $headerRow3[] = $loc['name'];
                    $headerRow3[] = '';
                    $headerRow4[] = 'Bagus';
                    $headerRow4[] = 'Rusak';

                    // Ensure minimum width of at least half the location name or 10
                    $locMin = max(10, (int) ceil(mb_strlen($loc['name']) / 2) + 2);
                    $this->columnMaxWidths[$colIdx] = max($this->columnMaxWidths[$colIdx] ?? 0, $locMin);
                    $this->columnMaxWidths[$colIdx + 1] = max($this->columnMaxWidths[$colIdx + 1] ?? 0, $locMin);

                    // Estimate location name line wrapping across 2 columns (~2 * locMin width)
                    $locSpanWidth = $locMin * 2;
                    $lLines = max(1, (int) ceil(mb_strlen($loc['name']) / max(1, $locSpanWidth)));
                    $this->row3MaxLines = max($this->row3MaxLines, $lLines);

                    $colIdx += 2;
                }
            }
        }

        $totalColumns = max(5, $colIdx - 1);
        $this->lastColumnLetter = $this->getColumnLetter($totalColumns);

        // Row 1: Title Row
        $headerRow1 = array_fill(0, $totalColumns, '');
        $headerRow1[0] = 'Stok Persediaan Lintas Bisnis';

        $exportRows[] = $headerRow1;
        $exportRows[] = $headerRow2;
        $exportRows[] = $headerRow3;
        $exportRows[] = $headerRow4;

        foreach ($this->rows as $product) {
            $productStr = $product['product_name'] . ' (' . $product['product_code'] . ')';
            $catStr = (string) ($product['category_name'] ?? '');
            $brandStr = (string) ($product['brand_name'] ?? '');
            $totalGood = $product['total_good'] ?? 0;
            $totalBad = $product['total_bad'] ?? 0;

            $this->columnMaxWidths[1] = max($this->columnMaxWidths[1], mb_strlen($productStr));
            $this->columnMaxWidths[2] = max($this->columnMaxWidths[2], mb_strlen($catStr));
            $this->columnMaxWidths[3] = max($this->columnMaxWidths[3], mb_strlen($brandStr));
            $this->columnMaxWidths[4] = max($this->columnMaxWidths[4], mb_strlen((string) $totalGood));
            $this->columnMaxWidths[5] = max($this->columnMaxWidths[5], mb_strlen((string) $totalBad));

            $row = [
                $productStr,
                $catStr,
                $brandStr,
                $totalGood,
                $totalBad,
            ];

            $cIdx = 6;
            foreach ($this->businesses as $b) {
                $bData = $product['businesses'][$b['setting_id']] ?? null;
                $locs = $b['locations'];

                if (empty($locs)) {
                    $good = $bData['good'] ?? 0;
                    $bad = $bData['bad'] ?? 0;
                    $row[] = $good;
                    $row[] = $bad;
                    $this->columnMaxWidths[$cIdx] = max($this->columnMaxWidths[$cIdx] ?? 0, mb_strlen((string) $good));
                    $this->columnMaxWidths[$cIdx + 1] = max($this->columnMaxWidths[$cIdx + 1] ?? 0, mb_strlen((string) $bad));
                    $cIdx += 2;
                } else {
                    foreach ($locs as $loc) {
                        $locData = $bData['locations'][$loc['id']] ?? null;
                        $good = $locData['good'] ?? 0;
                        $bad = $locData['bad'] ?? 0;
                        $row[] = $good;
                        $row[] = $bad;
                        $this->columnMaxWidths[$cIdx] = max($this->columnMaxWidths[$cIdx] ?? 0, mb_strlen((string) $good));
                        $this->columnMaxWidths[$cIdx + 1] = max($this->columnMaxWidths[$cIdx + 1] ?? 0, mb_strlen((string) $bad));
                        $cIdx += 2;
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
        // 1. Merge Title Row 1 across all columns
        $sheet->mergeCells("A1:{$this->lastColumnLetter}1");

        // 2. Merge Fixed product columns vertically across all 3 header tiers (Rows 2 to 4)
        $sheet->mergeCells('A2:A4');
        $sheet->mergeCells('B2:B4');
        $sheet->mergeCells('C2:C4');
        $sheet->mergeCells('D2:D4');
        $sheet->mergeCells('E2:E4');

        // 3. Merge Business headers (Row 2) and Location headers (Row 3)
        $colIndex = 6;
        foreach ($this->businesses as $b) {
            $locs = $b['locations'];
            $locCount = max(1, count($locs));

            // Merge Business Name horizontally across all location Good/Bad columns
            $businessStartCol = $this->getColumnLetter($colIndex);
            $businessEndCol = $this->getColumnLetter($colIndex + ($locCount * 2) - 1);
            $sheet->mergeCells("{$businessStartCol}2:{$businessEndCol}2");

            for ($i = 0; $i < $locCount; $i++) {
                $locStartCol = $this->getColumnLetter($colIndex);
                $locEndCol = $this->getColumnLetter($colIndex + 1);
                $sheet->mergeCells("{$locStartCol}3:{$locEndCol}3");
                $colIndex += 2;
            }
        }

        // Apply explicit column widths with padding so content is not truncated
        foreach ($this->columnMaxWidths as $cIndex => $maxWidth) {
            $colLetter = $this->getColumnLetter($cIndex);
            $width = max(10, $maxWidth + 3);
            // Produk column has longer text, give ample space
            if ($cIndex === 1) {
                $width = max(25, $width);
            }
            $sheet->getColumnDimension($colLetter)->setWidth($width);
        }

        $sheet->getRowDimension(1)->setRowHeight(32);
        $sheet->getRowDimension(2)->setRowHeight(max(24, $this->row2MaxLines * 18));
        $sheet->getRowDimension(3)->setRowHeight(max(22, $this->row3MaxLines * 17));
        $sheet->getRowDimension(4)->setRowHeight(20);

        $styles = [
            // Row 1: Title styling
            1 => [
                'font' => [
                    'bold' => true,
                    'size' => 15,
                    'color' => ['argb' => 'FF1F2937'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFF3F4F6'],
                ],
            ],
            // Row 2: Business Header Tier 1 (Dark slate)
            2 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF1F2937'],
                ],
            ],
            // Row 3: Location Header Tier 2 (Medium slate)
            3 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 10],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF374151'],
                ],
            ],
            // Row 4: Good / Bad Header Tier 3 (Light slate)
            4 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 9],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF4B5563'],
                ],
            ],
        ];

        // Apply borders across the whole table (headers + data rows)
        if ($this->lastRowIndex >= 4) {
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
