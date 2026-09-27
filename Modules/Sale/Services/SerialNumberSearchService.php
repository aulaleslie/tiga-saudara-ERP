<?php

namespace Modules\Sale\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Product\Entities\ProductSerialNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Modules\Pos\Entities\PosTransaction;
use Illuminate\Pagination\LengthAwarePaginator;

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
     * Build a complex query based on multiple filters for Sales.
     *
     * @param array $filters
     * @param int|null $settingId
     * @return Builder
     */
    public function buildQuery(array $filters, ?int $settingId = null): Builder
    {
        return $this->getSaleQuery($filters, $settingId);
    }

    /**
     * Get Builder for Sales with advanced OR filtering.
     *
     * @param array $filters
     * @param int|null $settingId
     * @return Builder
     */
    public function getSaleQuery(array $filters, ?int $settingId = null): Builder
    {
        $query = Sale::query()
            ->with(['customer', 'seller', 'tenantSetting', 'saleDetails.product', 'location', 'dispatchDetails', 'posCheckout']);

        if ($settingId !== null) {
            $this->applyTenantFilter($query, $settingId);
        }

        $this->applySaleFilters($query, $filters);

        return $query->orderByDesc('created_at');
    }

    /**
     * Apply Sale specific filters.
     */
    protected function applySaleFilters(Builder $query, array $filters): void
    {
        // Keyword Search (OR Logic)
        $keyword = $filters['search'] ?? null;
        
        // Backwards compatibility for UI that sends individual fields
        if (!$keyword && !empty($filters)) {
            $query->where(function (Builder $q) use ($filters) {
                if (!empty($filters['serial_number'])) {
                    $q->orWhere(function(Builder $sq) use ($filters) {
                        $sq->whereHas('dispatchDetails', function (Builder $dq) use ($filters) {
                            $dq->whereRaw('JSON_SEARCH(serial_numbers, \'one\', ?) IS NOT NULL', [$filters['serial_number']]);
                        })->orWhereHas('saleDetails', function (Builder $dq) use ($filters) {
                            $dq->whereJsonContains('serial_number_ids', $filters['serial_number']);
                        });
                    });
                }
                if (!empty($filters['sale_reference'])) {
                    $q->orWhere('reference', 'like', "%{$filters['sale_reference']}%");
                }
                if (!empty($filters['customer_name'])) {
                    $q->orWhereHas('customer', fn($sq) => $sq->where('customer_name', 'like', "%{$filters['customer_name']}%"));
                }
                if (!empty($filters['product_name'])) {
                    $q->orWhereHas('saleDetails.product', fn($sq) => $sq->where('product_name', 'like', "%{$filters['product_name']}%")->orWhere('product_code', 'like', "%{$filters['product_name']}%"));
                }
            });
        }

        if ($keyword) {
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('reference', 'like', "%{$keyword}%")
                  ->orWhereHas('customer', fn($subQ) => $subQ->where('customer_name', 'like', "%{$keyword}%"))
                  ->orWhereHas('posCheckout', function (Builder $subQ) use ($keyword) {
                      $subQ->where('receipt_number', 'like', "%{$keyword}%")
                           ->orWhereHas('transaction', fn($ssq) => $ssq->where('code', 'like', "%{$keyword}%"));
                  })
                  ->orWhereHas('checkoutSale.checkout', function (Builder $subQ) use ($keyword) {
                      $subQ->where('receipt_number', 'like', "%{$keyword}%")
                           ->orWhereHas('transaction', fn($ssq) => $ssq->where('code', 'like', "%{$keyword}%"));
                  })
                  ->orWhereHas('saleDetails.product', fn($subQ) => $subQ->where('product_name', 'like', "%{$keyword}%")->orWhere('product_code', 'like', "%{$keyword}%"))
                  ->orWhereHas('dispatchDetails', function (Builder $subQ) use ($keyword) {
                      $subQ->whereRaw('JSON_SEARCH(serial_numbers, \'one\', ?) IS NOT NULL', [$keyword]);
                  })
                  ->orWhereHas('saleDetails', function (Builder $subQ) use ($keyword) {
                      $subQ->whereJsonContains('serial_number_ids', $keyword);
                  });
            });
        }

        // AND filters
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        if (!empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }
        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
    }

    /**
     * Get Builder for POS Transactions with advanced OR filtering.
     *
     * @param array $filters
     * @param int|null $settingId
     * @return Builder
     */
    public function getPosTransactionQuery(array $filters, ?int $settingId = null): Builder
    {
        $query = PosTransaction::query()
            ->with(['customer', 'creator', 'setting', 'lines', 'completedCheckout.cashier']);

        if ($settingId !== null) {
            $this->applyTenantFilter($query, $settingId);
        }

        $this->applyPosFilters($query, $filters);

        return $query->orderByDesc('created_at');
    }

    /**
     * Apply POS specific filters.
     */
    protected function applyPosFilters(Builder $query, array $filters): void
    {
        $keyword = $filters['search'] ?? null;

        if ($keyword) {
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('code', 'like', "%{$keyword}%")
                  ->orWhereHas('customer', fn($subQ) => $subQ->where('customer_name', 'like', "%{$keyword}%"))
                  ->orWhere('metadata->customer_name', 'like', "%{$keyword}%")
                  ->orWhereHas('creator', fn($subQ) => $subQ->where('name', 'like', "%{$keyword}%"))
                  ->orWhereHas('completedCheckout.cashier', fn($subQ) => $subQ->where('name', 'like', "%{$keyword}%"))
                  ->orWhereHas('completedCheckout', function (Builder $subQ) use ($keyword) {
                      $subQ->where('receipt_number', 'like', "%{$keyword}%")
                           ->orWhereHas('sale', fn($ssq) => $ssq->where('reference', 'like', "%{$keyword}%"))
                           ->orWhereHas('checkoutSales.sale', fn($ssq) => $ssq->where('reference', 'like', "%{$keyword}%"));
                  })
                  ->orWhereHas('lines', fn($subQ) => $subQ->where('product_name_snapshot', 'like', "%{$keyword}%")->orWhere('product_code_snapshot', 'like', "%{$keyword}%"));
            });
        }

        // AND filters
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
    }

    /**
     * Get a unified paginated result combining Sales and POS Transactions.
     */
    public function getUnifiedPagination(array $filters, ?int $settingId = null, int $perPage = 20, int $page = 1)
    {
        // Fetch a safe window of results from both tables
        $fetchLimit = $perPage * $page + $perPage;

        $sales = $this->getSaleQuery($filters, $settingId)->limit($fetchLimit)->get();
        $pos = $this->getPosTransactionQuery($filters, $settingId)->limit($fetchLimit)->get();

        $unified = collect();

        foreach ($sales as $sale) {
            $unified->push([
                'id' => $sale->id,
                'type' => 'sale',
                'reference' => $sale->reference,
                'customer_name' => $sale->customer?->customer_name ?: 'Walking Customer',
                'date' => $sale->created_at->toDateTimeString(),
                'total_amount' => $sale->total_amount,
                'status' => $sale->status,
                'status_label' => $sale->status,
                'raw_date' => $sale->created_at,
            ]);
        }

        foreach ($pos as $p) {
            $total = 0;
            if ($p->status === 'COMPLETED' && $p->completedCheckout) {
                $total = $p->completedCheckout->grand_total;
            } elseif ($p->snapshot_totals) {
                $total = $p->snapshot_totals['total'] ?? 0;
            }

            $unified->push([
                'id' => $p->id,
                'type' => 'pos',
                'reference' => $p->code,
                'customer_name' => $p->customer?->customer_name ?: ($p->metadata['customer_name'] ?? 'Walking Customer'),
                'date' => $p->created_at->toDateTimeString(),
                'total_amount' => $total,
                'status' => $p->status,
                'status_label' => $p->status,
                'raw_date' => $p->created_at,
            ]);
        }

        $sorted = $unified->sortByDesc('raw_date')->values();
        
        $trueTotal = $this->getSaleQuery($filters, $settingId)->count() + $this->getPosTransactionQuery($filters, $settingId)->count();
        $items = $sorted->forPage($page, $perPage);

        return new LengthAwarePaginator(
            $items,
            $trueTotal,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );
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
