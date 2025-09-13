<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

use Modules\Product\Entities\{
    ProductImportBatch,
    ProductImportRow,
    Product,
    ProductPrice,
    ProductStock,
    ProductSerialNumber,
    Category,
    Brand
};
use Modules\Setting\Entities\{ Setting, Unit, Tax };

class ProcessProductImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(public int $batchId) {}

    public function handle(): void
    {
        $batch = ProductImportBatch::findOrFail($this->batchId);
        $batch->update(['status' => 'processing']);

        // Use current session setting if present, fallback to any Setting
        $defaultSettingId = (int)(session('setting_id') ?: Setting::query()->value('id'));

        ProductImportRow::where('batch_id', $batch->id)
            ->orderBy('row_number')
            ->chunkById(200, function ($rows) use ($batch, $defaultSettingId) {
                foreach ($rows as $row) {
                    DB::beginTransaction();
                    try {
                        $p = (array) $row->raw_json;

                        // --- 1) Reference lookups ---
                        $categoryId = $this->firstOrCreateCategory($p['category_name'] ?? null);
                        $brandId    = $this->firstOrCreateBrand($p['brand_name'] ?? null);
                        $unitId     = $this->firstOrCreateUnit($p['base_unit_name'] ?? null);

                        // --- 2) Product upsert (by product_code) ---
                        $product = Product::updateOrCreate(
                            ['product_code' => $p['product_code'] ?: null],
                            [
                                'product_name'            => $p['product_name'],
                                'setting_id'              => $defaultSettingId,
                                'category_id'             => $categoryId,
                                'brand_id'                => $brandId,
                                'base_unit_id'            => $unitId,
                                'barcode'                 => $p['barcode'] ?: null,
                                'serial_number_required'  => (int)($p['serial_required'] ?? 0),
                                'stock_managed'           => (int)($p['stock_managed'] ?? 1),
                                'product_stock_alert'     => (int)($p['min_stock'] ?? 0),

                                // keep legacy price columns zero; real prices go to product_prices
                                'purchase_price'          => 0,
                                'sale_price'              => 0,
                                'tier_1_price'            => 0,
                                'tier_2_price'            => 0,
                                'purchase_tax_id'         => null,
                                'sale_tax_id'             => null,
                            ]
                        );

                        // --- 3) Product prices (per setting) ---
                        ProductPrice::updateOrCreate(
                            ['product_id' => $product->id, 'setting_id' => $defaultSettingId],
                            [
                                'sale_price'             => (int)($p['sale_price'] ?? 0),
                                'tier_1_price'           => (int)($p['tier_1_price'] ?? 0),
                                'tier_2_price'           => (int)($p['tier_2_price'] ?? 0),
                                'last_purchase_price'    => (int)($p['purchase_price'] ?? 0),
                                'average_purchase_price' => (int)($p['purchase_price'] ?? 0),
                                'purchase_tax_id'        => $this->taxIdByName($p['purchase_tax_name'] ?? null),
                                'sale_tax_id'            => $this->taxIdByName($p['sale_tax_name'] ?? null),
                            ]
                        );

                        // --- 4) Stock (for batch’s location only) ---
                        $stockId = null;
                        $qty = (int)($p['stock_qty'] ?? 0);
                        if ($qty > 0) {
                            $stock = ProductStock::firstOrNew([
                                'product_id'  => $product->id,
                                'location_id' => $batch->location_id,
                            ]);
                            $stock->quantity                 = $qty;
                            $stock->quantity_non_tax         = $qty;
                            $stock->quantity_tax             = 0;
                            $stock->broken_quantity          = 0;
                            $stock->broken_quantity_tax      = 0;
                            $stock->broken_quantity_non_tax  = 0;
                            $stock->save();
                            $stockId = $stock->id;
                        }

                        // --- 5) Serials (if required) ---
                        if (!empty($p['serial_required']) && !empty($p['serials'])) {
                            foreach ($p['serials'] as $sn) {
                                $sn = trim((string)$sn);
                                if ($sn === '') continue;
                                ProductSerialNumber::firstOrCreate(
                                    ['serial_number' => $sn],
                                    [
                                        'product_id'  => $product->id,
                                        'location_id' => $batch->location_id,
                                    ]
                                );
                            }
                        }

                        // --- 6) Row bookkeeping ---
                        $row->update([
                            'status'           => 'imported',
                            'product_id'       => $product->id,
                            'created_stock_id' => $stockId,
                        ]);

                        $batch->increment('processed_rows');
                        $batch->increment('success_rows');

                        DB::commit();
                    } catch (Throwable $e) {
                        DB::rollBack();

                        $row->update([
                            'status'        => 'error',
                            'error_message' => Str::limit($e->getMessage(), 2000),
                        ]);

                        $batch->increment('processed_rows');
                        $batch->increment('error_rows');
                    }
                }
            });

        $batch->update([
            'status' => 'completed',
            'completed_at' => now(),
            'undo_available_until' => now()->addHour(),
        ]);
    }

    private function firstOrCreateCategory(?string $name): ?int
    {
        if (!$name) return null;
        return Category::firstOrCreate(['category_name' => $name])->id;
    }

    private function firstOrCreateBrand(?string $name): ?int
    {
        if (!$name) return null;
        return Brand::firstOrCreate(['name' => $name])->id;
    }

    private function firstOrCreateUnit(?string $name): ?int
    {
        if (!$name) return null;
        return Unit::firstOrCreate(['name' => $name])->id;
    }

    private function taxIdByName(?string $name): ?int
    {
        if (!$name) return null;
        return Tax::where('name', $name)->value('id');
    }
}
