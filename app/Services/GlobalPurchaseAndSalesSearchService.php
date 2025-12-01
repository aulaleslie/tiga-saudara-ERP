<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Product\Entities\ProductSerialNumber;
use App\Models\PosReceipt;

/**
 * Global Purchase and Sales Search Service
 *
 * Provides unified search capabilities across both purchase orders and sales orders,
 * supporting serial number tracking, reference lookups, and party searches.
 */
class GlobalPurchaseAndSalesSearchService
{
    /**
     * Search for transactions by serial number (exact or partial match).
     *
     * @param string $serial
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return array
     */
    public function searchBySerialNumber(string $serial, ?int $settingId = null, int $limit = 20, int $page = 1): array
    {
        $startTime = microtime(true);

        // Search purchases
        $purchaseResults = $this->searchPurchasesBySerial($serial, $settingId);

        // Search sales
        $saleResults = $this->searchSalesBySerial($serial, $settingId);

        // Combine and sort results
        $combinedResults = array_merge($purchaseResults, $saleResults);
        usort($combinedResults, fn($a, $b) => strtotime($b['date']) <=> strtotime($a['date']));

        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('GlobalPurchaseAndSalesSearchService::searchBySerialNumber completed', [
            'serial' => $serial,
            'settingId' => $settingId,
            'purchase_results' => count($purchaseResults),
            'sale_results' => count($saleResults),
            'total_results' => count($combinedResults),
            'response_time_ms' => $responseTime
        ]);

        return [
            'results' => array_slice($combinedResults, ($page - 1) * $limit, $limit),
            'total' => count($combinedResults),
            'page' => $page,
            'limit' => $limit,
            'response_time_ms' => $responseTime
        ];
    }

    /**
     * Search purchases by serial number.
     *
     * @param string $serial
     * @param int|null $settingId
     * @return array
     */
    private function searchPurchasesBySerial(string $serial, ?int $settingId = null): array
    {
        $query = Purchase::query()
            ->with(['supplier', 'purchaseDetails.receivedNoteDetails.productSerialNumbers'])
            ->whereHas('purchaseDetails.receivedNoteDetails.productSerialNumbers', function ($q) use ($serial) {
                $q->where('serial_number', 'like', "%{$serial}%");
            });

        if ($settingId !== null) {
            $query->where('setting_id', $settingId);
        }

        return $query->orderByDesc('created_at')->get()->map(function ($purchase) {
            return [
                'type' => 'purchase',
                'id' => $purchase->id,
                'reference' => $purchase->reference,
                'party_name' => $purchase->supplier?->supplier_name ?? 'Unknown Supplier',
                'amount' => $purchase->total_amount,
                'status' => $purchase->status,
                'location' => null,
                'date' => $purchase->created_at->format('Y-m-d'),
                'serial_count' => $this->countSerialsInPurchase($purchase),
                'tenant' => $purchase->setting_id
            ];
        })->toArray();
    }

    /**
     * Search sales by serial number.
     *
     * @param string $serial
     * @param int|null $settingId
     * @return array
     */
    private function searchSalesBySerial(string $serial, ?int $settingId = null): array
    {
        $query = Sale::query()
            ->with(['customer', 'seller', 'saleDetails', 'dispatchDetails'])
            ->where(function (Builder $q) use ($serial) {
                // Search in dispatch_details.serial_numbers
                $q->whereHas('dispatchDetails', function ($dispatchQ) use ($serial) {
                    $dispatchQ->whereRaw('JSON_SEARCH(serial_numbers, \'one\', ?) IS NOT NULL', [$serial]);
                });
                // OR search in sale_details.serial_number_ids
                $q->orWhereHas('saleDetails', function ($saleDetailQ) use ($serial) {
                    $saleDetailQ->whereJsonContains('serial_number_ids', $serial);
                });
            });

        if ($settingId !== null) {
            $query->where('setting_id', $settingId);
        }

        return $query->orderByDesc('created_at')->get()->map(function ($sale) {
            return [
                'type' => 'sale',
                'id' => $sale->id,
                'reference' => $sale->reference,
                'party_name' => $sale->customer?->customer_name ?? 'Unknown Customer',
                'amount' => $sale->total_amount,
                'status' => $sale->status,
                'location' => $sale->location?->name ?? null,
                'seller_name' => $sale->seller?->name ?? null,
                'date' => $sale->created_at->format('Y-m-d'),
                'serial_count' => $this->countSerialsInSale($sale),
                'tenant' => $sale->setting_id
            ];
        })->toArray();
    }

    /**
     * Count serial numbers in a purchase.
     *
     * @param Purchase $purchase
     * @return int
     */
    private function countSerialsInPurchase(Purchase $purchase): int
    {
        return $purchase->purchaseDetails->sum(function ($detail) {
            return $detail->receivedNoteDetails->sum(function ($receivedNoteDetail) {
                return $receivedNoteDetail->productSerialNumbers->count();
            });
        });
    }

    /**
     * Count serial numbers in a sale.
     *
     * @param Sale $sale
     * @return int
     */
    private function countSerialsInSale(Sale $sale): int
    {
        return $sale->dispatchDetails->sum(function ($dispatchDetail) {
            $serials = json_decode($dispatchDetail->serial_numbers, true);
            return is_array($serials) ? count($serials) : 0;
        });
    }

    /**
     * Search for purchase orders by reference number.
     *
     * @param string $reference
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return array
     */
    public function searchByPurchaseReference(string $reference, ?int $settingId = null, int $limit = 20, int $page = 1): array
    {
        $startTime = microtime(true);

        $query = Purchase::query()
            ->with(['supplier', 'purchaseDetails'])
            ->where('reference', 'like', "%{$reference}%");

        if ($settingId !== null) {
            $query->where('setting_id', $settingId);
        }

        $paginator = $query->orderByDesc('created_at')->paginate($limit, ['*'], 'page', $page);

        $results = $paginator->getCollection()->map(function ($purchase) {
            return [
                'type' => 'purchase',
                'id' => $purchase->id,
                'reference' => $purchase->reference,
                'party_name' => $purchase->supplier?->supplier_name ?? 'Unknown Supplier',
                'amount' => $purchase->total_amount,
                'status' => $purchase->status,
                'location' => null,
                'date' => $purchase->created_at->format('Y-m-d'),
                'serial_count' => $this->countSerialsInPurchase($purchase),
                'tenant' => $purchase->setting_id
            ];
        })->toArray();

        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        return [
            'results' => $results,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'limit' => $paginator->perPage(),
            'response_time_ms' => $responseTime
        ];
    }

    /**
     * Search for sales orders by reference number.
     *
     * @param string $reference
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return array
     */
    public function searchBySalesReference(string $reference, ?int $settingId = null, int $limit = 20, int $page = 1): array
    {
        $startTime = microtime(true);

        $query = Sale::query()
            ->with(['customer', 'seller', 'saleDetails', 'dispatchDetails'])
            ->where('reference', 'like', "%{$reference}%");

        if ($settingId !== null) {
            $query->where('setting_id', $settingId);
        }

        $paginator = $query->orderByDesc('created_at')->paginate($limit, ['*'], 'page', $page);

        $results = $paginator->getCollection()->map(function ($sale) {
            return [
                'type' => 'sale',
                'id' => $sale->id,
                'reference' => $sale->reference,
                'party_name' => $sale->customer?->customer_name ?? 'Unknown Customer',
                'amount' => $sale->total_amount,
                'status' => $sale->status,
                'location' => $sale->location?->name ?? null,
                'seller_name' => $sale->seller?->name ?? null,
                'date' => $sale->created_at->format('Y-m-d'),
                'serial_count' => $this->countSerialsInSale($sale),
                'tenant' => $sale->setting_id
            ];
        })->toArray();

        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        return [
            'results' => $results,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'limit' => $paginator->perPage(),
            'response_time_ms' => $responseTime
        ];
    }

    /**
     * Search for purchases by supplier name.
     *
     * @param string $supplierName
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return array
     */
    public function searchBySupplier(string $supplierName, ?int $settingId = null, int $limit = 20, int $page = 1): array
    {
        $startTime = microtime(true);

        $query = Purchase::query()
            ->with(['supplier', 'purchaseDetails'])
            ->whereHas('supplier', function ($q) use ($supplierName) {
                $q->where('supplier_name', 'like', "%{$supplierName}%");
            });

        if ($settingId !== null) {
            $query->where('setting_id', $settingId);
        }

        $paginator = $query->orderByDesc('created_at')->paginate($limit, ['*'], 'page', $page);

        $results = $paginator->getCollection()->map(function ($purchase) {
            return [
                'type' => 'purchase',
                'id' => $purchase->id,
                'reference' => $purchase->reference,
                'party_name' => $purchase->supplier?->supplier_name ?? 'Unknown Supplier',
                'amount' => $purchase->total_amount,
                'status' => $purchase->status,
                'location' => null,
                'date' => $purchase->created_at->format('Y-m-d'),
                'serial_count' => $this->countSerialsInPurchase($purchase),
                'tenant' => $purchase->setting_id
            ];
        })->toArray();

        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        return [
            'results' => $results,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'limit' => $paginator->perPage(),
            'response_time_ms' => $responseTime
        ];
    }

    /**
     * Search for sales by customer name.
     *
     * @param string $customerName
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return array
     */
    public function searchByCustomer(string $customerName, ?int $settingId = null, int $limit = 20, int $page = 1): array
    {
        $startTime = microtime(true);

        $query = Sale::query()
            ->with(['customer', 'seller', 'saleDetails', 'dispatchDetails'])
            ->whereHas('customer', function ($q) use ($customerName) {
                $q->where('customer_name', 'like', "%{$customerName}%");
            });

        if ($settingId !== null) {
            $query->where('setting_id', $settingId);
        }

        $paginator = $query->orderByDesc('created_at')->paginate($limit, ['*'], 'page', $page);

        $results = $paginator->getCollection()->map(function ($sale) {
            return [
                'type' => 'sale',
                'id' => $sale->id,
                'reference' => $sale->reference,
                'party_name' => $sale->customer?->customer_name ?? 'Unknown Customer',
                'amount' => $sale->total_amount,
                'status' => $sale->status,
                'location' => $sale->location?->name ?? null,
                'seller_name' => $sale->seller?->name ?? null,
                'date' => $sale->created_at->format('Y-m-d'),
                'serial_count' => $this->countSerialsInSale($sale),
                'tenant' => $sale->setting_id
            ];
        })->toArray();

        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        return [
            'results' => $results,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'limit' => $paginator->perPage(),
            'response_time_ms' => $responseTime
        ];
    }

    /**
     * Combined search across all fields and transaction types.
     *
     * @param string $query
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return array
     */
    public function searchCombined(string $query, ?int $settingId = null, int $limit = 20, int $page = 1): array
    {
        $startTime = microtime(true);

        // Search all types in parallel
        $serialResults = $this->searchBySerialNumber($query, $settingId, 1000, 1)['results'];
        $purchaseRefResults = $this->searchByPurchaseReference($query, $settingId, 1000, 1)['results'];
        $salesRefResults = $this->searchBySalesReference($query, $settingId, 1000, 1)['results'];
        $supplierResults = $this->searchBySupplier($query, $settingId, 1000, 1)['results'];
        $customerResults = $this->searchByCustomer($query, $settingId, 1000, 1)['results'];
        $posTransactionResults = $this->searchByPosTransactionNo($query, $settingId, 1000, 1)['results'];
        $productResults = $this->searchByProduct($query, $settingId, 1000, 1)['results'];

        // Combine all results
        $allResults = array_merge(
            $serialResults,
            $purchaseRefResults,
            $salesRefResults,
            $supplierResults,
            $customerResults,
            $posTransactionResults,
            $productResults
        );

        // Remove duplicates based on type + id
        $uniqueResults = [];
        $seen = [];
        foreach ($allResults as $result) {
            $key = $result['type'] . '_' . $result['id'];
            if (!in_array($key, $seen)) {
                $seen[] = $key;
                $uniqueResults[] = $result;
            }
        }

        // Sort by date descending
        usort($uniqueResults, fn($a, $b) => strtotime($b['date']) <=> strtotime($a['date']));

        $total = count($uniqueResults);
        $paginatedResults = array_slice($uniqueResults, ($page - 1) * $limit, $limit);

        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('GlobalPurchaseAndSalesSearchService::searchCombined completed', [
            'query' => $query,
            'settingId' => $settingId,
            'serial_results' => count($serialResults),
            'purchase_ref_results' => count($purchaseRefResults),
            'sales_ref_results' => count($salesRefResults),
            'supplier_results' => count($supplierResults),
            'customer_results' => count($customerResults),
            'pos_transaction_results' => count($posTransactionResults),
            'product_results' => count($productResults),
            'unique_results' => $total,
            'response_time_ms' => $responseTime
        ]);

        return [
            'results' => $paginatedResults,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'response_time_ms' => $responseTime
        ];
    }

    /**
     * Search for POS transactions by receipt number.
     *
     * @param string $receiptNumber
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return array
     */
    public function searchByPosTransactionNo(string $receiptNumber, ?int $settingId = null, int $limit = 20, int $page = 1): array
    {
        $startTime = microtime(true);

        $query = PosReceipt::query()
            ->with(['sales.customer', 'posSession.location'])
            ->where('receipt_number', 'like', "%{$receiptNumber}%")
            ->whereHas('sales', function (Builder $query) use ($settingId) {
                if ($settingId !== null) {
                    $query->where('setting_id', $settingId);
                }
            });

        $paginator = $query->orderByDesc('created_at')->paginate($limit, ['*'], 'page', $page);

        $results = $paginator->getCollection()->map(function ($receipt) {
            $sale = $receipt->sales->first();
            return [
                'type' => 'pos_transaction',
                'id' => $receipt->id,
                'reference' => $receipt->receipt_number,
                'party_name' => $receipt->customer_name ?: ($sale?->customer?->customer_name ?: 'Walk-in'),
                'amount' => $receipt->total_amount,
                'status' => $receipt->payment_status,
                'location' => $receipt->posSession?->location?->name ?? null,
                'date' => $receipt->created_at->format('Y-m-d'),
                'serial_count' => 0, // POS transactions may not have serial tracking
                'tenant' => $sale?->setting_id ?? null
            ];
        })->toArray();

        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        return [
            'results' => $results,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'limit' => $paginator->perPage(),
            'response_time_ms' => $responseTime
        ];
    }

    /**
     * Search for transactions by product name or code.
     *
     * @param string $productQuery
     * @param int|null $settingId
     * @param int $limit
     * @param int $page
     * @return array
     */
    public function searchByProduct(string $productQuery, ?int $settingId = null, int $limit = 20, int $page = 1): array
    {
        $startTime = microtime(true);

        // Search in purchases
        $purchaseResults = $this->searchPurchasesByProduct($productQuery, $settingId);

        // Search in sales
        $saleResults = $this->searchSalesByProduct($productQuery, $settingId);

        // Combine and sort results
        $combinedResults = array_merge($purchaseResults, $saleResults);
        usort($combinedResults, fn($a, $b) => strtotime($b['date']) <=> strtotime($a['date']));

        $total = count($combinedResults);
        $paginatedResults = array_slice($combinedResults, ($page - 1) * $limit, $limit);

        $responseTime = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('GlobalPurchaseAndSalesSearchService::searchByProduct completed', [
            'productQuery' => $productQuery,
            'settingId' => $settingId,
            'purchase_results' => count($purchaseResults),
            'sale_results' => count($saleResults),
            'total_results' => $total,
            'response_time_ms' => $responseTime
        ]);

        return [
            'results' => $paginatedResults,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'response_time_ms' => $responseTime
        ];
    }

    /**
     * Search purchases by product name or code.
     */
    private function searchPurchasesByProduct(string $productQuery, ?int $settingId = null): array
    {
        $query = Purchase::query()
            ->with(['supplier', 'purchaseDetails.product'])
            ->whereHas('purchaseDetails.product', function (Builder $q) use ($productQuery) {
                $q->where('product_name', 'like', "%{$productQuery}%")
                  ->orWhere('product_code', 'like', "%{$productQuery}%");
            });

        if ($settingId !== null) {
            $query->where('setting_id', $settingId);
        }

        return $query->orderByDesc('created_at')->get()->map(function ($purchase) {
            return [
                'type' => 'purchase',
                'id' => $purchase->id,
                'reference' => $purchase->reference,
                'party_name' => $purchase->supplier?->supplier_name ?? 'Unknown Supplier',
                'amount' => $purchase->total_amount,
                'status' => $purchase->status,
                'location' => null,
                'date' => $purchase->created_at->format('Y-m-d'),
                'serial_count' => $this->countSerialsInPurchase($purchase),
                'tenant' => $purchase->setting_id
            ];
        })->toArray();
    }

    /**
     * Search sales by product name or code.
     */
    private function searchSalesByProduct(string $productQuery, ?int $settingId = null): array
    {
        $query = Sale::query()
            ->with(['customer', 'seller', 'saleDetails.product'])
            ->whereHas('saleDetails.product', function (Builder $q) use ($productQuery) {
                $q->where('product_name', 'like', "%{$productQuery}%")
                  ->orWhere('product_code', 'like', "%{$productQuery}%");
            });

        if ($settingId !== null) {
            $query->where('setting_id', $settingId);
        }

        return $query->orderByDesc('created_at')->get()->map(function ($sale) {
            return [
                'type' => 'sale',
                'id' => $sale->id,
                'reference' => $sale->reference,
                'party_name' => $sale->customer?->customer_name ?? 'Unknown Customer',
                'amount' => $sale->total_amount,
                'status' => $sale->status,
                'location' => $sale->location?->name ?? null,
                'seller_name' => $sale->seller?->name ?? null,
                'date' => $sale->created_at->format('Y-m-d'),
                'serial_count' => $this->countSerialsInSale($sale),
                'tenant' => $sale->setting_id
            ];
        })->toArray();
    }

    /**
     * Get autocomplete suggestions for search queries.
     *
     * @param string $query
     * @param string $type
     * @param int|null $settingId
     * @param int $limit
     * @return array
     */
    public function getSuggestions(string $query, string $type, ?int $settingId = null, int $limit = 10): array
    {
        switch ($type) {
            case 'serial':
                return $this->getSerialSuggestions($query, $settingId, $limit);
            case 'purchase_ref':
                return $this->getPurchaseReferenceSuggestions($query, $settingId, $limit);
            case 'sales_ref':
                return $this->getSalesReferenceSuggestions($query, $settingId, $limit);
            case 'supplier':
                return $this->getSupplierSuggestions($query, $settingId, $limit);
            case 'customer':
                return $this->getCustomerSuggestions($query, $settingId, $limit);
            default:
                return [];
        }
    }

    /**
     * Get serial number suggestions.
     */
    private function getSerialSuggestions(string $query, ?int $settingId = null, int $limit = 10): array
    {
        // From sales dispatch details
        $salesSerials = DB::table('dispatch_details')
            ->whereNotNull('serial_numbers')
            ->whereRaw('JSON_SEARCH(serial_numbers, \'one\', ?) IS NOT NULL', ["%{$query}%"])
            ->selectRaw('JSON_UNQUOTE(JSON_EXTRACT(serial_numbers, CONCAT(\'$.*\'))) as serial')
            ->limit($limit * 2)
            ->get()
            ->pluck('serial')
            ->filter()
            ->unique()
            ->take($limit)
            ->toArray();

        // From purchase serial numbers
        $purchaseSerials = ProductSerialNumber::query()
            ->where('serial_number', 'like', "%{$query}%")
            ->select('serial_number')
            ->distinct()
            ->limit($limit)
            ->pluck('serial_number')
            ->toArray();

        return array_unique(array_merge($salesSerials, $purchaseSerials));
    }

    /**
     * Get purchase reference suggestions.
     */
    private function getPurchaseReferenceSuggestions(string $query, ?int $settingId = null, int $limit = 10): array
    {
        $queryBuilder = Purchase::query()
            ->where('reference', 'like', "%{$query}%")
            ->select('reference')
            ->distinct()
            ->limit($limit);

        if ($settingId !== null) {
            $queryBuilder->where('setting_id', $settingId);
        }

        return $queryBuilder->pluck('reference')->toArray();
    }

    /**
     * Get sales reference suggestions.
     */
    private function getSalesReferenceSuggestions(string $query, ?int $settingId = null, int $limit = 10): array
    {
        $queryBuilder = Sale::query()
            ->where('reference', 'like', "%{$query}%")
            ->select('reference')
            ->distinct()
            ->limit($limit);

        if ($settingId !== null) {
            $queryBuilder->where('setting_id', $settingId);
        }

        return $queryBuilder->pluck('reference')->toArray();
    }

    /**
     * Get supplier name suggestions.
     */
    private function getSupplierSuggestions(string $query, ?int $settingId = null, int $limit = 10): array
    {
        $queryBuilder = DB::table('suppliers')
            ->where('supplier_name', 'like', "%{$query}%")
            ->select('supplier_name')
            ->distinct()
            ->limit($limit);

        if ($settingId !== null) {
            $queryBuilder->join('purchases', 'suppliers.id', '=', 'purchases.supplier_id')
                        ->where('purchases.setting_id', $settingId);
        }

        return $queryBuilder->pluck('supplier_name')->toArray();
    }

    /**
     * Get customer name suggestions.
     */
    private function getCustomerSuggestions(string $query, ?int $settingId = null, int $limit = 10): array
    {
        $queryBuilder = DB::table('customers')
            ->where('customer_name', 'like', "%{$query}%")
            ->select('customer_name')
            ->distinct()
            ->limit($limit);

        if ($settingId !== null) {
            $queryBuilder->join('sales', 'customers.id', '=', 'sales.customer_id')
                        ->where('sales.setting_id', $settingId);
        }

        return $queryBuilder->pluck('customer_name')->toArray();
    }
}