<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePosTransactionSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sell') && Gate::allows('pos.transactions.save');
    }

    public function rules(): array
    {
        return [];
    }
}
