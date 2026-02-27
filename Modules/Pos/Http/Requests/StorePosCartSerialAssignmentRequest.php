<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePosCartSerialAssignmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'serial_numbers' => ['required', 'array', 'min:1'],
            'serial_numbers.*' => ['required', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
