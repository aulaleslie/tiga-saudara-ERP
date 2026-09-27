<?php

namespace App\Exports;

use App\Services\Reports\CrossBusinessQuantityPresenter;
use App\Services\Reports\CrossBusinessStockInventoryFilterData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class CrossBusinessStockInventoryExport implements FromGenerator, WithHeadings, WithEvents, ShouldAutoSize
{
    /** @var iterable<int, array> */
    private iterable $rows;
    private Collection $businesses;
    private CrossBusinessStockInventoryFilterData $filterData;
    private string $displayMode;

    private ?array $headerRows = null;
    private string $lastColumnLetter = 'C';
    private array $numericColumnIndexes = [];
    private int $row2MaxLines = 1;
    private int $row3MaxLines = 1;

    /**
     * @param iterable<int, array> $rows Row view models; pass a Generator (as the Livewire
     *                                   component does for real downloads) so the export never
     *                                   holds a second full in-memory copy of the report data
     *                                   alongside the rows PhpSpreadsheet is writing out.
     */
    public function __construct(
        iterable $rows,
        Collection $businesses,
        CrossBusinessStockInventoryFilterData $filterData,
        string $displayMode = 'decimal'
    ) {
        $this->rows = $rows;
        $this->businesses = $businesses;
        $this->filterData = $filterData;
        $this->displayMode = in_array($displayMode, ['decimal', 'conversion'], true) ? $displayMode : 'decimal';
    }

    /**
     * The 4-tier header only depends on business/location structure, not on report rows,
     * so it can be computed once up front without buffering any data rows.
     *
     * @return array<int, array>
     */
    public function headings(): array
    {
        return $this->buildHeaderRows();
    }

    /**
     * Streams one formatted spreadsheet row per report row, so memory stays bounded by a
     * single row at a time rather than by the full result set.
     */
    public function generator(): \Generator
    {
        // Ensure header/column metadata (numeric columns, column count) is resolved even
        // if generator() were ever consumed before headings().
        $this->buildHeaderRows();

        $isConversion = ($this->displayMode === 'conversion');

        foreach ($this->rows as $product) {
            yield $this->buildDataRow($product, $isConversion);
        }
    }

    /**
     * Convenience for tests that want to assert on the full sheet content as a plain array
     * (headers + all data rows). Not used by the real download path, which streams rows via
     * generator() instead so memory stays bounded to one row at a time.
     */
    public function array(): array
    {
        return array_merge($this->headings(), iterator_to_array($this->generator()));
    }

    private function buildDataRow(array $product, bool $isConversion): array
    {
        $productStr = $product['product_name'] . ' (' . $product['product_code'] . ')';
        $catStr = (string) ($product['category_name'] ?? '');
        $brandStr = (string) ($product['brand_name'] ?? '');

        $baseUnitName = $product['base_unit_name'] ?? null;
        $convUnitName = $product['conversion_unit_name'] ?? null;
        $convFactor = $product['conversion_factor'] ?? null;

        $formatVal = function ($val) use ($isConversion, $baseUnitName, $convUnitName, $convFactor) {
            if ($isConversion) {
                return CrossBusinessQuantityPresenter::format(
                    $val,
                    'conversion',
                    $baseUnitName,
                    $convUnitName,
                    $convFactor
                );
            }
            $v = (float) ($val ?? 0.0);
            $rounded = round($v, 2);
            return (abs($rounded - round($rounded)) < 0.000001) ? (int) round($rounded) : $rounded;
        };

        $row = [
            $productStr,
            $catStr,
            $brandStr,
            $formatVal($product['total_good'] ?? 0),
            $formatVal($product['total_bad'] ?? 0),
            $formatVal($product['total_in_delivery'] ?? 0),
        ];

        foreach ($this->businesses as $b) {
            $bData = $product['businesses'][$b['setting_id']] ?? null;
            $locs = $b['locations'];
            $bInDelivery = $bData['in_delivery'] ?? 0;

            if (empty($locs)) {
                $row[] = $formatVal($bData['good'] ?? 0);
                $row[] = $formatVal($bData['bad'] ?? 0);
            } else {
                foreach ($locs as $loc) {
                    $locData = $bData['locations'][$loc['id']] ?? null;
                    $row[] = $formatVal($locData['good'] ?? 0);
                    $row[] = $formatVal($locData['bad'] ?? 0);
                }
            }

            $row[] = $formatVal($bInDelivery);
        }

        return $row;
    }

    /**
     * Build the 4 header rows and, as a side effect, resolve column count / numeric column
     * indexes needed by registerEvents() for merges and number formatting. Memoized so
     * headings() and generator() share one computation.
     */
    private function buildHeaderRows(): array
    {
        if ($this->headerRows !== null) {
            return $this->headerRows;
        }

        $isConversion = ($this->displayMode === 'conversion');

        // Header Row 1: Title
        // Header Row 2: Business Names (merged across all locations of that business + in-delivery)
        // Header Row 3: Location Names (merged across Good / Bad for each location) & Business in-delivery
        // Header Row 4: Good / Bad subheaders ('Bagus' / 'Rusak' / 'Dalam Pengiriman')

        $headerRow2 = ['Produk', 'Kategori', 'Merek', 'Total Bagus', 'Total Rusak', 'Total Dalam Pengiriman'];
        $headerRow3 = ['', '', '', '', '', ''];
        $headerRow4 = ['', '', '', '', '', ''];

        $this->numericColumnIndexes = $isConversion ? [] : [4, 5, 6];

        $colIdx = 7;
        foreach ($this->businesses as $b) {
            $locs = $b['locations'];
            $locCount = max(1, count($locs));
            $bizSpan = ($locCount * 2) + 1; // Good/Bad pairs per location + 1 business in-delivery

            $headerRow2[] = $b['company_name'];
            for ($i = 1; $i < $bizSpan; $i++) {
                $headerRow2[] = '';
            }

            $totalSpanWidth = $bizSpan * 15;
            $bLines = max(1, (int) ceil(mb_strlen($b['company_name']) / max(1, $totalSpanWidth)));
            $this->row2MaxLines = max($this->row2MaxLines, $bLines);

            if (empty($locs)) {
                $headerRow3[] = '—';
                $headerRow3[] = '';
                $headerRow4[] = 'Bagus';
                $headerRow4[] = 'Rusak';
                if (!$isConversion) {
                    $this->numericColumnIndexes[] = $colIdx;
                    $this->numericColumnIndexes[] = $colIdx + 1;
                }
                $colIdx += 2;
            } else {
                foreach ($locs as $loc) {
                    $headerRow3[] = $loc['name'];
                    $headerRow3[] = '';
                    $headerRow4[] = 'Bagus';
                    $headerRow4[] = 'Rusak';

                    $locMin = max(10, (int) ceil(mb_strlen($loc['name']) / 2) + 2);
                    $locSpanWidth = $locMin * 2;
                    $lLines = max(1, (int) ceil(mb_strlen($loc['name']) / max(1, $locSpanWidth)));
                    $this->row3MaxLines = max($this->row3MaxLines, $lLines);

                    if (!$isConversion) {
                        $this->numericColumnIndexes[] = $colIdx;
                        $this->numericColumnIndexes[] = $colIdx + 1;
                    }
                    $colIdx += 2;
                }
            }

            $headerRow3[] = 'Dalam Pengiriman';
            $headerRow4[] = 'Dalam Pengiriman';
            if (!$isConversion) {
                $this->numericColumnIndexes[] = $colIdx;
            }
            $colIdx++;
        }

        $totalColumns = max(6, $colIdx - 1);
        $this->lastColumnLetter = $this->getColumnLetter($totalColumns);

        $headerRow1 = array_fill(0, $totalColumns, '');
        $headerRow1[0] = 'Stok Persediaan Lintas Bisnis';

        return $this->headerRows = [$headerRow1, $headerRow2, $headerRow3, $headerRow4];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $this->applyStyles($event->sheet->getDelegate());
            },
        ];
    }

    private function applyStyles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        $this->buildHeaderRows();
        $lastRowIndex = $sheet->getHighestRow();

        // 1. Merge Title Row 1 across all columns
        $sheet->mergeCells("A1:{$this->lastColumnLetter}1");

        // 2. Merge Fixed product columns vertically across all 3 header tiers (Rows 2 to 4)
        $sheet->mergeCells('A2:A4');
        $sheet->mergeCells('B2:B4');
        $sheet->mergeCells('C2:C4');
        $sheet->mergeCells('D2:D4');
        $sheet->mergeCells('E2:E4');
        $sheet->mergeCells('F2:F4');

        // 3. Merge Business headers (Row 2) and Location headers (Row 3)
        $colIndex = 7;
        foreach ($this->businesses as $b) {
            $locs = $b['locations'];
            $locCount = max(1, count($locs));
            $bizSpan = ($locCount * 2) + 1;

            $businessStartCol = $this->getColumnLetter($colIndex);
            $businessEndCol = $this->getColumnLetter($colIndex + $bizSpan - 1);
            $sheet->mergeCells("{$businessStartCol}2:{$businessEndCol}2");

            for ($i = 0; $i < $locCount; $i++) {
                $locStartCol = $this->getColumnLetter($colIndex);
                $locEndCol = $this->getColumnLetter($colIndex + 1);
                $sheet->mergeCells("{$locStartCol}3:{$locEndCol}3");
                $colIndex += 2;
            }

            $inDeliveryCol = $this->getColumnLetter($colIndex);
            $sheet->mergeCells("{$inDeliveryCol}3:{$inDeliveryCol}4");
            $colIndex++;
        }

        $sheet->getRowDimension(1)->setRowHeight(32);
        $sheet->getRowDimension(2)->setRowHeight(max(24, $this->row2MaxLines * 18));
        $sheet->getRowDimension(3)->setRowHeight(max(22, $this->row3MaxLines * 17));
        $sheet->getRowDimension(4)->setRowHeight(20);

        $sheet->getColumnDimension('A')->setWidth(max(25, $sheet->getColumnDimension('A')->getWidth()));

        $headerStyles = [
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

        foreach ($headerStyles as $rowIndex => $style) {
            $sheet->getStyle("A{$rowIndex}:{$this->lastColumnLetter}{$rowIndex}")->applyFromArray($style);
        }

        if ($lastRowIndex >= 4) {
            $sheet->getStyle("A1:{$this->lastColumnLetter}{$lastRowIndex}")->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['argb' => 'FFD0D7DE'],
                    ],
                ],
            ]);

            if (!empty($this->numericColumnIndexes)) {
                foreach ($this->numericColumnIndexes as $colIdx) {
                    $colLetter = $this->getColumnLetter($colIdx);
                    $sheet->getStyle("{$colLetter}5:{$colLetter}{$lastRowIndex}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.##');

                    $sheet->getStyle("{$colLetter}5:{$colLetter}{$lastRowIndex}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
            } else {
                $startQtyCol = $this->getColumnLetter(4);
                $sheet->getStyle("{$startQtyCol}5:{$this->lastColumnLetter}{$lastRowIndex}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }
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
