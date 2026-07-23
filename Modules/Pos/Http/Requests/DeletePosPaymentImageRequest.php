<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeletePosPaymentImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pos.sell');
    }

    public function rules(): array
    {
        return [
            'token' => [
                'required',
                'string',
            ],
            'cart_token' => [
                'required',
                'string',
            ],
        ];
    }
}
