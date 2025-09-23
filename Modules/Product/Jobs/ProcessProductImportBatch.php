<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Log;
use RuntimeException;
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
use Modules\Setting\Entities\{Location, Setting, Unit, Tax};

class ProcessProductImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;
    private ProductImportBatch $batch;
    private Location $location;

    public function __construct(public int $batchId) {}

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $this->batch = ProductImportBatch::findOrFail($this->batchId);
        $this->location = $this->batch->location;
        $this->batch->update(['status' => 'processing']);

        // Use the current session setting if present, fallback to any Setting
        $defaultSettingId = $this->location->setting_id;
        $allSettingIds = Setting::query()->pluck('id')->map(fn($id) => (int)$id)->all();

        $batch = $this->batch;
        ProductImportRow::where('batch_id', $this->batch->id)
            ->whereNotIn('status', ['imported','error','skipped'])
            ->orderBy('row_number')
            ->chunkById(200, function ($rows) use ($batch, $defaultSettingId, $allSettingIds) {
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
                                'product_name'            => $p['product_name'], // strip leading '*' if present
                                'setting_id'              => (int) $defaultSettingId,
                                'category_id'             => $categoryId,
                                'brand_id'                => $brandId,     // ensure DB allows NULL; otherwise set a fallback brand id here
                                'base_unit_id'            => $unitId,      // ensure DB allows NULL; otherwise set a fallback unit id here
                                'barcode'                 => $p['barcode'] ?: null,
                                'serial_number_required'  => (int)($p['serial_required'] ?? 0),
                                'stock_managed'           => (int)($p['stock_managed'] ?? 1),
                                'product_stock_alert'     => (int)($p['min_stock'] ?? 0),

                                // ✅ REQUIRED to satisfy NOT NULL w/o default
                                'product_quantity'        => (int)($p['stock_qty'] ?? 0),

                                // keep legacy price columns zero; real prices go to product_prices
                                'product_cost'            => 0,
                                'product_order_tax'       => 0,
                                'product_tax_type'        => 0,
                                'profit_percentage'       => 0,
                                'purchase_price'          => 0,
                                'purchase_tax_id'         => null,   // or 'purchase_tax' => 0 if your column is boolean/legacy
                                'sale_price'              => 0,
                                'sale_tax_id'             => null,   // or 'sale_tax' => 0 if your column is boolean/legacy
                                'product_price'           => 0,
                                'last_purchase_price'     => 0,
                                'average_purchase_price'  => 0,
                            ]
                        );

                        // --- 3) Product prices (per setting) ---
                        foreach ($allSettingIds as $sid) {
                            ProductPrice::updateOrCreate(
                                ['product_id' => $product->id, 'setting_id' => $sid],
                                [
                                    'sale_price'             => (int)($p['sale_price'] ?? 0),
                                    'tier_1_price'           => (int)($p['tier_1_price'] ?? 0),
                                    'tier_2_price'           => (int)($p['tier_2_price'] ?? 0),
                                    'last_purchase_price'    => (int)($p['purchase_price'] ?? 0),
                                    'average_purchase_price' => (int)($p['purchase_price'] ?? 0),

                                    // If taxes are per-setting, resolve by setting.
                                    'purchase_tax_id'        => $this->taxIdByName($p['purchase_tax_name'] ?? null),
                                    'sale_tax_id'            => $this->taxIdByName($p['sale_tax_name'] ?? null),
                                ]
                            );
                        }

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

    private function firstOrCreateCategory(?string $name, ?string $preferredCode = null): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null; // or throw if a category is mandatory for your business rule
        }

        // 1) Try to find by name (optionally scoped)
        $q = Category::query()->where('category_name', $name);
        if ($existing = $q->value('id')) {
            return (int) $existing;
        }

        // 2) Build a base code
        $base = 'CAT';

        // Trim to column length (adjust to your schema, e.g., 32 or 50)
        $maxLen = 32;
        $base = substr($base, 0, $maxLen);

        // 3) Try to create with incremental suffix on conflict
        //    e.g., LAPTOP, LAPTOP_2, LAPTOP_3 ...
        $suffix = 0;
        $attempts = 0;
        $settingId = $this->location->setting_id;
        $createdBy = $this->batch->user_id ?? null;

        Log::info('firstOrCreateCategory', compact('name', 'preferredCode', 'settingId', 'createdBy'));

        do {
            $attempts++;
            $code = $suffix === 0 ? $base : substr($base, 0, max(1, $maxLen - (strlen((string)$suffix) + 1))) . '_' . $suffix;

            try {
                $payload = [
                    'category_code' => $code,
                    'category_name' => $name,
                ];
                if ($settingId !== null && Schema::hasColumn('categories', 'setting_id')) {
                    $payload['setting_id'] = $settingId;
                }

                if (Schema::hasColumn('categories', 'created_by') && $createdBy) {
                    $payload['created_by'] = $createdBy;
                }
                if (Schema::hasColumn('categories', 'updated_by') && $createdBy) {
                    $payload['updated_by'] = $createdBy;
                }

                $category = Category::create($payload); // relies on DB unique constraints
                return (int) $category->id;
            } catch (QueryException $e) {
                // 23,000 = integrity constraint violation (duplicate key)
                if ($e->getCode() !== '23000') {
                    throw $e; // real error
                }
                // Check if someone else created the same NAME in parallel — reuse it.
                $q2 = Category::query()->where('category_name', $name);
                if ($settingId !== null && Schema::hasColumn('categories', 'setting_id')) {
                    $q2->where('setting_id', $settingId);
                }
                if ($id = $q2->value('id')) {
                    return (int) $id;
                }
                // Otherwise, the CODE collided; bump suffix and retry
                $suffix++;
            }
        } while ($attempts < 25);

        // If we somehow exhausted attempts, surface a meaningful error
        throw new RuntimeException("Unable to generate a unique category_code for '$name'.");
    }

    private function firstOrCreateBrand(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') return null;

        $settingId = $this->location->setting_id ?? null;
        $createdBy = $this->batch->user_id ?? null;

        // Uniqueness keys
        $attrs = ['name' => $name];
        if ($settingId !== null && Schema::hasColumn('brands', 'setting_id')) {
            $attrs['setting_id'] = $settingId;
        }

        // Defaults for create
        $defaults = [];
        if ($createdBy) {
            if (Schema::hasColumn('brands', 'created_by')) $defaults['created_by'] = $createdBy;
            if (Schema::hasColumn('brands', 'updated_by')) $defaults['updated_by'] = $createdBy;
        }

        // Create or fetch
        try {
            $brand = Brand::firstOrCreate($attrs, $defaults);
        } catch (MassAssignmentException $e) {
            // If model fillable is strict, force fill
            $brand = (new Brand())->forceFill(array_merge($attrs, $defaults));
            $brand->save();
        }

        // Backfill setting_id if the row existed without it
        if (
            $settingId !== null &&
            Schema::hasColumn('brands', 'setting_id') &&
            empty($brand->setting_id)
        ) {
            $brand->setting_id = $settingId;
            if ($createdBy && Schema::hasColumn('brands', 'updated_by')) {
                $brand->updated_by = $createdBy;
            }
            $brand->save();
        }

        return (int) $brand->id;
    }

    private function firstOrCreateUnit(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') return null;

        // Prefer the batch/location’s setting if present
        $settingId = $this->location->setting_id;

        // Uniqueness keys
        $attrs = ['name' => $name];
        if ($settingId !== null && Schema::hasColumn('units', 'setting_id')) {
            $attrs['setting_id'] = $settingId;
        }

        // Defaults on create
        $defaults = [];
        if (Schema::hasColumn('units', 'short_name')) {
            $defaults['short_name'] = $name;   // short_name mirrors name on creation
        }

        // Create or fetch
        try {
            $unit = Unit::firstOrCreate($attrs, $defaults);
        } catch (MassAssignmentException $e) {
            // Fallback if model fillable is strict
            $unit = (new Unit())->forceFill(array_merge($attrs, $defaults));
            $unit->save();
        }

        // Backfill short_name for existing rows that don't have it
        if (
            Schema::hasColumn('units', 'short_name')
            && (empty($unit->short_name) || trim((string)$unit->short_name) === '')
        ) {
            $unit->short_name = $name;
            $unit->save();
        }

        return (int) $unit->id;
    }

    private function taxIdByName(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        // Use the batch/location setting by default; fallback to any Setting
        $settingId = $this->location->setting_id;

        // Attributes that define uniqueness
        $attrs = ['name' => $name];
        if (Schema::hasColumn('taxes', 'setting_id')) {
            $attrs['setting_id'] = $settingId;
        }

        // Defaults for a new record (only set what your table actually has)
        $defaults = [];
        if (Schema::hasColumn('taxes', 'rate')) {
            $defaults['rate'] = $this->parsePercentFromName($name); // e.g. "PPN 12%" -> 12
        }
        if (Schema::hasColumn('taxes', 'type')) {
            $defaults['type'] = 'percentage'; // adjust if your schema uses another enum/value
        }
        if (Schema::hasColumn('taxes', 'is_active')) {
            $defaults['is_active'] = 1;
        }
        if (Schema::hasColumn('taxes', 'code')) {
            $defaults['code'] = Str::upper(Str::slug($name, '_'));
        }
        $createdBy = $this->batch->user_id ?? null;
        if ($createdBy) {
            if (Schema::hasColumn('taxes', 'created_by')) $defaults['created_by'] = $createdBy;
            if (Schema::hasColumn('taxes', 'updated_by')) $defaults['updated_by'] = $createdBy;
        }

        // Prefer Eloquent firstOrCreate (if fillable is set up). Fallback to forceFill.
        try {
            $tax = Tax::firstOrCreate($attrs, $defaults);
        } catch (MassAssignmentException $e) {
            $tax = (new Tax())->forceFill(array_merge($attrs, $defaults));
            $tax->save();
        }

        return (int) $tax->id;
    }

    // Helper: extract percentage (int) from strings like "PPN 12%" / "VAT 10.5%"
    private function parsePercentFromName(string $s): int
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $s, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            return (int) $val;
        }
        return 0;
    }
}
