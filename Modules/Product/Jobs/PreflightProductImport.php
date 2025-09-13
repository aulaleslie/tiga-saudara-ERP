<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Csv\Exception;
use League\Csv\Reader;
use League\Csv\SyntaxError;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Setting\Entities\Location;
use Throwable;

class PreflightProductImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, Batchable;

    public function __construct(
        public     $batchId,
        public int $uploaderId
    ) {}

    /**
     * @throws Throwable
     * @throws SyntaxError
     * @throws Exception
     */
    public function handle(): void
    {
        /** @var ProductImportBatch $batch */
        $batch = ProductImportBatch::lockForUpdate()->findOrFail($this->batchId);
        $batch->update(['status' => 'validating', 'validated_rows' => 0]);

        $path = $batch->original_csv_path;
        $location = Location::with('setting')->findOrFail($batch->location_id);
        $settingIdForCreations = $location->setting_id;

        // Read CSV
        $stream = Storage::readStream($path);
        $csv = Reader::createFromStream($stream);
        $csv->setHeaderOffset(0);
        $headers = array_map('trim', $csv->getHeader());

        // Header map (Bahasa header -> internal key)
        $map = [
            'Nama Produk*'            => 'product_name',
            'Kode Produk'             => 'product_code',
            'Kategori'                => 'category_name',
            'Merek'                   => 'brand_name',
            'Unit Dasar*'             => 'base_unit_name',
            'Kelola Stok'             => 'stock_managed',
            'Peringatan Stok'         => 'minimum_stock',
            'Dijual?'                 => 'is_sold',
            'Dibeli?'                 => 'is_purchased',
            'Harga Jual'              => 'sale_price',
            'Harga Tier 1'            => 'tier_1_price',
            'Harga Tier 2'            => 'tier_2_price',
            'Pajak Jual'              => 'sale_tax_name',
            'Harga Beli'              => 'purchase_price',
            'Pajak Beli'              => 'purchase_tax_name',
            'Stok'                    => 'stock',
            'Barcode'                 => 'barcode',
            // Conversions (up to 5)
            'Konversi 1 - Unit'      => 'conv_1_unit',
            'Konversi 1 - Faktor'    => 'conv_1_factor',
            'Konversi 1 - Harga'     => 'conv_1_price',
            'Konversi 1 - Barcode'   => 'conv_1_barcode',
            'Konversi 2 - Unit'      => 'conv_2_unit',
            'Konversi 2 - Faktor'    => 'conv_2_factor',
            'Konversi 2 - Harga'     => 'conv_2_price',
            'Konversi 2 - Barcode'   => 'conv_2_barcode',
            'Konversi 3 - Unit'      => 'conv_3_unit',
            'Konversi 3 - Faktor'    => 'conv_3_factor',
            'Konversi 3 - Harga'     => 'conv_3_price',
            'Konversi 3 - Barcode'   => 'conv_3_barcode',
            'Konversi 4 - Unit'      => 'conv_4_unit',
            'Konversi 4 - Faktor'    => 'conv_4_factor',
            'Konversi 4 - Harga'     => 'conv_4_price',
            'Konversi 4 - Barcode'   => 'conv_4_barcode',
            'Konversi 5 - Unit'      => 'conv_5_unit',
            'Konversi 5 - Faktor'    => 'conv_5_factor',
            'Konversi 5 - Harga'     => 'conv_5_price',
            'Konversi 5 - Barcode'   => 'conv_5_barcode',
        ];

        // quick header validation (required visible headers)
        foreach (['Nama Produk*','Unit Dasar*'] as $req) {
            if (!in_array($req, $headers, true)) {
                $batch->update(['status' => 'failed', 'error_message' => "Missing header: {$req}"]);
                return;
            }
        }

        $rows = iterator_to_array($csv->getRecords(), false);
        $batch->update(['total_rows' => count($rows)]);

        // Track in-file duplicates
        $seenNames = [];
        $seenCodes = [];

        // Preload DB duplicates (by name/code)
        $existingNames = DB::table('products')->pluck('product_name')->map(fn($v)=>mb_strtolower(trim($v)))->all();
        $existingCodes = DB::table('products')->pluck('product_code')->filter()->map(fn($v)=>mb_strtolower(trim($v)))->all();
        $existingNames = array_flip(array_unique($existingNames));
        $existingCodes = array_flip(array_unique($existingCodes));

        $validRowIds = [];
        $lineNo = 1; // 1-based excluding header

        DB::transaction(function () use ($rows, $map, $batch, &$validRowIds, &$seenNames, &$seenCodes, $existingNames, $existingCodes, &$lineNo) {
            foreach ($rows as $record) {
                $lineNo++;
                $data = [];
                foreach ($map as $label => $key) {
                    $data[$key] = Arr::get($record, $label);
                }

                $name = mb_strtolower(trim((string)($data['product_name'] ?? '')));
                $code = mb_strtolower(trim((string)($data['product_code'] ?? '')));

                $errors = [];

                if ($name === '') {
                    $errors[] = 'Nama produk wajib.';
                }

                // In-file duplicate checks
                if ($name && isset($seenNames[$name])) {
                    $errors[] = 'Duplikat nama (di dalam file).';
                }
                if ($code && isset($seenCodes[$code])) {
                    $errors[] = 'Duplikat kode (di dalam file).';
                }

                // DB duplicate checks
                if ($name && isset($existingNames[$name])) {
                    $errors[] = 'Nama sudah ada di database.';
                }
                if ($code && isset($existingCodes[$code])) {
                    $errors[] = 'Kode sudah ada di database.';
                }

                // Persist raw row
                $row = ProductImportRow::create([
                    'batch_id' => $batch->id,
                    'line_no'  => $lineNo,
                    'raw_json' => $data,
                    'status'   => empty($errors) ? 'pending' : 'error',
                    'error_message' => empty($errors) ? null : implode(' | ', $errors),
                ]);

                if (empty($errors)) {
                    $validRowIds[] = $row->id;
                    $seenNames[$name] = true;
                    if ($code) $seenCodes[$code] = true;
                }
            }
        });

        // Build per-chunk processing jobs
        $chunkSize = 500;
        $jobs = [];
        foreach (array_chunk($validRowIds, $chunkSize) as $chunkIds) {
            $jobs[] = new ProcessProductImportChunk(
                batchId: $batch->id,
                rowIds: $chunkIds
            );
        }

        // Fire a Bus::batch of per-chunk jobs; finalize after
        if (count($jobs)) {
            /** @var PendingBatch $pending */
            $pending = Bus::batch($jobs)
                ->name("product-import-{$batch->id}")
                ->onQueue('imports')
                ->allowFailures()
                ->then(fn (Batch $b) => FinalizeProductImport::dispatch($batch->id))
                ->catch(function (Batch $b, Throwable $e) use ($batch) {
                    ProductImportBatch::whereKey($batch->id)->update([
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ]);
                })
                ->finally(function (Batch $b) {
                    // no-op; Finalize job will set end state
                });

            $pending->dispatch();
            $batch->update(['status' => 'processing', 'validated_rows' => count($validRowIds)]);
        } else {
            // Nothing to import; finalize immediately (only errors)
            FinalizeProductImport::dispatch($batch->id);
        }
    }
}
