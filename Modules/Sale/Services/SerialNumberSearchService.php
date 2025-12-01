<?php

namespace Modules\Sale\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Product\Entities\ProductSerialNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

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
        $query = Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails', 'location', 'dispatchDetails'])
            ->whereHas('dispatchDetails', function (Builder $query) use ($serial) {
                // Search for serial numbers in the JSON array in dispatch_details
                $query->whereRaw('JSON_SEARCH(serial_numbers, \'one\', ?) IS NOT NULL', [$serial]);
            });

        // Apply tenant filter only if settingId is provided
        if ($settingId !== null) {
            $this->applyTenantFilter($query, $settingId);
        }

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
        $query = Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails', 'location'])
            ->where('reference', $reference);

        // Apply tenant filter only if settingId is provided
        if ($settingId !== null) {
            $query->where('setting_id', $settingId);
        }

        return $query->first();
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
        $query = Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails', 'location'])
            ->whereHas('customer', function (Builder $query) use ($customerIdentifier) {
                if (is_numeric($customerIdentifier)) {
                    $query->where('id', $customerIdentifier);
                } else {
                    $query->where('customer_name', 'like', "%{$customerIdentifier}%");
                }
            });

        // Apply tenant filter only if settingId is provided
        if ($settingId !== null) {
            $this->applyTenantFilter($query, $settingId);
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Build a complex query based on multiple filters.
     *
     * @param array $filters
     * @param int|null $settingId
     * @return Builder
     */
    public function buildQuery(array $filters, ?int $settingId = null): Builder
    {
        Log::info('SerialNumberSearchService::buildQuery called', [
            'filters' => $filters,
            'settingId' => $settingId,
            'session_setting_id' => session('setting_id')
        ]);

        $query = Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails', 'location', 'dispatchDetails']);

        // Apply tenant filter only if settingId is provided (for global search, pass null)
        if ($settingId !== null) {
            $this->applyTenantFilter($query, $settingId);
        }

        // Build search conditions with OR logic for search filters
        $searchConditions = [];

        // Serial number filter - search in both dispatch_details and sale_details
        if (!empty($filters['serial_number'])) {
            Log::info('Adding serial number search condition', ['serial_number' => $filters['serial_number']]);
            $searchConditions[] = function (Builder $q) use ($filters) {
                $q->where(function (Builder $subQ) use ($filters) {
                    // Search in dispatch_details.serial_numbers
                    $subQ->whereHas('dispatchDetails', function (Builder $dispatchQ) use ($filters) {
                        $dispatchQ->whereRaw('JSON_SEARCH(serial_numbers, \'one\', ?) IS NOT NULL', [$filters['serial_number']]);
                    });
                    // OR search in sale_details.serial_number_ids
                    $subQ->orWhereHas('saleDetails', function (Builder $saleDetailQ) use ($filters) {
                        $saleDetailQ->whereJsonContains('serial_number_ids', $filters['serial_number']);
                    });
                });
            };
        }

        // Sale reference filter
        if (!empty($filters['sale_reference'])) {
            Log::info('Adding sale reference search condition', ['sale_reference' => $filters['sale_reference']]);
            $searchConditions[] = function (Builder $q) use ($filters) {
                $q->where('reference', 'like', "%{$filters['sale_reference']}%");
            };
        }

        // Customer filter
        if (!empty($filters['customer_id'])) {
            Log::info('Adding customer ID search condition', ['customer_id' => $filters['customer_id']]);
            $searchConditions[] = function (Builder $q) use ($filters) {
                $q->where('customer_id', $filters['customer_id']);
            };
        } elseif (!empty($filters['customer_name'])) {
            Log::info('Adding customer name search condition', ['customer_name' => $filters['customer_name']]);
            $searchConditions[] = function (Builder $q) use ($filters) {
                $q->whereHas('customer', function (Builder $subQ) use ($filters) {
                    $subQ->where('customer_name', 'like', "%{$filters['customer_name']}%");
                });
            };
        }

        // POS transaction filter
        if (!empty($filters['pos_transaction'])) {
            Log::info('Adding POS transaction search condition', ['pos_transaction' => $filters['pos_transaction']]);
            $searchConditions[] = function (Builder $q) use ($filters) {
                $q->whereHas('posReceipt', function (Builder $subQ) use ($filters) {
                    $subQ->where('receipt_number', 'like', "%{$filters['pos_transaction']}%");
                });
            };
        }

        // Product name/code filter
        if (!empty($filters['product_name'])) {
            Log::info('Adding product name/code search condition', ['product_name' => $filters['product_name']]);
            $searchConditions[] = function (Builder $q) use ($filters) {
                $q->whereHas('saleDetails.product', function (Builder $subQ) use ($filters) {
                    $subQ->where('product_name', 'like', "%{$filters['product_name']}%")
                         ->orWhere('product_code', 'like', "%{$filters['product_name']}%");
                });
            };
        }

        // Apply search conditions with OR logic if any exist
        if (!empty($searchConditions)) {
            $query->where(function (Builder $q) use ($searchConditions) {
                foreach ($searchConditions as $condition) {
                    $q->orWhere($condition);
                }
            });
        }

        // Apply non-search filters with AND logic
        // Status filter
        if (!empty($filters['status'])) {
            Log::info('Applying status filter', ['status' => $filters['status']]);
            $query->where('status', $filters['status']);
        }

        // Date range filters
        if (!empty($filters['date_from'])) {
            Log::info('Applying date_from filter', ['date_from' => $filters['date_from']]);
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            Log::info('Applying date_to filter', ['date_to' => $filters['date_to']]);
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        // Location filter
        if (!empty($filters['location_id'])) {
            Log::info('Applying location filter', ['location_id' => $filters['location_id']]);
            $query->where('location_id', $filters['location_id']);
        }

        // Product filter
        if (!empty($filters['product_id'])) {
            Log::info('Applying product filter', ['product_id' => $filters['product_id']]);
            $query->whereHas('saleDetails', function (Builder $q) use ($filters) {
                $q->where('product_id', $filters['product_id']);
            });
        }

        // Order by most recent first
        $query->orderByDesc('created_at');

        Log::info('Query built successfully', ['query_sql' => $query->toSql(), 'query_bindings' => $query->getBindings()]);

        // Log the actual results before returning
        $rawResults = $query->get();
        Log::info('SerialNumberSearchService::buildQuery raw results', [
            'raw_results_count' => $rawResults->count(),
            'raw_results' => $rawResults->map(function ($sale) {
                return [
                    'id' => $sale->id,
                    'reference' => $sale->reference,
                    'customer_name' => $sale->customer?->customer_name,
                    'status' => $sale->status,
                    'created_at' => $sale->created_at
                ];
            })->toArray()
        ]);

        return $query;
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
        $query = ProductSerialNumber::query()
            ->where('serial_number', 'like', "{$serial}%")
            ->select('serial_number')
            ->distinct();

        // Apply location-based tenant filter only if settingId is provided
        if ($settingId !== null) {
            $query->whereHas('location', function (Builder $query) use ($settingId) {
                $query->where('setting_id', $settingId);
            });
        }

        $suggestions = $query->limit($limit)->pluck('serial_number')->toArray();

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
        // Find sales that have dispatch details containing the serial number
        $query = Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails', 'location', 'dispatchDetails'])
            ->whereHas('dispatchDetails', function (Builder $query) use ($serial) {
                $query->whereRaw('JSON_SEARCH(serial_numbers, \'one\', ?) IS NOT NULL', [$serial]);
            });

        // Apply tenant filter only if settingId is provided
        if ($settingId !== null) {
            $this->applyTenantFilter($query, $settingId);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Search for sales by POS transaction receipt number.
     *
     * @param string $receiptNumber
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return mixed
     */
    public function searchByPosTransactionNo(string $receiptNumber, ?int $settingId = null, int $limit = 50, int $page = 1)
    {
        $query = Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails', 'location', 'posReceipt'])
            ->whereHas('posReceipt', function (Builder $query) use ($receiptNumber) {
                $query->where('receipt_number', 'like', "%{$receiptNumber}%");
            });

        // Apply tenant filter only if settingId is provided
        if ($settingId !== null) {
            $this->applyTenantFilter($query, $settingId);
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Search for sales by product name or code.
     *
     * @param string $productQuery
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return mixed
     */
    public function searchByProductNameOrCode(string $productQuery, ?int $settingId = null, int $limit = 50, int $page = 1)
    {
        $query = Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails.product', 'location', 'dispatchDetails'])
            ->whereHas('saleDetails.product', function (Builder $query) use ($productQuery) {
                $query->where('product_name', 'like', "%{$productQuery}%")
                      ->orWhere('product_code', 'like', "%{$productQuery}%");
            });

        // Apply tenant filter only if settingId is provided
        if ($settingId !== null) {
            $this->applyTenantFilter($query, $settingId);
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }
}
