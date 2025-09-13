<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Reader;
use League\Csv\Writer;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;

class FinalizeProductImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, Batchable;

    public function __construct(public $batchId) {}

    public function handle(): void
    {
        /** @var ProductImportBatch $batch */
        $batch = ProductImportBatch::findOrFail($this->batchId);

        // --- open source CSV for reading (via stream) ---
        $readStream = Storage::readStream($batch->source_csv_path);
        if ($readStream === false) {
            throw new \RuntimeException("Cannot open source CSV: {$batch->source_csv_path}");
        }

        $reader = Reader::createFromStream($readStream);
        $reader->setHeaderOffset(0);
        $headers = $reader->getHeader();

        // Append result columns
        $headers[] = 'Status';
        $headers[] = 'Error';
        $headers[] = 'ProductID';

        // --- prepare writer into a temp stream, then upload via writeStream ---
        $resultRelPath = "imports/products/{$batch->id}/result.csv";
        Storage::makeDirectory(dirname($resultRelPath));

        $out = fopen('php://temp', 'w+');                 // resource
        $writer = Writer::createFromStream($out);         // League\Csv expects a resource
        $writer->insertOne($headers);

        // Map per-row metadata by line number (header = line 1, first data row = line 2)
        $metaMap = ProductImportRow::where('batch_id', $batch->id)
            ->get(['row_number', 'status', 'error_message', 'product_id'])
            ->keyBy('row_number');

        $line = 1;
        foreach ($reader->getRecords() as $record) {
            $line++;
            $meta   = $metaMap->get($line);
            $status = $meta?->status ?? 'skipped';
            $error  = $meta?->error_message ?? '';
            $pid    = $meta?->product_id ?? '';

            // Keep original column order, then append our annotations
            $record['Status']   = strtoupper($status);
            $record['Error']    = $error;
            $record['ProductID']= $pid;

            $writer->insertOne($record);
        }

        // Flush to storage
        rewind($out);
        $ok = Storage::writeStream($resultRelPath, $out);  // <-- correct signature
        fclose($out);

        if ($ok === false) {
            throw new \RuntimeException("Failed to write result CSV to {$resultRelPath}");
        }

        // --- finalize batch status & undo window (1 hour) ---
        $finalStatus = ($batch->success_rows === 0 && $batch->error_rows > 0)
            ? 'failed'
            : 'completed';

        // block undo if there’s a newer batch in any active/completed state
        $hasNewer = ProductImportBatch::where('id', '<>', $batch->id)
            ->where('created_at', '>', $batch->created_at)
            ->whereIn('status', ['queued', 'validating', 'processing', 'completed'])
            ->whereNull('undone_at')
            ->exists();

        $batch->update([
            'status'               => $finalStatus,
            'result_csv_path'      => $resultRelPath,
            'completed_at'         => now(),
            'undo_token'           => $hasNewer ? null : Str::uuid()->toString(),
            'undo_available_until' => $hasNewer ? null : now()->addHour(), // 1-hour window
        ]);
    }
}
