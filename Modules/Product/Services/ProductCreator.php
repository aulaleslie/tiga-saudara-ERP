<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Setting\Entities\Setting;
use Throwable;

class ProductCreator
{
    /**
     * Create a product using the same persistence path as Product Create page.
     *
     * @throws Throwable
     */
    public function create(array $validatedData): Product
    {
        $settingId = $this->resolveActiveSettingId();

        if (empty($validatedData['product_code'])) {
            $lastSku = Product::where('product_code', 'like', 'SKU-%')
                ->orderByRaw("CAST(SUBSTRING(product_code, 5) AS UNSIGNED) DESC")
                ->value('product_code');

            if ($lastSku) {
                $lastNumber = (int) substr($lastSku, 4);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }

            $validatedData['product_code'] = 'SKU-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
        }

        $settingIds = Setting::query()->pluck('id');
        if ($settingIds->isEmpty()) {
            $settingIds = collect([$settingId]);
        }

        $isPurchased = (bool) data_get($validatedData, 'is_purchased', false);
        $isSold = (bool) data_get($validatedData, 'is_sold', false);

        $incomingPrices = [
            'sale_price' => $isSold ? data_get($validatedData, 'sale_price', 0) : 0,
            'tier_1_price' => $isSold ? data_get($validatedData, 'tier_1_price', 0) : 0,
            'tier_2_price' => $isSold ? data_get($validatedData, 'tier_2_price', 0) : 0,
            'last_purchase_price' => $isPurchased ? data_get($validatedData, 'purchase_price', 0) : 0,
            'average_purchase_price' => 0,
            'purchase_tax_id' => $isPurchased
                ? data_get($validatedData, 'purchase_tax_id', data_get($validatedData, 'purchase_tax'))
                : null,
            'sale_tax_id' => $isSold
                ? data_get($validatedData, 'sale_tax_id', data_get($validatedData, 'sale_tax'))
                : null,
        ];

        $fieldsWithDefaults = [
            'product_quantity'        => 0,
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
        ];
        foreach ($fieldsWithDefaults as $field => $defaultValue) {
            $validatedData[$field] = $defaultValue;
        }

        $validatedData['product_stock_alert'] = (int) ($validatedData['product_stock_alert'] ?? 0);

        foreach (['brand_id', 'category_id', 'base_unit_id'] as $field) {
            if (empty($validatedData[$field])) {
                $validatedData[$field] = null;
            }
        }

        $validatedData['setting_id'] = $settingId;

        $documents = $validatedData['document'] ?? [];
        $conversions = $validatedData['conversions'] ?? [];
        unset($validatedData['document'], $validatedData['conversions'], $validatedData['location_id']);

        DB::beginTransaction();

        try {
            $product = Product::create($validatedData);

            if (!empty($validatedData['barcode'])) {
                $res = app(\Modules\Product\Services\BarcodeIdentityService::class)
                    ->reserve($validatedData['barcode'], $product->id);
                if (!$res['success']) {
                    throw new \Exception("Barcode sudah digunakan atau tidak valid: " . $validatedData['barcode']);
                }
            }

            ProductPrice::seedForSettings(
                $product->id,
                [
                    'sale_price'             => $incomingPrices['sale_price'] ?: 0,
                    'tier_1_price'           => $incomingPrices['tier_1_price'] ?: 0,
                    'tier_2_price'           => $incomingPrices['tier_2_price'] ?: 0,
                    'last_purchase_price'    => $incomingPrices['last_purchase_price'] ?: 0,
                    'average_purchase_price' => $incomingPrices['average_purchase_price'] ?: 0,
                    'purchase_tax_id'        => $incomingPrices['purchase_tax_id'] ?: null,
                    'sale_tax_id'            => $incomingPrices['sale_tax_id'] ?: null,
                ],
                $settingIds
            );

            if (! empty($documents)) {
                foreach ($documents as $file) {
                    $product->addMedia(Storage::path('temp/dropzone/' . $file))->toMediaCollection('images');
                }
            }

            if (! empty($conversions)) {
                foreach ($conversions as $conversion) {
                    if (empty($conversion['unit_id'])) {
                        continue;
                    }

                    $price = (float) ($conversion['price'] ?? 0);

                    $createdConversion = $product->conversions()->create([
                        'unit_id'           => $conversion['unit_id'] ?? null,
                        'base_unit_id'      => $validatedData['base_unit_id'],
                        'conversion_factor' => $conversion['conversion_factor'] ?? 0,
                        'barcode'           => $conversion['barcode'] ?? null,
                    ]);

                    if (!empty($conversion['barcode'])) {
                        $res = app(\Modules\Product\Services\BarcodeIdentityService::class)
                            ->reserve($conversion['barcode'], null, $createdConversion->id);
                        if (!$res['success']) {
                            throw new \Exception("Barcode konversi sudah digunakan atau tidak valid: " . $conversion['barcode']);
                        }
                    }

                    ProductUnitConversionPrice::seedForSettings(
                        $createdConversion->id,
                        $price,
                        $settingIds
                    );
                }
            }

            DB::commit();
            Log::info('Product created with prices stored for all settings.', [
                'product_id' => $product->id,
                'setting_ids' => $settingIds->all(),
            ]);

            return $product;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Gagal membuat Produk (replicate prices).', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function resolveActiveSettingId(): int
    {
        $user = auth()->user();

        return (int) (
            session('setting_id')
            ?? optional($user?->settings()->select('settings.id')->first())->id
            ?? Setting::query()->min('id')
        );
    }
}
