<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StorePosCheckoutFinalizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sell');
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'payment' => ['required', 'array'],
            // Accept either payment_method_id (new) or method_code (legacy), with method_code taking precedence
            'payment.payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'payment.method_code' => ['nullable', 'string', Rule::in(['cash', 'transfer', 'qris'])],
            'payment.amount_paid' => ['required', 'numeric', 'gt:0'],
            'payment.reference' => ['nullable', 'string', 'max:255'],
            'client_context' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $payment = $this->input('payment', []);
            $paymentMethodId = $payment['payment_method_id'] ?? null;
            $methodCode = $payment['method_code'] ?? null;

            // At least one must be provided
            if (! $paymentMethodId && ! $methodCode) {
                $validator->errors()->add(
                    'payment.method',
                    'Either payment.payment_method_id or payment.method_code must be provided.'
                );
            }

            // Legacy validation: if only method_code is provided, validate it's one of the allowed codes
            if (! $paymentMethodId && $methodCode && ! in_array(strtolower($methodCode), ['cash', 'transfer', 'qris'], true)) {
                $validator->errors()->add(
                    'payment.method_code',
                    'The payment.method_code must be one of: cash, transfer, qris.'
                );
            }
        });
    }
}
