<?php

namespace Modules\Sale\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateSaleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'customer_id' => 'required|numeric',
            'reference' => 'required|string|max:255|unique:sales,reference,' . $this->route('sale')->id . ',id,setting_id,' . session('setting_id'),
            'tax_percentage' => 'required|integer|min:0|max:100',
            'discount_percentage' => 'required|integer|min:0|max:100',
            'shipping_amount' => 'required|numeric',
            'total_amount' => 'required|numeric',
            'paid_amount' => 'required|numeric|max:' . $this->sale->total_amount,
            'status' => 'required|string|max:255',
            'payment_method' => 'required|string|max:255',
            'note' => 'nullable|string|max:1000'
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Pelanggan wajib dipilih.',
            'reference.required' => 'Referensi penjualan wajib diisi.',
            'reference.unique' => 'Referensi penjualan sudah digunakan.',
            'tax_percentage.required' => 'Persentase pajak wajib diisi.',
            'tax_percentage.integer' => 'Persentase pajak harus berupa angka.',
            'tax_percentage.min' => 'Persentase pajak minimal 0.',
            'tax_percentage.max' => 'Persentase pajak maksimal 100.',
            'discount_percentage.required' => 'Persentase diskon wajib diisi.',
            'discount_percentage.integer' => 'Persentase diskon harus berupa angka.',
            'discount_percentage.min' => 'Persentase diskon minimal 0.',
            'discount_percentage.max' => 'Persentase diskon maksimal 100.',
            'shipping_amount.required' => 'Biaya pengiriman wajib diisi.',
            'shipping_amount.numeric' => 'Biaya pengiriman harus berupa angka.',
            'total_amount.required' => 'Total jumlah wajib diisi.',
            'total_amount.numeric' => 'Total jumlah harus berupa angka.',
            'paid_amount.required' => 'Jumlah yang dibayar wajib diisi.',
            'paid_amount.numeric' => 'Jumlah yang dibayar harus berupa angka.',
            'paid_amount.max' => 'Jumlah yang dibayar tidak boleh melebihi total jumlah.',
            'status.required' => 'Status wajib dipilih.',
            'payment_method.required' => 'Metode pembayaran wajib dipilih.',
            'note.max' => 'Catatan maksimal 1000 karakter.',
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Gate::allows('sales.edit');
    }
}
