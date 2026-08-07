<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Product\Entities\ProductImportBatch;
use Throwable;

class ProcessSalesPriceSnapshotBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(public int $batchId) {}

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        Log::info('[SalesPriceSnapshotImport] Job started', ['batch_id' => $this->batchId]);

        $batch = ProductImportBatch::findOrFail($this->batchId);
        $batch->update(['status' => 'processing']);

        try {
            $existingRowCount = \Modules\Product\Entities\ProductImportRow::where('batch_id', $batch->id)->count();
            if ($existingRowCount === 0) {
                Log::info('[SalesPriceSnapshotImport] Staging rows from XLSX', ['batch_id' => $this->batchId]);
                $stagedCount = $this->stageRowsFromXlsx($batch);
                if ($stagedCount === null) {
                    return; // Batch failed during staging
                }
                Log::info('[SalesPriceSnapshotImport] Rows staged', ['batch_id' => $this->batchId, 'total_rows' => $stagedCount]);
            }

            // Phase 3 & 4: Processing rows
            $this->processRows($batch);

        } catch (Throwable $e) {
            Log::error('[SalesPriceSnapshotImport] Job failed', ['batch_id' => $this->batchId, 'error' => $e->getMessage()]);
            $batch->update(['status' => 'failed', 'error_message' => \Illuminate\Support\Str::limit($e->getMessage(), 1000)]);
            throw $e;
        }

        $batch->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        Log::info('[SalesPriceSnapshotImport] Job completed', ['batch_id' => $this->batchId]);
    }

    private function stageRowsFromXlsx(ProductImportBatch $batch): ?int
    {
        $path = $batch->source_csv_path;
        $fullPath = storage_path('app/' . $path);

        if (!file_exists($fullPath)) {
            $msg = 'XLSX file not found.';
            Log::error('[SalesPriceSnapshotImport] ' . $msg, ['batch_id' => $this->batchId, 'path' => $fullPath]);
            $batch->update(['status' => 'failed', 'error_message' => $msg]);
            return null;
        }

        try {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($fullPath);
            $worksheet = $spreadsheet->getActiveSheet();
        } catch (Throwable $e) {
            $msg = 'Failed to read XLSX file: ' . $e->getMessage();
            Log::error('[SalesPriceSnapshotImport] ' . $msg, ['batch_id' => $this->batchId]);
            $batch->update(['status' => 'failed', 'error_message' => $msg]);
            return null;
        }

        $rowIterator = $worksheet->getRowIterator();
        $headerMap = [];
        $isFirstRow = true;
        
        $insertBuffer = [];
        $batchSize = 500;
        $rowNo = 0;
        
        // Canonical aliases
        $aliases = [
            'name*'       => 'Name*',
            'productcode' => 'ProductCode',
            'sellprice'   => 'SellPrice',
            'stock'       => 'Stock',
            '*unit'       => '*Unit',
        ];

        foreach ($rowIterator as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            
            $rowData = [];
            foreach ($cellIterator as $cell) {
                $rowData[] = $cell->getFormattedValue(); // Get string as displayed if possible, else calculated
            }

            if ($isFirstRow) {
                // Normalize and validate headers
                $normalize = function (?string $h): string {
                    $h = preg_replace('/^\xEF\xBB\xBF/', '', (string)$h);
                    $h = trim(preg_replace('/\s+/', ' ', $h));
                    return mb_strtolower($h);
                };

                foreach ($rowData as $i => $val) {
                    $norm = $normalize($val);
                    if (isset($aliases[$norm])) {
                        $headerMap[$aliases[$norm]] = $i;
                    }
                }

                $required = ['Name*', 'SellPrice', 'Stock']; // ProductCode and *Unit are optional
                $missing = [];
                foreach ($required as $req) {
                    if (!isset($headerMap[$req])) {
                        $missing[] = $req;
                    }
                }

                if (!empty($missing)) {
                    $msg = 'Missing required columns in XLSX: ' . implode(', ', $missing);
                    Log::error('[SalesPriceSnapshotImport] ' . $msg, ['batch_id' => $this->batchId]);
                    $batch->update(['status' => 'failed', 'error_message' => $msg]);
                    return null;
                }

                $isFirstRow = false;
                continue;
            }

            // Data row
            $rowNo++;
            
            // Build raw JSON payload
            $payload = [
                'name*'       => isset($headerMap['Name*']) ? trim((string)$rowData[$headerMap['Name*']]) : null,
                'productcode' => isset($headerMap['ProductCode']) ? trim((string)$rowData[$headerMap['ProductCode']]) : null,
                'sellprice'   => isset($headerMap['SellPrice']) ? trim((string)$rowData[$headerMap['SellPrice']]) : null,
                'stock'       => isset($headerMap['Stock']) ? trim((string)$rowData[$headerMap['Stock']]) : null,
                '*unit'       => isset($headerMap['*Unit']) ? trim((string)$rowData[$headerMap['*Unit']]) : null,
            ];

            $insertBuffer[] = [
                'batch_id'   => $batch->id,
                'row_number' => $row->getRowIndex(),
                'raw_json'   => json_encode($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($insertBuffer) >= $batchSize) {
                \Illuminate\Support\Facades\DB::table('product_import_rows')->insert($insertBuffer);
                $insertBuffer = [];
            }
        }

        if (!empty($insertBuffer)) {
            \Illuminate\Support\Facades\DB::table('product_import_rows')->insert($insertBuffer);
        }

        $batch->update(['total_rows' => $rowNo]);

        return $rowNo;
    }

    private function processRows(ProductImportBatch $batch): void
    {
        $settingsMap = [];
        $settingsMapNames = [];
        $settingsPkpStatus = [];
        $settingsLocationMap = [];
        $settings = \Modules\Setting\Entities\Setting::all();
        foreach ($settings as $setting) {
            $settingsMap[strtoupper(trim($setting->company_name))] = $setting->id;
            $settingsMapNames[$setting->id] = $setting->company_name;
            $settingsPkpStatus[$setting->id] = $setting->is_pkp ?? false;
            $settingsLocationMap[$setting->id] = \Modules\Setting\Entities\Location::where('setting_id', $setting->id)->first();
        }

        // Build a flexible DAIZU settings resolver that matches DAIZU product companies
        $daizuSettingId = null;
        foreach ($settings as $setting) {
            $normalized = preg_replace('/[^\w\s]/', ' ', strtoupper(trim($setting->company_name)));
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            if (preg_match('/\bDAIZU\b/', $normalized)) {
                $daizuSettingId = $setting->id;
                break;
            }
        }

        $markerResolver = app(\App\Support\SalesImportMarkerResolver::class);
        $canonicalizer = app(\Modules\Product\Services\ProductCanonicalizer::class);

        $resolver = app(\Modules\Product\Services\ProductResolver::class);
        $resolvedCache = [];

        $validRows = [];

        \Modules\Product\Entities\ProductImportRow::where('batch_id', $batch->id)
            ->whereNull('status')
            ->chunkById(500, function ($rows) use ($batch, $settingsMap, $settingsMapNames, $settingsPkpStatus, $settingsLocationMap, $daizuSettingId, $markerResolver, $canonicalizer, $resolver, &$resolvedCache, &$validRows) {
                foreach ($rows as $row) {
                    $payload = is_array($row->raw_json) ? $row->raw_json : json_decode($row->raw_json, true);
                    $rawName = $payload['name*'] ?? '';
                    $productCode = $payload['productcode'] ?? '';
                    $sellPriceRaw = $payload['sellprice'] ?? null;
                    $stockRaw = $payload['stock'] ?? null;

                    $meta = [
                        'raw_product_name' => $rawName,
                        'imported_price' => $sellPriceRaw,
                    ];

                    // 1. Resolve owner setting ID using SalesImportMarkerResolver precedence
                    $settingId = null;

                    // Check if product is DAIZU (highest priority)
                    if ($markerResolver->isDaizuProduct($rawName)) {
                        if ($daizuSettingId) {
                            $settingId = $daizuSettingId;
                        }
                    } else {
                        // Use marker-based resolution
                        $companyName = $markerResolver->resolveOwnerCompanyName($rawName);
                        $settingId = $settingsMap[$companyName] ?? null;
                    }

                    if (!$settingId) {
                        $marker = $markerResolver->parseProductName($rawName)['marker'];
                        $msg = $markerResolver->isDaizuProduct($rawName)
                            ? "No DAIZU setting found"
                            : "Unmapped owner marker: {$marker}";
                        $this->recordFailure($batch, $row, $msg, 'error', $meta);
                        continue;
                    }

                    $meta['setting_id'] = $settingId;
                    $meta['owner_setting_name'] = $settingsMapNames[$settingId] ?? $companyName;

                    // Resolve target location for stock mutations
                    $location = $settingsLocationMap[$settingId] ?? null;
                    if (!$location) {
                        $this->recordFailure($batch, $row, "No location configured for owner: {$meta['owner_setting_name']}", 'error', $meta);
                        continue;
                    }
                    $meta['target_location_id'] = $location->id;
                    $meta['target_location_name'] = $location->name;
                    $meta['is_pkp'] = $settingsPkpStatus[$settingId] ?? false;

                    // 2. Parse Product
                    $parsed = $markerResolver->parseProductName($rawName);
                    $cleanName = $parsed['clean_name'];

                    $meta['clean_product_name'] = $cleanName;
                    $meta['raw_marker'] = $parsed['marker'];

                    $product = null;
                    $matchStrategy = null;

                    if ($productCode !== '') {
                        $product = \Modules\Product\Entities\Product::where('product_code', $productCode)->first();
                        if ($product) {
                            // Check code/name disagreement using canonical keys
                            try {
                                $incomingIdentity = $canonicalizer->canonicalize($cleanName);
                                $dbIdentity = $canonicalizer->canonicalize($product->product_name);
                                if ($incomingIdentity['canonical_key'] !== $dbIdentity['canonical_key']) {
                                    $meta['ambiguous_candidates'] = [
                                        ['id' => $product->id, 'name' => $product->product_name]
                                    ];
                                    $this->recordFailure($batch, $row, "Code/name disagreement: Found ID {$product->id} '{$product->product_name}' but expected '{$cleanName}'.", 'error', $meta);
                                    continue;
                                }
                            } catch (\InvalidArgumentException $e) {
                                $meta['ambiguous_candidates'] = [
                                    ['id' => $product->id, 'name' => $product->product_name]
                                ];
                                $this->recordFailure($batch, $row, "Code/name validation error: {$e->getMessage()}", 'error', $meta);
                                continue;
                            }
                            $matchStrategy = 'code';
                        }
                    }

                    if (!$product) {
                        if (!array_key_exists($rawName, $resolvedCache)) {
                            try {
                                $resolvedCache[$rawName] = $resolver->resolveExisting($rawName);
                            } catch (\Modules\Product\Exceptions\AmbiguousProductResolutionException $e) {
                                $resolvedCache[$rawName] = $e;
                            }
                        }

                        $product = $resolvedCache[$rawName];
                        if ($product instanceof \Exception) {
                            $this->recordFailure($batch, $row, "Ambiguous product name: " . $product->getMessage(), 'error', $meta);
                            continue;
                        }
                        if ($product) {
                            $matchStrategy = 'canonical_identity';
                        }
                    }

                    if (!$product) {
                        $this->recordFailure($batch, $row, "Product not found.", 'skipped', $meta);
                        continue;
                    }
                    
                    $meta['match_strategy'] = $matchStrategy;
                    $meta['matched_product_name'] = $product->product_name;

                    // 3. Resolve Price
                    $parsedPrice = \App\Support\AccurateDecimalParser::parse($sellPriceRaw);

                    if ($parsedPrice === null || $parsedPrice <= 0) {
                        $this->recordFailure($batch, $row, "Blank or non-positive sell price.", 'skipped', $meta);
                        continue;
                    }

                    if ($parsedPrice > 99999999.99) {
                        $this->recordFailure($batch, $row, "Sell price exceeds maximum limit (99,999,999.99).", 'error', $meta);
                        continue;
                    }

                    // 4. Resolve Stock (signed integer)
                    $parsedStock = \App\Support\AccurateDecimalParser::parseStock($stockRaw);

                    if ($parsedStock === null) {
                        $this->recordFailure($batch, $row, "Stock value is blank or invalid.", 'error', $meta);
                        continue;
                    }

                    $meta['imported_stock'] = $parsedStock;

                    // Success for Phase 3 (we stage the resolved data in memory for Phase 4)
                    $row->product_id = $product->id;
                    $meta['sell_price'] = $parsedPrice;
                    $row->result_metadata = $meta;
                    $validRows[] = $row;
                }
            });

        // Phase 4: Target Validation and Price Synchronization
        $this->applyMutations($batch, $validRows);
    }

    private function applyMutations(ProductImportBatch $batch, array $validRows): void
    {
        // Load all available settings dynamically once
        $allSettings = \Modules\Setting\Entities\Setting::all();
        $allSettingIds = $allSettings->pluck('id')->toArray();

        // 1. Group resolved rows by (product_id, setting_id) target
        $groups = [];
        foreach ($validRows as $row) {
            $productId = $row->product_id;
            $settingId = $row->result_metadata['setting_id'];
            $key = "{$productId}_{$settingId}";
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $row;
        }

        // 2. Validate conflicts and apply mutations
        foreach ($groups as $key => $groupRows) {
            $firstRow = $groupRows[0];
            $targetPrice = $firstRow->result_metadata['sell_price'];
            $targetStock = $firstRow->result_metadata['imported_stock'];
            $conflict = false;

            foreach ($groupRows as $row) {
                if ($row->result_metadata['sell_price'] !== $targetPrice ||
                    $row->result_metadata['imported_stock'] !== $targetStock) {
                    $conflict = true;
                    break;
                }
            }

            if ($conflict) {
                foreach ($groupRows as $row) {
                    $this->recordFailure($batch, $row, 'Conflicting duplicate prices or stock values for the same target.', 'error', $row->result_metadata ?? []);
                }
                continue;
            }

            $productId = $firstRow->product_id;
            $settingId = $firstRow->result_metadata['setting_id'];
            $locationId = $firstRow->result_metadata['target_location_id'];
            $isPkp = $firstRow->result_metadata['is_pkp'];

            try {
                \Illuminate\Support\Facades\DB::beginTransaction();

                // Apply price mutation
                $productPrice = \Modules\Product\Entities\ProductPrice::firstOrNew([
                    'product_id' => $productId,
                    'setting_id' => $settingId,
                ]);

                $prevTiers = [
                    'sale_price' => $productPrice->sale_price ?? 0,
                    'tier_1_price' => $productPrice->tier_1_price ?? 0,
                    'tier_2_price' => $productPrice->tier_2_price ?? 0,
                ];

                $priceTiersChanged = (
                    (float)$productPrice->sale_price !== (float)$targetPrice ||
                    (float)$productPrice->tier_1_price !== (float)$targetPrice ||
                    (float)$productPrice->tier_2_price !== (float)$targetPrice
                );

                $productPrice->sale_price = $targetPrice;
                $productPrice->tier_1_price = $targetPrice;
                $productPrice->tier_2_price = $targetPrice;

                if (!$productPrice->exists) {
                    $productPrice->last_purchase_price = 0;
                    $productPrice->average_purchase_price = 0;
                }
                $productPrice->save();

                // Seed missing price rows in other settings
                $this->seedMissingPricesForOtherSettings($productId, $settingId, $targetPrice, $allSettingIds);

                // Apply stock snapshot mutation
                $product = \Modules\Product\Entities\Product::findOrFail($productId);
                $stock = \Modules\Product\Entities\ProductStock::firstOrCreate([
                    'product_id' => $productId,
                    'location_id' => $locationId,
                ], [
                    'quantity' => 0,
                    'quantity_non_tax' => 0,
                    'quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                    'broken_quantity_tax' => 0,
                    'broken_quantity' => 0,
                ]);

                $prevStockQty = $stock->quantity;
                $prevQtyTax = $stock->quantity_tax;
                $prevQtyNonTax = $stock->quantity_non_tax;

                // Resolve latest ledger quantity for ADJ transaction
                $latestTxn = \Modules\Product\Entities\Transaction::where('product_id', $productId)
                    ->where('setting_id', $settingId)
                    ->where('location_id', $locationId)
                    ->latest('id')
                    ->first();

                $prevLedgerQtyLoc = $latestTxn ? (float)$latestTxn->after_quantity_at_location : 0.0;

                // Set stock in target PKP bucket
                $newQtyTax = $isPkp ? $targetStock : 0;
                $newQtyNonTax = $isPkp ? 0 : $targetStock;

                $stockDifference = $targetStock - $prevStockQty;
                $adjDifference = $targetStock - $prevLedgerQtyLoc;

                $diffQtyTax = $newQtyTax - $prevQtyTax;
                $diffQtyNonTax = $newQtyNonTax - $prevQtyNonTax;

                $stock->quantity = $targetStock;
                $stock->quantity_tax = $newQtyTax;
                $stock->quantity_non_tax = $newQtyNonTax;
                $stock->save();

                if ($stockDifference != 0) {
                    $product->product_quantity += $stockDifference;
                    $product->save();
                }

                // Create ADJ transaction
                $txn = \Modules\Product\Entities\Transaction::create([
                    'product_id' => $productId,
                    'setting_id' => $settingId,
                    'location_id' => $locationId,
                    'type' => 'ADJ',
                    'quantity' => $adjDifference,
                    'current_quantity' => $targetStock,
                    'previous_quantity' => $prevLedgerQtyLoc,
                    'after_quantity' => $targetStock,
                    'previous_quantity_at_location' => $prevLedgerQtyLoc,
                    'after_quantity_at_location' => $targetStock,
                    'quantity_tax' => $diffQtyTax,
                    'quantity_non_tax' => $diffQtyNonTax,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                    'user_id' => $batch->user_id,
                    'reason' => 'Sales Price & Stock Snapshot Import',
                ]);

                // Mark first row as imported, subsequent exact duplicates as skipped
                $isFirst = true;
                foreach ($groupRows as $row) {
                    $meta = $row->result_metadata ?? [];
                    $meta['prev_tiers'] = $prevTiers;
                    $meta['new_tiers'] = [
                        'sale_price' => $targetPrice,
                        'tier_1_price' => $targetPrice,
                        'tier_2_price' => $targetPrice,
                    ];
                    $meta['price_changed'] = $priceTiersChanged;
                    $meta['previous_quantity'] = $prevLedgerQtyLoc;
                    $meta['after_quantity'] = $targetStock;
                    $meta['delta_quantity'] = $adjDifference;
                    $meta['prev_quantity_tax'] = $prevQtyTax;
                    $meta['prev_quantity_non_tax'] = $prevQtyNonTax;
                    $meta['after_quantity_tax'] = $newQtyTax;
                    $meta['after_quantity_non_tax'] = $newQtyNonTax;
                    $meta['delta_quantity_tax'] = $diffQtyTax;
                    $meta['delta_quantity_non_tax'] = $diffQtyNonTax;
                    $meta['created_stock_id'] = $stock->id;

                    $status = $isFirst ? 'imported' : 'skipped';
                    if (!$isFirst) {
                        $meta['outcome'] = 'duplicate';
                    }

                    $row->forceFill([
                        'status' => $status,
                        'result_metadata' => $meta,
                        'created_txn_id' => $txn->id,
                        'created_stock_id' => $stock->id,
                        'error_message' => $status === 'skipped' ? 'Equivalent duplicate row.' : null,
                    ])->save();

                    $batch->increment('processed_rows');
                    if ($status === 'imported') {
                        $batch->increment('success_rows');
                    }
                    $isFirst = false;
                }

                \Illuminate\Support\Facades\DB::commit();
            } catch (Throwable $e) {
                \Illuminate\Support\Facades\DB::rollBack();
                Log::error('[SalesPriceSnapshotImport] Row mutation failed', [
                    'batch_id' => $batch->id,
                    'product_id' => $productId,
                    'setting_id' => $settingId,
                    'error' => $e->getMessage()
                ]);

                foreach ($groupRows as $row) {
                    $this->recordFailure($batch, $row, 'Database error during mutation: ' . \Illuminate\Support\Str::limit($e->getMessage(), 500), 'error', $row->result_metadata ?? []);
                }
            }
        }
    }

    private function seedMissingPricesForOtherSettings(int $productId, int $ownerSettingId, float $sellPrice, array $allSettingIds): void
    {
        // Get existing price rows for this product
        $existingRows = \Modules\Product\Entities\ProductPrice::where('product_id', $productId)
            ->whereIn('setting_id', $allSettingIds)
            ->pluck('setting_id')
            ->toArray();

        // For each setting that doesn't have a price row, create one with the imported price
        foreach ($allSettingIds as $settingId) {
            if (!in_array($settingId, $existingRows)) {
                \Modules\Product\Entities\ProductPrice::create([
                    'product_id' => $productId,
                    'setting_id' => $settingId,
                    'sale_price' => $sellPrice,
                    'tier_1_price' => $sellPrice,
                    'tier_2_price' => $sellPrice,
                    'last_purchase_price' => 0,
                    'average_purchase_price' => 0,
                ]);
            }
        }
    }

    private function recordFailure(ProductImportBatch $batch, \Modules\Product\Entities\ProductImportRow $row, string $message, string $status = 'error', array $meta = []): void
    {
        $row->forceFill([
            'status' => $status,
            'error_message' => $message,
            'result_metadata' => $meta,
        ])->save();
        $batch->increment('processed_rows');
        if ($status === 'error') {
            $batch->increment('error_rows');
        }
    }
}
