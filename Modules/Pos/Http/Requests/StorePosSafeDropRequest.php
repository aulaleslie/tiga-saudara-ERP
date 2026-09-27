<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePosSafeDropRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.safeDrops.create');
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'supervisor_identifier' => ['nullable', 'string', 'max:255'],
            'supervisor_pin' => ['nullable', 'string', 'max:255'],
        ];
    }
}
