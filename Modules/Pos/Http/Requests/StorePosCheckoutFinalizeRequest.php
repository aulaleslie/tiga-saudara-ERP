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
            'payment.method_code' => ['required', 'string', Rule::in(['cash', 'transfer', 'qris'])],
            'payment.amount_paid' => ['required', 'numeric', 'gt:0'],
            'payment.reference' => ['nullable', 'string', 'max:255', 'required_if:payment.method_code,transfer,qris'],
            'client_context' => ['nullable', 'array'],
        ];
    }
}
