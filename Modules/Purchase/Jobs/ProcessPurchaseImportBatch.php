<?php

namespace Modules\Purchase\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Services\PurchaseImportService;

class ProcessPurchaseImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 7200; // 2 hours for large batches

    public function __construct(public int $batchId) {}

    public function handle(PurchaseImportService $importService): void
    {
        Log::info('[PurchaseImportBatch] Job started', ['batch_id' => $this->batchId]);

        $batch = PurchaseImportBatch::findOrFail($this->batchId);

        try {
            $importService->processBatch($batch);

            Log::info('[PurchaseImportBatch] Job completed', [
                'batch_id' => $this->batchId,
                'success_count' => $batch->success_count,
                'error_count' => $batch->error_count,
            ]);
        } catch (\Exception $e) {
            Log::error('[PurchaseImportBatch] Job failed', [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
            ]);

            $batch->update(['status' => PurchaseImportBatch::STATUS_FAILED]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[PurchaseImportBatch] Job failed permanently', [
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
        ]);

        $batch = PurchaseImportBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => PurchaseImportBatch::STATUS_FAILED]);
        }
    }
}
