<?php

namespace Modules\Adjustment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StockTransferRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Authorize only if the user has permission to create transfers
        return Gate::allows('create_transfers');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'origin_location' => ['required', 'exists:locations,id'],
            'destination_location' => ['required', 'exists:locations,id'],
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['exists:products,id'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['integer', 'min:1'],
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
            'origin_location.required' => 'Origin location is required.',
            'destination_location.required' => 'Destination location is required.',
            'product_ids.required' => 'You must select at least one product.',
            'product_ids.*.exists' => 'The selected product is invalid.',
            'quantities.required' => 'Please provide quantities for each product.',
            'quantities.*.min' => 'Quantities must be at least 1.',
        ];
    }
}
