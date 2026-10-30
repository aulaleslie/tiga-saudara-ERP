<?php

namespace Modules\Pos\Http\Requests;

class DeletePosPaymentImageRequest extends PosPaymentImageRequest
{
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
