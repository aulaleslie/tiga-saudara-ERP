<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Check if the user has permission to edit products
        return Gate::allows('edit_products');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'product_name' => ['sometimes', 'required', 'string', 'max:255'],
            'product_code' => ['sometimes', 'required', 'string', 'max:255', 'unique:products,product_code,' . $this->product->id],

            'product_stock_alert' => ['nullable', 'integer', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_tax' => ['nullable'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'sale_tax' => ['nullable'],
            'product_note' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'stock_managed' => ['nullable', 'boolean'],

            // Validate conversions if provided
            'conversions' => ['nullable', 'array'],
            'conversions.*.unit_id' => ['required_if:stock_managed,1', 'integer', 'not_in:0'],
            'conversions.*.conversion_factor' => ['required_if:stock_managed,1', 'numeric', 'min:0.0001'],
            'conversions.*.barcode' => ['nullable', 'string', 'max:255'],

            'document' => ['nullable', 'array'],
            'document.*' => ['nullable', 'string'],

            // Ensure base_unit_id is required and not 0 if stock_managed is true, otherwise allow 0 or nullable
            'base_unit_id' => ['required_if:stock_managed,1', 'integer', function ($attribute, $value, $fail) {
                if ($this->input('stock_managed') && $value == 0) {
                    $fail('Unit dasar tidak boleh 0 ketika manajemen stok diaktifkan.');
                }
            }],

            // Location required if product_quantity is greater than 0
            'location_id' => ['integer', function ($attribute, $value, $fail) {
                if ($this->input('product_quantity') > 0 && empty($value)) {
                    $fail('Location harus diisi jika jumlah produk lebih dari 0.');
                }
            }],

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
            'conversions.*.unit_id' => 'Unit harus dipilih jika stock managed',
            'conversions.*.conversion_factor' => 'Conversion factor harus dipilih jika stock managed',
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
