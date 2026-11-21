<?php

namespace App\Exports;

use App\Services\Reports\CrossBusinessQuantityPresenter;
use App\Services\Reports\CrossBusinessStockInventoryFilterData;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class CrossBusinessStockInventoryExport implements FromGenerator, WithHeadings, WithEvents, ShouldAutoSize, WithStrictNullComparison
{
    public const CONTENT_STOCK = 'stock';
    public const CONTENT_PRICES = 'prices';
    public const CONTENT_STOCK_AND_PRICES = 'stock_and_prices';

    /** @var iterable<int, array> */
    private iterable $rows;
    private Collection $businesses;
    private CrossBusinessStockInventoryFilterData $filterData;
    private string $displayMode;
    private string $exportContent;

    private ?array $headerRows = null;
    private string $lastColumnLetter = 'C';
    private int $fixedColumnCount = 6;
    private array $numericColumnIndexes = [];
    private array $priceColumnIndexes = [];
    private array $conversionColumnIndexes = [];
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
        string $displayMode = 'decimal',
        string $exportContent = self::CONTENT_STOCK
    ) {
        $this->rows = $rows;
        $this->businesses = $businesses;
        $this->filterData = $filterData;
        $this->displayMode = in_array($displayMode, ['decimal', 'conversion'], true) ? $displayMode : 'decimal';
        $this->exportContent = in_array($exportContent, [
            self::CONTENT_STOCK,
            self::CONTENT_PRICES,
            self::CONTENT_STOCK_AND_PRICES,
        ], true) ? $exportContent : self::CONTENT_STOCK;
    }

    private function includesStock(): bool
    {
        return in_array($this->exportContent, [self::CONTENT_STOCK, self::CONTENT_STOCK_AND_PRICES], true);
    }

    private function includesPrices(): bool
    {
        return in_array($this->exportContent, [self::CONTENT_PRICES, self::CONTENT_STOCK_AND_PRICES], true);
    }

    /**
     * The 4-tier header only depends on business/location structure and export content, not on
     * report rows, so it can be computed once up front without buffering any data rows.
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

        $row = [
            $productStr,
            $catStr,
            $brandStr,
        ];

        if ($this->includesPrices()) {
            $row[] = $baseUnitName !== null && $baseUnitName !== '' ? (string) $baseUnitName : '-';
        }

        if ($this->includesStock()) {
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

            $row[] = $formatVal($product['total_good'] ?? 0);
            $row[] = $formatVal($product['total_bad'] ?? 0);
            $row[] = $formatVal($product['total_in_delivery'] ?? 0);
        }

        foreach ($this->businesses as $b) {
            $settingId = $b['setting_id'];

            if ($this->includesStock()) {
                $bData = $product['businesses'][$settingId] ?? null;
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

            if ($this->includesPrices()) {
                $pData = $product['prices'][$settingId] ?? null;
                $row[] = $this->formatPriceCell($pData['average_purchase_price'] ?? null);
                $row[] = $this->formatPriceCell($pData['sale_price'] ?? null);
                $row[] = $this->formatPriceCell($pData['tier_1_price'] ?? null);
                $row[] = $this->formatPriceCell($pData['tier_2_price'] ?? null);
            }
        }

        return $row;
    }

    private function formatPriceCell($value): ?float
    {
        if ($value === null) {
            return null;
        }

        return (float) $value;
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
        $includeStock = $this->includesStock();
        $includePrices = $this->includesPrices();

        $headerRow2 = ['Produk', 'Kategori', 'Merek'];
        $headerRow3 = ['', '', ''];
        $headerRow4 = ['', '', ''];

        if ($includePrices) {
            $headerRow2[] = 'Satuan';
            $headerRow3[] = '';
            $headerRow4[] = '';
        }

        $this->numericColumnIndexes = [];
        $this->priceColumnIndexes = [];
        $this->conversionColumnIndexes = [];

        if ($includeStock) {
            $totalStartIdx = count($headerRow2) + 1;
            $headerRow2[] = 'Total Bagus';
            $headerRow2[] = 'Total Rusak';
            $headerRow2[] = 'Total Dalam Pengiriman';
            $headerRow3[] = '';
            $headerRow3[] = '';
            $headerRow3[] = '';
            $headerRow4[] = '';
            $headerRow4[] = '';
            $headerRow4[] = '';

            if ($isConversion) {
                $this->conversionColumnIndexes[] = $totalStartIdx;
                $this->conversionColumnIndexes[] = $totalStartIdx + 1;
                $this->conversionColumnIndexes[] = $totalStartIdx + 2;
            } else {
                $this->numericColumnIndexes[] = $totalStartIdx;
                $this->numericColumnIndexes[] = $totalStartIdx + 1;
                $this->numericColumnIndexes[] = $totalStartIdx + 2;
            }
        }

        $this->fixedColumnCount = count($headerRow2);
        $colIdx = $this->fixedColumnCount + 1;

        foreach ($this->businesses as $b) {
            $locs = $b['locations'];
            $locCount = max(1, count($locs));
            $stockSpan = $includeStock ? (($locCount * 2) + 1) : 0;
            $priceSpan = $includePrices ? 4 : 0;
            $bizSpan = $stockSpan + $priceSpan;

            $headerRow2[] = $b['company_name'];
            for ($i = 1; $i < $bizSpan; $i++) {
                $headerRow2[] = '';
            }

            $totalSpanWidth = $bizSpan * 15;
            $bLines = max(1, (int) ceil(mb_strlen($b['company_name']) / max(1, $totalSpanWidth)));
            $this->row2MaxLines = max($this->row2MaxLines, $bLines);

            if ($includeStock) {
                if (empty($locs)) {
                    $headerRow3[] = '—';
                    $headerRow3[] = '';
                    $headerRow4[] = 'Bagus';
                    $headerRow4[] = 'Rusak';
                    if ($isConversion) {
                        $this->conversionColumnIndexes[] = $colIdx;
                        $this->conversionColumnIndexes[] = $colIdx + 1;
                    } else {
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

                        if ($isConversion) {
                            $this->conversionColumnIndexes[] = $colIdx;
                            $this->conversionColumnIndexes[] = $colIdx + 1;
                        } else {
                            $this->numericColumnIndexes[] = $colIdx;
                            $this->numericColumnIndexes[] = $colIdx + 1;
                        }
                        $colIdx += 2;
                    }
                }

                $headerRow3[] = 'Dalam Pengiriman';
                $headerRow4[] = 'Dalam Pengiriman';
                if ($isConversion) {
                    $this->conversionColumnIndexes[] = $colIdx;
                } else {
                    $this->numericColumnIndexes[] = $colIdx;
                }
                $colIdx++;
            }

            if ($includePrices) {
                $headerRow3[] = 'Harga (Satuan Dasar)';
                $headerRow3[] = '';
                $headerRow3[] = '';
                $headerRow3[] = '';
                $headerRow4[] = 'Harga Modal';
                $headerRow4[] = 'Harga Jual';
                $headerRow4[] = 'Tier 1';
                $headerRow4[] = 'Tier 2';

                $this->priceColumnIndexes[] = $colIdx;
                $this->priceColumnIndexes[] = $colIdx + 1;
                $this->priceColumnIndexes[] = $colIdx + 2;
                $this->priceColumnIndexes[] = $colIdx + 3;
                $colIdx += 4;
            }
        }

        $totalColumns = max($this->fixedColumnCount, $colIdx - 1);
        $this->lastColumnLetter = $this->getColumnLetter($totalColumns);

        $title = match ($this->exportContent) {
            self::CONTENT_PRICES => 'Daftar Harga Produk Lintas Bisnis',
            self::CONTENT_STOCK_AND_PRICES => 'Stok & Harga Produk Lintas Bisnis',
            default => 'Stok Persediaan Lintas Bisnis',
        };

        $headerRow1 = array_fill(0, $totalColumns, '');
        $headerRow1[0] = $title;

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
        $includeStock = $this->includesStock();
        $includePrices = $this->includesPrices();

        // 1. Merge Title Row 1 across all columns
        $sheet->mergeCells("A1:{$this->lastColumnLetter}1");

        // 2. Merge Fixed product columns vertically across all 3 header tiers (Rows 2 to 4)
        for ($i = 1; $i <= $this->fixedColumnCount; $i++) {
            $colLetter = $this->getColumnLetter($i);
            $sheet->mergeCells("{$colLetter}2:{$colLetter}4");
        }

        // 3. Merge Business headers (Row 2), Location headers (Row 3), In-Delivery (Row 3:4), and Price group (Row 3)
        $colIndex = $this->fixedColumnCount + 1;
        foreach ($this->businesses as $b) {
            $locs = $b['locations'];
            $locCount = max(1, count($locs));
            $stockSpan = $includeStock ? (($locCount * 2) + 1) : 0;
            $priceSpan = $includePrices ? 4 : 0;
            $bizSpan = $stockSpan + $priceSpan;

            $businessStartCol = $this->getColumnLetter($colIndex);
            $businessEndCol = $this->getColumnLetter($colIndex + $bizSpan - 1);
            $sheet->mergeCells("{$businessStartCol}2:{$businessEndCol}2");

            if ($includeStock) {
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

            if ($includePrices) {
                $priceStartCol = $this->getColumnLetter($colIndex);
                $priceEndCol = $this->getColumnLetter($colIndex + 3);
                $sheet->mergeCells("{$priceStartCol}3:{$priceEndCol}3");
                $colIndex += 4;
            }
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

            if ($lastRowIndex >= 5) {
                foreach ($this->numericColumnIndexes as $colIdx) {
                    $colLetter = $this->getColumnLetter($colIdx);
                    $sheet->getStyle("{$colLetter}5:{$colLetter}{$lastRowIndex}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.##');

                    $sheet->getStyle("{$colLetter}5:{$colLetter}{$lastRowIndex}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }

                foreach ($this->conversionColumnIndexes as $colIdx) {
                    $colLetter = $this->getColumnLetter($colIdx);
                    $sheet->getStyle("{$colLetter}5:{$colLetter}{$lastRowIndex}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                foreach ($this->priceColumnIndexes as $colIdx) {
                    $colLetter = $this->getColumnLetter($colIdx);
                    $sheet->getStyle("{$colLetter}5:{$colLetter}{$lastRowIndex}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00');

                    $sheet->getStyle("{$colLetter}5:{$colLetter}{$lastRowIndex}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
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
