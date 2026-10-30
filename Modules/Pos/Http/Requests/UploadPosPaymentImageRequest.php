<?php

namespace Modules\Pos\Http\Requests;

class UploadPosPaymentImageRequest extends PosPaymentImageRequest
{
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
