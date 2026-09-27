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
use Carbon\Carbon;
use Log;
use Throwable;

use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Entities\SaleDetails;
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

    private array $salesHppGroupCounts = [];
    private array $salesHppGroupIds = [];
    private ?\Modules\Setting\Entities\Setting $daizuSetting = null;
    private ?\Modules\Setting\Entities\Setting $tigaNusaSetting = null;
    private ?\Modules\Setting\Entities\Setting $topItSetting = null;
    private ?\Modules\Setting\Entities\Setting $perdanaSetting = null;
    private bool $settingsLoaded = false;

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

        if ($this->batch->import_type === ProductImportBatch::TYPE_SALES_HPP_SNAPSHOT) {
            $this->appendSalesHppCoverageReportRows();
        }

        $updateData = [
            'status' => 'completed',
            'completed_at' => now(),
        ];
        
        if ($this->batch->import_type !== \Modules\Product\Entities\ProductImportBatch::TYPE_SALES_HPP_SNAPSHOT) {
            $updateData['undo_available_until'] = now()->addHour();
        }
        
        $this->batch->update($updateData);

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
            'tipe transaksi'     => 'Tipe Transaksi',
            'no. transaksi'      => 'No. Transaksi',
            'tanggal'            => 'Tanggal',
            'date'               => 'Tanggal',
            'barang'             => 'Barang',
            'mutasi'             => 'Mutasi',
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
        } elseif ($this->batch->import_type === ProductImportBatch::TYPE_SALES_HPP_SNAPSHOT) {
            $required = ['Tipe Transaksi', 'No. Transaksi', 'Barang', 'Mutasi', 'Harga Rata-rata'];
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

        $payload = [
            'product_name'      => $get('Nama Produk'),
            'product_code'      => $get('Kode Produk'),
            'unit_name'         => $get('Satuan'),
            'average_price'     => $get('Harga Rata-rata'),
            'stock_on_hand'     => $get('Stok di tangan'),
            'minimum_stock'     => $get('Batas Minimum'),
            'nilai'             => $get('Nilai'),
            'unassigned'        => $get('Unassigned'),
            'total_quantity'    => $get('Total Quantity'),
            'tipe_transaksi'    => $get('Tipe Transaksi'),
            'no_transaksi'      => $get('No. Transaksi'),
            'tanggal'           => $get('Tanggal'),
            'barang'            => $get('Barang'),
            'mutasi'            => $get('Mutasi'),
        ];

        if ($this->batch->import_type === ProductImportBatch::TYPE_SALES_HPP_SNAPSHOT) {
            $markerResolver = app(\App\Support\SalesImportMarkerResolver::class);
            $numericParser = app(\App\Support\HppNumericParser::class);
            
            $rawName = (string) $payload['barang'];
            $parsed = $markerResolver->parseProductName($rawName);
            
            $payload['raw_product_name'] = $rawName;
            $payload['cleaned_product_name'] = $parsed['clean_name'] ?? $rawName;
            $payload['source_quantity'] = $numericParser->parseQuantity($payload['mutasi']);
            $payload['source_hpp'] = $numericParser->parseHpp($payload['average_price']);
        }

        return $payload;
    }

    private function processRow(ProductImportRow $row): void
    {
        if ($this->batch->import_type === 'stock_snapshot') {
            $this->processStockSnapshotRow($row);
            return;
        }

        if ($this->batch->import_type === ProductImportBatch::TYPE_SALES_HPP_SNAPSHOT) {
            $this->processSalesHppSnapshotRow($row);
            return;
        }

        $payload = (array) $row->raw_json;

        $rawName = (string) ($payload['product_name'] ?? '');
        if ($rawName === '') {
            $this->recordFailure($row, 'Nama produk wajib.');
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

        $resolver = app(\Modules\Product\Services\ProductResolver::class);
        $canonicalizer = app(\Modules\Product\Services\ProductCanonicalizer::class);

        try {
            $identity = $canonicalizer->canonicalize($rawName);
            $nameKey = $identity['canonical_key'];
            $displayName = $identity['display_name'];
        } catch (\InvalidArgumentException $e) {
            $this->recordFailure($row, 'Nama produk tidak valid setelah kanonikalisasi: ' . $e->getMessage());
            return;
        }

        $codeInput = trim((string) ($payload['product_code'] ?? ''));

        $product = null;
        if ($codeInput !== '') {
            $product = Product::where('product_code', $codeInput)->first();
            if ($product) {
                $dbNormalizedName = $canonicalizer->canonicalize($product->product_name)['canonical_key'];
                if ($dbNormalizedName !== $nameKey) {
                    $this->recordFailure($row, "Code/name disagreement: Found ID {$product->id} '{$product->product_name}' but expected '{$displayName}'.", 'error');
                    return;
                }
            }
        }

        if (!$product) {
            try {
                $product = $resolver->resolveExisting($rawName);
            } catch (\Modules\Product\Exceptions\AmbiguousProductResolutionException $e) {
                $this->recordFailure($row, 'Produk duplikat ambigu.', 'error');
                return;
            }
        }

        if ($product) {
            try {
                DB::transaction(function () use ($product, $salePrice, $tier1Price, $tier2Price, $purchasePrice, $row) {
                    $beforePrice = ProductPrice::where('product_id', $product->id)
                        ->where('setting_id', $this->defaultSettingId)
                        ->first();

                    $beforeSnapshot = $beforePrice ? [
                        'sale_price'          => (float) $beforePrice->sale_price,
                        'tier_1_price'        => (float) $beforePrice->tier_1_price,
                        'tier_2_price'        => (float) $beforePrice->tier_2_price,
                        'last_purchase_price' => (float) $beforePrice->last_purchase_price,
                    ] : [
                        'sale_price' => 0.0, 'tier_1_price' => 0.0, 'tier_2_price' => 0.0, 'last_purchase_price' => 0.0,
                    ];

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

                    app(\Modules\Product\Services\ProductPriceFeedRecorder::class)->record(
                        \Modules\Product\Entities\ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED,
                        \Modules\Product\Entities\ProductPriceFeedEvent::SUBJECT_PRODUCT,
                        $product->id,
                        $product->product_name,
                        $product->product_code,
                        [
                            [
                                'setting_id' => $this->defaultSettingId,
                                'before' => $beforeSnapshot,
                                'after' => [
                                    'sale_price'          => (float) $salePrice,
                                    'tier_1_price'        => (float) $tier1Price,
                                    'tier_2_price'        => (float) $tier2Price,
                                    'last_purchase_price' => (float) $purchasePrice,
                                ],
                            ],
                        ],
                        \Modules\Product\Entities\ProductPriceFeedEvent::SOURCE_IMPORT
                    );
                });
                $this->recordSuccess($row, $product->id);
            } catch (\Throwable $e) {
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

            $product = $resolver->resolveOrCreate($rawName, function ($displayNameFromResolver, $canonicalKey) use ($resolvedCode, $unitName) {
                $unitId = $this->firstOrCreateUnit($unitName);
                return [
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
                ];
            });

            if ($product->wasRecentlyCreated) {
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

                $sections = [];
                foreach ($this->settingIds as $sId) {
                    $sections[] = [
                        'setting_id' => $sId,
                        'after' => [
                            'sale_price'          => (float) $salePrice,
                            'tier_1_price'        => (float) $tier1Price,
                            'tier_2_price'        => (float) $tier2Price,
                            'last_purchase_price' => (float) $purchasePrice,
                        ],
                    ];
                }

                app(\Modules\Product\Services\ProductPriceFeedRecorder::class)->record(
                    \Modules\Product\Entities\ProductPriceFeedEvent::TYPE_PRODUCT_CREATED,
                    \Modules\Product\Entities\ProductPriceFeedEvent::SUBJECT_PRODUCT,
                    $product->id,
                    $product->product_name,
                    $product->product_code,
                    $sections,
                    \Modules\Product\Entities\ProductPriceFeedEvent::SOURCE_IMPORT
                );
            } else {
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
            }

            DB::commit();
            $this->recordSuccess($row, $product->id);
            Log::info('[ProductImportBatch] Row imported successfully', ['batch_id' => $this->batchId, 'row_id' => $row->id, 'product_id' => $product->id]);
        } catch (\Modules\Product\Exceptions\AmbiguousProductResolutionException $e) {
            DB::rollBack();
            $this->recordFailure($row, 'Produk duplikat ambigu.', 'error');
        } catch (\Throwable $e) {
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
            'product_name' => $displayName
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

    private function recordHppSnapshotSuccess(ProductImportRow $row, int $productId, array $resultMeta): void
    {
        $row->forceFill([
            'status'           => 'imported',
            'product_id'       => $productId,
            'result_metadata'  => $resultMeta,
            'error_message'    => null,
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
        $canonicalizer = app(\Modules\Product\Services\ProductCanonicalizer::class);
        Product::query()
            ->select('id', 'product_name')
            ->chunkById(1000, function ($products) use (&$set, $canonicalizer) {
                foreach ($products as $product) {
                    try {
                        $identity = $canonicalizer->canonicalize((string) $product->product_name);
                        $set[$identity['canonical_key']] = true;
                    } catch (\InvalidArgumentException $e) {
                        Log::warning('[ProductImportBatch] Failed to canonicalize existing product', [
                            'product_id' => $product->id,
                            'product_name' => $product->product_name,
                            'error' => $e->getMessage(),
                        ]);
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

        if (!$ownerId) {
            $markerLabel = $rawMarker === '' ? 'tanpa marker' : "marker '{$rawMarker}'";
            $this->recordFailure($row, "Pemilik (Perusahaan) tidak ditemukan untuk {$markerLabel}.");
            return;
        }

        if (!$locationId) {
            $this->recordFailure($row, "Lokasi gudang tidak ditemukan untuk pemilik '{$ownerName}'.");
            return;
        }

        $canonicalizer = app(\Modules\Product\Services\ProductCanonicalizer::class);

        try {
            $identity = $canonicalizer->canonicalize($rawName);
            $displayName = $identity['display_name'];
        } catch (\InvalidArgumentException $e) {
            $this->recordFailure($row, 'Nama produk tidak valid setelah kanonikalisasi: ' . $e->getMessage());
            return;
        }

        try {
            DB::beginTransaction();

            $product = $this->matchOrCreateProduct($payload, $displayName);

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

            $prevStockQty = $stock->quantity;
            $prevQtyTax = $stock->quantity_tax;
            $prevQtyNonTax = $stock->quantity_non_tax;

            $latestTxn = \Modules\Product\Entities\Transaction::where('product_id', $product->id)
                ->where('setting_id', $ownerId ?: $this->defaultSettingId)
                ->where('location_id', $locationId)
                ->latest('id')
                ->first();
            
            $prevLedgerQtyLoc = $latestTxn ? (float)$latestTxn->after_quantity_at_location : 0.0;

            $newQtyTax = $isPkp ? $stockVal : 0;
            $newQtyNonTax = $isPkp ? 0 : $stockVal;

            $stockDifference = $stockVal - $prevStockQty;
            $adjDifference = $stockVal - $prevLedgerQtyLoc;

            $diffQtyTax = $newQtyTax - $prevQtyTax;
            $diffQtyNonTax = $newQtyNonTax - $prevQtyNonTax;

            $stock->quantity = $stockVal;
            $stock->quantity_tax = $newQtyTax;
            $stock->quantity_non_tax = $newQtyNonTax;
            $stock->save();

            if ($stockDifference != 0) {
                $product->product_quantity += $stockDifference;
                $product->save();
            }

            $location = \Modules\Setting\Entities\Location::find($locationId);

            $txn = \Modules\Product\Entities\Transaction::create([
                'product_id' => $product->id,
                'setting_id' => $ownerId ?: $this->defaultSettingId,
                'location_id' => $locationId,
                'type' => 'ADJ',
                'quantity' => $adjDifference,
                'current_quantity' => $stockVal,
                'previous_quantity' => $prevLedgerQtyLoc,
                'after_quantity' => $stockVal,
                'previous_quantity_at_location' => $prevLedgerQtyLoc,
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
                'clean_product_name'  => $displayName,
                'owner_setting_id'    => $ownerId,
                'owner_setting_name'  => $ownerName,
                'is_pkp'              => $isPkp,
                'target_location_id'  => $locationId,
                'target_location_name'=> $location ? $location->name : null,
                'total_quantity'      => $stockVal,
                'previous_quantity'   => $prevLedgerQtyLoc,
                'after_quantity'      => $stockVal,
                'prev_quantity_tax'   => $prevQtyTax,
                'prev_quantity_non_tax' => $prevQtyNonTax,
                'after_quantity_tax'  => $newQtyTax,
                'after_quantity_non_tax' => $newQtyNonTax,
                'delta_quantity'      => $adjDifference,
                'delta_quantity_tax'  => $diffQtyTax,
                'delta_quantity_non_tax' => $diffQtyNonTax,
            ];

            $this->recordStockSnapshotSuccess($row, $product->id, $stock->id, $txn->id, $resultMeta);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->recordFailure($row, 'Gagal sinkronisasi stok: ' . $e->getMessage());
        }
    }

    private function processSalesHppSnapshotRow(ProductImportRow $row): void
    {
        $payload = (array) $row->raw_json;
        $tipeTransaksi = strtolower(trim((string) ($payload['tipe_transaksi'] ?? '')));

        if ($tipeTransaksi !== 'sales invoice') {
            $this->recordFailure($row, 'Baris diabaikan: Tipe transaksi bukan Sales Invoice.', 'skipped');
            return;
        }

        $mutasi = (float) ($payload['source_quantity'] ?? 0);
        if ($mutasi >= 0) {
            $this->recordFailure($row, 'Baris diabaikan: Mutasi positif bukan penjualan.', 'skipped');
            return;
        }

        $rawName = (string) ($payload['raw_product_name'] ?? '');
        $markerResolver = app(\App\Support\SalesImportMarkerResolver::class);
        $canonicalizer = app(\Modules\Product\Services\ProductCanonicalizer::class);

        $ownerKey = $markerResolver->resolveEffectiveOwnerKey($rawName);

        if (!$this->settingsLoaded) {
            $this->daizuSetting = \Modules\Setting\Entities\Setting::where('company_name', 'like', '%DAIZU%')->first();
            $this->tigaNusaSetting = \Modules\Setting\Entities\Setting::where('company_name', 'like', '%CV TIGA NUSA COMPUTER%')->first();
            $this->topItSetting = \Modules\Setting\Entities\Setting::where('company_name', 'like', '%CV TOP IT INTERNUSA%')->first();
            $this->perdanaSetting = \Modules\Setting\Entities\Setting::where('company_name', 'like', '%PERDANA%')->first();
            $this->settingsLoaded = true;
        }

        // Find setting based on the resolved owner key
        $owner = match ($ownerKey) {
            'daizu' => $this->daizuSetting,
            'marker:asterisk' => $this->tigaNusaSetting,
            'marker:tp' => $this->topItSetting,
            default => $this->perdanaSetting,
        };

        if (!$owner) {
            $this->recordFailure($row, "Pemilik tidak ditemukan untuk produk {$rawName} (OwnerKey: {$ownerKey}).");
            return;
        }
        $ownerId = $owner->id;
        $ownerName = $owner->company_name;

        $cleanName = (string) ($payload['cleaned_product_name'] ?? '');

        // Canonicalize the HPP source name using ProductCanonicalizer
        try {
            $hppIdentity = $canonicalizer->canonicalize($cleanName);
            $hppCanonicalKey = $hppIdentity['canonical_key'];
        } catch (\InvalidArgumentException $e) {
            $this->recordFailure($row, "Nama produk HPP tidak valid setelah kanonikalisasi: {$e->getMessage()}");
            return;
        }

        $reference = trim((string) ($payload['no_transaksi'] ?? ''));
        if ($reference === '') {
            $this->recordFailure($row, 'No transaksi kosong.');
            return;
        }

        $sale = \Modules\Sale\Entities\Sale::where('imported_sales_reference_number', $reference)
            ->where('setting_id', $ownerId)
            ->first();

        if (!$sale) {
            $this->recordFailure($row, "Invoice '{$reference}' tidak ditemukan untuk pemilik {$ownerName}.");
            return;
        }

        $saleDetails = \Modules\Sale\Entities\SaleDetails::where('sale_id', $sale->id)
            ->whereRaw('ABS(quantity - ?) < 0.001', [abs($mutasi)])
            ->get();

        $qtyDisambiguated = $saleDetails->filter(function ($detail) use ($canonicalizer, $hppCanonicalKey) {
            try {
                $detailIdentity = $canonicalizer->canonicalize($detail->product_name);
                return $detailIdentity['canonical_key'] === $hppCanonicalKey;
            } catch (\InvalidArgumentException $e) {
                return false;
            }
        });

        $matchMethod = 'exact';
        $matchScore = 100.0;

        if ($qtyDisambiguated->isEmpty()) {
            $this->recordFailure($row, "Detail penjualan untuk produk '{$cleanName}' di invoice '{$reference}' dengan mutasi " . abs($mutasi) . " tidak ditemukan (HPP tidak cocok dengan penjualan).");
            return;
        }

        $groupKey = $reference . '|' . $ownerId . '|' . $hppCanonicalKey . '|' . abs($mutasi);

        if ($qtyDisambiguated->count() > 1) {
            if (!isset($this->salesHppGroupCounts[$groupKey])) {
                $similarRows = ProductImportRow::where('batch_id', $this->batch->id)
                    ->where('raw_json->no_transaksi', $reference)
                    ->orderBy('id', 'asc')
                    ->get()
                    ->filter(function ($sRow) use ($markerResolver, $canonicalizer, $ownerId, $hppCanonicalKey, $mutasi) {
                        $p = (array) $sRow->raw_json;
                        if (strtolower(trim($p['tipe_transaksi'] ?? '')) !== 'sales invoice') return false;
                        if ((float) ($p['source_quantity'] ?? 0) >= 0) return false;
                        if (abs(abs((float) ($p['source_quantity'] ?? 0)) - abs($mutasi)) > 0.001) return false;

                        $rName = (string) ($p['raw_product_name'] ?? '');
                        $oKey = $markerResolver->resolveEffectiveOwnerKey($rName);
                        $o = match ($oKey) {
                            'daizu' => $this->daizuSetting,
                            'marker:asterisk' => $this->tigaNusaSetting,
                            'marker:tp' => $this->topItSetting,
                            default => $this->perdanaSetting,
                        };
                        if (!$o || $o->id !== $ownerId) return false;

                        $cName = (string) ($p['cleaned_product_name'] ?? '');
                        try {
                            $identity = $canonicalizer->canonicalize($cName);
                            return $identity['canonical_key'] === $hppCanonicalKey;
                        } catch (\InvalidArgumentException $e) {
                            return false;
                        }
                    });

                $this->salesHppGroupCounts[$groupKey] = $similarRows->count();
                $this->salesHppGroupIds[$groupKey] = $similarRows->pluck('id')->values()->all();
            }

            $similarSourceRowsCount = $this->salesHppGroupCounts[$groupKey];
            if ($similarSourceRowsCount !== $qtyDisambiguated->count()) {
                $this->recordFailure($row, "Ambiguous match: Ditemukan {$qtyDisambiguated->count()} detail penjualan, tapi {$similarSourceRowsCount} baris HPP untuk produk '{$cleanName}' dengan mutasi " . abs($mutasi) . " di invoice '{$reference}'.");
                return;
            }

            $groupIds = $this->salesHppGroupIds[$groupKey] ?? [];
            $matchIndex = array_search($row->id, $groupIds);
            if ($matchIndex === false) $matchIndex = 0;
        } else {
            $matchIndex = 0;
        }

        $orderedDetails = $qtyDisambiguated->sortBy('id')->values();

        if ($orderedDetails->count() <= $matchIndex) {
            $this->recordFailure($row, "Ambiguous match: Tidak ada detail penjualan tersisa (index {$matchIndex}) untuk produk '{$cleanName}' dengan mutasi " . abs($mutasi) . " di invoice '{$reference}'.");
            return;
        }

        $saleDetail = $orderedDetails->get($matchIndex);

        try {
            DB::beginTransaction();

            $sourceHpp = $payload['source_hpp'] ?? null;
            if ($sourceHpp === null || !is_numeric($sourceHpp) || $sourceHpp <= 0) {
                $this->recordFailure($row, "Invalid HPP: Harga rata-rata tidak valid (" . ($payload['source_hpp'] ?? 'kosong') . ").");
                DB::rollBack();
                return;
            }
            $sourceHpp = (float) $sourceHpp;
            
            $qty = (float) $saleDetail->quantity;
            $prevCostUnit = $saleDetail->cost_unit_snapshot;
            $prevCostTotal = $saleDetail->cost_total_snapshot;
            $prevSource = $saleDetail->cost_snapshot_source;

            $saleDetail->cost_unit_snapshot = round($sourceHpp, 6);
            $saleDetail->cost_total_snapshot = round($sourceHpp * $qty, 2);
            $saleDetail->cost_snapshot_source = 'HPP_SNAPSHOT_IMPORT';
            $saleDetail->cost_snapshot_at = now();
            $saleDetail->save();

            $resultMeta = [
                'raw_marker'          => $rawName,
                'clean_product_name'  => $cleanName,
                'owner_setting_id'    => $ownerId,
                'owner_setting_name'  => $ownerName,
                'matched_sale_id'     => $sale->id,
                'matched_sale_detail_id' => $saleDetail->id,
                'matched_product_name'=> $saleDetail->product_name,
                'matched_canonical_key' => $hppCanonicalKey,
                'match_method'        => $matchMethod,
                'match_score'         => round($matchScore, 2),
                'source_quantity'     => $mutasi,
                'imported_hpp'        => $sourceHpp,
                'previous_cost_unit'  => (float) $prevCostUnit,
                'previous_cost_total' => (float) $prevCostTotal,
                'previous_source'     => $prevSource,
                'after_cost_unit'     => (float) $saleDetail->cost_unit_snapshot,
                'after_cost_total'    => (float) $saleDetail->cost_total_snapshot,
            ];

            $this->recordHppSnapshotSuccess($row, $saleDetail->product_id, $resultMeta);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->recordFailure($row, 'Gagal update HPP: ' . $e->getMessage());
        }
    }

    private function appendSalesHppCoverageReportRows(): void
    {
        [$startDate, $endDate] = $this->resolveSalesHppImportDateRange();

        ProductImportRow::where('batch_id', $this->batch->id)
            ->where('status', 'skipped')
            ->where('error_message', 'like', 'Sales detail tidak memiliki HPP snapshot%')
            ->delete();

        if (!$startDate || !$endDate) {
            Log::warning('[ProductImportBatch] HPP coverage report skipped: no Sales Invoice date range', [
                'batch_id' => $this->batch->id,
            ]);
            return;
        }

        $nextRowNumber = ((int) ProductImportRow::where('batch_id', $this->batch->id)->max('row_number')) + 1;
        $now = now();
        $buffer = [];

        SaleDetails::query()
            ->select('sale_details.*')
            ->with(['sale:id,date,reference,imported_sales_reference_number,setting_id', 'product:id,product_name'])
            ->where(function ($query) {
                $query->whereNull('cost_snapshot_source')
                    ->orWhere('cost_snapshot_source', '!=', 'HPP_SNAPSHOT_IMPORT');
            })
            ->whereHas('sale', function ($query) use ($startDate, $endDate) {
                $query->whereDate('date', '>=', $startDate->toDateString())
                    ->whereDate('date', '<=', $endDate->toDateString());
            })
            ->orderBy('sale_id')
            ->orderBy('id')
            ->chunkById(500, function ($details) use (&$nextRowNumber, &$buffer, $now) {
                foreach ($details as $detail) {
                    $sale = $detail->sale;
                    $saleDate = $sale?->date ? Carbon::parse($sale->date)->toDateString() : null;
                    $buffer[] = [
                        'batch_id' => $this->batch->id,
                        'row_number' => $nextRowNumber++,
                        'raw_json' => json_encode([
                            'report_type' => 'sales_hpp_uncovered_sale_detail',
                            'sale_id' => $detail->sale_id,
                            'sale_detail_id' => $detail->id,
                            'sale_reference' => $sale?->reference,
                            'imported_sales_reference_number' => $sale?->imported_sales_reference_number,
                            'sale_date' => $saleDate,
                            'setting_id' => $sale?->setting_id,
                            'product_id' => $detail->product_id,
                            'product_name' => $detail->product_name,
                            'quantity' => (float) $detail->quantity,
                            'unit_price' => (float) $detail->unit_price,
                            'sub_total' => (float) $detail->sub_total,
                            'current_cost_snapshot_source' => $detail->cost_snapshot_source,
                        ]),
                        'status' => 'skipped',
                        'error_message' => 'Sales detail tidak memiliki HPP snapshot dalam periode import.',
                        'product_id' => $detail->product_id,
                        'result_metadata' => json_encode([
                            'report_type' => 'sales_hpp_uncovered_sale_detail',
                            'matched_sale_id' => $detail->sale_id,
                            'matched_sale_detail_id' => $detail->id,
                            'matched_product_name' => $detail->product_name,
                            'source_quantity' => (float) $detail->quantity,
                            'imported_sales_reference_number' => $sale?->imported_sales_reference_number,
                            'sale_reference' => $sale?->reference,
                            'sale_date' => $saleDate,
                            'owner_setting_id' => $sale?->setting_id,
                            'current_cost_snapshot_source' => $detail->cost_snapshot_source,
                        ]),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($buffer) >= 500) {
                        DB::table('product_import_rows')->insert($buffer);
                        $buffer = [];
                    }
                }
            }, 'sale_details.id', 'id');

        if (!empty($buffer)) {
            DB::table('product_import_rows')->insert($buffer);
        }
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveSalesHppImportDateRange(): array
    {
        $startDate = null;
        $endDate = null;

        ProductImportRow::where('batch_id', $this->batch->id)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$startDate, &$endDate) {
                foreach ($rows as $row) {
                    $payload = (array) $row->raw_json;
                    if (strtolower(trim((string) ($payload['tipe_transaksi'] ?? ''))) !== 'sales invoice') {
                        continue;
                    }

                    $date = $this->parseImportDate($payload['tanggal'] ?? null);
                    if (!$date) {
                        continue;
                    }

                    $startDate = $startDate ? $startDate->min($date) : $date->copy();
                    $endDate = $endDate ? $endDate->max($date) : $date->copy();
                }
            }, 'id');

        return [$startDate, $endDate];
    }

    private function parseImportDate(mixed $value): ?Carbon
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $raw)->startOfDay();
            } catch (\Throwable) {
                // Try next supported import date format.
            }
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function matchOrCreateProduct(array $payload, string $normalizedName): Product
    {
        $cleanName = trim(preg_replace('/^(?:\*\s*)?(.*?)(?:\s+TP)?$/i', '$1', $normalizedName));

        if (!empty($payload['product_code'])) {
            $product = Product::where('product_code', $payload['product_code'])->first();
            if ($product) {
                $dbNormalizedName = app(\Modules\Product\Services\ProductCanonicalizer::class)->canonicalize($product->product_name)['canonical_key'];
                $expectedNormalized = app(\Modules\Product\Services\ProductCanonicalizer::class)->canonicalize($cleanName)['canonical_key'];
                if ($dbNormalizedName !== $expectedNormalized) {
                    throw new \Exception("Code/name disagreement: Found ID {$product->id} '{$product->product_name}' but expected '{$cleanName}'.");
                }
            }
        }

        $resolver = app(\Modules\Product\Services\ProductResolver::class);
        $unitName = (string) ($payload['unit_name'] ?? 'Pcs');
        $codeInput = trim((string) ($payload['product_code'] ?? ''));
        $productCode = $codeInput !== '' ? $codeInput : null;

        $product = $resolver->resolveOrCreate($cleanName, function ($displayName, $canonicalKey) use ($unitName, $productCode) {
            $unitId = $this->firstOrCreateUnit($unitName);
            return [
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
            ];
        });

        if ($product->wasRecentlyCreated) {
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
        }

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
