<?php

namespace Modules\Purchase\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Statement;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;

/**
 * Job to stage CSV rows into the database in chunks.
 * This moves the heavy row insertion out of the HTTP request.
 */
class StagePurchaseImportRows implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 minutes for large files

    protected const CHUNK_SIZE = 500;

    public function __construct(
        public int $batchId,
        public array $normalizedHeaders,
        public array $rawHeaders,
        public string $delimiter = ','
    ) {}

    public function handle(): void
    {
        Log::info('[PurchaseImport] StagePurchaseImportRows job started', ['batch_id' => $this->batchId]);

        $batch = PurchaseImportBatch::findOrFail($this->batchId);
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

                // Skip empty rows
                if (empty($mapped['produk']) || empty($mapped['tanggal'])) {
                    continue;
                }

                $chunk[] = [
                    'batch_id' => $this->batchId,
                    'row_number' => ++$rowNo,
                    'raw_json' => json_encode($mapped),
                    'status' => PurchaseImportRow::STATUS_PENDING,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Bulk insert when chunk is full
                if (count($chunk) >= self::CHUNK_SIZE) {
                    PurchaseImportRow::insert($chunk);
                    $chunk = [];

                    // Log progress every 5000 rows
                    if ($rowNo % 5000 === 0) {
                        Log::info('[PurchaseImport] Staging progress', [
                            'batch_id' => $this->batchId,
                            'rows_staged' => $rowNo,
                        ]);
                    }
                }
            }

            // Insert remaining rows
            if (!empty($chunk)) {
                PurchaseImportRow::insert($chunk);
            }

            $batch->update([
                'total_rows' => $rowNo,
                'status' => 'validating',
            ]);

            Log::info('[PurchaseImport] Rows staged successfully', [
                'batch_id' => $this->batchId,
                'total_rows' => $rowNo,
            ]);

            // Dispatch the processing job
            ProcessPurchaseImportBatch::dispatch($this->batchId);

        } catch (\Exception $e) {
            Log::error('[PurchaseImport] StagePurchaseImportRows job failed', [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
            ]);

            $batch->update(['status' => PurchaseImportBatch::STATUS_FAILED]);
            throw $e;
        }
    }

    /**
     * Map CSV row to normalized structure.
     */
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
            'supplier' => $get('supplier'),
            'no_faktur' => $get('no_faktur'),
            'produk' => $get('produk'),
            'kuantitas' => $get('kuantitas'),
            'satuan' => $get('satuan'),
            'harga_satuan' => $get('harga_satuan'),
            'pajak' => $get('pajak') ?: '0',
            // New fields
            'tag' => $get('tag'),
            'tarif_pajak' => $get('tarif_pajak'),
            'deskripsi' => $get('deskripsi'),
            'memo' => $get('memo'),
            'tanggal_jatuh_tempo' => $get('tanggal_jatuh_tempo'),
            'sisa_tagihan_hari_ini' => $get('sisa_tagihan_hari_ini'),
            'sisa_tagihan' => $get('sisa_tagihan'),
            'pembayaran' => $get('pembayaran'),
            'source_total' => $get('source_total'),
            'biaya_pengiriman' => $get('biaya_pengiriman'),
            'nama_perusahaan' => $get('nama_perusahaan'),
            'nomor_pajak' => $get('nomor_pajak'),
            'nomor_telepon' => $get('nomor_telepon'),
            'diskon' => $get('diskon'),
            'diskon_document_persen' => $get('diskon_document_persen') ?: '0',
            'diskon_persen' => $get('diskon_persen') ?: '0',
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[PurchaseImport] StagePurchaseImportRows job failed permanently', [
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
        ]);

        $batch = PurchaseImportBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => PurchaseImportBatch::STATUS_FAILED]);
        }
    }
}
