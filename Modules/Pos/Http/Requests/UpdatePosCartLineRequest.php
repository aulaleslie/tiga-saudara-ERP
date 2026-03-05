<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdatePosCartLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sell');
    }

    public function rules(): array
    {
        return [
            'qty' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'line_discount_type' => ['prohibited'],
            'line_discount_value' => ['prohibited'],
        ];
    }
}
