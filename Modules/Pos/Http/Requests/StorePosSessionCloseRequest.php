<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePosSessionCloseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sessions.close');
    }

    public function rules(): array
    {
        return [
            'counted_cash_total' => ['required', 'numeric', 'min:0'],
            'counted_denominations' => ['nullable', 'array'],
            'counted_denominations.*' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'supervisor_identifier' => ['nullable', 'string', 'max:255'],
            'supervisor_pin' => ['nullable', 'string', 'max:255'],
        ];
    }
}
