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

        Log::info('Processing batch', compact('batch'));

        ProductImportRow::where('batch_id', $this->batch->id)
            ->where(function ($q) {
                $q->whereNull('status');
            })
            // Use the primary key for stable pagination; adjust chunk size if needed
            ->chunkById(100, function ($rows) use ($batch, $defaultSettingId, $allSettingIds) {
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
                                'setting_id'              => (int) $defaultSettingId,
                                'category_id'             => $categoryId,
                                'brand_id'                => $brandId,
                                'base_unit_id'            => $unitId,
                                'barcode'                 => $p['barcode'] ?: null,
                                'serial_number_required'  => (int)($p['serial_required'] ?? 0),
                                'stock_managed'           => (int)($p['stock_managed'] ?? 1),
                                'product_stock_alert'     => (int)($p['min_stock'] ?? 0),

                                // required (no default) columns
                                'product_quantity'        => (int)($p['stock_qty'] ?? 0),

                                // legacy price cols (kept zero)
                                'product_cost'            => 0,
                                'product_order_tax'       => 0,
                                'product_tax_type'        => 0,
                                'profit_percentage'       => 0,
                                'purchase_price'          => 0,
                                'purchase_tax_id'         => null,
                                'sale_price'              => 0,
                                'sale_tax_id'             => null,
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
                                    'sale_price'             => $this->dec($p['sale_price'] ?? null),
                                    'tier_1_price'           => $this->dec($p['tier_1_price'] ?? null),
                                    'tier_2_price'           => $this->dec($p['tier_2_price'] ?? null),
                                    'last_purchase_price'    => $this->dec($p['purchase_price'] ?? null),
                                    'average_purchase_price' => $this->dec($p['purchase_price'] ?? null),
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
                        $row->forceFill([
                            'status'           => 'imported',
                            'product_id'       => $product->id,
                            'created_stock_id' => $stockId,
                            'error_message'    => null,
                        ])->save();

                        $batch->increment('processed_rows');
                        $batch->increment('success_rows');

                        $processedRows = $batch->processed_rows;
                        DB::commit();

                        Log::info('Imported row', compact('processedRows'));

                        // help GC on long runs
                        unset($p, $product, $stock);
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                    } catch (Throwable $e) {
                        DB::rollBack();

                        $row->forceFill([
                            'status'        => 'error',
                            'error_message' => Str::limit($e->getMessage(), 2000),
                        ])->save();

                        $batch->increment('processed_rows');
                        $batch->increment('error_rows');
                    }
                }
            }, 'id');

        $batch->update([
            'status' => 'completed',
            'completed_at' => now(),
            'undo_available_until' => now()->addHour(),
        ]);
    }

    private function dec($v): string
    {
        if ($v === null || $v === '') return '0.00';
        $val = (float) $v;                   // CSV already numeric
        // Optional clamp to DECIMAL(10,2) max (8 digits before dot):
        if ($val > 99999999.99) $val = 99999999.99;
        if ($val < -99999999.99) $val = -99999999.99;
        return number_format($val, 2, '.', ''); // -> "1234.50"
    }

    private function firstOrCreateCategory(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') return null;

        // Case-insensitive match on name (optionally scoped by setting_id)
        $q = Category::query()
            ->whereRaw('LOWER(category_name) = ?', [mb_strtolower($name)]);

        if ($existing = $q->value('id')) {
            return (int) $existing;
        }

        // Build code from the name, e.g. "ALL IN ONE PC" -> "ALL_IN_ONE_PC"
        $maxLen   = 32;
        $base     = Str::upper(Str::slug($name, '_'));
        if ($base === '') $base = 'CAT';
        $base     = substr($base, 0, $maxLen);

        $settingId = $this->location->setting_id ?? null;
        $createdBy = $this->batch->user_id ?? null;

        // Try with incremental suffix on code collision
        for ($suffix = 0; $suffix < 50; $suffix++) {
            $code = $suffix === 0
                ? $base
                : substr($base, 0, max(1, $maxLen - (strlen((string)$suffix) + 1))) . '_' . $suffix;

            try {
                $payload = [
                    'category_code' => $code,
                    'category_name' => $name,
                ];
                if ($settingId !== null && Schema::hasColumn('categories', 'setting_id')) {
                    $payload['setting_id'] = $settingId;
                }
                if ($createdBy && Schema::hasColumn('categories', 'created_by')) $payload['created_by'] = $createdBy;
                if ($createdBy && Schema::hasColumn('categories', 'updated_by')) $payload['updated_by'] = $createdBy;

                $category = Category::create($payload);
                return (int) $category->id;

            } catch (QueryException $e) {
                // 23000 = integrity/duplicate key
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                // Re-check by name in case another worker created it concurrently
                $check = Category::query()
                    ->whereRaw('LOWER(category_name) = ?', [mb_strtolower($name)])
                    ->value('id');
                if ($check) return (int) $check;

                // else, bump suffix and retry
            }
        }

        throw new RuntimeException("Unable to generate a unique category_code for '$name'.");
    }

    private function firstOrCreateBrand(?string $name): ?int
    {
        // 0) Normalize & guard
        $name = (string) $name;
        $name = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $name); // non-breaking spaces -> space
        $name = preg_replace('/\s+/u', ' ', $name); // collapse whitespace
        $name = ltrim($name, "* \t\n\r\0\x0B");     // strip leading "*" and spaces
        $name = trim($name);

        if ($name === '' || in_array(mb_strtolower($name), ['-', 'n/a', 'na', 'none', 'tidak ada'], true)) {
            return null;
        }

        $settingId = $this->location->setting_id ?? null;
        $createdBy = $this->batch->user_id ?? null;

        // 1) Case-insensitive lookup by name, optionally scoped by setting
        $q = Brand::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);

        if ($id = $q->value('id')) {
            return (int) $id;
        }

        // 2) Prepare payload & (optional) deterministic brand_code if column exists
        $payload = ['name' => $name];

        if ($settingId !== null && Schema::hasColumn('brands', 'setting_id')) {
            $payload['setting_id'] = $settingId;
        }
        if ($createdBy) {
            if (Schema::hasColumn('brands', 'created_by')) $payload['created_by'] = $createdBy;
            if (Schema::hasColumn('brands', 'updated_by')) $payload['updated_by'] = $createdBy;
        }

        $hasCode = Schema::hasColumn('brands', 'brand_code');
        $maxLen  = 32;
        $base    = Str::upper(Str::slug($name, '_')) ?: 'BRAND';
        $base    = substr($base, 0, $maxLen);

        // 3) Try to create; if a code column exists, handle code collisions with suffixes
        for ($suffix = 0; $suffix < 50; $suffix++) {
            $data = $payload;

            if ($hasCode) {
                $code = $suffix === 0
                    ? $base
                    : substr($base, 0, max(1, $maxLen - (strlen((string)$suffix) + 1))) . '_' . $suffix;
                $data['brand_code'] = $code;
            }

            try {
                // Prefer normal create to honor mass assignment (catch & fallback to forceFill)
                try {
                    $brand = Brand::create($data);
                } catch (MassAssignmentException $e) {
                    $brand = (new Brand())->forceFill($data);
                    $brand->save();
                }
                return (int) $brand->id;

            } catch (QueryException $e) {
                // 23000 = duplicate keys (either unique name or unique code)
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                // Recheck by normalized name (race with another worker)
                $check = Brand::query()
                    ->when($settingId !== null && Schema::hasColumn('brands', 'setting_id'), function ($q) use ($settingId) {
                        $q->where('setting_id', $settingId);
                    })
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->value('id');
                if ($check) return (int) $check;

                // If collision was on brand_code, loop and try the next suffix
                if (!$hasCode) {
                    // No code column; collision must be on a unique name key—return that row
                    $existingId = Brand::query()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                        ->value('id');
                    if ($existingId) return (int) $existingId;
                    // Otherwise we hit some other constraint—rethrow
                    throw $e;
                }
                // else: continue loop to bump suffix
            }
        }

        throw new RuntimeException("Unable to generate a unique brand_code for '$name'.");
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
        if ($name === '') return null;

        // Apply your business rules (PPN 12% -> 11, bare PPN -> 10)
        [$canonicalName, $rate] = $this->resolveTaxNameAndRate($name);

        $settingId = $this->location->setting_id;

        // Unique keys
        $attrs = ['name' => $canonicalName];
        if (Schema::hasColumn('taxes', 'setting_id')) {
            $attrs['setting_id'] = $settingId;
        }

        // Defaults/updates
        $data = [];

        // IMPORTANT: support either column the schema uses
        if (Schema::hasColumn('taxes', 'rate')) {
            $data['rate'] = $rate;
        } elseif (Schema::hasColumn('taxes', 'value')) {
            $data['value'] = $rate; // <-- your schema uses this
        }

        if (Schema::hasColumn('taxes', 'type'))       $data['type'] = 'percentage';
        if (Schema::hasColumn('taxes', 'is_active'))  $data['is_active'] = 1;
        if (Schema::hasColumn('taxes', 'code'))       $data['code'] = Str::upper(Str::slug($canonicalName, '_'));

        $createdBy = $this->batch->user_id ?? null;
        if ($createdBy) {
            if (Schema::hasColumn('taxes', 'created_by')) $data['created_by'] = $createdBy;
            if (Schema::hasColumn('taxes', 'updated_by')) $data['updated_by'] = $createdBy;
        }

        // Case-insensitive fetch to avoid dupes by casing
        $q = Tax::query()
            ->when(Schema::hasColumn('taxes','setting_id'), fn($q) => $q->where('setting_id', $settingId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($canonicalName)]);
        if ($id = $q->value('id')) {
            // Update value/rate if changed
            try {
                Tax::where('id', $id)->update($data);
            } catch (MassAssignmentException $e) {
                Tax::where('id', $id)->first()->forceFill($data)->save();
            }
            return (int) $id;
        }

        try {
            $tax = Tax::updateOrCreate($attrs, $data);
        } catch (MassAssignmentException $e) {
            $tax = (new Tax())->forceFill(array_merge($attrs, $data));
            $tax->save();
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $id = $q->value('id');
                if ($id) return (int) $id;
            }
            throw $e;
        }

        return (int) $tax->id;
    }

    /** Business rules: "PPN 12%" -> ["PPN 11%", 11], "PPN" -> ["PPN 10%", 10], otherwise parse percent */
    private function resolveTaxNameAndRate(string $raw): array
    {
        $upper = mb_strtoupper(trim($raw));
        $pct = $this->extractPercent($raw); // float|null

        if (preg_match('/\bPPN\b/u', $upper) && $pct !== null && (int)$pct === 12) {
            return ['PPN 11%', 11];
        }
        if ($upper === 'PPN') {
            return ['PPN 10%', 10];
        }
        if ($pct !== null) {
            return [$raw, (int)$pct];
        }
        return [$raw, 0];
    }

    private function extractPercent(string $s): ?float
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $s, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }
        return null;
    }
}
