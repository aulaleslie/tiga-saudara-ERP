<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InventoryReplaySupport;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\Transaction;

class InventorySummaryReportQueryService
{
    use InventoryReplaySupport;

    public function getSummary(InventorySummaryReportFilterData $filters, int $settingId, int $perPage = 15, int $page = 1): array
    {
        $productsQuery = Product::query()
            ->where('setting_id', $settingId)
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
                'allRows' => collect()
            ];
        }

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

        $asOfDate = $filters->asOfDate;
        
        $results = [];
        $totalValue = 0.0;

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

            if ($filters->stockStatus === 'available' && $runningStock <= 0) {
                continue;
            }
            if ($filters->stockStatus === 'out_of_stock' && $runningStock > 0) {
                continue;
            }
            if ($filters->stockStatus === 'below_minimum' && $runningStock >= ($product->product_stock_alert ?? 0)) {
                continue;
            }

            $value = $runningStock * $runningAvg;

            $results[] = [
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'product_unit' => $product->product_unit ?? 'Pcs',
                'minimum_stock' => $product->product_stock_alert ?? 0,
                'stock' => $runningStock,
                'average_cost' => $runningAvg,
                'value' => $value,
            ];
            
            $totalValue += $value;
        }

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
            'totalValue' => $totalValue,
            'allRows' => $resultsCollection,
        ];
    }

}
