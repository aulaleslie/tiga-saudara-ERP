<?php

namespace Modules\Sale\Services;

use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sale\Entities\Sale;

class SearchFilterProcessor
{
    /**
     * Available status values for sales.
     */
    protected array $validStatuses = [
        Sale::STATUS_DRAFTED,
        Sale::STATUS_WAITING_APPROVAL,
        Sale::STATUS_APPROVED,
        Sale::STATUS_REJECTED,
        Sale::STATUS_DISPATCHED_PARTIALLY,
        Sale::STATUS_DISPATCHED,
        Sale::STATUS_RETURNED,
        Sale::STATUS_RETURNED_PARTIALLY,
    ];

    /**
     * Validate filter criteria.
     *
     * @param array $filters
     * @return array
     * @throws ValidationException
     */
    public function validateFilters(array $filters): array
    {
        $rules = [
            'serial_number' => 'nullable|string|max:255',
            'sale_reference' => 'nullable|string|max:255',
            'customer_id' => 'nullable|integer|min:1',
            'customer_name' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:' . implode(',', $this->validStatuses),
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'location_id' => 'nullable|integer|min:1',
            'product_id' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];

        $messages = [
            'serial_number.string' => 'Serial number must be a string.',
            'sale_reference.string' => 'Sale reference must be a string.',
            'customer_id.integer' => 'Customer ID must be an integer.',
            'customer_name.string' => 'Customer name must be a string.',
            'status.in' => 'Invalid status value. Allowed values: ' . implode(', ', $this->validStatuses),
            'date_from.date_format' => 'Date from must be in Y-m-d format.',
            'date_to.date_format' => 'Date to must be in Y-m-d format.',
            'location_id.integer' => 'Location ID must be an integer.',
            'product_id.integer' => 'Product ID must be an integer.',
            'page.integer' => 'Page must be an integer.',
            'per_page.integer' => 'Per page must be an integer between 1 and 100.',
        ];

        $validator = ValidatorFactory::make($filters, $rules, $messages);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return $validator->validated();
    }

    /**
     * Build a filter query based on validated filters.
     *
     * @param Builder $query
     * @param array $filters
     * @return Builder
     */
    public function buildFilterQuery(Builder $query, array $filters): Builder
    {
        // Serial number filter
        if (!empty($filters['serial_number'])) {
            $serial = $filters['serial_number'];
            $query->whereHas('saleDetails', function (Builder $q) use ($serial) {
                $q->where('serial_number_ids', 'like', "%{$serial}%");
            });
        }

        // Sale reference filter
        if (!empty($filters['sale_reference'])) {
            $query->where('reference', 'like', "%{$filters['sale_reference']}%");
        }

        // Customer filter (by ID or name)
        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        } elseif (!empty($filters['customer_name'])) {
            $customerName = $filters['customer_name'];
            $query->whereHas('customer', function (Builder $q) use ($customerName) {
                $q->where('name', 'like', "%{$customerName}%");
            });
        }

        // Status filter
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Date range filters
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        // Location filter
        if (!empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        // Product filter
        if (!empty($filters['product_id'])) {
            $productId = $filters['product_id'];
            $query->whereHas('saleDetails', function (Builder $q) use ($productId) {
                $q->where('product_id', $productId);
            });
        }

        return $query;
    }

    /**
     * Get validation errors formatted for API response.
     *
     * @param array $filters
     * @return array|null
     */
    public function getValidationErrors(array $filters): ?array
    {
        try {
            $this->validateFilters($filters);
            return null;
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    /**
     * Get allowed status values.
     *
     * @return array
     */
    public function getValidStatuses(): array
    {
        return $this->validStatuses;
    }

    /**
     * Sanitize filters by removing null and empty values.
     *
     * @param array $filters
     * @return array
     */
    public function sanitizeFilters(array $filters): array
    {
        return array_filter($filters, function ($value) {
            return !is_null($value) && $value !== '';
        });
    }

    /**
     * Apply default pagination parameters if not provided.
     *
     * @param array $filters
     * @return array
     */
    public function applyPaginationDefaults(array $filters): array
    {
        return array_merge([
            'page' => 1,
            'per_page' => 20,
        ], $filters);
    }

    /**
     * Check if date range is valid (from <= to).
     *
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @return bool
     */
    public function isValidDateRange(?string $dateFrom, ?string $dateTo): bool
    {
        if (!$dateFrom || !$dateTo) {
            return true;
        }

        return strtotime($dateFrom) <= strtotime($dateTo);
    }

    /**
     * Convert filters to string representation for logging.
     *
     * @param array $filters
     * @return string
     */
    public function filtersToString(array $filters): string
    {
        $parts = [];
        foreach ($filters as $key => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = "{$key}={$value}";
            }
        }
        return implode('&', $parts);
    }
}
