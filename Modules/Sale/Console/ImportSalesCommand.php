<?php

namespace Modules\Sale\Console;

use Illuminate\Console\Command;
use Modules\Sale\Services\SalesImportService;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Jobs\StageSalesImportRows;
use League\Csv\Reader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ImportSalesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sales:import-local {file : Path to the CSV file} {--user=1 : User ID to attribute the import to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import sales from a local CSV file, bypassing upload limits.';

    /**
     * Execute the console command.
     */
    public function handle(SalesImportService $importService)
    {
        $filePath = $this->argument('file');
        $userId = $this->option('user');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $this->info("Processing file: {$filePath}");

        // Copy file to storage to mimic upload
        $fileName = 'local_import_' . time() . '_' . basename($filePath);
        $relativePath = 'imports/sales/' . $fileName;
        
        Storage::makeDirectory('imports/sales');
        $storagePath = Storage::path($relativePath);
        
        if (!copy($filePath, $storagePath)) { // Manual copy to storage path
             $this->error("Failed to copy file to storage: {$storagePath}");
             return 1;
        }

        $this->info("File copied to storage: {$relativePath}");

        // Create CSV Reader for validation
        $csv = Reader::createFromPath($storagePath);
        
        // Auto-detect delimiter
        $sample = file_get_contents($storagePath, false, null, 0, 4096);
        $delimiter = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';
        $csv->setDelimiter($delimiter);
        $csv->setHeaderOffset(0);
        
        $rawHeaders = $csv->getHeader();
        $normalizedHeaders = $importService->normalizeHeaders($rawHeaders);

        // Validation
        $required = ['tanggal', 'customer', 'no_faktur', 'produk', 'kuantitas', 'satuan', 'harga_satuan'];
        $missing = array_diff($required, array_keys($normalizedHeaders));

        if (!empty($missing)) {
             $this->error('Missing required columns: ' . implode(', ', $missing));
             Storage::delete($relativePath); // Clean up
             return 1;
        }

        // Create Batch
        $batch = SalesImportBatch::create([
            'user_id' => $userId,
            'source_csv_path' => $relativePath,
            'file_sha256' => hash_file('sha256', $storagePath),
            'status' => 'queued',
        ]);
        
        $this->info("Batch created with ID: {$batch->id}");

        // Dispatch Job
        StageSalesImportRows::dispatch(
            $batch->id,
            $normalizedHeaders,
            $rawHeaders,
            $delimiter
        );

        $this->info("Import job dispatched successfully.");
        return 0;
    }
}
