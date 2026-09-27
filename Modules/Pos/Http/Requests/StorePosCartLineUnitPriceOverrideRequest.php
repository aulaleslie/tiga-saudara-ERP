<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Unit-price override contract.
 *
 * Accepts only the line's requested unit price, an optional reason, and an
 * optional approval token. Source values, pricing source, fingerprint, customer
 * context, discounts, tax, and totals are never accepted from the client — they
 * are constructed server-side from the authoritative cart.
 */
class StorePosCartLineUnitPriceOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sell');
    }

    public function rules(): array
    {
        return [
            'unit_price' => ['required_without:approval_token', 'nullable', 'numeric', 'gte:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'approval_token' => ['nullable', 'string', 'max:100'],
        ];
    }
}
