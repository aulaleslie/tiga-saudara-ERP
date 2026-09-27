<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdatePosCartDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sell');
    }

    public function rules(): array
    {
        return [
            'bill_discount_type' => ['required', 'in:fixed,percentage'],
            'bill_discount_value' => ['required', 'numeric', 'min:0'],
        ];
    }
}
