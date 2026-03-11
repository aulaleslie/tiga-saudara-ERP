<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePosTransactionLoadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sell') && Gate::allows('pos.transactions.load');
    }

    public function rules(): array
    {
        return [];
    }
}
