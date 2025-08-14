<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Product\Entities\ProductUnitConversion;

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
        return Gate::allows('products.edit');
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
            'purchase_tax' => ['nullable'],
            'sale_tax' => ['nullable'],
            'product_note' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'stock_managed' => ['nullable', 'boolean'],
            'serial_number_required' => ['nullable', 'boolean'],
            'barcode' => [
                'nullable',
                'string',
                'max:255',
                'unique:products,barcode,' . $this->product->id,
            ],

            'base_unit_id' => [
                'required_if:stock_managed,1',
                'integer',
                function ($attribute, $value, $fail) {
                    if ($this->input('stock_managed') && $value == 0) {
                        $fail('Unit dasar tidak boleh 0 ketika manajemen stok diaktifkan.');
                    }
                }
            ],

            'is_purchased' => ['sometimes','boolean'],
            'purchase_price' => ['nullable','numeric','min:0'],
            'purchase_tax_id' => ['nullable','integer','exists:taxes,id'],

            'is_sold' => ['sometimes','boolean'],
            'sale_price' => ['nullable','numeric','min:0'],
            'sale_tax_id' => ['nullable','integer','exists:taxes,id'],

            'tier_1_price' => ['nullable','numeric','min:0'],
            'tier_2_price' => ['nullable','numeric','min:0'],

            'conversions' => ['nullable', 'array'],

            // Unit ID validation with custom logic for duplicates and base_unit_id conflict
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
                    $baseUnitId = (int)$this->input('base_unit_id');
                    $unitId = (int)$value;

                    if ($unitId === $baseUnitId) {
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

                    // Check for duplicates within the conversions array
                    if (count(array_unique($barcodes)) !== count($barcodes)) {
                        $fail('Barcode konversi tidak boleh duplikat di antara elemen-elemen konversi.');
                    }

                    // Retrieve the conversion index
                    $index = explode('.', $attribute)[1] ?? null;
                    if (is_null($index)) {
                        $fail('Invalid conversion index.');
                        return;
                    }

                    // Retrieve the corresponding product unit conversion based on the index
                    $conversion = ProductUnitConversion::find($this->input("conversions.$index.id"));

                    // If the conversion exists, check if the barcode has changed
                    if ($value && $conversion && $conversion->barcode !== $value) {
                        // Check if the barcode is unique in the database, ignoring the current conversion
                        if (ProductUnitConversion::where('barcode', $value)->where('id', '!=', $conversion->id)->exists()) {
                            $fail('Barcode konversi ini sudah ada di database.');
                        }
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

            // Location required if product_quantity is greater than 0
            'location_id' => [
                'integer',
                function ($attribute, $value, $fail) {
                    if ($this->input('product_quantity') > 0 && empty($value)) {
                        $fail('Location harus diisi jika jumlah produk lebih dari 0.');
                    }
                }
            ],
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
            'conversions.*.conversion_factor' => 'Conversion factor harus dipilih jika stock managed',
            'conversions.*.price.required_with' => 'Harga konversi wajib diisi jika Anda memilih unit konversi.',
            'conversions.*.price.numeric'       => 'Harga konversi harus berupa angka.',
            'conversions.*.price.gt'            => 'Harga konversi harus lebih dari 0.',
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
