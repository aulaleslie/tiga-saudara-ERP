<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePosCartPriceOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sell');
    }

    public function rules(): array
    {
        return [
            'unit_price' => ['required', 'numeric', 'gt:0'],
            'approval_token' => ['nullable', 'string', 'max:100'],
        ];
    }
}
