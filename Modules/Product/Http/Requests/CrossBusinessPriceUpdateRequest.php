<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Setting;

class CrossBusinessPriceUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Gate::allows('products.manage_cross_business_prices');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'prices' => 'required|array|min:1',
            'prices.*.setting_id' => 'required|integer|exists:settings,id|distinct',
            'prices.*.sale_price' => 'required|numeric|decimal:0,2|min:0',
            'prices.*.tier_1_price' => 'required|numeric|decimal:0,2|min:0',
            'prices.*.tier_2_price' => 'required|numeric|decimal:0,2|min:0',
            'prices.*.last_purchase_price' => 'required|numeric|decimal:0,2|min:0',
            'prices.*.version' => 'nullable|string',

            'conversions' => 'nullable|array',
            'conversions.*.setting_id' => 'required|integer|exists:settings,id',
            'conversions.*.conversion_id' => 'required|integer',
            'conversions.*.price' => 'nullable|numeric|decimal:0,2|min:0',
            'conversions.*.version' => 'nullable|string',

            'conversion_snapshot' => 'nullable|string',
            'conversion_snapshot_signature' => 'nullable|string',

            'bundles' => 'nullable|array',
            'bundles.*.bundle_id' => 'required|integer',
            'bundles.*.setting_id' => 'required|integer|exists:settings,id',
            'bundles.*.replica_group_uuid' => 'required|string',
            'bundles.*.bundle_sale_price' => 'required|numeric|decimal:0,2|min:0',
            'bundles.*.version' => 'nullable|string',

            'bundle_snapshot' => 'nullable|string',
            'bundle_snapshot_signature' => 'nullable|string',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $product = $this->route('product');
            if (!$product) {
                return;
            }

            // Verify unique pairs of (setting_id, conversion_id)
            $conversionsInput = $this->input('conversions', []);
            if (!empty($conversionsInput)) {
                $pairs = [];
                foreach ($conversionsInput as $idx => $item) {
                    $settingId = $item['setting_id'] ?? null;
                    $conversionId = $item['conversion_id'] ?? null;
                    $key = "{$settingId}_{$conversionId}";
                    if (isset($pairs[$key])) {
                        $v->errors()->add("conversions.{$idx}.conversion_id", "Duplikat identitas konversi dan bisnis terdeteksi.");
                    }
                    $pairs[$key] = true;
                }

                // Verify conversions belong to product
                $productConversionIds = ProductUnitConversion::where('product_id', $product->id)->pluck('id')->all();
                foreach ($conversionsInput as $idx => $item) {
                    $cId = (int) ($item['conversion_id'] ?? 0);
                    if (!in_array($cId, $productConversionIds, true)) {
                        $v->errors()->add("conversions.{$idx}.conversion_id", "Konversi unit ID {$cId} bukan milik produk ini.");
                    }
                }
            }

            // Verify unique bundle_ids and unique pairs of (setting_id, replica_group_uuid)
            $bundlesInput = $this->input('bundles', []);
            if (!empty($bundlesInput)) {
                $seenBundleIds = [];
                $settingGroupPairs = [];
                foreach ($bundlesInput as $idx => $item) {
                    $bId = $item['bundle_id'] ?? null;
                    $settingId = $item['setting_id'] ?? null;
                    $uuid = $item['replica_group_uuid'] ?? null;

                    if ($bId !== null) {
                        if (isset($seenBundleIds[$bId])) {
                            $v->errors()->add("bundles.{$idx}.bundle_id", "Duplikat identitas paket terdeteksi.");
                        }
                        $seenBundleIds[$bId] = true;
                    }

                    if ($settingId !== null && $uuid !== null) {
                        $key = "{$settingId}_{$uuid}";
                        if (isset($settingGroupPairs[$key])) {
                            $v->errors()->add("bundles.{$idx}.replica_group_uuid", "Duplikat grup paket untuk bisnis yang sama terdeteksi.");
                        }
                        $settingGroupPairs[$key] = true;
                    }
                }

                // Verify bundles belong to product and have non-null replica group
                $productBundleIds = \Modules\Product\Entities\ProductBundle::where('parent_product_id', $product->id)
                    ->whereNotNull('replica_group_uuid')
                    ->where('replica_group_uuid', '!=', '')
                    ->pluck('id')
                    ->all();

                foreach ($bundlesInput as $idx => $item) {
                    $bId = (int) ($item['bundle_id'] ?? 0);
                    if (!in_array($bId, $productBundleIds, true)) {
                        $v->errors()->add("bundles.{$idx}.bundle_id", "Paket ID {$bId} bukan milik produk ini atau bukan paket tereplikasi.");
                    }
                }
            }
        });
    }
}

