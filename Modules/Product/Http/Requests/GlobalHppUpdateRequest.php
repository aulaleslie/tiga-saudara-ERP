<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class GlobalHppUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('products.manage_cross_business_prices');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'average_purchase_price' => 'required|numeric|decimal:0,2|gt:0',
            'loaded_state_evidence' => 'required|string',
            'loaded_state_signature' => 'required|string',
        ];
    }

    /**
     * Custom error messages in Bahasa Indonesia.
     */
    public function messages(): array
    {
        return [
            'average_purchase_price.required' => 'Harga Beli Rata-rata wajib diisi.',
            'average_purchase_price.numeric' => 'Harga Beli Rata-rata harus berupa angka yang valid.',
            'average_purchase_price.decimal' => 'Harga Beli Rata-rata maksimal 2 tempat desimal.',
            'average_purchase_price.gt' => 'Harga Beli Rata-rata harus bernilai positif lebih dari 0.',
            'loaded_state_evidence.required' => 'Bukti status data tidak valid atau hilang. Silakan muat ulang halaman.',
            'loaded_state_signature.required' => 'Tanda tangan bukti status data tidak valid atau hilang. Silakan muat ulang halaman.',
        ];
    }
}
