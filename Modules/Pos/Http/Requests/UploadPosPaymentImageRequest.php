<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadPosPaymentImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pos.sell');
    }

    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'image',
                'mimes:jpeg,png,jpg',
                'max:5120', // 5MB
            ],
            'cart_token' => [
                'required',
                'string',
            ],
        ];
    }
}
