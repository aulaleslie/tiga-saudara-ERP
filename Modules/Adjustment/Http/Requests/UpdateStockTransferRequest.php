<?php

namespace Modules\Adjustment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateStockTransferRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Check if the user has permission to update stock transfers
        return Gate::allows('update_transfers');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['required', 'exists:products,id'],

            'quantities' => ['required', 'array'],
            'quantities.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Customize the error messages.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'product_ids.required' => 'At least one product is required.',
            'product_ids.*.exists' => 'The selected product is invalid.',
            'quantities.required' => 'Please enter quantities for the selected products.',
            'quantities.*.integer' => 'Quantities must be valid numbers.',
            'quantities.*.min' => 'Quantities must be at least 1.',
        ];
    }
}
