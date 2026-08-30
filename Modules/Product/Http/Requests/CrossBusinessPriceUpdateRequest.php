<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

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
        ];
    }
}
