<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PosCartTotalOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('pos.access');
    }

    public function rules(): array
    {
        return [
            'target_total' => [
                'nullable',
                'numeric',
                'min:0',
                'required_without:approval_token',
            ],
            'reason' => 'nullable|string|max:500',
            'approval_token' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'target_total.required' => 'Target total diperlukan.',
            'target_total.numeric' => 'Target total harus berupa angka.',
            'target_total.min' => 'Target total tidak boleh negatif.',
            'reason.max' => 'Alasan tidak boleh lebih dari 500 karakter.',
            'approval_token.max' => 'Token persetujuan tidak valid.',
        ];
    }
}
