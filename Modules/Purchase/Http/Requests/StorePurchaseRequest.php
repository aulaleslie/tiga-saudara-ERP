<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePurchaseRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:date',
            'tax_id' => 'nullable|integer|exists:taxes,id',
            'discount_percentage' => 'required|integer|min:0|max:100',
            'shipping_amount' => 'required|numeric',
            'total_amount' => 'required|numeric|min:0', // Ensure total amount is a valid number
            'payment_term' => 'required|integer|exists:payment_terms,id', // New field for payment term
            'note' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return Gate::allows('create_purchase');
    }
}
