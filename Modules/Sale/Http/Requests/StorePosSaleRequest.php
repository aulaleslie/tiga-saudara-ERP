<?php

namespace Modules\Sale\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Modules\Setting\Entities\PaymentMethod;

class StorePosSaleRequest extends FormRequest
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
            'tax_percentage' => 'required|integer|min:0|max:100',
            'discount_percentage' => 'required|integer|min:0|max:100',
            'shipping_amount' => 'required|numeric',
            'total_amount' => 'required|numeric',
            'paid_amount' => 'required|numeric',
            'payment_method_id' => 'required|integer|exists:payment_methods,id,is_available_in_pos,1',
            'note' => 'nullable|string|max:1000'
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $paymentMethodId = $this->input('payment_method_id');

            if (! $paymentMethodId) {
                return;
            }

            $totalAmount = (float) $this->input('total_amount', 0);
            $paidAmount = (float) $this->input('paid_amount', 0);

            if ($paidAmount <= $totalAmount) {
                return;
            }

            $paymentMethod = PaymentMethod::query()
                ->where('is_available_in_pos', true)
                ->find($paymentMethodId);

            if ($paymentMethod && ! $paymentMethod->is_cash) {
                $validator->errors()->add('paid_amount', 'Overpayment is only allowed for cash payments.');
            }
        });
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Gate::allows('pos.create');
    }
}
