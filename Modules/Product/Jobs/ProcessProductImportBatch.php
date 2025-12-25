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

    private function processRow(ProductImportRow $row): void
    {
        $payload = (array) $row->raw_json;

        $normalizedName = $this->normalizeProductName((string) ($payload['product_name'] ?? ''));
        if ($normalizedName === '') {
            $this->recordFailure($row, 'Nama produk wajib setelah normalisasi.');
            return;
        }

        $nameKey = mb_strtolower($normalizedName);
        if (isset($this->existingNameKeys[$nameKey]) || isset($this->seenNameKeys[$nameKey])) {
            $this->recordFailure($row, 'Produk dengan nama sama sudah ada.', 'skipped');
            return;
        }

        $unitName = trim((string) ($payload['unit_name'] ?? ''));
        if ($unitName === '') {
            $this->recordFailure($row, 'Satuan wajib diisi.');
            return;
        }

        $priceFloat = $this->parsePrice($payload['average_price'] ?? null);
        $price = $this->dec($priceFloat);
        $isPriced = $priceFloat > 0;

        $codeInput = trim((string) ($payload['product_code'] ?? ''));
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
                    'sale_price'             => $price,
                    'tier_1_price'           => $price,
                    'tier_2_price'           => $price,
                    'last_purchase_price'    => $price,
                    'average_purchase_price' => $price,
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
            $unit = Unit::firstOrCreate($attrs, $defaults);
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
}
