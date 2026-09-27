<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePosCartLineTotalOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sell');
    }

    public function rules(): array
    {
        return [
            'line_total' => ['required_without:approval_token', 'nullable', 'numeric', 'gte:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'approval_token' => ['nullable', 'string', 'max:100'],
        ];
    }
}
