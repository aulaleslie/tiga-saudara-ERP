<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class StoreProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        // Check if the user has permission to create products
        return Gate::allows('create_products');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'product_name' => ['required', 'string', 'max:255'],
            'product_code' => ['required', 'string', 'max:255', 'unique:products,product_code'],
            'product_quantity' => ['nullable', 'integer', 'min:0'],
            'product_cost' => ['nullable', 'numeric', 'max:2147483647'],
            'product_price' => ['required', 'numeric', 'max:2147483647'], // Changed to nullable to allow for dynamic calculation
            'profit_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'], // Added to support profit-based pricing
            'product_stock_alert' => ['nullable', 'integer', 'min:0'],
            'product_order_tax' => ['nullable', 'integer', 'min:0', 'max:100'],
            'product_tax_type' => ['nullable', 'integer'],
            'product_note' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['nullable', 'integer'], // Made nullable
            'brand_id' => ['nullable', 'integer'], // Added brand_id and made it nullable
            'stock_managed' => ['nullable', 'boolean'], // Added for managing stock

            // Ensure base_unit_id is required and not 0 if stock_managed is true
            'base_unit_id' => ['required_if:stock_managed,1', 'integer', 'not_in:0'],

            // Only validate conversions if the array is not empty and stock_managed is true
            'conversions' => ['nullable', 'array'],
            'conversions.*.unit_id' => ['required_if:stock_managed,1', 'integer', 'not_in:0'],
            'conversions.*.conversion_factor' => ['required_if:stock_managed,1', 'numeric', 'min:0.0001'],

            'document' => ['nullable', 'array'],
            'document.*' => ['file', 'mimes:jpg,jpeg,png', 'max:1024'], // Example validation for images
        ];
    }

    /**
     * Customize the error messages.
     *
     * @return array
     */
    public function messages()
    {
        // TODO update translation
        return [
            'product_name.required' => 'Nama Barang diperlukan.',
            'product_code.required' => 'Product code is required.',
            'product_code.unique' => 'This product code is already in use.',
            'base_unit_id.required_if' => 'Primary unit is required when stock management is enabled.',
            'conversions.unit_id.*.required_if' => 'Conversion to unit is required when stock management is enabled.',
            'conversions.conversion_factor.*.required_if' => 'Conversion factor is required when stock management is enabled.',
            // Add custom messages for other fields as needed
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        Log::info('Validation input:', $this->input());
        Log::error('Validation failed', $validator->errors()->toArray());

        throw new HttpResponseException(
            redirect()->back()->withErrors($validator)->withInput()
        );
    }
}
