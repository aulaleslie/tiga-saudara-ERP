<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InventoryReplaySupport;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;

class WarehouseStockValuationReportQueryService
{
    use InventoryReplaySupport;

    public function build(WarehouseStockValuationReportFilterData $filter): Collection
    {
        $settingIds = !empty($filter->selectedSettingIds)
            ? $filter->selectedSettingIds
            : ($filter->scopeSettingId ? [$filter->scopeSettingId] : [(int) session('setting_id')]);
        $settingIds = array_values(array_unique(array_filter(array_map('intval', (array) $settingIds))));

        if (empty($settingIds)) {
            return collect();
        }

        $asOfDate = $filter->asOfDate ? Carbon::parse($filter->asOfDate)->endOfDay() : now()->endOfDay();

        $warehousesQuery = Location::query()
            ->whereIn('setting_id', $settingIds)
            ->where('is_active', true);

        if (!empty($filter->warehouseIds)) {
            $warehousesQuery->whereIn('id', $filter->warehouseIds);
        }

        $warehouses = $warehousesQuery->get()->keyBy('id');

        if ($warehouses->isEmpty()) {
            return collect();
        }

        $warehouseOrder = $filter->warehouseNameOrder ?? 'asc';
        if ($warehouseOrder === 'desc') {
            $warehouseIds = $warehouses->sortByDesc('name')->pluck('id')->toArray();
        } else {
            $warehouseIds = $warehouses->sortBy('name')->pluck('id')->toArray();
        }

        $stockProductIds = ProductStock::query()
            ->whereIn('location_id', $warehouseIds)
            ->pluck('product_id');

        $txProductIds = Transaction::query()
            ->whereIn('setting_id', $settingIds)
            ->pluck('product_id');

        $candidateProductIds = $stockProductIds->concat($txProductIds)->unique()->filter()->values();

        if ($candidateProductIds->isEmpty()) {
            return collect();
        }

        $query = Product::query()
            ->whereIn('id', $candidateProductIds)
            ->where('stock_managed', 1)
            ->with(['unit', 'category']);

        if (!empty($filter->categoryIds)) {
            if ($filter->categoryMatchMode === 'all') {
                foreach ($filter->categoryIds as $catId) {
                    $query->where('category_id', $catId);
                }
            } else {
                $query->whereIn('category_id', $filter->categoryIds);
            }
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            return collect();
        }

        $productIds = $products->pluck('id');

        $transactions = Transaction::query()
            ->whereIn('setting_id', $settingIds)
            ->whereIn('product_id', $productIds)
            ->whereIn('location_id', $warehouseIds)
            ->where(function ($q) use ($asOfDate) {
                $q->where('created_at', '<=', $asOfDate)
                  ->orWhereIn('type', ['BUY', 'DISPATCH', 'SELL', 'TRF']);
            })
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
                $purchaseRefs[$transaction->setting_id][$reference] = true;
            }

            if (in_array($type, ['DISPATCH', 'SELL'], true)) {
                $saleRefs[$transaction->setting_id][$reference] = true;
            }
        }

        $purchaseDateMap = [];
        $saleDateMap = [];
        foreach ($settingIds as $sId) {
            if (!empty($purchaseRefs[$sId])) {
                $pMap = $this->buildPurchaseDateMap(array_keys($purchaseRefs[$sId]), $sId);
                foreach ($pMap as $ref => $dt) {
                    $purchaseDateMap["{$sId}_{$ref}"] = $dt;
                }
            }
            if (!empty($saleRefs[$sId])) {
                $sMap = $this->buildSaleDateMap(array_keys($saleRefs[$sId]), $sId);
                foreach ($sMap as $ref => $dt) {
                    $saleDateMap["{$sId}_{$ref}"] = $dt;
                }
            }
        }

        $transferMeta = $this->loadTransferMeta($transactions);

        $priceMaps = [];
        foreach ($settingIds as $sId) {
            $priceMaps[$sId] = $this->loadProductPriceMap($productIds, $sId);
        }

        $transactionsByProductAndLocation = $transactions->groupBy(['product_id', 'location_id']);

        $isMulti = count($settingIds) > 1;

        if (! $isMulti) {
            $singleSettingId = $settingIds[0];
            $results = [];

            foreach ($warehouseIds as $warehouseId) {
                $locationObj = $warehouses->get($warehouseId);
                $isConsignment = (bool) ($locationObj?->is_consignment ?? false);
                $warehouseName = $locationObj?->name ?? 'Unknown';
                if ($isConsignment) {
                    $warehouseName .= ' (Konsinyasi)';
                }

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

                    $averageCost = $priceMaps[$singleSettingId][$product->id]['average'] ?? (float) ($product->average_purchase_price ?? 0);
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
                    $row->is_consignment = $isConsignment;
                    $row->product_id = $product->id;
                    $row->product_code = $product->product_code ?? '';
                    $row->product_name = $product->product_name;
                    $row->product_unit = $product->unit?->short_name ?? $product->product_unit ?? 'PCS';
                    $row->minimum_qty = $minQty;
                    $row->average_cost = $averageCost;
                    $row->qty = $locationQty;
                    $row->stock_value = $isConsignment ? 0.0 : ($locationQty * $averageCost);
                    $row->total_quantity = $locationQty;
                    $row->total_value = $row->stock_value;

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
                $resultsCollection = $resultsCollection->sortBy([
                    ['warehouse_name', $warehouseOrder === 'desc' ? 'desc' : 'asc'],
                    ['product_name', 'asc']
                ])->values();
            }

            return $resultsCollection;
        }

        // Multi-business matrix: One row per product
        $results = [];

        foreach ($products as $product) {
            $minQty = (float) ($product->product_stock_alert ?? 0);
            $totalProductQty = 0.0;
            $totalProductValue = 0.0;
            $bTotals = [];
            $locQtyMap = [];

            foreach ($settingIds as $sId) {
                $bWarehouses = $warehouses->where('setting_id', $sId);
                $bQty = 0.0;
                $bValue = 0.0;
                $bAvg = $priceMaps[$sId][$product->id]['average'] ?? (float) ($product->average_purchase_price ?? 0);

                foreach ($bWarehouses as $wId => $locationObj) {
                    $locationTransactions = $transactionsByProductAndLocation->get($product->id, collect())->get($wId, collect());
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

                    $locQtyMap[$wId] = $locationQty;
                    $bQty += $locationQty;

                    $isConsignment = (bool) ($locationObj?->is_consignment ?? false);
                    if (!$isConsignment) {
                        $bValue += ($locationQty * $bAvg);
                    }
                }

                $bTotals[$sId] = [
                    'qty' => $bQty,
                    'average_cost' => $bAvg,
                    'value' => $bValue,
                ];

                $totalProductQty += $bQty;
                $totalProductValue += $bValue;
            }

            $status = $filter->productStockStatus;
            if ($status) {
                if ($status === 'out_of_stock' && $totalProductQty != 0) continue;
                if ($status === 'available' && $totalProductQty <= 0) continue;
                if ($status === 'below_minimum' && $totalProductQty > $minQty) continue;
            }

            $row = new \stdClass();
            $row->product_id = $product->id;
            $row->product_code = $product->product_code ?? '';
            $row->product_name = $product->product_name;
            $row->product_unit = $product->unit?->short_name ?? $product->product_unit ?? 'PCS';
            $row->minimum_qty = $minQty;
            $row->qty = $totalProductQty;
            $row->total_quantity = $totalProductQty;
            $row->stock_value = $totalProductValue;
            $row->total_value = $totalProductValue;
            $row->businesses = $bTotals;

            foreach ($settingIds as $sId) {
                $row->{"qty_business_{$sId}"} = $bTotals[$sId]['qty'];
                $row->{"avg_business_{$sId}"} = $bTotals[$sId]['average_cost'];
                $row->{"val_business_{$sId}"} = $bTotals[$sId]['value'];
            }

            foreach ($warehouseIds as $wId) {
                $row->{"qty_loc_{$wId}"} = $locQtyMap[$wId] ?? 0.0;
            }

            $results[] = $row;
        }

        $resultsCollection = collect($results);

        $sortField = $filter->sortField;
        $sortDirection = $filter->sortDirection === 'desc';

        if (in_array($sortField, ['product_name', 'product_code', 'qty', 'stock_value'])) {
            $resultsCollection = $resultsCollection->sortBy($sortField, SORT_REGULAR, $sortDirection)->values();
        } else {
            $resultsCollection = $resultsCollection->sortBy('product_name', SORT_REGULAR, false)->values();
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

    public function sumInventoryValueAsOf(int|array $settingScope, string $asOfDate): float
    {
        $settingIds = is_array($settingScope) ? $settingScope : [$settingScope];
        $asOfDateCarbon = Carbon::parse($asOfDate)->endOfDay();
        $totalValue = 0.0;

        foreach ($settingIds as $settingId) {
            $warehouseIds = Location::where('setting_id', $settingId)
                ->where('is_consignment', false)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
            if (empty($warehouseIds)) {
                continue;
            }

            $stockProductIds = ProductStock::query()
                ->whereIn('location_id', $warehouseIds)
                ->pluck('product_id');

            $txProductIds = Transaction::query()
                ->where('setting_id', $settingId)
                ->pluck('product_id');

            $candidateProductIds = $stockProductIds->concat($txProductIds)->unique()->filter()->values();

            if ($candidateProductIds->isEmpty()) {
                continue;
            }

            $products = Product::query()
                ->whereIn('id', $candidateProductIds)
                ->where('stock_managed', 1)
                ->get(['id', 'average_purchase_price']);

            if ($products->isEmpty()) {
                continue;
            }

            $productIds = $products->pluck('id');
            $priceMap = $this->loadProductPriceMap($productIds, $settingId);

            $averageCostMap = [];
            foreach ($products as $product) {
                $averageCostMap[$product->id] = $priceMap[$product->id]['average'] ?? (float) ($product->average_purchase_price ?? 0);
            }

            $productQtyMap = [];

            Transaction::query()
                ->where('setting_id', $settingId)
                ->whereIn('product_id', $productIds)
                ->whereIn('location_id', $warehouseIds)
                ->where(function ($q) use ($asOfDateCarbon) {
                    $q->where('created_at', '<=', $asOfDateCarbon)
                      ->orWhereIn('type', ['BUY', 'DISPATCH', 'SELL', 'TRF']);
                })
                ->orderBy('id')
                ->chunkById(1000, function ($transactions) use (&$productQtyMap, $asOfDateCarbon, $settingId) {
                    $purchaseRefs = [];
                    $saleRefs = [];

                    foreach ($transactions as $transaction) {
                        $type = strtoupper((string) $transaction->type);

                        if ($type === 'ADJ') {
                            $diff = (float) ($transaction->after_quantity ?? 0) - (float) ($transaction->previous_quantity ?? 0);
                            if ($diff == 0.0) {
                                continue;
                            }
                        }

                        $reference = $this->extractReference($transaction->reason);
                        if ($reference) {
                            if ($type === 'BUY') {
                                $purchaseRefs[$reference] = true;
                            }

                            if (in_array($type, ['DISPATCH', 'SELL'], true)) {
                                $saleRefs[$reference] = true;
                            }
                        }
                    }

                    $purchaseDateMap = [];
                    $pMap = $this->buildPurchaseDateMap(array_keys($purchaseRefs), $settingId);
                    foreach ($pMap as $ref => $dt) {
                        $purchaseDateMap["{$settingId}_{$ref}"] = $dt;
                    }

                    $saleDateMap = [];
                    $sMap = $this->buildSaleDateMap(array_keys($saleRefs), $settingId);
                    foreach ($sMap as $ref => $dt) {
                        $saleDateMap["{$settingId}_{$ref}"] = $dt;
                    }

                    $transferMeta = $this->loadTransferMeta($transactions);

                    foreach ($transactions as $transaction) {
                        $delta = $this->resolveDelta($transaction);
                        if ($delta == 0.0) {
                            continue;
                        }

                        $reference = $this->extractReference($transaction->reason);
                        $transactionDate = $this->resolveTransactionDate(
                            $transaction,
                            $delta,
                            $reference,
                            $purchaseDateMap,
                            $saleDateMap,
                            $transferMeta
                        );

                        if ($transactionDate === null || $transactionDate->gt($asOfDateCarbon)) {
                            continue;
                        }

                        $productId = $transaction->product_id;
                        $productQtyMap[$productId] = ($productQtyMap[$productId] ?? 0.0) + $delta;
                    }
                });

            foreach ($productQtyMap as $productId => $qty) {
                $avgCost = $averageCostMap[$productId] ?? 0.0;
                $totalValue += ($qty * $avgCost);
            }
        }

        return $totalValue;
    }

    private function resolveTransactionDate(
        Transaction $transaction,
        float $delta,
        ?string $reference,
        array $purchaseDateMap,
        array $saleDateMap,
        array $transferMeta
    ): ?Carbon {
        $type = strtoupper((string) $transaction->type);
        $sId = $transaction->setting_id;

        if ($reference) {
            $key = "{$sId}_{$reference}";
            if ($type === 'BUY' && isset($purchaseDateMap[$key])) {
                return Carbon::parse($purchaseDateMap[$key]);
            }

            if (in_array($type, ['DISPATCH', 'SELL'], true) && isset($saleDateMap[$key])) {
                return Carbon::parse($saleDateMap[$key]);
            }
        }

        if ($type === 'TRF') {
            $transferId = $this->extractTransferId($transaction->reason);
            $meta = $transferId ? ($transferMeta[$transferId] ?? null) : null;
            if ($meta) {
                $candidate = $delta >= 0
                    ? ($meta['received_at'] ?? null)
                    : ($meta['dispatched_at'] ?? null);

                if ($candidate) {
                    return Carbon::parse($candidate);
                }
            }
        }

        return $transaction->created_at ? Carbon::parse($transaction->created_at) : null;
    }
}
