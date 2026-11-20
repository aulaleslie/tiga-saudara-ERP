<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaleByProductReportValidator
{
    /**
     * @throws ValidationException
     */
    public function validate(array $data): array
    {
        // Normalize obsolete return-quantity sort to deterministic product_name ordering.
        if (isset($data['sortField']) && !in_array($data['sortField'], ['product_name', 'product_code', 'sold_quantity', 'sold_value', 'average_sales_value'], true)) {
            $data['sortField'] = 'product_name';
        }

        $validator = Validator::make($data, [
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
            'scopeSettingIds' => 'nullable|array',
            'scopeSettingIds.*' => 'integer',
            'customerIds' => 'nullable|array',
            'customerIds.*' => 'integer|exists:customers,id',
            'tagIds' => 'nullable|array',
            'tagIds.*' => 'integer|exists:tags,id',
            'tagLogic' => 'required|string|in:Mencakup semua,Salah satu',
            'categoryIds' => 'nullable|array',
            'categoryIds.*' => 'integer|exists:categories,id',
            'categoryLogic' => 'nullable|string|in:Mencakup semua,Salah satu',
            'brandIds' => 'nullable|array',
            'brandIds.*' => 'integer|exists:brands,id',
            'productIds' => 'nullable|array',
            'productIds.*' => 'integer|exists:products,id',
            'sortField' => 'required|string|in:product_name,product_code,sold_quantity,sold_value,average_sales_value',
            'sortDirection' => 'required|string|in:asc,desc',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $validated['categoryLogic'] = 'Salah satu';
        $validated['brandIds'] = $validated['brandIds'] ?? [];

        return $validated;
    }
}
