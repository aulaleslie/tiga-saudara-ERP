<?php

namespace Modules\Sale\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdatePosDraftRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'tax_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'discount_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'pos_location_assignment_id' => ['nullable', 'integer', 'exists:setting_sale_locations,id'],
            'payload' => ['nullable', 'array'],
            'payload.cart' => ['nullable', 'array'],
            'payload.cart.*.id' => ['nullable'],
            'payload.cart.*.name' => ['nullable', 'string'],
            'payload.cart.*.qty' => ['nullable', 'numeric', 'min:0'],
            'payload.cart.*.price' => ['nullable', 'numeric', 'min:0'],
            'payload.cart.*.options' => ['nullable', 'array'],
        ];
    }

    public function authorize(): bool
    {
        return Gate::allows('pos.drafts.update');
    }
}
