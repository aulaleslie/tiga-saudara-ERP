<?php

namespace Modules\Adjustment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StockTransferRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Authorize only if the user has permission to create transfers
        return Gate::allows('create_transfers');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'origin_location' => ['required', 'exists:locations,id'],
            'destination_location' => ['required', 'exists:locations,id'],
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['exists:products,id'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['integer', 'min:1'],
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
            'origin_location.required' => 'lokasi awal diperlukan.',
            'destination_location.required' => 'Lokasi tujuan wajib diisi.',
            'product_ids.required' => 'Anda harus memilih setidaknya satu produk.',
            'product_ids.*.exists' => 'Produk yang dipilih tidak valid.',
            'quantities.required' => 'Harap berikan jumlah untuk setiap produk.',
            'quantities.*.min' => 'Jumlahnya minimal harus 1.',
        ];
    }
}
