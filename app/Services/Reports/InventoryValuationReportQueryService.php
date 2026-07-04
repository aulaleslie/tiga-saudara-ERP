<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InventoryReplaySupport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\Transaction;

class InventoryValuationReportQueryService
{
    use InventoryReplaySupport;

    private const TYPE_LABELS = [
        'BUY' => 'Pembelian',
        'SELL' => 'Penjualan',
        'DISPATCH' => 'Penjualan',
        'ADJ' => 'Penyesuaian',
        'TRF' => 'Transfer',
        'OPENING' => 'Saldo Awal',
    ];

    public function getSummary(InventoryValuationReportFilterData $filters, int $settingId, int $perPage = 15, int $page = 1): array
    {
        return $this->buildReport($filters, $settingId, false, null, $perPage, $page);
    }

    public function getReport(InventoryValuationReportFilterData $filters, int $settingId, int $perPage = 15, int $page = 1): array
    {
        return $this->buildReport($filters, $settingId, true, null, $perPage, $page);
    }

    public function getProductDetail(InventoryValuationReportFilterData $filters, int $settingId, int $productId): array
    {
        // Clone the filters to not mutate the original if passed by reference
        $scopedFilters = clone $filters;
        $scopedFilters->productIds = [$productId];
        
        $result = $this->buildReport($scopedFilters, $settingId, true, [$productId], 1, 1);
        return $result['allRows']->first() ?: [];
    }

    private function buildReport(InventoryValuationReportFilterData $filters, int $settingId, bool $loadDetails, ?array $specificProductsToLoadDetail, int $perPage, int $page): array
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

        $sortColumnMap = [
            'product_name' => 'product_name',
            'product_code' => 'product_code',
        ];
        $sortColumn = $sortColumnMap[$filters->sortColumn] ?? 'product_name';
        
        $products = $products->sortBy([
            [$sortColumn, $filters->sortDirection === 'desc' ? 'desc' : 'asc'],
            ['id', 'asc'],
        ])->values();

        $tanggalAwal = $filters->tanggalAwal;
        $tanggalAkhir = $filters->tanggalAkhir;
        
        $allGroupedRows = [];
        $grandTotalValue = 0.0;

        foreach ($products as $product) {
            $productTransactions = $transactionsByProduct->get($product->id, collect());

            $fallbackAvg = (float) ($priceMap[$product->id]['average'] ?? $product->average_purchase_price ?? 0);
            $fallbackSale = (float) ($priceMap[$product->id]['sale'] ?? $product->sale_price ?? 0);
            $fallbackPurchase = (float) ($priceMap[$product->id]['last_purchase'] ?? $product->last_purchase_price ?? 0);
            
            $runningStock = 0.0;
            $runningAvg = 0.0;
            
            $openingStock = 0.0;
            $openingAvg = 0.0;
            
            $periodStockIn = 0.0;
            $periodStockOut = 0.0;
            
            $ledgerRows = [];
            
            $shouldLoadDetailForThisProduct = $loadDetails && ($specificProductsToLoadDetail === null || in_array($product->id, $specificProductsToLoadDetail));

            foreach ($productTransactions as $transaction) {
                $meta = $transactionMeta[$transaction->id] ?? null;
                $transactionDate = $meta['date'] ?? null;

                if (!$transactionDate || $transactionDate->gt($tanggalAkhir)) {
                    continue; // Skip transactions after the period or without date
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

                if ($transactionDate->lt($tanggalAwal)) {
                    // This is before the start date, just accumulate the running totals
                    $openingStock = $runningStock;
                    $openingAvg = $runningAvg;
                } else {
                    if ($delta > 0) {
                        $periodStockIn += $delta;
                    } else {
                        $periodStockOut += abs($delta);
                    }
                    
                    if ($shouldLoadDetailForThisProduct) {
                        // This is within the period, add to ledger
                        $ledgerRows[] = [
                            'date' => $transactionDate->format('Y-m-d'),
                            'type_label' => self::TYPE_LABELS[$type] ?? $type,
                            'reference' => $meta['display_reference'] ?? '-',
                            'description' => $transaction->reason ?? '-',
                            'mutation' => $delta,
                            'running_stock' => $runningStock,
                            'unit' => $product->product_unit ?? 'Pcs',
                            'running_avg' => $runningAvg,
                            'unit_price' => $unitPrice,
                            'running_value' => $runningStock * $runningAvg,
                        ];
                    }
                }
            }

            // Fallback avg if zero and never bought
            if ($openingAvg == 0.0 && $fallbackAvg > 0) {
                $openingAvg = $fallbackAvg;
            }
            if ($runningAvg == 0.0 && $fallbackAvg > 0) {
                $runningAvg = $fallbackAvg;
                // Update ledger rows avg and value if we used fallback
                if ($shouldLoadDetailForThisProduct) {
                    foreach ($ledgerRows as &$row) {
                        if ($row['running_avg'] == 0.0) {
                            $row['running_avg'] = $fallbackAvg;
                            $row['running_value'] = $row['running_stock'] * $fallbackAvg;
                        }
                    }
                }
            }

            $openingValue = $openingStock * $openingAvg;
            $finalValue = $runningStock * $runningAvg;

            $group = [
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'product_unit' => $product->product_unit ?? 'Pcs',
                'opening_stock' => $openingStock,
                'opening_value' => $openingValue,
                'period_stock_in' => $periodStockIn,
                'period_stock_out' => $periodStockOut,
                'ending_stock' => $runningStock,
                'ending_avg' => $runningAvg,
                'ending_value' => $finalValue,
            ];
            
            if ($shouldLoadDetailForThisProduct) {
                $group['opening_row'] = [
                    'date' => $tanggalAwal ? $tanggalAwal->format('Y-m-d') : '-',
                    'type_label' => self::TYPE_LABELS['OPENING'],
                    'reference' => '-',
                    'description' => '-',
                    'mutation' => '-',
                    'running_stock' => $openingStock,
                    'unit' => $product->product_unit ?? 'Pcs',
                    'running_avg' => $openingAvg,
                    'unit_price' => '-',
                    'running_value' => $openingValue,
                ];
                $group['ledger_rows'] = $ledgerRows;
                $group['subtotal'] = [
                    'stock' => $runningStock,
                    'unit' => $product->product_unit ?? 'Pcs',
                    'value' => $finalValue,
                ];
            }

            $allGroupedRows[] = $group;
            $grandTotalValue += $finalValue;
        }

        $resultsCollection = collect($allGroupedRows);

        $paginator = new LengthAwarePaginator(
            $resultsCollection->forPage($page, $perPage)->values(),
            $resultsCollection->count(),
            $perPage,
            $page
        );

        return [
            'paginator' => $paginator,
            'totalValue' => $grandTotalValue,
            'allRows' => $resultsCollection,
        ];
    }

}
