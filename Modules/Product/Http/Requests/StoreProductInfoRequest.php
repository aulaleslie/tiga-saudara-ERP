<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Product\Entities\ProductUnitConversion;

class StoreProductInfoRequest extends FormRequest
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
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'stock_managed' => ['nullable', 'boolean'],
            'serial_number_required' => ['nullable', 'boolean'],
            'product_stock_alert' => ['nullable', 'integer', 'min:0'],

            // Fields for Buying
            'is_purchased' => ['nullable', 'boolean'],
            'purchase_price' => ['required_if:is_purchased,1', 'nullable', 'numeric', 'min:0'],
            'purchase_tax' => ['nullable', 'integer'],
            'purchase_tax_id' => ['nullable', 'integer', 'exists:taxes,id'],

            // Fields for Selling
            'is_sold' => ['nullable', 'boolean'],
            'sale_price' => ['required_if:is_sold,1', 'nullable', 'numeric', 'min:0'],
            'sale_tax' => ['nullable', 'integer'],
            'sale_tax_id' => ['nullable', 'integer', 'exists:taxes,id'],

            'barcode' => ['nullable', 'string', 'max:255', 'unique:products,barcode'],

            // Ensure base_unit_id is required if stock_managed is true
            'base_unit_id' => ['required_if:stock_managed,1', 'integer', function ($attribute, $value, $fail) {
                if ($this->input('stock_managed') && $value == 0) {
                    $fail('Unit dasar tidak boleh 0 ketika manajemen stok diaktifkan.');
                }
            }],

            'tier_1_price' => ['nullable', 'numeric', 'min:0'],
            'tier_2_price' => ['nullable', 'numeric', 'min:0'],

            // Validate conversions if provided
            'conversions' => ['nullable', 'array'],
            'conversions.*.unit_id' => [
                'required_if:stock_managed,1',
                'integer',
                'not_in:0',
                function ($attribute, $value, $fail) {
                    // Prevent duplicates within conversions array
                    $conversions = $this->input('conversions') ?? [];
                    $unitIds = array_column($conversions, 'unit_id');

                    if (count(array_unique($unitIds)) !== count($unitIds)) {
                        $fail('Unit ID tidak boleh duplikat di antara elemen-elemen konversi.');
                    }

                    if ($value == $this->input('base_unit_id')) {
                        $fail('Unit ID di konversi tidak boleh sama dengan unit dasar.');
                    }
                },
            ],
            'conversions.*.conversion_factor' => ['required_if:stock_managed,1', 'numeric', 'min:0.0001'],
            'conversions.*.barcode' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $conversions = $this->input('conversions') ?? [];
                    $barcodes = array_column($conversions, 'barcode');

                    if (count(array_unique($barcodes)) !== count($barcodes)) {
                        $fail('Barcode konversi tidak boleh duplikat di antara elemen-elemen konversi.');
                    }

                    if ($value && ProductUnitConversion::where('barcode', $value)->exists()) {
                        $fail('Barcode konversi ini sudah ada di database.');
                    }
                }
            ],
            'conversions.*.price' => [
                'required_with:conversions.*.unit_id',
                'numeric',
                'gt:0',
            ],

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
            'product_name.required' => 'Nama produk wajib diisi.',
            'product_name.max' => 'Nama produk tidak boleh lebih dari 255 karakter.',
            'product_code.required' => 'Kode produk wajib diisi.',
            'product_code.unique' => 'Kode produk sudah digunakan.',
            'category_id.integer' => 'Kategori yang dipilih tidak valid.',
            'brand_id.integer' => 'Merek yang dipilih tidak valid.',
            'stock_managed.boolean' => 'Nilai manajemen stok tidak valid.',
            'product_stock_alert.integer' => 'Peringatan jumlah stok harus berupa angka.',
            'product_stock_alert.min' => 'Peringatan jumlah stok tidak boleh kurang dari 0.',

            // Buying Fields
            'is_purchased.boolean' => 'Nilai pembelian produk tidak valid.',
            'purchase_price.required_if' => 'Harga beli wajib diisi jika produk dibeli.',
            'purchase_price.numeric' => 'Harga beli harus berupa angka.',
            'purchase_price.min' => 'Harga beli tidak boleh kurang dari 0.',
            'purchase_tax.integer' => 'Pajak beli yang dipilih tidak valid.',

            // Selling Fields
            'is_sold.boolean' => 'Nilai penjualan produk tidak valid.',
            'sale_price.required_if' => 'Harga jual wajib diisi jika produk dijual.',
            'sale_price.numeric' => 'Harga jual harus berupa angka.',
            'sale_price.min' => 'Harga jual tidak boleh kurang dari 0.',
            'sale_tax.integer' => 'Pajak jual yang dipilih tidak valid.',

            // Barcode
            'barcode.max'    => 'Barcode tidak boleh lebih dari 255 karakter.',
            'barcode.unique' => 'Barcode sudah digunakan.',

            // Conversions
            'base_unit_id.required_if' => 'Unit dasar diperlukan ketika manajemen stok diaktifkan.',
            'conversions.*.unit_id.required_if' => 'Konversi ke unit wajib diisi ketika manajemen stok diaktifkan.',
            'conversions.*.unit_id.not_in' => 'Unit ID tidak boleh 0 atau sama dengan unit dasar.',
            'conversions.*.conversion_factor.required_if' => 'Faktor konversi wajib diisi jika unit tersedia.',
            'conversions.*.conversion_factor.min' => 'Faktor konversi harus lebih dari 0.',
            'conversions.*.barcode.max' => 'Barcode konversi tidak boleh lebih dari 255 karakter.',
            'conversions.*.price.required_with' => 'Harga konversi wajib diisi jika Anda memilih unit konversi.',
            'conversions.*.price.numeric'       => 'Harga konversi harus berupa angka.',
            'conversions.*.price.gt'            => 'Harga konversi harus lebih dari 0.',
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
