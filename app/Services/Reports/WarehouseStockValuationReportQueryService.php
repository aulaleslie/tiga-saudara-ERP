<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InventoryReplaySupport;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;

class WarehouseStockValuationReportQueryService
{
    use InventoryReplaySupport;

    public function build(WarehouseStockValuationReportFilterData $filter): Collection
    {
        $settingId = $filter->scopeSettingId ?: session('setting_id');
        $warehouseIds = $filter->warehouseIds;
        $asOfDate = $filter->asOfDate ? Carbon::parse($filter->asOfDate)->endOfDay() : now()->endOfDay();

        if (empty($warehouseIds)) {
            $warehouseIds = Location::where('setting_id', $settingId)->pluck('id')->toArray();
        }

        if (empty($warehouseIds)) {
            return collect();
        }

        $warehouses = Location::where('setting_id', $settingId)
            ->whereIn('id', $warehouseIds)
            ->get()
            ->keyBy('id');
            
        $warehouseOrder = $filter->warehouseNameOrder ?? 'asc';
        if ($warehouseOrder === 'desc') {
            $warehouseIds = $warehouses->sortByDesc('name')->pluck('id')->toArray();
        } else {
            $warehouseIds = $warehouses->sortBy('name')->pluck('id')->toArray();
        }

        $query = Product::query()
            ->where('setting_id', $settingId)
            ->where('stock_managed', 1)
            ->with(['unit', 'category']);

        if (!empty($filter->categoryIds)) {
            if ($filter->categoryMatchMode === 'all') {
                foreach ($filter->categoryIds as $catId) {
                    $query->whereHas('category', function ($q) use ($catId) {
                        $q->where('categories.id', $catId);
                    });
                }
            } else {
                $query->whereHas('category', function ($q) use ($filter) {
                    $q->whereIn('categories.id', $filter->categoryIds);
                });
            }
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            return collect();
        }

        $productIds = $products->pluck('id');

        $transactions = Transaction::query()
            ->where('setting_id', $settingId)
            ->whereIn('product_id', $productIds)
            ->whereIn('location_id', $warehouseIds)
            ->get();

        $purchaseRefs = [];
        $saleRefs = [];

        foreach ($transactions as $transaction) {
            $type = strtoupper((string) $transaction->type);
            $reference = $this->extractReference($transaction->reason);

            if (!$reference) {
                continue;
            }

            if ($type === 'BUY') {
                $purchaseRefs[$reference] = true;
            }

            if (in_array($type, ['DISPATCH', 'SELL'], true)) {
                $saleRefs[$reference] = true;
            }
        }

        $purchaseDateMap = $this->buildPurchaseDateMap(array_keys($purchaseRefs), $settingId);
        $saleDateMap = $this->buildSaleDateMap(array_keys($saleRefs), $settingId);
        $transferMeta = $this->loadTransferMeta($transactions);

        $priceMap = $this->loadProductPriceMap($productIds, $settingId);

        $transactionsByProductAndLocation = $transactions->groupBy(['product_id', 'location_id']);

        $results = [];

        foreach ($warehouseIds as $warehouseId) {
            $warehouseName = $warehouses->get($warehouseId)?->name ?? 'Unknown';

            foreach ($products as $product) {
                $locationTransactions = $transactionsByProductAndLocation->get($product->id, collect())->get($warehouseId, collect());
                
                $locationQty = 0.0;
                foreach ($locationTransactions as $transaction) {
                    $delta = $this->resolveDelta($transaction);
                    $reference = $this->extractReference($transaction->reason);
                    
                    $transactionDate = $this->resolveTransactionDate(
                        $transaction,
                        $delta,
                        $reference,
                        $purchaseDateMap,
                        $saleDateMap,
                        $transferMeta
                    );
                    
                    if ($transactionDate === null || $transactionDate->gt($asOfDate)) {
                        continue;
                    }
                    
                    $locationQty += $delta;
                }
                
                $averageCost = $priceMap[$product->id]['average'] ?? (float) ($product->average_purchase_price ?? 0);
                $minQty = (float) ($product->product_stock_alert ?? 0);

                // Apply stock status filter
                $status = $filter->productStockStatus;
                if ($status) {
                    if ($status === 'out_of_stock' && $locationQty != 0) continue;
                    if ($status === 'available' && $locationQty <= 0) continue;
                    if ($status === 'below_minimum' && $locationQty > $minQty) continue;
                }

                $row = new \stdClass();
                $row->warehouse_id = $warehouseId;
                $row->warehouse_name = $warehouseName;
                $row->product_id = $product->id;
                $row->product_code = $product->product_code ?? '';
                $row->product_name = $product->product_name;
                $row->product_unit = $product->unit?->short_name ?? $product->product_unit ?? 'PCS';
                $row->minimum_qty = $minQty;
                $row->average_cost = $averageCost;
                $row->qty = $locationQty;
                $row->stock_value = $locationQty * $averageCost;

                $results[] = $row;
            }
        }

        $resultsCollection = collect($results);

        // Apply sorting
        $sortField = $filter->sortField;
        $sortDirection = $filter->sortDirection === 'desc';

        if (in_array($sortField, ['product_name', 'product_code', 'qty', 'stock_value', 'average_cost'])) {
            $resultsCollection = $resultsCollection->sortBy([
                ['warehouse_name', $warehouseOrder === 'desc' ? 'desc' : 'asc'],
                [$sortField, $sortDirection ? 'desc' : 'asc']
            ])->values();
        } else {
            // Default sort: just warehouse, then probably product_name if nothing else specified.
            $resultsCollection = $resultsCollection->sortBy([
                ['warehouse_name', $warehouseOrder === 'desc' ? 'desc' : 'asc'],
                ['product_name', 'asc']
            ])->values();
        }

        return $resultsCollection;
    }

    public function paginate(WarehouseStockValuationReportFilterData $filter, &$grandTotal = null): LengthAwarePaginator
    {
        $allResults = $this->build($filter);
        $perPage = $filter->perPage;
        $page = $filter->page;

        if (func_num_args() > 1) {
            $grandTotal = $allResults->sum('stock_value');
        }

        return new LengthAwarePaginator(
            $allResults->forPage($page, $perPage)->values(),
            $allResults->count(),
            $perPage,
            $page
        );
    }
}
