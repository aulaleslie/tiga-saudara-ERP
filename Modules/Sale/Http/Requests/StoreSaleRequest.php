<?php

namespace Modules\Sale\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\Setting;

class StoreSaleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'required',
                'integer',
                \Illuminate\Validation\Rule::exists('customers', 'id')->where('is_active', true),
            ],
            'reference' => 'nullable|string|max:255|unique:sales,reference,NULL,id,setting_id,' . session('setting_id'),
            'date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:date',
            'tax_id' => [
                'nullable',
                'integer',
                \Illuminate\Validation\Rule::exists('taxes', 'id')->where('is_active', true),
            ],
            'discount_percentage' => 'nullable|numeric|min:0|max:100|required_without:discount_amount',
            'discount_amount' => 'nullable|numeric|min:0|required_without:discount_percentage',
            'shipping_amount' => 'required|numeric',
            'total_amount' => 'required|numeric|min:0', // Ensure total amount is a valid number
            'payment_term_id' => [
                'required',
                'integer',
                \Illuminate\Validation\Rule::exists('payment_terms', 'id')->where('is_active', true),
            ],
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
            'customer_id.required' => 'Pelanggan wajib dipilih.',
            'customer_id.exists' => 'Pelanggan yang dipilih tidak valid.',
            'reference.unique' => 'Referensi penjualan sudah digunakan.',
            'date.required' => 'Tanggal penjualan wajib diisi.',
            'date.date' => 'Format tanggal tidak valid.',
            'due_date.required' => 'Tanggal jatuh tempo wajib diisi.',
            'due_date.date' => 'Format tanggal jatuh tempo tidak valid.',
            'due_date.after_or_equal' => 'Tanggal jatuh tempo harus sama atau setelah tanggal penjualan.',
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
            'payment_term_id.required' => 'Term pembayaran wajib dipilih.',
            'payment_term_id.exists' => 'Term pembayaran yang dipilih tidak valid.',
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
        return Gate::allows('sales.create');
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('payment_term_id')) {
            $this->merge([
                'payment_term_id' => PaymentTerm::defaultCodTermId(),
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
