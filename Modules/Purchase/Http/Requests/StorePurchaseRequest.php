<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\Setting;

class StorePurchaseRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'reference' => 'required|string|max:255|unique:purchases,reference,NULL,id,setting_id,' . session('setting_id'),
            'supplier_purchase_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('purchases', 'supplier_purchase_number')
                    ->where('setting_id', session('setting_id'))
                    ->whereNull('archived_at'),
            ],
            'tax_ref_no' => 'sometimes|nullable|string|max:255|unique:purchases,tax_ref_no,NULL,id,setting_id,' . session('setting_id'),
            'date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:date',
            'tax_id' => 'nullable|integer|exists:taxes,id',
            'discount_percentage' => 'nullable|numeric|min:0|max:100|required_without:discount_amount',
            'discount_amount' => 'nullable|numeric|min:0|required_without:discount_percentage',
            'shipping_amount' => 'required|numeric',
            'total_amount' => 'required|numeric|min:0', // Ensure total amount is a valid number
            'payment_term' => 'required|integer|exists:payment_terms,id', // New field for payment term
            'note' => 'nullable|string|max:1000',
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
            'supplier_id.required' => 'Pemasok wajib dipilih.',
            'supplier_id.exists' => 'Pemasok yang dipilih tidak valid.',
            'reference.required' => 'Referensi pembelian wajib diisi.',
            'reference.unique' => 'Referensi pembelian sudah digunakan.',
            'supplier_purchase_number.max' => 'Nomor pembelian pemasok maksimal 255 karakter.',
            'tax_ref_no.max' => 'Nomor Faktur Pajak maksimal 255 karakter.',
            'date.required' => 'Tanggal pembelian wajib diisi.',
            'date.date' => 'Format tanggal tidak valid.',
            'due_date.required' => 'Tanggal jatuh tempo wajib diisi.',
            'due_date.date' => 'Format tanggal jatuh tempo tidak valid.',
            'due_date.after_or_equal' => 'Tanggal jatuh tempo harus sama atau setelah tanggal pembelian.',
            'tax_id.exists' => 'Pajak yang dipilih tidak valid.',
            'discount_percentage.numeric' => 'Persentase diskon harus berupa angka.',
            'discount_percentage.min' => 'Persentase diskon minimal 0.',
            'discount_percentage.max' => 'Persentase diskon maksimal 100.',
            'discount_percentage.required_without' => 'Persentase diskon wajib diisi jika jumlah diskon tidak diisi.',
            'discount_amount.numeric' => 'Jumlah diskon harus berupa angka.',
            'discount_amount.min' => 'Jumlah diskon minimal 0.',
            'discount_amount.required_without' => 'Jumlah diskon wajib diisi jika persentase diskon tidak diisi.',
            'shipping_amount.required' => 'Biaya pengiriman wajib diisi.',
            'shipping_amount.numeric' => 'Biaya pengiriman harus berupa angka.',
            'total_amount.required' => 'Total jumlah wajib diisi.',
            'total_amount.numeric' => 'Total jumlah harus berupa angka.',
            'total_amount.min' => 'Total jumlah minimal 0.',
            'payment_term.required' => 'Term pembayaran wajib dipilih.',
            'payment_term.exists' => 'Term pembayaran yang dipilih tidak valid.',
            'note.max' => 'Catatan maksimal 1000 karakter.',
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return Gate::allows('purchases.create');
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('payment_term')) {
            $this->merge([
                'payment_term' => PaymentTerm::defaultCodTermId(),
            ]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $settingId = (int) session('setting_id');
            $isPkp = (bool) (Setting::query()->whereKey($settingId)->value('is_pkp') ?? false);
            if (! $isPkp) {
                return;
            }

            $cartItems = (array) $this->input('cart', []);
            if (empty($cartItems)) {
                return;
            }

            foreach ($cartItems as $index => $item) {
                if (empty($item['tax_id'])) {
                    $validator->errors()->add('cart', 'Semua produk wajib memilih pajak karena bisnis PKP.');
                    break;
                }
            }
        });
    }
}
