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
        });
    }
}

