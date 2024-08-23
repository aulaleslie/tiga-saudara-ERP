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
    public function authorize(): bool
    {
        // Check if the user has permission to create products
        return Gate::allows('create_products');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'product_name' => ['required', 'string', 'max:255'],
            'product_code' => ['required', 'string', 'max:255', 'unique:products,product_code'],
            'product_quantity' => ['nullable', 'integer', 'min:0'],
            'product_cost' => ['nullable', 'numeric', 'max:2147483647'],
            'product_price' => ['required', 'numeric', 'max:2147483647'],
            'profit_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'product_stock_alert' => ['nullable', 'integer', 'min:0'],
            'product_order_tax' => ['nullable', 'integer', 'min:0', 'max:100'],
            'product_tax_type' => ['nullable', 'integer'],
            'product_note' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'stock_managed' => ['nullable', 'boolean'],

            // Ensure base_unit_id is required and not 0 if stock_managed is true, otherwise allow 0 or nullable
            'base_unit_id' => ['required_if:stock_managed,1', 'integer', function ($attribute, $value, $fail) {
                if ($this->input('stock_managed') && $value == 0) {
                    $fail('Unit dasar tidak boleh 0 ketika manajemen stok diaktifkan.');
                }
            }],

            // Validate conversions if provided
            'conversions' => ['nullable', 'array'],
            'conversions.*.unit_id' => ['required_with:conversions.*.conversion_factor', 'integer', 'not_in:0'],
            'conversions.*.conversion_factor' => ['required_with:conversions.*.unit_id', 'numeric', 'min:0.0001'],

            'document' => ['nullable', 'array'],
            'document.*' => ['nullable', 'string'],
        ];
    }

    /**
     * Customize the error messages.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'product_name.required' => 'Nama Barang diperlukan.',
            'product_code.required' => 'Diperlukan Kode Produk.',
            'product_code.unique' => 'Kode Produk ini sudah digunakan.',
            'base_unit_id.required_if' => 'Unit primer diperlukan ketika manajemen stok diaktifkan.',
            'conversions.*.unit_id.required_with' => 'Konversi ke satuan diperlukan ketika memberikan faktor konversi.',
            'conversions.*.conversion_factor.required_with' => 'Faktor konversi diperlukan saat menyediakan unit.',
            // Add custom messages for other fields as needed
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param Validator $validator
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        Log::info('Validation input:', $this->input());
        Log::error('Validation failed', $validator->errors()->toArray());

        throw new HttpResponseException(
            redirect()->back()->withErrors($validator)->withInput()
        );
    }
}
