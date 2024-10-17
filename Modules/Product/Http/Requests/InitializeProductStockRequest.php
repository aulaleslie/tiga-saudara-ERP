<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class InitializeProductStockRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'gt:0'],
            'quantity_non_tax' => ['required', 'integer', 'min:0'],
            'quantity_tax' => ['required', 'integer', 'min:0'],
            'broken_quantity_non_tax' => ['required', 'integer', 'min:0'],
            'broken_quantity_tax' => ['required', 'integer', 'min:0'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],

            // Custom rule to validate that the total of the quantities is equal to the overall quantity
            'total_quantity_check' => function ($attribute, $value, $fail) {
                $totalQuantity = $this->input('quantity');
                $sumOfQuantities = $this->input('quantity_non_tax') + $this->input('quantity_tax')
                    + $this->input('broken_quantity_non_tax') + $this->input('broken_quantity_tax');

                if ($sumOfQuantities !== (int)$totalQuantity) {
                    $fail('Jumlah total stok (termasuk non-tax, tax, dan broken) harus sama dengan jumlah stok yang dimasukkan.');
                }
            },
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
            'quantity.required' => 'Jumlah stok diperlukan.',
            'quantity.gt' => 'Jumlah stok harus lebih besar dari 0.',
            'quantity_non_tax.required' => 'Jumlah stok non-PPN diperlukan.',
            'quantity_tax.required' => 'Jumlah stok PPN diperlukan.',
            'broken_quantity_non_tax.required' => 'Jumlah stok rusak non-PPN diperlukan.',
            'broken_quantity_tax.required' => 'Jumlah stok rusak PPN diperlukan.',
            'quantity_non_tax.min' => 'Jumlah stok non-PPN tidak boleh kurang dari 0.',
            'quantity_tax.min' => 'Jumlah stok PPN tidak boleh kurang dari 0.',
            'broken_quantity_non_tax.min' => 'Jumlah stok rusak non-PPN tidak boleh kurang dari 0.',
            'broken_quantity_tax.min' => 'Jumlah stok rusak PPN tidak boleh kurang dari 0.',
            'last_buy_price.required' => 'Harga beli terakhir diperlukan.',
            'last_buy_price.min' => 'Harga beli terakhir tidak boleh kurang dari 0.',
            'average_buy_price.required' => 'Harga beli rata-rata diperlukan.',
            'average_buy_price.min' => 'Harga beli rata-rata tidak boleh kurang dari 0.',
            'sale_price.required' => 'Harga jual diperlukan.',
            'sale_price.min' => 'Harga jual tidak boleh kurang dari 0.',
            'location_id.required' => 'Lokasi produk harus dipilih.',
            'location_id.exists' => 'Lokasi yang dipilih tidak valid.',
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
