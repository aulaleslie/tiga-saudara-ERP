<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePosCartPriceOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.overrides.price');
    }

    public function rules(): array
    {
        return [
            'unit_price' => ['required', 'numeric', 'gt:0'],
            'supervisor_identifier' => ['required', 'string', 'max:255'],
            'supervisor_pin' => ['required', 'string', 'max:255'],
        ];
    }
}
