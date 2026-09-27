<?php

namespace Modules\Expense\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Statement;
use Modules\Expense\Entities\ExpenseImportBatch;
use Modules\Expense\Entities\ExpenseImportRow;

class StageExpenseImportRows implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    protected const CHUNK_SIZE = 500;

    public function __construct(
        public int $batchId,
        public array $normalizedHeaders,
        public array $rawHeaders,
        public string $delimiter = ','
    ) {}

    public function handle(): void
    {
        Log::info('[ExpenseImport] StageExpenseImportRows job started', ['batch_id' => $this->batchId]);

        $batch = ExpenseImportBatch::findOrFail($this->batchId);
        $fullPath = Storage::path($batch->source_csv_path);

        try {
            $batch->update(['status' => 'staging']);

            $csv = Reader::createFromPath($fullPath);
            $csv->setDelimiter($this->delimiter);
            $csv->setHeaderOffset(0);

            $records = (new Statement())->process($csv);
            $rowNo = 0;
            $chunk = [];

            foreach ($records as $record) {
                $mapped = $this->mapCsvRow((array) $record);

                // Skip completely empty rows
                if (empty($mapped['nomor']) && empty($mapped['tanggal'])) {
                    continue;
                }

                $chunk[] = [
                    'batch_id' => $this->batchId,
                    'row_number' => ++$rowNo,
                    'raw_json' => json_encode($mapped),
                    'status' => ExpenseImportRow::STATUS_PENDING,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($chunk) >= self::CHUNK_SIZE) {
                    ExpenseImportRow::insert($chunk);
                    $chunk = [];
                }
            }

            if (!empty($chunk)) {
                ExpenseImportRow::insert($chunk);
            }

            $batch->update([
                'total_rows' => $rowNo,
                'status' => 'validating',
            ]);

            Log::info('[ExpenseImport] Rows staged successfully', [
                'batch_id' => $this->batchId,
                'total_rows' => $rowNo,
            ]);

            // Dispatch the processing job
            ProcessExpenseImportBatch::dispatch($this->batchId);

        } catch (\Exception $e) {
            Log::error('[ExpenseImport] StageExpenseImportRows job failed', [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
            ]);

            $batch->update(['status' => ExpenseImportBatch::STATUS_FAILED]);
            throw $e;
        }
    }

    protected function mapCsvRow(array $record): array
    {
        $get = function (string $canonical) use ($record) {
            if (!isset($this->normalizedHeaders[$canonical])) {
                return null;
            }
            $actual = $this->normalizedHeaders[$canonical];
            return array_key_exists($actual, $record) ? trim((string) $record[$actual]) : null;
        };

        return [
            'tanggal' => $get('tanggal'),
            'transaksi' => $get('transaksi'),
            'nomor' => $get('nomor'),
            'kategori' => $get('kategori'),
            'deskripsi' => $get('deskripsi'),
            'supplier' => $get('supplier'),
            'jumlah' => $get('jumlah'),
            'tax' => $get('tax') ?: '0',
            'status' => $get('status'),
            'sisa_tagihan' => $get('sisa_tagihan') ?: '0',
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[ExpenseImport] StageExpenseImportRows job failed permanently', [
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
        ]);

        $batch = ExpenseImportBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => ExpenseImportBatch::STATUS_FAILED]);
        }
    }
}
