<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Log;
use Throwable;

use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;

class ProcessProductImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    private ProductImportBatch $batch;
    private int $defaultSettingId = 0;
    /** @var int[] */
    private array $settingIds = [];
    /** @var array<string,bool> */
    private array $existingNameKeys = [];
    /** @var array<string,bool> */
    private array $existingCodeKeys = [];
    /** @var array<string,bool> */
    private array $seenNameKeys = [];
    /** @var array<string,bool> */
    private array $seenCodeKeys = [];
    private int $nextSkuNumber = 1;

    public function __construct(public int $batchId) {}

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        Log::info('[ProductImportBatch] Job started', ['batch_id' => $this->batchId]);

        $this->batch = ProductImportBatch::with('location')->findOrFail($this->batchId);
        $this->batch->update(['status' => 'processing']);

        // --- Stage rows from CSV if not already staged ---
        $existingRowCount = ProductImportRow::where('batch_id', $this->batch->id)->count();
        if ($existingRowCount === 0) {
            Log::info('[ProductImportBatch] Staging rows from CSV', ['batch_id' => $this->batchId]);
            $stagedCount = $this->stageRowsFromCsv();
            if ($stagedCount === null) {
                // stageRowsFromCsv already marked batch as failed
                return;
            }
            Log::info('[ProductImportBatch] Rows staged', ['batch_id' => $this->batchId, 'total_rows' => $stagedCount]);
        }

        $this->settingIds = Setting::query()->pluck('id')->map(fn($id) => (int) $id)->all();
        $this->defaultSettingId = $this->resolveDefaultSettingId($this->settingIds);

        if ($this->defaultSettingId === 0) {
            Log::error('Product import failed: no setting found.', ['batch_id' => $this->batchId]);
            $this->batch->update(['status' => 'failed']);
            return;
        }

        if (empty($this->settingIds)) {
            $this->settingIds = [$this->defaultSettingId];
        }

        $this->existingNameKeys = $this->loadExistingNameKeys();
        $this->existingCodeKeys = $this->loadExistingCodeKeys();
        $this->initSkuCounter();

        ProductImportRow::where('batch_id', $this->batch->id)
            ->where(function ($q) {
                $q->whereNull('status');
            })
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $this->processRow($row);
                }
            }, 'id');

        $this->batch->update([
            'status' => 'completed',
            'completed_at' => now(),
            'undo_available_until' => now()->addHour(),
        ]);

        Log::info('[ProductImportBatch] Job completed', [
            'batch_id' => $this->batchId,
            'processed_rows' => $this->batch->processed_rows,
            'success_rows' => $this->batch->success_rows,
            'error_rows' => $this->batch->error_rows,
        ]);
    }

    /**
     * Stage rows from CSV file into ProductImportRow table.
     * Returns the number of rows staged, or null on failure.
     */
    private function stageRowsFromCsv(): ?int
    {
        $path = $this->batch->source_csv_path;
        $fullPath = storage_path('app/' . $path);

        if (!file_exists($fullPath)) {
            Log::error('[ProductImportBatch] CSV file not found', ['batch_id' => $this->batchId, 'path' => $fullPath]);
            $this->batch->update(['status' => 'failed', 'error_message' => 'CSV file not found']);
            return null;
        }

        // Read & normalize headers (BOM/whitespace/case) + auto-detect delimiter
        $csv = \League\Csv\Reader::createFromPath($fullPath);

        $sample = @file_get_contents($fullPath, false, null, 0, 4096) ?: '';
        $delimiter = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';
        $csv->setDelimiter($delimiter);

        $csv->setHeaderOffset(0);
        $rawHeaders = $csv->getHeader();

        $normalize = function (string $h): string {
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
            $h = trim(preg_replace('/\s+/', ' ', $h));
            return mb_strtolower($h);
        };

        $normHeaders = array_map($normalize, $rawHeaders);

        // Aliases: left = normalized incoming header, right = our canonical key
        $aliases = [
            'nama produk'        => 'Nama Produk',
            'product name'       => 'Nama Produk',
            'kode produk'        => 'Kode Produk',
            'product code'       => 'Kode Produk',
            'sku'                => 'Kode Produk',
            'stok di tangan'     => 'Stok di tangan',
            'stok'               => 'Stok di tangan',
            'batas minimum'      => 'Batas Minimum',
            'stok minimum'       => 'Batas Minimum',
            'satuan'             => 'Satuan',
            'unit'               => 'Satuan',
            'product unit'       => 'Satuan',
            'harga rata-rata'    => 'Harga Rata-rata',
            'harga rata rata'    => 'Harga Rata-rata',
            'average price'      => 'Harga Rata-rata',
            'nilai'              => 'Nilai',
            'unassigned'         => 'Unassigned',
            'total quantity'     => 'Total Quantity',
        ];

        // Build canonical => actual header map
        $headerMap = [];
        foreach ($normHeaders as $i => $norm) {
            if (isset($aliases[$norm])) {
                $headerMap[$aliases[$norm]] = $rawHeaders[$i];
            }
        }

        // Required columns validation
        if ($this->batch->import_type === 'stock_snapshot') {
            $required = ['Kode Produk', 'Nama Produk', 'Satuan', 'Unassigned', 'Total Quantity'];
        } else {
            $required = ['Nama Produk', 'Satuan', 'Harga Rata-rata'];
        }
        $missing = array_values(array_diff($required, array_keys($headerMap)));
        if (!empty($missing)) {
            $msg = 'CSV header mismatch. Missing columns: ' . implode(', ', $missing);
            Log::error('[ProductImportBatch] Header validation failed', ['batch_id' => $this->batchId, 'error' => $msg]);
            $this->batch->update(['status' => 'failed', 'error_message' => $msg]);
            return null;
        }

        // Stage rows using batch insert for better performance
        $records = (new \League\Csv\Statement())->process($csv);
        $rowNo = 0;
        $insertBuffer = [];
        $batchSize = 500;

        foreach ($records as $record) {
            $rowNo++;
            $insertBuffer[] = [
                'batch_id'   => $this->batch->id,
                'row_number' => $rowNo,
                'raw_json'   => json_encode($this->mapCsvRowToPayload((array) $record, $headerMap)),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($insertBuffer) >= $batchSize) {
                DB::table('product_import_rows')->insert($insertBuffer);
                $insertBuffer = [];
            }
        }

        // Insert remaining rows
        if (!empty($insertBuffer)) {
            DB::table('product_import_rows')->insert($insertBuffer);
        }

        $this->batch->update(['total_rows' => $rowNo, 'status' => 'processing']);

        return $rowNo;
    }

    /**
     * Map one CSV row into normalized payload using the header map.
     */
    private function mapCsvRowToPayload(array $record, array $headerMap): array
    {
        $get = function (string $canonical) use ($record, $headerMap) {
            if (!isset($headerMap[$canonical])) {
                return null;
            }
            $actual = $headerMap[$canonical];
            return array_key_exists($actual, $record) ? trim((string) $record[$actual]) : null;
        };

        return [
            'product_name'      => $get('Nama Produk'),
            'product_code'      => $get('Kode Produk'),
            'unit_name'         => $get('Satuan'),
            'average_price'     => $get('Harga Rata-rata'),
            'stock_on_hand'     => $get('Stok di tangan'),
            'minimum_stock'     => $get('Batas Minimum'),
            'nilai'             => $get('Nilai'),
            'unassigned'        => $get('Unassigned'),
            'total_quantity'    => $get('Total Quantity'),
        ];
    }

    private function processRow(ProductImportRow $row): void
    {
        if ($this->batch->import_type === 'stock_snapshot') {
            $this->processStockSnapshotRow($row);
            return;
        }

        $payload = (array) $row->raw_json;

        $normalizedName = $this->normalizeProductName((string) ($payload['product_name'] ?? ''));
        if ($normalizedName === '') {
            $this->recordFailure($row, 'Nama produk wajib setelah normalisasi.');
            return;
        }

        $unitName = trim((string) ($payload['unit_name'] ?? ''));
        if ($unitName === '') {
            $this->recordFailure($row, 'Satuan wajib diisi.');
            return;
        }

        $salePrice = $this->dec($this->parsePrice($payload['sale_price'] ?? $payload['average_price'] ?? null));
        $tier1Price = $this->dec($this->parsePrice($payload['tier_1_price'] ?? $payload['average_price'] ?? null));
        $tier2Price = $this->dec($this->parsePrice($payload['tier_2_price'] ?? $payload['average_price'] ?? null));
        $purchasePrice = $this->dec($this->parsePrice($payload['purchase_price'] ?? $payload['average_price'] ?? null));

        $nameKey = mb_strtolower($normalizedName);
        $codeInput = trim((string) ($payload['product_code'] ?? ''));

        $product = null;
        if ($codeInput !== '') {
            $product = Product::where('product_code', $codeInput)->first();
        }
        if (!$product) {
            $product = Product::where('product_name', $normalizedName)->first();
        }

        if ($product) {
            try {
                DB::beginTransaction();
                ProductPrice::upsertFor([
                    'product_id'             => $product->id,
                    'setting_id'             => $this->defaultSettingId,
                    'sale_price'             => $salePrice,
                    'tier_1_price'           => $tier1Price,
                    'tier_2_price'           => $tier2Price,
                    'last_purchase_price'    => $purchasePrice,
                    'average_purchase_price' => $purchasePrice,
                    'purchase_tax_id'        => null,
                    'sale_tax_id'            => null,
                ]);
                DB::commit();
                $this->recordSuccess($row, $product->id);
            } catch (Throwable $e) {
                DB::rollBack();
                $this->recordFailure($row, Str::limit($e->getMessage(), 2000));
            }
            return;
        }

        if (isset($this->seenNameKeys[$nameKey])) {
            $this->recordFailure($row, 'Produk dengan nama sama sudah ada.', 'skipped');
            return;
        }

        $resolvedCode = $this->resolveProductCode($codeInput);
        if ($resolvedCode === null) {
            $this->recordFailure($row, 'Kode produk sudah digunakan.', 'skipped');
            Log::info('[ProductImportBatch] Row skipped (duplicate code)', ['batch_id' => $this->batchId, 'row_id' => $row->id, 'code' => $codeInput]);
            return;
        }
        $codeKey = $resolvedCode !== '' ? mb_strtolower($resolvedCode) : null;

        try {
            DB::beginTransaction();

            $unitId = $this->firstOrCreateUnit($unitName);

            $product = Product::create([
                'product_name'            => $normalizedName,
                'product_code'            => $resolvedCode ?: null,
                'barcode'                 => null,
                'category_id'             => null,
                'brand_id'                => null,
                'base_unit_id'            => $unitId,
                'unit_id'                 => $unitId,
                'stock_managed'           => 1,
                'product_stock_alert'     => 0,
                'product_quantity'        => 0,
                'serial_number_required'  => 0,
                'setting_id'              => $this->defaultSettingId,
                'is_purchased'            => 1,
                'purchase_price'          => 0,
                'purchase_tax_id'         => null,
                'is_sold'                 => 1,
                'sale_price'              => 0,
                'sale_tax_id'             => null,
                'tier_1_price'            => 0,
                'tier_2_price'            => 0,
                'product_price'           => 0,
                'product_cost'            => 0,
                'product_order_tax'       => 0,
                'product_tax_type'        => 0,
                'profit_percentage'       => 0,
                'last_purchase_price'     => 0,
                'average_purchase_price'  => 0,
            ]);

            ProductPrice::seedForSettings(
                $product->id,
                [
                    'sale_price'             => $salePrice,
                    'tier_1_price'           => $tier1Price,
                    'tier_2_price'           => $tier2Price,
                    'last_purchase_price'    => $purchasePrice,
                    'average_purchase_price' => $purchasePrice,
                    'purchase_tax_id'        => null,
                    'sale_tax_id'            => null,
                ],
                $this->settingIds
            );

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('[ProductImportBatch] Row failed exception', [
                'batch_id' => $this->batchId,
                'row_id' => $row->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->recordFailure($row, Str::limit($e->getMessage(), 2000));
            return;
        }

        $this->existingNameKeys[$nameKey] = true;
        $this->seenNameKeys[$nameKey] = true;
        if ($codeKey) {
            $this->existingCodeKeys[$codeKey] = true;
            $this->seenCodeKeys[$codeKey] = true;
        }

        $this->recordSuccess($row, $product->id);
        
        Log::info('[ProductImportBatch] Product created', [
            'batch_id' => $this->batchId, 
            'row_id' => $row->id, 
            'product_id' => $product->id,
            'product_name' => $normalizedName
        ]);
    }

    private function recordFailure(ProductImportRow $row, string $message, string $status = 'error'): void
    {
        Log::warning('[ProductImportBatch] Row error', [
            'batch_id' => $this->batchId,
            'row_id' => $row->id,
            'row_number' => $row->row_number ?? null,
            'status' => $status,
            'error' => $message,
            'raw_data' => $row->raw_json,
        ]);

        $row->forceFill([
            'status' => $status,
            'error_message' => $message,
        ])->save();

        $this->batch->increment('processed_rows');
        $this->batch->increment('error_rows');
    }

    private function recordSuccess(ProductImportRow $row, int $productId): void
    {
        $row->forceFill([
            'status'        => 'imported',
            'product_id'    => $productId,
            'error_message' => null,
        ])->save();

        $this->batch->increment('processed_rows');
        $this->batch->increment('success_rows');
    }

    private function recordStockSnapshotSuccess(ProductImportRow $row, int $productId, int $stockId, int $txnId, array $resultMeta): void
    {
        $row->forceFill([
            'status'           => 'imported',
            'product_id'       => $productId,
            'created_stock_id' => $stockId,
            'created_txn_id'   => $txnId,
            'result_metadata'  => $resultMeta,
            'error_message'    => null,
        ])->save();

        $this->batch->increment('processed_rows');
        $this->batch->increment('success_rows');
    }

    private function normalizeProductName(string $name): string
    {
        $name = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = trim($name);

        // Remove leading asterisk + spaces
        $name = preg_replace('/^\*\s*/u', '', $name);
        // Remove trailing "TP"
        $name = preg_replace('/\s*TP\s*$/iu', '', $name);

        return trim($name);
    }

    private function parsePrice($value): float
    {
        if ($value === null) {
            return 0.0;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }

        $clean = preg_replace('/[^\d.,\-]/', '', $raw) ?? '';
        if ($clean === '' || $clean === '-' || $clean === '-.' || $clean === '.-') {
            return 0.0;
        }

        $commaCount = substr_count($clean, ',');
        $dotCount   = substr_count($clean, '.');

        if ($commaCount > 0 && $dotCount === 0) {
            $clean = str_replace(',', '.', $clean);
        } elseif ($commaCount > 0 && $dotCount > 0) {
            $clean = str_replace(',', '', $clean);
        }

        return (float) $clean;
    }

    private function dec($v): string
    {
        if ($v === null || $v === '') return '0.00';
        $val = (float) $v;
        if ($val > 99999999.99) $val = 99999999.99;
        if ($val < -99999999.99) $val = -99999999.99;
        return number_format($val, 2, '.', '');
    }

    private function resolveDefaultSettingId(array $settingIds): int
    {
        $locationSettingId = optional($this->batch->location)->setting_id;
        if ($locationSettingId) {
            return (int) $locationSettingId;
        }

        if (!empty($settingIds)) {
            return (int) $settingIds[0];
        }

        $fallback = Setting::query()->min('id');
        return (int) ($fallback ?? 0);
    }

    /** @return array<string,bool> */
    private function loadExistingNameKeys(): array
    {
        $set = [];
        Product::query()
            ->select('id', 'product_name')
            ->chunkById(1000, function ($products) use (&$set) {
                foreach ($products as $product) {
                    $normalized = $this->normalizeProductName((string) $product->product_name);
                    if ($normalized !== '') {
                        $set[mb_strtolower($normalized)] = true;
                    }
                }
            });

        return $set;
    }

    /** @return array<string,bool> */
    private function loadExistingCodeKeys(): array
    {
        $set = [];
        Product::query()
            ->select('id', 'product_code')
            ->whereNotNull('product_code')
            ->chunkById(1000, function ($products) use (&$set) {
                foreach ($products as $product) {
                    $code = trim((string) $product->product_code);
                    if ($code !== '') {
                        $set[mb_strtolower($code)] = true;
                    }
                }
            });

        return $set;
    }

    private function initSkuCounter(): void
    {
        $lastSku = Product::where('product_code', 'like', 'SKU-%')
            ->orderByRaw("CAST(SUBSTRING(product_code, 5) AS UNSIGNED) DESC")
            ->value('product_code');

        $this->nextSkuNumber = $lastSku ? ((int) substr($lastSku, 4) + 1) : 1;
    }

    private function generateNextSku(): string
    {
        return 'SKU-' . str_pad($this->nextSkuNumber++, 6, '0', STR_PAD_LEFT);
    }

    private function resolveProductCode(string $input): ?string
    {
        if ($input !== '') {
            $key = mb_strtolower($input);
            if (isset($this->existingCodeKeys[$key]) || isset($this->seenCodeKeys[$key])) {
                return null;
            }
            return $input;
        }

        do {
            $candidate = $this->generateNextSku();
            $key = mb_strtolower($candidate);
        } while (isset($this->existingCodeKeys[$key]) || isset($this->seenCodeKeys[$key]));

        return $candidate;
    }

    private function firstOrCreateUnit(string $name): int
    {
        $attrs = ['name' => $name];
        if (Schema::hasColumn('units', 'setting_id')) {
            $attrs['setting_id'] = $this->defaultSettingId;
        }

        $defaults = [];
        if (Schema::hasColumn('units', 'short_name')) {
            $defaults['short_name'] = $name;
        }

        try {
            $unitQuery = Unit::query();
            if (Schema::hasColumn('units', 'short_name')) {
                $unitQuery->where(function($q) use ($name) {
                    $q->whereRaw('LOWER(name) = ?', [strtolower($name)])
                      ->orWhereRaw('LOWER(short_name) = ?', [strtolower($name)]);
                });
            } else {
                $unitQuery->whereRaw('LOWER(name) = ?', [strtolower($name)]);
            }

            if (Schema::hasColumn('units', 'setting_id')) {
                $unitQuery->where('setting_id', $this->defaultSettingId);
            }
            $unit = $unitQuery->first();

            if (!$unit) {
                $unit = Unit::firstOrCreate($attrs, $defaults);
            }
        } catch (MassAssignmentException $e) {
            $unit = (new Unit())->forceFill(array_merge($attrs, $defaults));
            $unit->save();
        }

        if (
            Schema::hasColumn('units', 'short_name')
            && (empty($unit->short_name) || trim((string) $unit->short_name) === '')
        ) {
            $unit->short_name = $name;
            $unit->save();
        }

        return (int) $unit->id;
    }

    private function processStockSnapshotRow(ProductImportRow $row): void
    {
        $payload = (array) $row->raw_json;
        $rawName = (string) ($payload['product_name'] ?? '');
        $tempName = $rawName;
        $this->resolveOwnerFromMarker($tempName, $ownerId, $locationId, $ownerName, $rawMarker);

        if (!$ownerId || !$locationId) {
            $this->recordFailure($row, 'Pemilik atau lokasi tidak ditemukan untuk marker produk ini.');
            return;
        }

        $normalizedName = $this->normalizeProductName($rawName);

        if ($normalizedName === '') {
            $this->recordFailure($row, 'Nama produk wajib.');
            return;
        }

        try {
            DB::beginTransaction();

            $product = $this->matchOrCreateProduct($payload, $normalizedName);

            $stockVal = (int) ($payload['total_quantity'] ?? 0);

            $stock = \Modules\Product\Entities\ProductStock::firstOrCreate([
                'product_id' => $product->id,
                'location_id' => $locationId,
            ], [
                'quantity' => 0,
                'quantity_non_tax' => 0,
                'quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity' => 0,
            ]);

            $ownerSetting = \Modules\Setting\Entities\Setting::find($ownerId);
            $isPkp = $ownerSetting ? $ownerSetting->is_pkp : false;

            $prevQty = $stock->quantity;
            $prevQtyTax = $stock->quantity_tax;
            $prevQtyNonTax = $stock->quantity_non_tax;

            $newQtyTax = $isPkp ? $stockVal : 0;
            $newQtyNonTax = $isPkp ? 0 : $stockVal;

            $difference = $stockVal - $prevQty;
            $diffQtyTax = $newQtyTax - $prevQtyTax;
            $diffQtyNonTax = $newQtyNonTax - $prevQtyNonTax;

            $stock->quantity = $stockVal;
            $stock->quantity_tax = $newQtyTax;
            $stock->quantity_non_tax = $newQtyNonTax;
            $stock->save();

            if ($difference != 0) {
                $product->product_quantity += $difference;
                $product->save();
            }

            $location = \Modules\Setting\Entities\Location::find($locationId);

            $txn = \Modules\Product\Entities\Transaction::create([
                'product_id' => $product->id,
                'setting_id' => $ownerId ?: $this->defaultSettingId,
                'location_id' => $locationId,
                'type' => 'ADJ',
                'quantity' => $difference,
                'current_quantity' => $stockVal,
                'previous_quantity' => $prevQty,
                'after_quantity' => $stockVal,
                'previous_quantity_at_location' => $prevQty,
                'after_quantity_at_location' => $stockVal,
                'quantity_tax' => $diffQtyTax,
                'quantity_non_tax' => $diffQtyNonTax,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'user_id' => $this->batch->user_id,
                'reason' => 'Stock Snapshot Import overwrite',
            ]);

            // Build result metadata for UI row-level stock effect display
            $resultMeta = [
                'raw_marker'          => $rawMarker,
                'clean_product_name'  => $normalizedName,
                'owner_setting_id'    => $ownerId,
                'owner_setting_name'  => $ownerName,
                'is_pkp'              => $isPkp,
                'target_location_id'  => $locationId,
                'target_location_name'=> $location ? $location->name : null,
                'total_quantity'      => $stockVal,
                'previous_quantity'   => $prevQty,
                'after_quantity'      => $stockVal,
                'prev_quantity_tax'   => $prevQtyTax,
                'prev_quantity_non_tax' => $prevQtyNonTax,
                'after_quantity_tax'  => $newQtyTax,
                'after_quantity_non_tax' => $newQtyNonTax,
                'delta_quantity'      => $difference,
                'delta_quantity_tax'  => $diffQtyTax,
                'delta_quantity_non_tax' => $diffQtyNonTax,
            ];

            DB::commit();
            $this->recordStockSnapshotSuccess($row, $product->id, $stock->id, $txn->id, $resultMeta);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->recordFailure($row, 'Gagal sinkronisasi stok: ' . $e->getMessage());
        }
    }

    private function matchOrCreateProduct(array $payload, string $normalizedName): Product
    {
        $cleanName = trim(preg_replace('/^(?:\*\s*)?(.*?)(?:\s+TP)?$/i', '$1', $normalizedName));

        if (!empty($payload['product_code'])) {
            $product = Product::where('product_code', $payload['product_code'])->first();
            if ($product) return $product;
        }

        $product = Product::whereRaw('LOWER(product_name) = ?', [mb_strtolower($cleanName)])->first();
        if ($product) return $product;

        $unitId = $this->firstOrCreateUnit((string) ($payload['unit_name'] ?? 'Pcs'));
        $codeInput = trim((string) ($payload['product_code'] ?? ''));
        $productCode = $codeInput !== '' ? $codeInput : null;

        $product = Product::create([
            'product_name'            => $cleanName,
            'product_code'            => $productCode,
            'barcode'                 => null,
            'category_id'             => null,
            'brand_id'                => null,
            'base_unit_id'            => $unitId,
            'unit_id'                 => $unitId,
            'stock_managed'           => 1,
            'product_stock_alert'     => 0,
            'product_quantity'        => 0,
            'serial_number_required'  => 0,
            'setting_id'              => $this->defaultSettingId,
            'is_purchased'            => 1,
            'purchase_price'          => 0,
            'purchase_tax_id'         => null,
            'is_sold'                 => 1,
            'sale_price'              => 0,
            'sale_tax_id'             => null,
            'tier_1_price'            => 0,
            'tier_2_price'            => 0,
            'product_price'           => 0,
            'product_cost'            => 0,
            'product_order_tax'       => 0,
            'product_tax_type'        => 0,
            'profit_percentage'       => 0,
            'last_purchase_price'     => 0,
            'average_purchase_price'  => 0,
        ]);

        \Modules\Product\Entities\ProductPrice::seedForSettings(
            $product->id,
            [
                'sale_price'             => 0,
                'tier_1_price'           => 0,
                'tier_2_price'           => 0,
                'last_purchase_price'    => 0,
                'average_purchase_price' => 0,
                'purchase_tax_id'        => null,
                'sale_tax_id'            => null,
            ],
            $this->settingIds
        );

        return $product;
    }

    private function resolveOwnerFromMarker(string $productName, &$ownerId, &$locationId, &$ownerName = null, &$rawMarker = null): void
    {
        $ownerId = null;
        $locationId = null;
        $ownerName = null;
        $rawMarker = null;

        if (str_starts_with(trim($productName), '*')) {
            $rawMarker = '*';
            $owner = \Modules\Setting\Entities\Setting::where('company_name', 'like', '%CV TIGA NUSA COMPUTER%')->first();
        } elseif (preg_match('/\sTP\s*$/i', $productName)) {
            $rawMarker = 'TP';
            $owner = \Modules\Setting\Entities\Setting::where('company_name', 'like', '%CV TOP IT INTERNUSA%')->first();
        } else {
            $rawMarker = '';
            $owner = \Modules\Setting\Entities\Setting::where('company_name', 'like', '%PERDANA%')->first();
        }

        if (isset($owner)) {
            $ownerId = $owner->id;
            $ownerName = $owner->company_name;
            $location = \Modules\Setting\Entities\Location::where('setting_id', $ownerId)->first();
            if ($location) {
                $locationId = $location->id;
            }
        }
    }
}
