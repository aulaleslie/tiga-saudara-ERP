<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Modules\Product\Services\TigaNusaPriceExportService;

class ExportTigaNusaPricesCommand extends Command
{
    protected $signature = 'product:export-tiga-nusa-prices {--path= : The path to save the exported XLSX file} {--force : Overwrite the file if it already exists}';

    protected $description = 'Export product prices for CV TIGA NUSA COMPUTER to an Excel file.';

    public function handle(): int
    {
        try {
            $service = new TigaNusaPriceExportService();
            $setting = $service->resolveTargetSetting();

            $path = $this->option('path') ?: storage_path('app/product_prices_tiga_nusa_export.xlsx');
            $force = $this->option('force');

            if (file_exists($path) && !$force) {
                if (!$this->confirm("File {$path} already exists. Overwrite?", false)) {
                    $this->info('Export cancelled.');
                    return 1;
                }
            }

            $this->info("Exporting product prices to {$path}...");

            $query = $service->buildQuery();
            $products = $query->get();
            $count = $products->count();

            // Build spreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Set title
            $sheet->setCellValue('A1', $setting->company_name);
            $sheet->mergeCells('A1:D1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

            // Set subtitle
            $sheet->setCellValue('A2', 'Daftar Harga Produk');
            $sheet->mergeCells('A2:D2');
            $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

            // Set timestamp
            $sheet->setCellValue('A3', 'Tanggal: ' . now()->format('d/m/Y H:i'));
            $sheet->mergeCells('A3:D3');
            $sheet->getStyle('A3')->getFont()->setItalic(true);

            // Set headers
            $headers = ['Nama Produk', 'Harga Jual', 'Harga Tier 1', 'Harga Tier 2'];
            foreach ($headers as $col => $header) {
                $sheet->setCellValueByColumnAndRow($col + 1, 4, $header);
            }

            // Style header row
            $sheet->getStyle('4:4')->getFont()->setBold(true);
            $sheet->getStyle('4:4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
            $sheet->getStyle('4:4')->getFill()->getStartColor()->setARGB('FFF2F2F2');

            // Add data rows
            $row = 5;
            foreach ($products as $product) {
                $sheet->setCellValueByColumnAndRow(1, $row, $product->product_name);
                if ($product->sale_price !== null) {
                    $sheet->setCellValueByColumnAndRow(2, $row, (float) $product->sale_price);
                    $sheet->getStyleByColumnAndRow(2, $row)->getNumberFormat()->setFormatCode('#,##0.00');
                }
                if ($product->tier_1_price !== null) {
                    $sheet->setCellValueByColumnAndRow(3, $row, (float) $product->tier_1_price);
                    $sheet->getStyleByColumnAndRow(3, $row)->getNumberFormat()->setFormatCode('#,##0.00');
                }
                if ($product->tier_2_price !== null) {
                    $sheet->setCellValueByColumnAndRow(4, $row, (float) $product->tier_2_price);
                    $sheet->getStyleByColumnAndRow(4, $row)->getNumberFormat()->setFormatCode('#,##0.00');
                }
                $row++;
            }

            // Freeze header row
            $sheet->freezePane('A5');

            // Add autofilter
            $sheet->setAutoFilter('A4:D' . ($row - 1));

            // Set column widths
            $sheet->getColumnDimension('A')->setWidth(30);
            $sheet->getColumnDimension('B')->setWidth(15);
            $sheet->getColumnDimension('C')->setWidth(15);
            $sheet->getColumnDimension('D')->setWidth(15);

            // Write to file
            $writer = new Xlsx($spreadsheet);
            $writer->save($path);

            if ($count === 0) {
                $this->info("0 products exported. No products found in the catalog.");
            } else {
                $this->info("{$count} products exported successfully to {$path}");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
