<?php

namespace Modules\Sale\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class GlobalMenuSearchRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'serial_number' => 'nullable|string|max:255',
            'sale_reference' => 'nullable|string|max:255',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:DRAFTED,APPROVED,DISPATCHED,PARTIALLY_DISPATCHED,PARTIALLY_RETURNED,RETURNED,CANCELLED,COMPLETED',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'location_id' => 'nullable|integer|exists:locations,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'product_category_id' => 'nullable|integer|exists:product_categories,id',
            'serial_number_status' => 'nullable|string|in:allocated,dispatched,returned,available',
            'seller_id' => 'nullable|integer|exists:users,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes(): array
    {
        return [
            'serial_number' => 'Serial Number',
            'sale_reference' => 'Sale Reference',
            'customer_id' => 'Customer',
            'customer_name' => 'Customer Name',
            'status' => 'Sales Status',
            'date_from' => 'From Date',
            'date_to' => 'To Date',
            'location_id' => 'Location',
            'product_id' => 'Product',
            'product_category_id' => 'Product Category',
            'serial_number_status' => 'Serial Number Status',
            'seller_id' => 'Seller',
            'per_page' => 'Items Per Page',
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return Gate::allows('sales.search.global');
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Set default pagination values
        if (!$this->has('page')) {
            $this->merge(['page' => 1]);
        }
        
        if (!$this->has('per_page')) {
            $this->merge(['per_page' => 20]);
        }
    }
}
