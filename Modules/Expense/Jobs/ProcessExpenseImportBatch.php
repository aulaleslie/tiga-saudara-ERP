<?php

namespace Modules\Expense\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Expense\Entities\ExpenseImportBatch;
use Modules\Expense\Services\ExpenseImportService;

class ProcessExpenseImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 7200; // 2 hours for large batches

    public function __construct(public int $batchId) {}

    public function handle(ExpenseImportService $importService): void
    {
        Log::info('[ExpenseImportBatch] Job started', ['batch_id' => $this->batchId]);

        $batch = ExpenseImportBatch::findOrFail($this->batchId);

        try {
            $importService->processBatch($batch);

            Log::info('[ExpenseImportBatch] Job completed', [
                'batch_id' => $this->batchId,
                'success_count' => $batch->success_count,
                'error_count' => $batch->error_count,
            ]);
        } catch (\Exception $e) {
            Log::error('[ExpenseImportBatch] Job failed', [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
            ]);

            $batch->update(['status' => ExpenseImportBatch::STATUS_FAILED]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[ExpenseImportBatch] Job failed permanently', [
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
        ]);

        $batch = ExpenseImportBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => ExpenseImportBatch::STATUS_FAILED]);
        }
    }
}
