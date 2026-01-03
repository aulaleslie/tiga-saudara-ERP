<?php

namespace Modules\Sale\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Services\SalesImportService;

class ProcessSalesImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 7200; // 2 hours for large batches

    public function __construct(public int $batchId) {}

    public function handle(SalesImportService $importService): void
    {
        Log::info('[SalesImportBatch] Job started', ['batch_id' => $this->batchId]);

        $batch = SalesImportBatch::findOrFail($this->batchId);

        try {
            $importService->processBatch($batch);

            Log::info('[SalesImportBatch] Job completed', [
                'batch_id' => $this->batchId,
                'success_count' => $batch->success_count,
                'error_count' => $batch->error_count,
            ]);
        } catch (\Exception $e) {
            Log::error('[SalesImportBatch] Job failed', [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
            ]);

            $batch->update(['status' => SalesImportBatch::STATUS_FAILED]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[SalesImportBatch] Job failed permanently', [
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
        ]);

        $batch = SalesImportBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => SalesImportBatch::STATUS_FAILED]);
        }
    }
}
