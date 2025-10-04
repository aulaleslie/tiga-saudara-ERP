<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Product\Entities\ProductPrice;

use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;

use Modules\Product\Entities\Brand;      // adjust namespaces if Brand/Category live elsewhere
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class ProcessProductImportChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, Batchable;

    public function __construct(
        public                         $batchId,
        /** @var int[] */ public array $rowIds
    ) {}

    public function handle(): void
    {
        $batch    = ProductImportBatch::findOrFail($this->batchId);
        $location = Location::with('setting')->findOrFail($batch->location_id);
        $settingIdForCreations = $location->setting_id;
        $uploaderId = (int) $batch->user_id;

        // Cache lookups by lowercase name (avoid repeated queries)
        $cacheUnit = $cacheBrand = $cacheCategory = $cacheTax = [];

        // We need to replicate prices across ALL settings
        $allSettingIds = Setting::query()->pluck('id')->all();

        foreach ($this->rowIds as $rowId) {
            /** @var ProductImportRow $row */
            $row = ProductImportRow::findOrFail($rowId);
            $p   = $row->raw_json; // normalized keys from Preflight

            try {
                DB::transaction(function () use ($p, $row, $uploaderId, $settingIdForCreations, $location, $allSettingIds) {

                    // ---- helpers ----
                    $toInt  = fn($v) => (int) str_replace(',', '', trim((string) ($v ?? '0')));
                    $toDec  = fn($v) => (float) str_replace(',', '', trim((string) ($v ?? '0')));
                    $toBool = fn($v) => in_array(strtolower(trim((string) $v)), ['1','true','yes','y','ya'], true);

                    $getByName = function (string $name, string $table, string $col='name') {
                        return DB::table($table)->whereRaw("LOWER({$col}) = ?", [mb_strtolower(trim($name))])->first();
                    };

                    $firstOrCreateUnit = function (?string $name) use (&$cacheUnit, $settingIdForCreations) {
                        $n = trim((string) $name);
                        if ($n === '') return null;
                        $k = mb_strtolower($n);
                        if (isset($cacheUnit[$k])) return $cacheUnit[$k];

                        $row = Unit::whereRaw('LOWER(name) = ?', [$k])->first();
                        if (!$row) {
                            $row = Unit::create([
                                'name'       => $n,
                                'short_name' => $n,
                                'setting_id' => $settingIdForCreations, // keep consistent
                            ]);
                        }
                        return $cacheUnit[$k] = (int) $row->id;
                    };

                    $firstOrCreateBrand = function (?string $name) use (&$cacheBrand, $settingIdForCreations, $uploaderId) {
                        $n = trim((string) $name);
                        if ($n === '') return null;
                        $k = mb_strtolower($n);
                        if (isset($cacheBrand[$k])) return $cacheBrand[$k];

                        $row = Brand::whereRaw('LOWER(name) = ?', [$k])->first();
                        if (!$row) {
                            $row = Brand::create([
                                'name'       => $n,
                                'setting_id' => $settingIdForCreations, // required in many schemas
                                'created_by' => $uploaderId,
                            ]);
                        }
                        return $cacheBrand[$k] = (int) $row->id;
                    };

                    $firstOrCreateCategory = function (?string $name) use (&$cacheCategory, $settingIdForCreations, $uploaderId) {
                        $n = trim((string) $name);
                        if ($n === '') return null;
                        $k = mb_strtolower($n);
                        if (isset($cacheCategory[$k])) return $cacheCategory[$k];

                        $row = Category::whereRaw('LOWER(category_name) = ?', [$k])->first();
                        if (!$row) {
                            $row = Category::create([
                                'category_code' => Str::limit(Str::slug($n, '_'), 50),
                                'category_name' => $n,
                                'setting_id'    => $settingIdForCreations,
                                'created_by'    => $uploaderId,
                            ]);
                        }
                        return $cacheCategory[$k] = (int) $row->id;
                    };

                    $parseTaxPercent = function (?string $label) {
                        if (!$label) return null;
                        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*%?$/', $label, $m)) {
                            return (float) $m[1];
                        }
                        return null;
                    };

                    $firstOrCreateTax = function (?string $name) use (&$cacheTax, $parseTaxPercent) {
                        $n = trim((string) $name);
                        if ($n === '') return null;
                        $k = mb_strtolower($n);
                        if (isset($cacheTax[$k])) return $cacheTax[$k];

                        $row = Tax::whereRaw('LOWER(name) = ?', [$k])->first();
                        if (!$row) {
                            $row = Tax::create([
                                'name'       => $n,
                                'value'      => $parseTaxPercent($n) ?? 0.0,
                            ]);
                        }
                        return $cacheTax[$k] = (int) $row->id;
                    };

                    // ---- duplicate guard (DB) ----
                    $name = trim((string) ($p['product_name'] ?? ''));
                    $code = trim((string) ($p['product_code'] ?? ''));

                    if ($name === '') {
                        throw new \RuntimeException('Missing product name.');
                    }

                    $dupName = Product::whereRaw('LOWER(product_name) = ?', [mb_strtolower($name)])->exists();
                    $dupCode = $code !== '' ? Product::whereRaw('LOWER(product_code) = ?', [mb_strtolower($code)])->exists() : false;
                    if ($dupName || $dupCode) {
                        throw new \RuntimeException($dupName ? 'Duplicate product name (DB).' : 'Duplicate product code (DB).');
                    }

                    // ---- lookups ----
                    $categoryId = $firstOrCreateCategory($p['category_name'] ?? null);
                    $brandId    = $firstOrCreateBrand($p['brand_name'] ?? null);
                    $baseUnitId = $firstOrCreateUnit($p['base_unit_name'] ?? null);
                    if (!$baseUnitId) {
                        throw new \RuntimeException('Base unit is required.');
                    }

                    $saleTaxId     = $firstOrCreateTax($p['sale_tax_name'] ?? null);
                    $purchaseTaxId = $firstOrCreateTax($p['purchase_tax_name'] ?? null);

                    // ---- numbers/flags ----
                    $isSold      = $toBool($p['is_sold'] ?? false);
                    $isPurchased = $toBool($p['is_purchased'] ?? false);
                    $stockQty    = $toInt($p['stock'] ?? 0);

                    $salePrice = $toDec($p['sale_price'] ?? 0);
                    $tier1     = $toDec($p['tier_1_price'] ?? 0);
                    $tier2     = $toDec($p['tier_2_price'] ?? 0);
                    $buyPrice  = $toDec($p['purchase_price'] ?? 0);

                    // ---- create product (legacy price fields left at defaults) ----
                    $product = Product::create([
                        'product_name'           => $name,
                        'product_code'           => $code ?: null,
                        'barcode'                => $p['barcode'] ?? null,

                        'category_id'            => $categoryId,
                        'brand_id'               => $brandId,

                        'unit_id'                => $baseUnitId,
                        'base_unit_id'           => $baseUnitId,
                        'stock_managed'          => $toBool($p['stock_managed'] ?? true) ? 1 : 0,
                        'product_stock_alert'    => $toInt($p['min_stock'] ?? $p['minimum_stock'] ?? 0),

                        'product_quantity'       => $stockQty,
                        'serial_number_required' => 0,
                        'broken_quantity'        => 0,

                        // leave legacy pricing at defaults
                        'is_purchased'           => $isPurchased ? 1 : 0,
                        'purchase_price'         => 0,
                        'purchase_tax'           => null,
                        'is_sold'                => $isSold ? 1 : 0,
                        'sale_price'             => 0,
                        'tier_1_price'           => 0,
                        'tier_2_price'           => 0,
                        'sale_tax'               => null,
                        'last_purchase_price'    => 0,
                        'average_purchase_price' => 0,
                        'product_price'          => 0,
                        'product_cost'           => 0,
                        'product_order_tax'      => 0,
                        'product_tax_type'       => 0,
                    ]);

                    // ---- conversions (if any) ----
                    foreach (($p['conversions'] ?? []) as $conv) {
                        $uName = trim((string) ($conv['unit_name'] ?? ''));
                        if ($uName === '') continue;

                        $unitId = $firstOrCreateUnit($uName);
                        $factor = max(0.0001, (float) ($conv['factor'] ?? 0));
                        $price  = max(0, (float) ($conv['price'] ?? 0));
                        $bar    = trim((string) ($conv['barcode'] ?? ''));

                        ProductUnitConversion::create([
                            'product_id'        => $product->id,
                            'unit_id'           => $unitId,
                            'base_unit_id'      => $baseUnitId,
                            'conversion_factor' => $factor,
                            'price'             => $price,
                            'barcode'           => $bar ?: null,
                        ]);
                    }

                    // ---- prices for ALL settings (same values) ----
                    $rows = [];
                    foreach ($allSettingIds as $sid) {
                        $rows[] = [
                            'product_id'             => $product->id,
                            'setting_id'             => (int) $sid,
                            'sale_price'             => $isSold ? $salePrice : 0,
                            'tier_1_price'           => $isSold ? $tier1 : 0,
                            'tier_2_price'           => $isSold ? $tier2 : 0,
                            'last_purchase_price'    => $isPurchased ? $buyPrice : 0,
                            'average_purchase_price' => $isPurchased ? $buyPrice : 0,
                            'purchase_tax_id'        => $isPurchased ? $purchaseTaxId : null,
                            'sale_tax_id'            => $isSold ? $saleTaxId : null,
                            'created_at'             => now(),
                            'updated_at'             => now(),
                        ];
                    }
                    ProductPrice::upsert(
                        $rows,
                        ['product_id','setting_id'],
                        ['sale_price','tier_1_price','tier_2_price','last_purchase_price','average_purchase_price','purchase_tax_id','sale_tax_id','updated_at']
                    );

                    // ---- initial stock (selected location only) ----
                    if ($stockQty > 0) {
                        $txn = Transaction::create([
                            'product_id' => $product->id,
                            'setting_id' => $settingIdForCreations,
                            'type' => 'INIT',
                            'quantity' => $stockQty,
                            'previous_quantity' => 0,
                            'after_quantity' => $stockQty,
                            'previous_quantity_at_location' => 0,
                            'after_quantity_at_location' => 0,
                            'quantity_non_tax' => 0,
                            'quantity_tax' => 0,
                            'current_quantity' => $stockQty,
                            'broken_quantity' => 0,
                            'broken_quantity_non_tax' => 0,
                            'broken_quantity_tax' => 0,
                            'location_id' => $location->id,
                            'user_id' => $uploaderId,
                            'reason' => 'Initial stock setup from CSV import',
                        ]);

                        $stock = ProductStock::create([
                            'product_id' => $product->id,
                            'location_id' => $location->id,
                            'quantity' => $stockQty,
                            'quantity_non_tax' => 0,
                            'quantity_tax' => 0,
                            'broken_quantity_non_tax' => 0,
                            'broken_quantity_tax' => 0,
                            'sale_price' => $isSold ? $salePrice : 0,
                            'broken_quantity' => 0,
                        ]);

                        $row->created_txn_id  = $txn->id;
                        $row->created_stock_id= $stock->id;
                    }

                    // mark row imported
                    $row->status        = 'imported';
                    $row->product_id    = $product->id;
                    $row->error_message = null;
                    $row->save();
                });

                // counters
                ProductImportBatch::whereKey($this->batchId)->update([
                    'processed_rows' => DB::raw('processed_rows + 1'),
                    'success_rows'   => DB::raw('success_rows + 1'),
                ]);

            } catch (\Throwable $e) {
                $row->update(['status' => 'error', 'error_message' => $e->getMessage()]);

                ProductImportBatch::whereKey($this->batchId)->update([
                    'processed_rows' => DB::raw('processed_rows + 1'),
                    'error_rows'     => DB::raw('error_rows + 1'),
                ]);
            }
        }
    }
}
