<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Product\Entities\ProductUnitConversion;

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
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'product_quantity' => ['nullable', 'integer', 'min:0'],
            'product_stock_alert' => ['nullable', 'integer', 'min:0'],
            'stock_managed' => ['nullable', 'boolean'],

            // New Fields for Buying
            'is_purchased' => ['nullable', 'boolean'],
            'purchase_price' => ['required_if:is_purchased,1', 'nullable', 'numeric', 'min:0'],
            'purchase_tax' => ['nullable', 'integer'],

            // New Fields for Selling
            'is_sold' => ['nullable', 'boolean'],
            'sale_price' => ['required_if:is_sold,1', 'nullable', 'numeric', 'min:0'],
            'sale_tax' => ['nullable', 'integer'],

            'barcode' => ['nullable', 'digits:13', 'regex:/^\d{13}$/', 'unique:products,barcode'],

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

                    // Check for duplicate unit_id in conversions array
                    if (count(array_unique($unitIds)) !== count($unitIds)) {
                        $fail('Unit ID tidak boleh duplikat di antara elemen-elemen konversi.');
                    }

                    // Check if the unit_id matches the base_unit_id
                    if ($value == $this->input('base_unit_id')) {
                        $fail('Unit ID di konversi tidak boleh sama dengan unit dasar.');
                    }
                },
            ],
            'conversions.*.conversion_factor' => ['required_if:stock_managed,1', 'numeric', 'min:0.0001'],
            'conversions.*.barcode' => [
                'nullable',
                'digits:13',
                'regex:/^\d{13}$/',
                function ($attribute, $value, $fail) {
                    $conversions = $this->input('conversions') ?? [];
                    $barcodes = array_column($conversions, 'barcode');

                    // Check for duplicates within the conversions array
                    if (count(array_unique($barcodes)) !== count($barcodes)) {
                        $fail('Barcode konversi tidak boleh duplikat di antara elemen-elemen konversi.');
                    }

                    // Use the ProductUnitConversion model to check for uniqueness in the database
                    if ($value && ProductUnitConversion::where('barcode', $value)->exists()) {
                        $fail('Barcode konversi ini sudah ada di database.');
                    }
                }
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
            'product_name.required' => 'Nama Barang diperlukan.',
            'product_code.required' => 'Diperlukan Kode Produk.',
            'product_code.unique' => 'Kode Produk ini sudah digunakan.',
            'base_unit_id.required_if' => 'Unit primer diperlukan ketika manajemen stok diaktifkan.',
            'conversions.*.unit_id.required_with' => 'Konversi ke satuan diperlukan ketika memberikan faktor konversi.',
            'conversions.*.conversion_factor.required_with' => 'Faktor konversi diperlukan saat menyediakan unit.',
            'conversions.*.unit_id' => 'Unit harus dipilih jika stock managed',
            'conversions.*.conversion_factor' => 'Conversion factor harus dipilih jika stock managed',
            // Add custom messages for other fields as needed
            // New messages for purchase and sale validation
            'purchase_price.required_if' => 'Harga Beli diperlukan jika Anda membeli barang ini.',
            'purchase_tax.required_if' => 'Pajak Beli harus dipilih jika Anda membeli barang ini.',
            'sale_price.required_if' => 'Harga Jual diperlukan jika Anda menjual barang ini.',
            'sale_tax.required_if' => 'Pajak Jual harus dipilih jika Anda menjual barang ini.',
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
