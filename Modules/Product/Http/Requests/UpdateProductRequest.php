<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Modules\Product\Entities\ProductUnitConversion;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('products.edit');
    }

    public function rules(): array
    {
        // Get the current product id for unique ignores
        $productId = $this->route('product')?->id ?? ($this->product->id ?? null);

        return [
            // === Core ===
            'product_name'        => ['required', 'string', 'max:255'],
            'product_code'        => ['nullable', 'string', 'max:255', Rule::unique('products', 'product_code')->ignore($productId)],
            'category_id'         => ['nullable', 'integer'],
            'brand_id'            => ['nullable', 'integer'],

            'stock_managed'          => ['nullable', 'boolean'],
            'serial_number_required' => ['nullable', 'boolean'],
            'product_stock_alert'    => ['nullable', 'integer', 'min:0'],

            // === Buying (same as create) ===
            'is_purchased'     => ['nullable', 'boolean'],
            'purchase_price'   => ['required_if:is_purchased,1,true,on', 'nullable', 'numeric', 'gt:0'],
            'purchase_tax_id'  => ['nullable', 'integer', 'exists:taxes,id'],

            // === Selling (same as create) ===
            'is_sold'        => ['nullable', 'boolean'],
            'sale_price'     => ['required_if:is_sold,1,true,on', 'nullable', 'numeric', 'gt:0'],
            'tier_1_price'   => ['required_if:is_sold,1,true,on', 'nullable', 'numeric', 'gt:0'],
            'tier_2_price'   => ['required_if:is_sold,1,true,on', 'nullable', 'numeric', 'gt:0'],
            'sale_tax_id'    => ['nullable', 'integer', 'exists:taxes,id'],

            // === Barcode (same as create, but ignore current product) ===
            'barcode'        => ['nullable', 'string', 'max:255', Rule::unique('products', 'barcode')->ignore($productId)],

            // === Base Unit (same as create) ===
            'base_unit_id'   => [
                'required_if:stock_managed,1,true,on',
                'integer',
                function ($attribute, $value, $fail) {
                    if ($this->boolean('stock_managed') && (is_null($value) || (string)$value === '0')) {
                        $fail('Unit dasar tidak boleh kosong ketika manajemen stok diaktifkan.');
                    }
                }
            ],

            // === Conversions (aligned with create; keep smarter update uniqueness) ===
            'conversions'                     => ['nullable', 'array'],

            'conversions.*.unit_id'           => [
                'required_if:stock_managed,1,true,on',
                'integer',
                'not_in:0',
                function ($attribute, $value, $fail) {
                    $conversions = $this->input('conversions') ?? [];
                    // ignore blank unit_id when checking duplicates
                    $unitIds = array_values(array_filter(array_column($conversions, 'unit_id')));

                    if (count(array_unique($unitIds)) !== count($unitIds)) {
                        $fail('Unit ID tidak boleh duplikat di antara elemen-elemen konversi.');
                    }

                    $baseUnitId = (int) $this->input('base_unit_id');
                    if ($value && (int)$value === $baseUnitId) {
                        $fail('Unit ID di konversi tidak boleh sama dengan unit dasar.');
                    }
                },
            ],

            'conversions.*.conversion_factor' => ['required_if:stock_managed,1,true,on', 'numeric', 'min:0.0001'],

            'conversions.*.barcode'           => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $conversions = $this->input('conversions') ?? [];
                    // ignore blank barcodes when checking duplicates-in-payload
                    $barcodes = array_values(array_filter(array_column($conversions, 'barcode')));

                    if (count(array_unique($barcodes)) !== count($barcodes)) {
                        $fail('Barcode konversi tidak boleh duplikat di antara elemen-elemen konversi.');
                    }

                    if ($value) {
                        // in-payload record index
                        $index = (int) (explode('.', $attribute)[1] ?? -1);
                        $currentId = data_get($conversions, "$index.id");

                        // DB uniqueness: ignore current conversion row (edit case)
                        $query = ProductUnitConversion::where('barcode', $value);
                        if ($currentId) {
                            $query->where('id', '!=', $currentId);
                        }

                        if ($query->exists()) {
                            $fail('Barcode konversi ini sudah ada di database.');
                        }
                    }
                }
            ],

            'conversions.*.price'             => ['required_with:conversions.*.unit_id', 'numeric', 'gt:0'],

            // === Files ===
            'document'   => ['nullable', 'array'],
            'document.*' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        // Keep messages aligned with StoreProductInfoRequest
        return [
            'product_name.required' => 'Nama produk wajib diisi.',
            'product_name.max'      => 'Nama produk tidak boleh lebih dari 255 karakter.',
            'product_code.required' => 'Kode produk wajib diisi.',
            'product_code.unique'   => 'Kode produk sudah digunakan.',

            'category_id.integer'   => 'Kategori yang dipilih tidak valid.',
            'brand_id.integer'      => 'Merek yang dipilih tidak valid.',
            'stock_managed.boolean' => 'Nilai manajemen stok tidak valid.',
            'product_stock_alert.integer' => 'Peringatan jumlah stok harus berupa angka.',
            'product_stock_alert.min'     => 'Peringatan jumlah stok tidak boleh kurang dari 0.',

            // Buying
            'is_purchased.boolean'          => 'Nilai pembelian produk tidak valid.',
            'purchase_price.required_if'    => 'Harga beli wajib diisi jika produk dibeli.',
            'purchase_price.numeric'        => 'Harga beli harus berupa angka.',
            'purchase_price.gt'             => 'Harga beli harus lebih dari 0.',
            'purchase_tax_id.exists'        => 'Pajak beli yang dipilih tidak valid.',

            // Selling
            'is_sold.boolean'             => 'Nilai penjualan produk tidak valid.',
            'sale_price.required_if'      => 'Harga jual wajib diisi jika produk dijual.',
            'sale_price.numeric'          => 'Harga jual harus berupa angka.',
            'sale_price.gt'               => 'Harga jual harus lebih dari 0.',
            'tier_1_price.required_if'    => 'Harga jual Partai Besar wajib diisi jika produk dijual.',
            'tier_1_price.numeric'        => 'Harga jual Partai Besar harus berupa angka.',
            'tier_1_price.gt'             => 'Harga jual Partai Besar harus lebih dari 0.',
            'tier_2_price.required_if'    => 'Harga jual Reseller wajib diisi jika produk dijual.',
            'tier_2_price.numeric'        => 'Harga jual Reseller harus berupa angka.',
            'tier_2_price.gt'             => 'Harga jual Reseller harus lebih dari 0.',
            'sale_tax_id.exists'          => 'Pajak jual yang dipilih tidak valid.',

            // Barcode
            'barcode.max'    => 'Barcode tidak boleh lebih dari 255 karakter.',
            'barcode.unique' => 'Barcode sudah digunakan.',

            // Conversions
            'base_unit_id.required_if'                       => 'Unit dasar diperlukan ketika manajemen stok diaktifkan.',
            'conversions.*.unit_id.required_if'              => 'Konversi ke unit wajib diisi ketika manajemen stok diaktifkan.',
            'conversions.*.unit_id.not_in'                   => 'Unit ID tidak boleh 0 atau sama dengan unit dasar.',
            'conversions.*.conversion_factor.required_if'    => 'Faktor konversi wajib diisi jika unit tersedia.',
            'conversions.*.conversion_factor.min'            => 'Faktor konversi harus lebih dari 0.',
            'conversions.*.barcode.max'                      => 'Barcode konversi tidak boleh lebih dari 255 karakter.',
            'conversions.*.price.required_with'              => 'Harga konversi wajib diisi jika Anda memilih unit konversi.',
            'conversions.*.price.numeric'                    => 'Harga konversi harus berupa angka.',
            'conversions.*.price.gt'                         => 'Harga konversi harus lebih dari 0.',
        ];
    }

    /**
     * Normalize incoming checkbox values so required_if behaves like the create request.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_purchased'           => $this->boolean('is_purchased'),
            'is_sold'                => $this->boolean('is_sold'),
            'stock_managed'          => $this->boolean('stock_managed'),
            'serial_number_required' => $this->boolean('serial_number_required'),
        ]);
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
