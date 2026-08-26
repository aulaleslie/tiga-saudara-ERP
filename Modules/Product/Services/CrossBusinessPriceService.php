<?php

namespace Modules\Product\Services;

use Modules\Setting\Entities\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Illuminate\Support\Carbon;
use Exception;

class CrossBusinessPriceService
{
    /**
     * Load prices for a product across all businesses (settings).
     * Defaults absent values to zero and includes version metadata.
     */
    public function loadPricesForProduct(Product $product): array
    {
        $settings = Setting::all();
        $existingPrices = ProductPrice::where('product_id', $product->id)
            ->get()
            ->keyBy('setting_id');

        $result = [];

        foreach ($settings as $setting) {
            $price = $existingPrices->get($setting->id);

            if ($price) {
                $result[] = [
                    'setting_id' => $setting->id,
                    'business_name' => $setting->company_name, // Assuming company_name exists, or whatever name field is used
                    'sale_price' => $price->sale_price,
                    'tier_1_price' => $price->tier_1_price,
                    'tier_2_price' => $price->tier_2_price,
                    'last_purchase_price' => $price->last_purchase_price,
                    'average_purchase_price' => $price->average_purchase_price,
                    'version' => $price->updated_at ? $price->updated_at->format('Y-m-d H:i:s.u') : null, // high precision version
                    'is_existing' => true,
                ];
            } else {
                $result[] = [
                    'setting_id' => $setting->id,
                    'business_name' => $setting->company_name,
                    'sale_price' => 0,
                    'tier_1_price' => 0,
                    'tier_2_price' => 0,
                    'last_purchase_price' => 0,
                    'average_purchase_price' => 0,
                    'version' => null,
                    'is_existing' => false,
                ];
            }
        }

        return $result;
    }

    /**
     * Save prices across all businesses with optimistic locking.
     */
    public function savePricesForProduct(Product $product, array $pricesData): void
    {
        $allSettings = Setting::pluck('id')->all();
        $submittedSettings = array_column($pricesData, 'setting_id');
        
        // Check exact match (no missing, no extra)
        if (count(array_diff($allSettings, $submittedSettings)) > 0 || count(array_diff($submittedSettings, $allSettings)) > 0) {
            throw new Exception("Submitted prices do not exactly match the current set of businesses. Please reload and try again.");
        }

        DB::transaction(function () use ($product, $pricesData) {
            $snapshots = [];
            $operationUuid = (string) \Illuminate\Support\Str::uuid();

            foreach ($pricesData as $data) {
                $settingId = $data['setting_id'];
                $existingPrice = ProductPrice::where('product_id', $product->id)
                    ->where('setting_id', $settingId)
                    ->lockForUpdate() // For preventing race conditions on create
                    ->first();

                if ($existingPrice) {
                    // Check version for optimistic locking
                    $submittedVersion = $data['version'] ?? null;
                    $currentVersion = $existingPrice->updated_at ? $existingPrice->updated_at->format('Y-m-d H:i:s.u') : null;

                    if ($submittedVersion !== $currentVersion) {
                        if (empty($submittedVersion)) {
                            throw new Exception("Price data changed. Reload and try again.");
                        }
                        throw new Exception("Price data for setting ID {$settingId} has been updated by another user. Please refresh and try again.");
                    }

                    $beforeSnapshot = [
                        'sale_price' => (float) $existingPrice->sale_price,
                        'tier_1_price' => (float) $existingPrice->tier_1_price,
                        'tier_2_price' => (float) $existingPrice->tier_2_price,
                        'last_purchase_price' => (float) $existingPrice->last_purchase_price,
                    ];

                    $existingPrice->update([
                        'sale_price' => $data['sale_price'],
                        'tier_1_price' => $data['tier_1_price'],
                        'tier_2_price' => $data['tier_2_price'],
                        'last_purchase_price' => $data['last_purchase_price'],
                        // average_purchase_price and tax IDs are NOT updated
                    ]);

                    $afterSnapshot = [
                        'sale_price' => (float) $data['sale_price'],
                        'tier_1_price' => (float) $data['tier_1_price'],
                        'tier_2_price' => (float) $data['tier_2_price'],
                        'last_purchase_price' => (float) $data['last_purchase_price'],
                    ];

                    $snapshots[] = [
                        'setting_id' => $settingId,
                        'before' => $beforeSnapshot,
                        'after' => $afterSnapshot,
                    ];
                } else {
                    if (!empty($data['version'])) {
                        // The user submitted a version for a row that doesn't exist? Stale state.
                        throw new Exception("Price data for setting ID {$settingId} is out of sync. Please refresh and try again.");
                    }

                    try {
                        ProductPrice::create([
                            'product_id' => $product->id,
                            'setting_id' => $settingId,
                            'sale_price' => $data['sale_price'],
                            'tier_1_price' => $data['tier_1_price'],
                            'tier_2_price' => $data['tier_2_price'],
                            'last_purchase_price' => $data['last_purchase_price'],
                            'average_purchase_price' => 0,
                            'sale_tax_id' => null,
                            'purchase_tax_id' => null,
                        ]);

                        $snapshots[] = [
                            'setting_id' => $settingId,
                            'before' => ['sale_price' => 0.0, 'tier_1_price' => 0.0, 'tier_2_price' => 0.0, 'last_purchase_price' => 0.0],
                            'after' => [
                                'sale_price' => (float) $data['sale_price'],
                                'tier_1_price' => (float) $data['tier_1_price'],
                                'tier_2_price' => (float) $data['tier_2_price'],
                                'last_purchase_price' => (float) $data['last_purchase_price'],
                            ],
                        ];
                    } catch (QueryException $e) {
                        // Convert unique (product_id, setting_id) constraint violation to user-facing conflict
                        if ($e->errorInfo[1] == 1062 || $e->getCode() == 23000) {
                            throw new Exception("Price data changed. Reload and try again.");
                        }
                        throw new Exception("An error occurred while saving price data. Please try again.");
                    }
                }
            }

            app(ProductPriceFeedRecorder::class)->record(
                \Modules\Product\Entities\ProductPriceFeedEvent::TYPE_PRODUCT_PRICE_UPDATED,
                \Modules\Product\Entities\ProductPriceFeedEvent::SUBJECT_PRODUCT,
                $product->id,
                $product->product_name,
                $product->product_code,
                $snapshots,
                \Modules\Product\Entities\ProductPriceFeedEvent::SOURCE_MANUAL,
                null,
                $operationUuid
            );
        });
    }
}
