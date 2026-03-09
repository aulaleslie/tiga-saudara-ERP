<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdatePosCartCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sell');
    }

    public function rules(): array
    {
        return [
            'customer_id' => [
                'nullable',
                'integer',
                'exists:customers,id',
            ],
        ];
    }
}
