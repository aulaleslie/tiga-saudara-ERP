<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Statement;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\Transaction;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Tax;
use Throwable;

class OrchestrateProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 min; adjust to your needs

    public function __construct(public int $batchId) {}

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        /** @var ProductImportBatch $batch */
        $batch = ProductImportBatch::findOrFail($this->batchId);

        $this->updateBatch($batch, ['status' => 'validating']);

        // 1) Parse + Preflight (header + in-file duplicates)
        [$headers, $rows] = $this->readCsv($batch->source_csv_path);
        $normalized = $this->normalizeRows($headers, $rows);

        // detect in-file duplicates (name/code, case-insensitive)
        $dupNames = $this->findDuplicates(array_column($normalized, 'product_name'));
        $dupCodes = $this->findDuplicates(array_column($normalized, 'product_code'));

        $this->updateBatch($batch, ['total_rows' => count($normalized)]);

        // Persist raw rows for audit/undo; mark immediate errors (in-file dups / required fields)
        DB::transaction(function () use ($batch, $normalized, $dupNames, $dupCodes) {
            foreach ($normalized as $i => $payload) {
                $error = $this->preflightRowError($payload, $dupNames, $dupCodes);
                ProductImportRow::create([
                    'batch_id'     => $batch->id,
                    'row_number'   => $payload['_row'] ?? ($i + 2), // +2 includes header
                    'raw_json'     => Arr::except($payload, ['_row']),
                    'status'       => $error ? 'error' : null,
                    'error_message'=> $error,
                ]);
            }
        });

        // 2) Processing (create lookups, create products, prices for ALL settings, init stock)
        $this->updateBatch($batch, ['status' => 'processing']);

        // Prepare caches
        $settings = Setting::query()->pluck('id')->all();
        $unitCache = []; $brandCache = []; $categoryCache = []; $taxCache = [];

        ProductImportRow::where('batch_id', $batch->id)
            ->orderBy('id')
            ->chunkById(250, function (EloquentCollection $chunk) use ($batch, $settings, &$unitCache, &$brandCache, &$categoryCache, &$taxCache) {
                foreach ($chunk as $row) {
                    if ($row->status === 'error') {
                        $this->bumpBatch($batch, 'error_rows');
                        $this->bumpProcessed($batch);
                        continue;
                    }

                    try {
                        DB::transaction(function () use ($row, $batch, $settings, &$unitCache, &$brandCache, &$categoryCache, &$taxCache) {
                            $p = $row->raw_json;

                            // === Lookups by NAME (global scope) ===
                            $categoryId = $this->firstOrCreateByName(Category::class, $p['category_name'] ?? null, $categoryCache);
                            $brandId    = $this->firstOrCreateByName(Brand::class,    $p['brand_name']    ?? null, $brandCache);
                            $baseUnitId = $this->firstOrCreateByName(Unit::class,     $p['base_unit_name']?? null, $unitCache);

                            $purchaseTaxId = $this->firstOrCreateByName(Tax::class,   $p['purchase_tax_name'] ?? null, $taxCache);
                            $saleTaxId     = $this->firstOrCreateByName(Tax::class,   $p['sale_tax_name']     ?? null, $taxCache);

                            // === DB duplicates === (global scope)
                            $nameExists = Product::whereRaw('LOWER(product_name) = ?', [mb_strtolower($p['product_name'])])->exists();
                            $codeExists = Product::whereRaw('LOWER(product_code) = ?', [mb_strtolower($p['product_code'])])->exists();
                            if ($nameExists || $codeExists) {
                                $msg = $nameExists ? 'Duplicate product name (DB)' : 'Duplicate product code (DB)';
                                $row->update(['status' => 'error', 'error_message' => $msg]);
                                $this->bumpBatch($batch, 'error_rows');
                                $this->bumpProcessed($batch);
                                return;
                            }

                            // === Create product (legacy price columns kept at defaults) ===
                            $product = Product::create([
                                'product_name'          => $p['product_name'],
                                'product_code'          => $p['product_code'],
                                'barcode'               => $p['barcode'] ?? null,
                                'category_id'           => $categoryId,
                                'brand_id'              => $brandId,
                                'stock_managed'         => (bool) ($p['stock_managed'] ?? false),
                                'serial_number_required'=> (bool) ($p['sn_required'] ?? false),
                                'base_unit_id'          => $baseUnitId,
                                'product_quantity'      => (int) ($p['stock'] ?? 0),
                                'product_stock_alert'   => (int) ($p['min_stock'] ?? 0),
                                // pricing legacy defaults:
                                'purchase_price'        => 0,
                                'purchase_tax_id'       => null,
                                'sale_price'            => 0,
                                'sale_tax_id'           => null,
                                'tier_1_price'          => 0,
                                'tier_2_price'          => 0,
                                'product_price'         => 0,
                                'product_cost'          => 0,
                                'product_order_tax'     => 0,
                                'product_tax_type'      => 0,
                                'profit_percentage'     => 0,
                            ]);

                            // === Conversions (if provided)
                            foreach (($p['conversions'] ?? []) as $conv) {
                                if (!empty($conv['unit_name'])) {
                                    $convUnitId = $this->firstOrCreateByName(Unit::class, $conv['unit_name'], $unitCache);
                                    ProductUnitConversion::create([
                                        'product_id'        => $product->id,
                                        'base_unit_id'      => $baseUnitId,
                                        'unit_id'           => $convUnitId,
                                        'conversion_factor' => (float) $conv['factor'],
                                        'barcode'           => $conv['barcode'] ?? null,
                                        'price'             => (float) $conv['price'],
                                    ]);
                                }
                            }

                            // === Prices for ALL settings (bulk upsert)
                            $rows = [];
                            $salePrice   = (float) ($p['sale_price']   ?? 0);
                            $tier1       = (float) ($p['tier_1_price'] ?? 0);
                            $tier2       = (float) ($p['tier_2_price'] ?? 0);
                            $buyPrice    = (float) ($p['purchase_price'] ?? 0);
                            $purchaseTax = $purchaseTaxId ?: null;
                            $saleTax     = $saleTaxId ?: null;

                            foreach ($settings as $sid) {
                                $rows[] = [
                                    'product_id'             => $product->id,
                                    'setting_id'             => (int) $sid,
                                    'sale_price'             => $salePrice,
                                    'tier_1_price'           => $tier1,
                                    'tier_2_price'           => $tier2,
                                    'last_purchase_price'    => $buyPrice,
                                    'average_purchase_price' => $buyPrice,
                                    'purchase_tax_id'        => $purchaseTax,
                                    'sale_tax_id'            => $saleTax,
                                    'created_at'             => now(),
                                    'updated_at'             => now(),
                                ];
                            }
                            ProductPrice::upsert(
                                $rows,
                                ['product_id','setting_id'],
                                ['sale_price','tier_1_price','tier_2_price','last_purchase_price','average_purchase_price','purchase_tax_id','sale_tax_id','updated_at']
                            );

                            // === Initial stock transaction (if stock provided) for the selected location
                            $txnId = $stockId = null;
                            $stockQty = (int) ($p['stock'] ?? 0);
                            if ($stockQty > 0) {
                                $txn = Transaction::create([
                                    'product_id'  => $product->id,
                                    'setting_id'  => null, // no longer scoping by session setting
                                    'type'        => 'INIT',
                                    'quantity'    => $stockQty,
                                    'previous_quantity' => 0,
                                    'after_quantity'    => $stockQty,
                                    'current_quantity'  => $stockQty,
                                    'broken_quantity'   => 0,
                                    'location_id'       => $batch->location_id,
                                    'user_id'           => $batch->user_id,
                                    'reason'            => 'Initial stock setup from CSV import',
                                ]);
                                $txnId = $txn->id;

                                $stock = ProductStock::create([
                                    'product_id'            => $product->id,
                                    'location_id'           => $batch->location_id,
                                    'quantity'              => $stockQty,
                                    'quantity_non_tax'      => 0,
                                    'quantity_tax'          => 0,
                                    'broken_quantity_non_tax'=> 0,
                                    'broken_quantity_tax'    => 0,
                                    'sale_price'            => $salePrice,
                                    'broken_quantity'       => 0,
                                ]);
                                $stockId = $stock->id;
                            }

                            $row->update([
                                'status'          => 'imported',
                                'error_message'   => null,
                                'product_id'      => $product->id,
                                'created_txn_id'  => $txnId,
                                'created_stock_id'=> $stockId,
                            ]);
                        });

                        $this->bumpBatch($batch, 'success_rows');
                    } catch (Throwable $e) {
                        Log::error('[ImportRow] Failed', ['row_id' => $row->id, 'e' => $e->getMessage()]);
                        $row->update(['status' => 'error', 'error_message' => $e->getMessage()]);
                        $this->bumpBatch($batch, 'error_rows');
                    } finally {
                        $this->bumpProcessed($batch);
                    }
                }
            });

        // 3) Finalize: write annotated CSV and set undo window (1 hour)
        $resultPath = $this->buildResultCsv($batch, $headers);
        $this->updateBatch($batch, [
            'status'               => 'completed',
            'result_csv_path'      => $resultPath,
            'completed_at'         => now(),
            'undo_available_until' => now()->addHour(), // 1 hour window
        ]);
    }

    // ---------- Helpers ----------

    protected function readCsv(string $storagePath): array
    {
        $full = Storage::path($storagePath);
        $csv  = Reader::createFromPath($full);
        $csv->setHeaderOffset(0);
        $stmt = (new Statement())->process($csv);
        $headers = $csv->getHeader(); // Indonesian headers from template
        $rows = iterator_to_array($stmt);
        return [$headers, $rows];
    }

    protected function normalizeRows(array $headers, array $rows): array
    {
        // Map Indonesian headers -> canonical keys
        $map = [
            'Nama Produk'       => 'product_name',
            'Kode Produk'       => 'product_code',
            'Barcode'           => 'barcode',
            'Nama Kategori'     => 'category_name',
            'Nama Merek'        => 'brand_name',
            'Kelola Stok'       => 'stock_managed',
            'Wajib Nomor Seri'  => 'sn_required',
            'Nama Unit Dasar'   => 'base_unit_name',
            'Stok'              => 'stock',
            'Stok Minimum'      => 'min_stock',
            'Dibeli'            => 'is_purchased',
            'Harga Beli'        => 'purchase_price',
            'Nama Pajak Beli'   => 'purchase_tax_name',
            'Dijual'            => 'is_sold',
            'Harga Jual'        => 'sale_price',
            'Harga Tier 1'      => 'tier_1_price',
            'Harga Tier 2'      => 'tier_2_price',
            'Nama Pajak Jual'   => 'sale_tax_name',
            // Conversions handled below
        ];

        $norm = [];
        foreach ($rows as $idx => $row) {
            $p = ['_row' => $idx + 2]; // +2: header + 1-based
            foreach ($map as $h => $k) {
                $p[$k] = array_key_exists($h, $row) ? trim((string)$row[$h]) : null;
            }
            // Coerce booleans and numerics
            $p['stock_managed'] = $this->toBool($p['stock_managed']);
            $p['sn_required']   = $this->toBool($p['sn_required']);
            $p['is_purchased']  = $this->toBool($p['is_purchased']);
            $p['is_sold']       = $this->toBool($p['is_sold']);

            foreach (['stock','min_stock','purchase_price','sale_price','tier_1_price','tier_2_price'] as $n) {
                $p[$n] = $this->toNumber($p[$n]);
            }

            // Conversions Konv{n}_
            $p['conversions'] = [];
            for ($i = 1; $i <= 5; $i++) {
                $u = trim((string)($row["Konv{$i}_NamaUnit"] ?? ''));
                if ($u === '') continue;
                $p['conversions'][] = [
                    'unit_name' => $u,
                    'factor'    => $this->toNumber($row["Konv{$i}_Faktor"] ?? 0),
                    'barcode'   => trim((string)($row["Konv{$i}_Barcode"] ?? '')),
                    'price'     => $this->toNumber($row["Konv{$i}_Harga"] ?? 0),
                ];
            }

            $norm[] = $p;
        }
        return $norm;
    }

    protected function preflightRowError(array $p, array $dupNames, array $dupCodes): ?string
    {
        if (blank($p['product_name']))   return 'Missing product name';
        if (blank($p['product_code']))   return 'Missing product code';

        // required prices if toggles true
        if ($p['is_purchased'] && $p['purchase_price'] <= 0) return 'PurchasePrice required (>0) when Dibeli=1';
        if ($p['is_sold'] && ($p['sale_price'] <= 0 || $p['tier_1_price'] <= 0 || $p['tier_2_price'] <= 0)) {
            return 'Sale/Tier1/Tier2 required (>0) when Dijual=1';
        }

        // in-file duplicates
        if (isset($dupNames[mb_strtolower($p['product_name'])])) return 'Duplicate product name (CSV)';
        if (isset($dupCodes[mb_strtolower($p['product_code'])])) return 'Duplicate product code (CSV)';

        // base unit required if stock managed
        if ($p['stock_managed'] && blank($p['base_unit_name'])) return 'Nama Unit Dasar required when Kelola Stok=1';

        // conversions must be valid if present
        foreach ($p['conversions'] as $i => $c) {
            if ($c['factor'] <= 0 || $c['price'] <= 0) {
                return "Konversi #".($i+1)." must have Faktor>0 and Harga>0";
            }
        }

        return null;
    }

    protected function buildResultCsv(ProductImportBatch $batch, array $headers): string
    {
        // append result columns
        $headersOut = array_merge($headers, ['Status','Error','ProductID']);

        // we’ll re-read the source file to preserve original column order/values
        [$headersIn, $rows] = $this->readCsv($batch->source_csv_path);

        $resultRelPath = "imports/products/{$batch->id}/result.csv";
        Storage::makeDirectory(dirname($resultRelPath));
        $fp = fopen(Storage::path($resultRelPath), 'w');

        fputcsv($fp, $headersOut);

        // Build a lookup by row_number
        $rowMap = ProductImportRow::where('batch_id', $batch->id)
            ->get(['row_number','status','error_message','product_id'])
            ->keyBy('row_number');

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2;
            $annot = $rowMap->get($rowNumber);
            $status = $annot->status ?? 'skipped';
            $error  = $annot->error_message ?? '';
            $pid    = $annot->product_id ?? '';

            // keep original order, then append
            $out = [];
            foreach ($headers as $h) {
                $out[] = $row[$h] ?? '';
            }
            $out[] = strtoupper($status);
            $out[] = $error;
            $out[] = $pid;
            fputcsv($fp, $out);
        }

        fclose($fp);
        return $resultRelPath;
    }

    // ----- tiny utils -----

    protected function toBool($v): bool
    {
        $v = trim((string)$v);
        return in_array(strtolower($v), ['1','true','on','yes','y'], true);
    }
    protected function toNumber($v): float
    {
        if ($v === null) return 0.0;
        $s = trim((string)$v);
        $s = str_replace([','], '', $s);
        return is_numeric($s) ? (float) $s : 0.0;
    }
    protected function findDuplicates(array $values): array
    {
        $seen = []; $dups = [];
        foreach ($values as $v) {
            $k = mb_strtolower(trim((string)$v));
            if ($k === '') continue;
            if (isset($seen[$k])) { $dups[$k] = true; }
            $seen[$k] = true;
        }
        return $dups;
    }

    protected function firstOrCreateByName(string $modelClass, ?string $name, array &$cache): ?int
    {
        $n = trim((string)$name);
        if ($n === '') return null;
        $key = mb_strtolower($n);
        if (isset($cache[$key])) return $cache[$key];

        $row = $modelClass::firstOrCreate(['name' => $n]); // adjust column if your table uses different field
        return $cache[$key] = $row->id;
    }

    protected function updateBatch(ProductImportBatch $batch, array $attrs): void
    {
        $batch->fill($attrs);
        $batch->save();
    }
    protected function bumpBatch(ProductImportBatch $batch, string $field): void
    {
        $batch->increment($field);
    }
    protected function bumpProcessed(ProductImportBatch $batch): void
    {
        $batch->increment('processed_rows');
    }
}
