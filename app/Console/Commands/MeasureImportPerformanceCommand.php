<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Services\SalesImportService;

class MeasureImportPerformanceCommand extends Command
{
    protected $signature = 'import:measure-perf {type} {batch_id}';
    protected $description = 'Measure import processing performance for a staged batch.';

    public function handle(PurchaseImportService $purchaseService, SalesImportService $salesService)
    {
        $type = $this->argument('type');
        $batchId = $this->argument('batch_id');

        if (!in_array($type, ['purchase', 'sales'])) {
            $this->error('Type must be "purchase" or "sales"');
            return 1;
        }

        $this->info("Measuring {$type} import processing performance for batch {$batchId}...");

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $startDocs = 0;
        $startDetails = 0;

        if ($type === 'purchase') {
            $batch = PurchaseImportBatch::findOrFail($batchId);
            $startDocs = Purchase::count();
            $startDetails = PurchaseDetail::count();
            
            $purchaseService->processBatch($batch);
            $batch->refresh();
            
            $endDocs = Purchase::count();
            $endDetails = PurchaseDetail::count();
        } else {
            $batch = SalesImportBatch::findOrFail($batchId);
            $startDocs = Sale::count();
            $startDetails = SaleDetails::count();
            
            $salesService->processBatch($batch);
            $batch->refresh();
            
            $endDocs = Sale::count();
            $endDetails = SaleDetails::count();
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $elapsed = $endTime - $startTime;
        $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024;
        
        $docsCreated = $endDocs - $startDocs;
        $detailsCreated = $endDetails - $startDetails;
        
        $rowsProcessed = $batch->processed_rows;
        $successCount = $batch->success_count;
        $errorCount = $batch->error_count;
        $skippedCount = $rowsProcessed - $successCount - $errorCount; // Approximation
        
        $rowsPerSec = $elapsed > 0 ? $rowsProcessed / $elapsed : 0;
        $docsPerSec = $elapsed > 0 ? $docsCreated / $elapsed : 0;

        $this->table(
            ['Metric', 'Value'],
            [
                ['Elapsed Time (s)', number_format($elapsed, 2)],
                ['Memory Used (MB)', number_format($memoryUsed, 2)],
                ['Rows Processed', $rowsProcessed],
                ['Success Count', $successCount],
                ['Error Count', $errorCount],
                ['Skipped Count (approx)', $skippedCount],
                ['Documents Created', $docsCreated],
                ['Details Created', $detailsCreated],
                ['Rows / Sec', number_format($rowsPerSec, 2)],
                ['Docs / Sec', number_format($docsPerSec, 2)],
            ]
        );

        return 0;
    }
}
