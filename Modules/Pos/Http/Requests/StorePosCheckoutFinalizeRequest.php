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
        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'payment' => ['required', 'array'],
            'payment.payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'payment.amount_paid' => ['required', 'numeric', 'gt:0'],
            'payment.reference' => ['nullable', 'string', 'max:255'],
            'client_context' => ['nullable', 'array'],
        ];
    }
}
