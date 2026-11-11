<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InventoryReplaySupport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class InventoryValuationReportQueryService
{
    use InventoryReplaySupport;

    public function getSummary(InventoryValuationReportFilterData $filters, array|int $settingIds, int $perPage = 15, int $page = 1): array
    {
        return $this->buildReport($filters, $settingIds, false, null, $perPage, $page, true);
    }

    public function getReport(InventoryValuationReportFilterData $filters, array|int $settingIds, int $perPage = 15, int $page = 1): array
    {
        return $this->buildReport($filters, $settingIds, true, null, $perPage, $page, false);
    }

    public function getProductDetail(InventoryValuationReportFilterData $filters, array|int $settingIds, int $productId): array
    {
        // Clone the filters to not mutate the original if passed by reference
        $scopedFilters = clone $filters;
        $scopedFilters->productIds = [$productId];
        
        $result = $this->buildReport($scopedFilters, $settingIds, true, [$productId], 1, 1, false);
        $first = $result['allRows']->first();
        return $first['businesses'] ?? [];
    }

    private function buildReport(InventoryValuationReportFilterData $filters, array|int $settingIds, bool $loadDetails, ?array $specificProductsToLoadDetail, int $perPage, int $page, bool $paginateBeforeProcessing): array
    {
        $settingIds = is_array($settingIds)
            ? array_values(array_unique(array_filter(array_map('intval', $settingIds))))
            : [(int) $settingIds];

        if (empty($settingIds)) {
            return [
                'paginator' => new LengthAwarePaginator([], 0, $perPage, $page),
                'totalValue' => 0.0,
                'businessTotals' => [],
                'allRows' => collect(),
            ];
        }

        // Candidate products: stock or transactions at all locations of selected businesses (including inactive)
        $allLocationIds = Location::query()
            ->whereIn('setting_id', $settingIds)
            ->pluck('id');

        $stockProductIds = ProductStock::query()
            ->whereIn('location_id', $allLocationIds)
            ->pluck('product_id');

        $txProductIds = Transaction::query()
            ->whereIn('setting_id', $settingIds)
            ->pluck('product_id');

        $candidateProductIds = $stockProductIds->concat($txProductIds)->unique()->filter()->values();

        if ($candidateProductIds->isEmpty()) {
            return [
                'paginator' => new LengthAwarePaginator([], 0, $perPage, $page),
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

        $sortColumnMap = [
            'product_name' => 'product_name',
            'product_code' => 'product_code',
        ];
        $sortColumn = $sortColumnMap[$filters->sortColumn] ?? 'product_name';
        
        $products = $products->sortBy([
            [$sortColumn, $filters->sortDirection === 'desc' ? 'desc' : 'asc'],
            ['id', 'asc'],
        ])->values();

        $totalItems = $products->count();
        if ($paginateBeforeProcessing) {
            $products = $products->forPage($page, $perPage)->values();
        }

        $productIds = $products->pluck('id');

        if ($productIds->isEmpty()) {
            return [
                'paginator' => new LengthAwarePaginator([], $totalItems, $perPage, $page),
                'totalValue' => 0.0,
                'businessTotals' => [],
                'allRows' => collect(),
            ];
        }

        $settingsMap = Setting::whereIn('id', $settingIds)->pluck('company_name', 'id')->toArray();

        $businessProductResults = [];

        foreach ($settingIds as $sId) {
            $priceMap = $this->loadProductPriceMap($productIds, $sId);

            $transactions = Transaction::query()
                ->where('setting_id', $sId)
                ->whereIn('product_id', $productIds)
                ->get();

            [$purchasePriceMap, $salePriceMap, $saleNotes, $purchaseRefs, $saleRefs] = $this->loadTransactionPriceMaps(
                $transactions,
                $productIds,
                $sId
            );

            $purchaseDateMap = $this->buildPurchaseDateMap($purchaseRefs, $sId);
            $saleDateMap = $this->buildSaleDateMap($saleRefs, $sId);
            $transferMeta = $this->loadTransferMeta($transactions);
            $buyoutEffects = $this->loadBuyoutTransactionEffects($transactions);
            $transactionMeta = $this->buildTransactionMeta(
                $transactions,
                $purchaseDateMap,
                $saleDateMap,
                $transferMeta
            );

            $bLocationIds = $transactions->pluck('location_id')->filter()->unique();
            $consignmentLocationMap = Location::whereIn('id', $bLocationIds)
                ->pluck('is_consignment', 'id')
                ->toArray();

            $transactionsByProduct = $transactions->groupBy('product_id')->toBase()
                ->map(function (Collection $group) use ($transactionMeta) {
                    return $group->sort(function (Transaction $left, Transaction $right) use ($transactionMeta) {
                        return $this->compareTransactions($left, $right, $transactionMeta);
                    })->values();
                });

            $tanggalAwal = $filters->tanggalAwal;
            $tanggalAkhir = $filters->tanggalAkhir;

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
                    $buyoutEffect = $buyoutEffects[$transaction->id] ?? null;
                    // A buyout's non-owned (consignment) leg is custody, not owned stock.
                    $isConsignmentLocation = !empty($consignmentLocationMap[$transaction->location_id]) || ($buyoutEffect['ignore'] ?? false);
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

                    $this->applyTransaction($type, $delta, $unitPrice, $runningStock, $runningAvg, $isConsignmentLocation);
                    if (($buyoutEffect['average'] ?? null) !== null) {
                        $runningAvg = $buyoutEffect['average'];
                    }

                    if ($transactionDate->lt($tanggalAwal)) {
                        // This is before the start date, just accumulate the running totals
                        $openingStock = $runningStock;
                        $openingAvg = $runningAvg;
                    } else {
                        // Only count toward company-owned period stock movement if not non-owned consignment custody
                        if (!$isConsignmentLocation && !in_array($type, ['CONSIGNMENT_RECEIPT', 'CONSIGNMENT_RECEIPT_REVERSAL'], true)) {
                            if ($delta > 0) {
                                $periodStockIn += $delta;
                            } else {
                                $periodStockOut += abs($delta);
                            }
                        }
                        
                        if ($shouldLoadDetailForThisProduct) {
                            // This is within the period, add to ledger
                            $ledgerRows[] = [
                                'date' => $transactionDate->format('Y-m-d'),
                                'type_label' => \App\Support\Reports\InventoryTransactionTypeLabel::label(
                                    $type,
                                    $delta,
                                    \App\Support\Reports\InventoryTransactionTypeLabel::CONTEXT_VALUATION
                                ),
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
                        unset($row);
                    }
                }

                $openingValue = $openingStock * $openingAvg;
                $finalValue = $runningStock * $runningAvg;

                $bData = [
                    'setting_id' => $sId,
                    'setting_name' => $settingsMap[$sId] ?? "Bisnis #$sId",
                    'opening_stock' => $openingStock,
                    'opening_avg' => $openingAvg,
                    'opening_value' => $openingValue,
                    'period_stock_in' => $periodStockIn,
                    'period_stock_out' => $periodStockOut,
                    'ending_stock' => $runningStock,
                    'ending_avg' => $runningAvg,
                    'ending_value' => $finalValue,
                ];

                if ($shouldLoadDetailForThisProduct) {
                    $bData['opening_row'] = [
                        'date' => $tanggalAwal ? $tanggalAwal->format('Y-m-d') : '-',
                        'type_label' => \App\Support\Reports\InventoryTransactionTypeLabel::label(
                            'OPENING',
                            null,
                            \App\Support\Reports\InventoryTransactionTypeLabel::CONTEXT_VALUATION
                        ),
                        'reference' => '-',
                        'description' => '-',
                        'mutation' => '-',
                        'running_stock' => $openingStock,
                        'unit' => $product->product_unit ?? 'Pcs',
                        'running_avg' => $openingAvg,
                        'unit_price' => '-',
                        'running_value' => $openingValue,
                    ];
                    $bData['ledger_rows'] = $ledgerRows;
                    $bData['subtotal'] = [
                        'stock' => $runningStock,
                        'unit' => $product->product_unit ?? 'Pcs',
                        'value' => $finalValue,
                    ];
                }

                $businessProductResults[$product->id][$sId] = $bData;
            }
        }

        $isSingleBusiness = count($settingIds) <= 1;
        $singleSettingId = $settingIds[0] ?? null;

        $allGroupedRows = [];
        $businessTotals = [];
        foreach ($settingIds as $sId) {
            $businessTotals[$sId] = [
                'setting_id' => $sId,
                'setting_name' => $settingsMap[$sId] ?? "Bisnis #$sId",
                'stock' => 0.0,
                'value' => 0.0,
            ];
        }

        foreach ($products as $product) {
            $productBusinesses = $businessProductResults[$product->id] ?? [];

            $totalOpeningStock = 0.0;
            $totalOpeningValue = 0.0;
            $totalPeriodStockIn = 0.0;
            $totalPeriodStockOut = 0.0;
            $totalEndingStock = 0.0;
            $totalEndingValue = 0.0;

            foreach ($settingIds as $sId) {
                $bData = $productBusinesses[$sId] ?? [
                    'setting_id' => $sId,
                    'setting_name' => $settingsMap[$sId] ?? "Bisnis #$sId",
                    'opening_stock' => 0.0,
                    'opening_avg' => 0.0,
                    'opening_value' => 0.0,
                    'period_stock_in' => 0.0,
                    'period_stock_out' => 0.0,
                    'ending_stock' => 0.0,
                    'ending_avg' => 0.0,
                    'ending_value' => 0.0,
                ];
                $totalOpeningStock += $bData['opening_stock'];
                $totalOpeningValue += $bData['opening_value'];
                $totalPeriodStockIn += $bData['period_stock_in'];
                $totalPeriodStockOut += $bData['period_stock_out'];
                $totalEndingStock += $bData['ending_stock'];
                $totalEndingValue += $bData['ending_value'];

                $businessTotals[$sId]['stock'] += $bData['ending_stock'];
                $businessTotals[$sId]['value'] += $bData['ending_value'];
            }

            $singleBData = $isSingleBusiness && $singleSettingId ? ($productBusinesses[$singleSettingId] ?? null) : null;

            $group = [
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'product_unit' => $product->product_unit ?? 'Pcs',
                'opening_stock' => $isSingleBusiness && $singleBData ? $singleBData['opening_stock'] : $totalOpeningStock,
                'opening_value' => $isSingleBusiness && $singleBData ? $singleBData['opening_value'] : $totalOpeningValue,
                'period_stock_in' => $isSingleBusiness && $singleBData ? $singleBData['period_stock_in'] : $totalPeriodStockIn,
                'period_stock_out' => $isSingleBusiness && $singleBData ? $singleBData['period_stock_out'] : $totalPeriodStockOut,
                'ending_stock' => $isSingleBusiness && $singleBData ? $singleBData['ending_stock'] : $totalEndingStock,
                'ending_avg' => $isSingleBusiness && $singleBData ? $singleBData['ending_avg'] : ($totalEndingStock > 0 ? $totalEndingValue / $totalEndingStock : 0.0),
                'ending_value' => $isSingleBusiness && $singleBData ? $singleBData['ending_value'] : $totalEndingValue,
                'businesses' => $productBusinesses,
            ];

            if ($loadDetails && ($specificProductsToLoadDetail === null || in_array($product->id, $specificProductsToLoadDetail))) {
                if ($isSingleBusiness && $singleBData) {
                    $group['opening_row'] = $singleBData['opening_row'] ?? null;
                    $group['ledger_rows'] = $singleBData['ledger_rows'] ?? [];
                    $group['subtotal'] = $singleBData['subtotal'] ?? null;
                }
            }

            $allGroupedRows[] = $group;
        }

        $grandTotalValue = array_sum(array_column($businessTotals, 'value'));
        $resultsCollection = collect($allGroupedRows);

        $paginator = new LengthAwarePaginator(
            $paginateBeforeProcessing ? $resultsCollection : $resultsCollection->forPage($page, $perPage)->values(),
            $totalItems,
            $perPage,
            $page
        );

        return [
            'paginator' => $paginator,
            'totalValue' => $grandTotalValue,
            'businessTotals' => $businessTotals,
            'allRows' => $resultsCollection,
        ];
    }
}
