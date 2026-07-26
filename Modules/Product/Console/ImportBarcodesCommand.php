<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\Services\BarcodeIdentityService;
use Modules\Product\Utils\BarcodeUtils;

class ImportBarcodesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'product:import-barcodes {path : The path to the CSV file} {--dry-run : Perform a dry run without modifying the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import barcoded products from a CSV file.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(BarcodeIdentityService $barcodeIdentityService)
    {
        $path = $this->argument('path');
        $dryRun = $this->option('dry-run');

        if (!file_exists($path) || !is_readable($path)) {
            $this->error("File does not exist or is not readable: {$path}");
            return 1;
        }

        $file = fopen($path, 'r');
        if ($file === false) {
            $this->error("Failed to open {$path} for reading.");
            return 1;
        }

        if ($dryRun) {
            $this->info("Running in DRY-RUN mode. No writes will be performed.");
        }

        $applied = 0;
        $notFound = [];
        $ambiguous = [];
        $hasBarcode = [];
        $barcodeTaken = [];

        $isFirstRow = true;
        while (($row = fgetcsv($file)) !== false) {
            // Skip structurally blank rows
            if (empty($row) || (count($row) === 1 && empty(trim($row[0])))) {
                continue;
            }

            // Tolerate header row
            if ($isFirstRow) {
                $isFirstRow = false;
                if ($row[0] === 'product_name' || $row[1] === 'barcode') {
                    continue;
                }
            }

            if (count($row) < 2) {
                continue; // Malformed row
            }

            $productName = $row[0];
            $fileBarcode = $row[1];

            $products = DB::table('products')
                ->where('product_name', $productName)
                ->get();

            if ($products->count() === 0) {
                $notFound[] = $productName;
                continue;
            }

            if ($products->count() > 1) {
                $ambiguous[] = $productName;
                continue;
            }

            $product = $products->first();

            if (!empty($product->barcode)) {
                $hasBarcode[] = $productName;
                continue;
            }

            // Applicable row
            if ($dryRun) {
                $applied++;
                continue;
            }

            DB::beginTransaction();
            try {
                DB::table('products')->where('id', $product->id)->update(['barcode' => $fileBarcode]);
                $reserveResult = $barcodeIdentityService->reserve($fileBarcode, $product->id, null);
                
                if (!$reserveResult['success']) {
                    if (($reserveResult['error'] ?? '') === 'duplicate') {
                        DB::rollBack();
                        $barcodeTaken[] = $productName;
                        continue;
                    }
                    throw new \Exception('Failed to reserve barcode: ' . ($reserveResult['error'] ?? 'unknown'));
                }
                
                DB::commit();
                $applied++;
            } catch (\Illuminate\Database\QueryException $e) {
                DB::rollBack();
                if ($e->getCode() == '23000') {
                    $barcodeTaken[] = $productName;
                } else {
                    throw $e;
                }
            }
        }
        fclose($file);

        // Summary block
        $this->info("=== Import Summary ===");
        $this->info("Applied: {$applied}");
        $this->info("Not Found: " . count($notFound));
        $this->info("Ambiguous: " . count($ambiguous));
        $this->info("Has Barcode: " . count($hasBarcode));
        $this->info("Barcode Taken: " . count($barcodeTaken));
        
        $this->printList('Not Found', $notFound);
        $this->printList('Ambiguous', $ambiguous);
        $this->printList('Has Barcode', $hasBarcode);
        $this->printList('Barcode Taken', $barcodeTaken);

        return 0;
    }

    private function printList(string $category, array $items)
    {
        if (count($items) > 0) {
            $this->line("");
            $this->warn("--- Skipped: {$category} ---");
            foreach ($items as $item) {
                $this->line("- {$item}");
            }
        }
    }
}
