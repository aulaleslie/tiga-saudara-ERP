<?php

namespace Modules\Sale\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Product\Entities\ProductSerialNumber;
use Illuminate\Database\Eloquent\Builder;

class SerialNumberSearchService
{
    /**
     * Search for sales orders by serial number (exact or partial match).
     *
     * @param string $serial
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return mixed
     */
    public function searchBySerialNumber(string $serial, ?int $settingId = null, int $limit = 50, int $page = 1)
    {
        $settingId = $settingId ?? session('setting_id');

        $query = Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails', 'location'])
            ->whereHas('saleDetails', function (Builder $query) use ($serial) {
                // Search for serial numbers in the JSON array
                $query->where('serial_number_ids', 'like', "%{$serial}%");
            });

        // Apply tenant filter
        $this->applyTenantFilter($query, $settingId);

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Search for a specific sale by its reference number.
     *
     * @param string $reference
     * @param int|null $settingId
     * @return Sale|null
     */
    public function searchBySaleReference(string $reference, ?int $settingId = null): ?Sale
    {
        $settingId = $settingId ?? session('setting_id');

        return Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails', 'location'])
            ->where('reference', $reference)
            ->where('setting_id', $settingId)
            ->first();
    }

    /**
     * Search for sales by customer name or ID.
     *
     * @param string|int $customerIdentifier
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return mixed
     */
    public function searchByCustomer($customerIdentifier, ?int $settingId = null, int $limit = 50, int $page = 1)
    {
        $settingId = $settingId ?? session('setting_id');

        $query = Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails', 'location'])
            ->whereHas('customer', function (Builder $query) use ($customerIdentifier) {
                if (is_numeric($customerIdentifier)) {
                    $query->where('id', $customerIdentifier);
                } else {
                    $query->where('name', 'like', "%{$customerIdentifier}%");
                }
            });

        // Apply tenant filter
        $this->applyTenantFilter($query, $settingId);

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Build a complex query based on multiple filters.
     *
     * @param array $filters
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return mixed
     */
    public function buildQuery(array $filters, ?int $settingId = null, int $limit = 50, int $page = 1)
    {
        $settingId = $settingId ?? session('setting_id');

        $query = Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails', 'location']);

        // Apply tenant filter
        $this->applyTenantFilter($query, $settingId);

        // Serial number filter
        if (!empty($filters['serial_number'])) {
            $query->whereHas('saleDetails', function (Builder $q) use ($filters) {
                $q->where('serial_number_ids', 'like', "%{$filters['serial_number']}%");
            });
        }

        // Sale reference filter
        if (!empty($filters['sale_reference'])) {
            $query->where('reference', 'like', "%{$filters['sale_reference']}%");
        }

        // Customer filter
        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        } elseif (!empty($filters['customer_name'])) {
            $query->whereHas('customer', function (Builder $q) use ($filters) {
                $q->where('name', 'like', "%{$filters['customer_name']}%");
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
            $query->whereHas('saleDetails', function (Builder $q) use ($filters) {
                $q->where('product_id', $filters['product_id']);
            });
        }

        // Order by most recent first
        $query->orderByDesc('created_at');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Apply tenant isolation filter to the query.
     *
     * @param Builder $query
     * @param int|null $settingId
     * @return void
     */
    public function applyTenantFilter(Builder &$query, ?int $settingId = null): void
    {
        $settingId = $settingId ?? session('setting_id');

        if ($settingId) {
            $query->where('setting_id', $settingId);
        }
    }

    /**
     * Get autocomplete suggestions for serial numbers.
     *
     * @param string $serial
     * @param int|null $settingId
     * @param int $limit
     * @return array
     */
    public function getSerialSuggestions(string $serial, ?int $settingId = null, int $limit = 10): array
    {
        $settingId = $settingId ?? session('setting_id');

        $suggestions = ProductSerialNumber::query()
            ->where('serial_number', 'like', "{$serial}%")
            ->whereHas('location', function (Builder $query) use ($settingId) {
                $query->where('setting_id', $settingId);
            })
            ->select('serial_number')
            ->distinct()
            ->limit($limit)
            ->pluck('serial_number')
            ->toArray();

        return $suggestions;
    }

    /**
     * Get sales associated with a specific serial number with full details.
     *
     * @param string $serial
     * @param int|null $settingId
     * @return Collection
     */
    public function getSalesForSerialNumber(string $serial, ?int $settingId = null): Collection
    {
        $settingId = $settingId ?? session('setting_id');

        // First, find the product serial number record
        $productSerialNumbers = ProductSerialNumber::query()
            ->where('serial_number', $serial)
            ->whereHas('location', function (Builder $query) use ($settingId) {
                $query->where('setting_id', $settingId);
            })
            ->pluck('id');

        if ($productSerialNumbers->isEmpty()) {
            return collect();
        }

        // Then find all sales that have these serial numbers
        return Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails', 'location'])
            ->whereHas('saleDetails', function (Builder $query) use ($productSerialNumbers) {
                // This is a bit complex because we're searching in a JSON array
                foreach ($productSerialNumbers as $id) {
                    $query->orWhere('serial_number_ids', 'like', "%{$id}%");
                }
            })
            ->where('setting_id', $settingId)
            ->orderByDesc('created_at')
            ->get();
    }
}
