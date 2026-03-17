<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePosCheckoutFinalizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sell');
    }

    public function rules(): array
    {
        $legacyPayment = $this->input('payment');
        $multiPayments = $this->input('payments');
        $cartToken = $this->input('cart_token');
        $hasLegacyPayment = $legacyPayment !== null && is_array($legacyPayment);
        $hasMultiPayments = $multiPayments !== null && is_array($multiPayments);
        $hasCartToken = ! empty($cartToken);

        $rules = [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'cart_token' => ['nullable', 'string', 'uuid'],
            'client_context' => ['nullable', 'array'],
        ];

        // If cart_token is provided, we'll fetch payments from session, so payment fields are optional
        if ($hasCartToken) {
            return $rules;
        }

        // Support legacy single-payment path
        if ($hasLegacyPayment && ! $hasMultiPayments) {
            $rules['payment'] = ['required', 'array'];
            $rules['payment.payment_method_id'] = ['required', 'integer', 'exists:payment_methods,id'];
            $rules['payment.amount_paid'] = ['required', 'numeric', 'gt:0'];
            $rules['payment.reference'] = ['nullable', 'string', 'max:255'];
        }
        // Support new multi-payment path
        elseif ($hasMultiPayments && ! $hasLegacyPayment) {
            $rules['payments'] = ['required', 'array', 'min:1'];
            $rules['payments.*.payment_method_id'] = ['required', 'integer', 'exists:payment_methods,id'];
            $rules['payments.*.amount_paid'] = ['required', 'numeric', 'gt:0'];
            $rules['payments.*.reference'] = ['nullable', 'string', 'max:255'];
        } else {
            // Must provide exactly one: either payment or payments[]
            $rules['payment'] = ['required_without:payments', 'array'];
            $rules['payment.payment_method_id'] = ['required', 'integer', 'exists:payment_methods,id'];
            $rules['payment.amount_paid'] = ['required', 'numeric', 'gt:0'];
            $rules['payment.reference'] = ['nullable', 'string', 'max:255'];
            $rules['payments'] = ['required_without:payment', 'array'];
        }

        return $rules;
    }
}
