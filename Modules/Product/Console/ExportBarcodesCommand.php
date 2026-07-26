<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportBarcodesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'product:export-barcodes {--path= : The path to save the exported CSV file} {--force : Overwrite the file if it already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export barcoded products to a CSV file.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $path = $this->option('path') ?: storage_path('app/product_barcodes_export.csv');
        $force = $this->option('force');

        if (file_exists($path) && !$force) {
            if (!$this->confirm("File {$path} already exists. Overwrite?", false)) {
                $this->info('Export cancelled.');
                return 1;
            }
        }

        $this->info("Exporting barcodes to {$path}...");

        $file = fopen($path, 'w');
        if ($file === false) {
            $this->error("Failed to open {$path} for writing.");
            return 1;
        }

        fputcsv($file, ['product_name', 'barcode']);

        $count = 0;

        DB::table('products')
            ->select('product_name', 'barcode')
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->orderBy('id')
            ->chunk(1000, function ($products) use ($file, &$count) {
                foreach ($products as $product) {
                    fputcsv($file, [$product->product_name, $product->barcode]);
                    $count++;
                }
            });

        fclose($file);

        if ($count === 0) {
            $this->info("0 products exported. No products hold a barcode.");
        } else {
            $this->info("{$count} products exported successfully.");
        }

        return 0;
    }
}
