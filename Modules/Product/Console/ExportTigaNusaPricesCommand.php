<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Modules\Setting\Entities\Setting;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Modules\Product\Services\TigaNusaPriceExportService;

class ExportTigaNusaPricesCommand extends Command
{
    protected $signature = 'product:export-tiga-nusa-prices {--path= : The path to save the exported XLSX file} {--force : Overwrite the file if it already exists}';

    protected $description = 'Export product prices for CV TIGA NUSA COMPUTER and CV TOP IT INTERNUSA to an Excel file.';

    private const HEADERS = [
        'Nama Produk',
        'Harga Jual',
        'Harga Tier 1',
        'Harga Tier 2',
        'Harga Beli Terakhir',
        'Harga Beli Rata-rata',
    ];

    private const LAST_COLUMN = 'F';

    public function handle(): int
    {
        try {
            $service = new TigaNusaPriceExportService();

            // Resolve every required setting before touching the destination file.
            $settings = $service->resolveTargetSettings();

            $path = $this->option('path') ?: storage_path('app/product_prices_tiga_nusa_export.xlsx');
            $force = $this->option('force');

            if (file_exists($path) && !$force) {
                if (!$this->confirm("File {$path} already exists. Overwrite?", false)) {
                    $this->info('Export cancelled.');
                    return 1;
                }
            }

            $this->info("Exporting product prices to {$path}...");

            $spreadsheet = new Spreadsheet();
            $counts = [];

            foreach (array_values($settings) as $index => $setting) {
                $sheet = $index === 0
                    ? $spreadsheet->getActiveSheet()
                    : $spreadsheet->createSheet($index);

                $counts[$setting->company_name] = $this->buildWorksheet($sheet, $setting, $service);
            }

            $spreadsheet->setActiveSheetIndex(0);

            $writer = new Xlsx($spreadsheet);
            $writer->save($path);

            foreach ($counts as $companyName => $count) {
                if ($count === 0) {
                    $this->info("{$companyName}: 0 products exported. No products found in the catalog.");
                } else {
                    $this->info("{$companyName}: {$count} products exported successfully to {$path}");
                }
            }

            $total = array_sum($counts);
            if ($total === 0) {
                $this->info('0 products exported. No products found in the catalog.');
            } else {
                $this->info("{$total} products exported successfully to {$path}");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function buildWorksheet(Worksheet $sheet, Setting $setting, TigaNusaPriceExportService $service): int
    {
        $sheet->setTitle($this->worksheetTitle($setting->company_name));

        $products = $service->buildQuery($setting)->get();
        $count = $products->count();

        // Title
        $sheet->setCellValue('A1', $setting->company_name);
        $sheet->mergeCells('A1:' . self::LAST_COLUMN . '1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // Subtitle
        $sheet->setCellValue('A2', 'Daftar Harga Produk');
        $sheet->mergeCells('A2:' . self::LAST_COLUMN . '2');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

        // Timestamp
        $sheet->setCellValue('A3', 'Tanggal: ' . now()->format('d/m/Y H:i'));
        $sheet->mergeCells('A3:' . self::LAST_COLUMN . '3');
        $sheet->getStyle('A3')->getFont()->setItalic(true);

        // Headers
        foreach (self::HEADERS as $col => $header) {
            $sheet->setCellValueByColumnAndRow($col + 1, 4, $header);
        }

        $sheet->getStyle('4:4')->getFont()->setBold(true);
        $sheet->getStyle('4:4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle('4:4')->getFill()->getStartColor()->setARGB('FFF2F2F2');

        // Data rows
        $row = 5;
        foreach ($products as $product) {
            $sheet->setCellValueByColumnAndRow(1, $row, $product->product_name);

            $this->writePrice($sheet, 2, $row, $product->sale_price === null ? null : (float) $product->sale_price);
            $this->writePrice($sheet, 3, $row, $product->tier_1_price === null ? null : (float) $product->tier_1_price);
            $this->writePrice($sheet, 4, $row, $product->tier_2_price === null ? null : (float) $product->tier_2_price);

            $lastPurchasePrice = $service->resolveLastPurchasePrice(
                $product->last_purchase_price,
                $product->product_purchase_price
            );
            $averagePurchasePrice = $service->resolveAveragePurchasePrice(
                $product->average_purchase_price,
                $lastPurchasePrice
            );

            $this->writePrice($sheet, 5, $row, $lastPurchasePrice);
            $this->writePrice($sheet, 6, $row, $averagePurchasePrice);

            $row++;
        }

        $sheet->freezePane('A5');
        $sheet->setAutoFilter('A4:' . self::LAST_COLUMN . ($row - 1));

        $sheet->getColumnDimension('A')->setWidth(30);
        foreach (['B', 'C', 'D', 'E', 'F'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(18);
        }

        return $count;
    }

    private function writePrice(Worksheet $sheet, int $column, int $row, ?float $value): void
    {
        if ($value === null) {
            return;
        }

        $sheet->setCellValueByColumnAndRow($column, $row, $value);
        $sheet->getStyleByColumnAndRow($column, $row)->getNumberFormat()->setFormatCode('#,##0.00');
    }

    private function worksheetTitle(string $companyName): string
    {
        // Excel worksheet titles are limited to 31 characters.
        return mb_substr($companyName, 0, 31);
    }
}
