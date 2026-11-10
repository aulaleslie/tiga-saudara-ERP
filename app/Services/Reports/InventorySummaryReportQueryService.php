<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InventoryReplaySupport;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;

class InventorySummaryReportQueryService
{
    use InventoryReplaySupport;

    public function getSummary(InventorySummaryReportFilterData $filters, array|int $settingIds, int $perPage = 15, int $page = 1): array
    {
        $settingIds = array_values(array_unique(array_filter(array_map('intval', (array) $settingIds))));
        if (empty($settingIds)) {
            return [
                'paginator' => new LengthAwarePaginator([], 0, $perPage, $page),
                'totalItems' => 0,
                'totalValue' => 0.0,
                'businessTotals' => [],
                'allRows' => collect(),
            ];
        }

        $activeLocationIds = Location::query()
            ->whereIn('setting_id', $settingIds)
            ->where('is_active', true)
            ->pluck('id');

        $stockProductIds = ProductStock::query()
            ->whereIn('location_id', $activeLocationIds)
            ->pluck('product_id');

        $txProductIds = Transaction::query()
            ->whereIn('setting_id', $settingIds)
            ->pluck('product_id');

        $candidateProductIds = $stockProductIds->concat($txProductIds)->unique()->filter()->values();

        if ($candidateProductIds->isEmpty()) {
            return [
                'paginator' => new LengthAwarePaginator([], 0, $perPage, $page),
                'totalItems' => 0,
                'totalValue' => 0.0,
                'businessTotals' => [],
                'allRows' => collect(),
            ];
        }

        $productsQuery = Product::query()
            ->whereIn('id', $candidateProductIds)
            ->where('stock_managed', true)
            ->with('category');

        if (!empty($filters->productIds)) {
            $productsQuery->whereIn('id', $filters->productIds);
        }

        $products = $productsQuery->get();

        if (!empty($filters->categoryIds)) {
            $products = $products->filter(function ($product) use ($filters) {
                if (!$product->category_id) {
                    return false;
                }
                $productCategoryIds = [$product->category_id];
                if ($filters->categoryMatchMode === 'all') {
                    foreach ($filters->categoryIds as $id) {
                        if (!in_array($id, $productCategoryIds)) {
                            return false;
                        }
                    }
                    return true;
                } else {
                    foreach ($filters->categoryIds as $id) {
                        if (in_array($id, $productCategoryIds)) {
                            return true;
                        }
                    }
                    return false;
                }
            })->values();
        }

        $productIds = $products->pluck('id');
        if ($productIds->isEmpty()) {
            return [
                'paginator' => new LengthAwarePaginator([], 0, $perPage, $page),
                'totalItems' => 0,
                'totalValue' => 0.0,
                'businessTotals' => [],
                'allRows' => collect(),
            ];
        }

        $asOfDate = $filters->asOfDate;
        $productBusinesses = [];

        foreach ($settingIds as $settingId) {
            $priceMap = $this->loadProductPriceMap($productIds, $settingId);

            $transactions = Transaction::query()
                ->where('setting_id', $settingId)
                ->whereIn('product_id', $productIds)
                ->get();

            [$purchasePriceMap, $salePriceMap, $saleNotes, $purchaseRefs, $saleRefs] = $this->loadTransactionPriceMaps(
                $transactions,
                $productIds,
                $settingId
            );

            $purchaseDateMap = $this->buildPurchaseDateMap($purchaseRefs, $settingId);
            $saleDateMap = $this->buildSaleDateMap($saleRefs, $settingId);
            $transferMeta = $this->loadTransferMeta($transactions);
            $transactionMeta = $this->buildTransactionMeta(
                $transactions,
                $purchaseDateMap,
                $saleDateMap,
                $transferMeta
            );

            $transactionsByProduct = $transactions->groupBy('product_id')->toBase()
                ->map(function (Collection $group) use ($transactionMeta) {
                    return $group->sort(function (Transaction $left, Transaction $right) use ($transactionMeta) {
                        return $this->compareTransactions($left, $right, $transactionMeta);
                    })->values();
                });

            foreach ($products as $product) {
                $productTransactions = $transactionsByProduct->get($product->id, collect());

                $fallbackAvg = (float) ($priceMap[$product->id]['average'] ?? $product->average_purchase_price ?? 0);
                $fallbackSale = (float) ($priceMap[$product->id]['sale'] ?? $product->sale_price ?? 0);
                $fallbackPurchase = (float) ($priceMap[$product->id]['last_purchase'] ?? $product->last_purchase_price ?? 0);

                $runningStock = 0.0;
                $runningAvg = 0.0;

                foreach ($productTransactions as $transaction) {
                    $meta = $transactionMeta[$transaction->id] ?? null;
                    $transactionDate = $meta['date'] ?? null;

                    if (!$transactionDate || $transactionDate->gt($asOfDate)) {
                        continue;
                    }

                    $delta = $this->resolveDelta($transaction);
                    if ($delta == 0.0) {
                        continue;
                    }

                    $type = strtoupper((string) $transaction->type);
                    $reference = $meta['reference'] ?? $this->extractReference($transaction->reason);

                    $unitPrice = $this->resolveUnitPrice(
                        $type,
                        $reference,
                        $product->id,
                        $purchasePriceMap,
                        $salePriceMap,
                        $fallbackPurchase,
                        $fallbackSale
                    );

                    $this->applyTransaction($type, $delta, $unitPrice, $runningStock, $runningAvg);
                }

                if ($runningAvg == 0.0 && $fallbackAvg > 0) {
                    $runningAvg = $fallbackAvg;
                }

                $productBusinesses[$product->id][$settingId] = [
                    'stock' => $runningStock,
                    'average_cost' => $runningAvg,
                    'value' => $runningStock * $runningAvg,
                ];
            }
        }

        $isSingleBusiness = count($settingIds) === 1;
        $singleSettingId = $settingIds[0];

        $results = [];
        $businessTotals = [];
        foreach ($settingIds as $sId) {
            $businessTotals[$sId] = [
                'stock' => 0.0,
                'value' => 0.0,
            ];
        }

        foreach ($products as $product) {
            $bList = $productBusinesses[$product->id] ?? [];
            $totalStock = 0.0;
            $totalValue = 0.0;

            foreach ($settingIds as $sId) {
                $bData = $bList[$sId] ?? ['stock' => 0.0, 'average_cost' => 0.0, 'value' => 0.0];
                $totalStock += $bData['stock'];
                $totalValue += $bData['value'];
            }

            if ($filters->stockStatus === 'available' && $totalStock <= 0) {
                continue;
            }
            if ($filters->stockStatus === 'out_of_stock' && $totalStock > 0) {
                continue;
            }
            if ($filters->stockStatus === 'below_minimum' && $totalStock >= ($product->product_stock_alert ?? 0)) {
                continue;
            }

            $singleBData = $bList[$singleSettingId] ?? ['stock' => 0.0, 'average_cost' => 0.0, 'value' => 0.0];

            $results[] = [
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'product_unit' => $product->product_unit ?? 'Pcs',
                'minimum_stock' => $product->product_stock_alert ?? 0,
                'stock' => $isSingleBusiness ? $singleBData['stock'] : $totalStock,
                'total_stock' => $totalStock,
                'average_cost' => $isSingleBusiness ? $singleBData['average_cost'] : 0.0,
                'value' => $isSingleBusiness ? $singleBData['value'] : $totalValue,
                'total_value' => $totalValue,
                'businesses' => $bList,
            ];

            foreach ($settingIds as $sId) {
                $bData = $bList[$sId] ?? ['stock' => 0.0, 'average_cost' => 0.0, 'value' => 0.0];
                $businessTotals[$sId]['stock'] += $bData['stock'];
                $businessTotals[$sId]['value'] += $bData['value'];
            }
        }

        $grandTotalValue = array_sum(array_column($businessTotals, 'value'));

        $resultsCollection = collect($results);

        $sortColumnMap = [
            'product_name' => 'product_name',
            'product_code' => 'product_code',
            'stock' => 'stock',
            'average_cost' => 'average_cost',
            'value' => 'value',
        ];
        $sortColumn = $sortColumnMap[$filters->sortColumn] ?? 'product_name';

        $resultsCollection = $resultsCollection->sortBy(
            $sortColumn,
            SORT_REGULAR,
            $filters->sortDirection === 'desc'
        )->values();

        $paginator = new LengthAwarePaginator(
            $resultsCollection->forPage($page, $perPage)->values(),
            $resultsCollection->count(),
            $perPage,
            $page
        );

        return [
            'paginator' => $paginator,
            'totalItems' => $resultsCollection->count(),
            'totalValue' => $grandTotalValue,
            'businessTotals' => $businessTotals,
            'allRows' => $resultsCollection,
        ];
    }
}
