<?php

namespace Modules\Product\Support;

use Modules\Product\Entities\ProductUnitConversion;

class ProductCreateValidation
{
    public static function normalize(array $input): array
    {
        $conversions = is_array($input['conversions'] ?? null)
            ? ProductConversionPriceNormalizer::normalizeConversions($input['conversions'])
            : ($input['conversions'] ?? null);

        return [
            'is_purchased' => self::toBoolean($input['is_purchased'] ?? false),
            'is_sold' => self::toBoolean($input['is_sold'] ?? false),
            'stock_managed' => self::toBoolean($input['stock_managed'] ?? false),
            'serial_number_required' => self::toBoolean($input['serial_number_required'] ?? false),
            'conversions' => $conversions,
        ];
    }

    public static function rules(array $input = []): array
    {
        return [
            'product_name'        => ['required', 'string', 'max:255'],
            'product_code'        => ['nullable', 'string', 'max:255', 'unique:products,product_code'],
            'category_id'         => ['nullable', 'integer'],
            'brand_id'            => ['nullable', 'integer'],

            'stock_managed'          => ['nullable', 'boolean'],
            'serial_number_required' => ['nullable', 'boolean'],
            'product_stock_alert'    => ['nullable', 'integer', 'min:0'],

            'is_purchased'      => ['nullable', 'boolean'],
            'purchase_price'    => ['required_if:is_purchased,1,true,on', 'nullable', 'numeric', 'gt:0'],
            'purchase_tax_id'   => ['nullable', 'integer', 'exists:taxes,id'],

            'is_sold'           => ['nullable', 'boolean'],
            'sale_price'        => ['required_if:is_sold,1,true,on', 'nullable', 'numeric', 'gt:0'],
            'tier_1_price'      => ['required_if:is_sold,1,true,on', 'nullable', 'numeric', 'gt:0'],
            'tier_2_price'      => ['required_if:is_sold,1,true,on', 'nullable', 'numeric', 'gt:0'],
            'sale_tax_id'       => ['nullable', 'integer', 'exists:taxes,id'],

            'barcode'           => ['nullable', 'string', 'max:255', new \Modules\Product\Rules\UniqueBarcodeIdentity()],

            'base_unit_id'      => [
                'required_if:stock_managed,1,true,on',
                'integer',
                function ($attribute, $value, $fail) use ($input) {
                    $stockManaged = self::toBoolean($input['stock_managed'] ?? false);
                    if ($stockManaged && (is_null($value) || (string) $value === '0')) {
                        $fail('Unit dasar tidak boleh kosong ketika manajemen stok diaktifkan.');
                    }
                },
            ],

            'conversions'                         => ['nullable', 'array'],
            'conversions.*.unit_id'               => [
                'required_if:stock_managed,1,true,on',
                'integer',
                'not_in:0',
                function ($attribute, $value, $fail) use ($input) {
                    $conversions = is_array($input['conversions'] ?? null) ? $input['conversions'] : [];
                    $unitIds = array_filter(array_column($conversions, 'unit_id'));

                    if (count(array_unique($unitIds)) !== count($unitIds)) {
                        $fail('Unit ID tidak boleh duplikat di antara elemen-elemen konversi.');
                    }

                    if ($value && $value == ($input['base_unit_id'] ?? null)) {
                        $fail('Unit ID di konversi tidak boleh sama dengan unit dasar.');
                    }
                },
            ],
            'conversions.*.conversion_factor'     => ['required_if:stock_managed,1,true,on', 'numeric', 'min:0.0001'],
            'conversions.*.barcode'               => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($input) {
                    $conversions = is_array($input['conversions'] ?? null) ? $input['conversions'] : [];
                    $barcodes = array_filter(array_column($conversions, 'barcode'));

                    if (count(array_unique($barcodes)) !== count($barcodes)) {
                        $fail('Barcode konversi tidak boleh duplikat di antara elemen-elemen konversi.');
                    }

                    if ($value) {
                        $rule = new \Modules\Product\Rules\UniqueBarcodeIdentity();
                        if (!$rule->passes($attribute, $value)) {
                            $fail($rule->message());
                        }
                    }
                },
            ],
            'conversions.*.price'                 => [
                'required_with:conversions.*.unit_id',
                'numeric',
                'gt:0',
            ],

            'document'     => ['nullable', 'array'],
            'document.*'   => ['nullable', 'string'],
        ];
    }

    public static function messages(): array
    {
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

            'purchase_price.required_if' => 'Harga beli wajib diisi jika produk dibeli.',
            'purchase_price.numeric'     => 'Harga beli harus berupa angka.',
            'purchase_price.gt'          => 'Harga beli harus lebih dari 0.',

            'sale_price.required_if'   => 'Harga jual wajib diisi jika produk dijual.',
            'sale_price.numeric'       => 'Harga jual harus berupa angka.',
            'sale_price.gt'            => 'Harga jual harus lebih dari 0.',
            'tier_1_price.required_if' => 'Harga jual Partai Besar wajib diisi jika produk dijual.',
            'tier_1_price.numeric'     => 'Harga jual Partai Besar harus berupa angka.',
            'tier_1_price.gt'          => 'Harga jual Partai Besar harus lebih dari 0.',
            'tier_2_price.required_if' => 'Harga jual Reseller wajib diisi jika produk dijual.',
            'tier_2_price.numeric'     => 'Harga jual Reseller harus berupa angka.',
            'tier_2_price.gt'          => 'Harga jual Reseller harus lebih dari 0.',

            'barcode.max'    => 'Barcode tidak boleh lebih dari 255 karakter.',
            'barcode.unique' => 'Barcode sudah digunakan.',

            'base_unit_id.required_if'                     => 'Unit dasar diperlukan ketika manajemen stok diaktifkan.',
            'conversions.*.unit_id.required_if'           => 'Konversi ke unit wajib diisi ketika manajemen stok diaktifkan.',
            'conversions.*.unit_id.not_in'                => 'Unit ID tidak boleh 0 atau sama dengan unit dasar.',
            'conversions.*.conversion_factor.required_if' => 'Faktor konversi wajib diisi jika unit tersedia.',
            'conversions.*.conversion_factor.min'         => 'Faktor konversi harus lebih dari 0.',
            'conversions.*.barcode.max'                   => 'Barcode konversi tidak boleh lebih dari 255 karakter.',
            'conversions.*.price.required_with'           => 'Harga konversi wajib diisi jika Anda memilih unit konversi.',
            'conversions.*.price.numeric'                 => 'Harga konversi harus berupa angka.',
            'conversions.*.price.gt'                      => 'Harga konversi harus lebih dari 0.',
        ];
    }

    private static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }
}
