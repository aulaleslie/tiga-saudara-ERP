<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePosTransactionSaveAndPrintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('pos.sell')
            && Gate::allows('pos.transactions.save')
            && Gate::allows('pos.transactions.load')
            && Gate::allows('pos.transactions.print-current');
    }

    public function rules(): array
    {
        return [];
    }
}
