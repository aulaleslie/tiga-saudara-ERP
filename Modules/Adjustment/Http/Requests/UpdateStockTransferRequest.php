<?php

namespace Modules\Adjustment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateStockTransferRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Check if the user has permission to update stock transfers
        return Gate::allows('update_transfers');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['required', 'exists:products,id'],

            'quantities' => ['required', 'array'],
            'quantities.*' => ['required', 'integer', 'min:1'],
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
            'product_ids.required' => 'Setidaknya satu produk diperlukan.',
            'product_ids.*.exists' => 'Produk yang dipilih tidak valid.',
            'quantities.required' => 'Silakan masukkan jumlah untuk produk yang dipilih.',
            'quantities.*.integer' => 'Besaran harus berupa angka yang valid.',
            'quantities.*.min' => 'Jumlahnya minimal harus 1.',
        ];
    }
}
