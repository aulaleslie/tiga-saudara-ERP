<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InventoryReplaySupport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class InventoryDetailReportQueryService
{
    use InventoryReplaySupport;

    private const TYPE_LABELS = [
        'BUY' => 'Pembelian',
        'SELL' => 'Penjualan',
        'DISPATCH' => 'Pengiriman',
        'ADJ' => 'Penyesuaian',
        'TRF' => 'Transfer',
        'OPENING' => 'Saldo Awal',
    ];

    public function getSummary(InventoryDetailReportFilterData $filters, array|int $settingIds, int $perPage = 15, int $page = 1): array
    {
        return $this->buildReport($filters, $settingIds, false, null, $perPage, $page, true);
    }

    public function getDetail(InventoryDetailReportFilterData $filters, array|int $settingIds, int $perPage = 15, int $page = 1): array
    {
        return $this->buildReport($filters, $settingIds, true, null, $perPage, $page, false);
    }

    public function getProductDetail(InventoryDetailReportFilterData $filters, array|int $settingIds, int $productId): array
    {
        // Clone the filters to not mutate the original if passed by reference
        $scopedFilters = clone $filters;
        $scopedFilters->productIds = [$productId];
        
        $result = $this->buildReport($scopedFilters, $settingIds, true, [$productId], 1, 1, false);
        $first = $result['allRows']->first();
        return $first['businesses'] ?? [];
    }

    private function buildReport(InventoryDetailReportFilterData $filters, array|int $settingIds, bool $loadDetails, ?array $specificProductsToLoadDetail, int $perPage, int $page, bool $paginateBeforeProcessing): array
    {
        $settingIds = is_array($settingIds)
            ? array_values(array_unique(array_filter(array_map('intval', $settingIds))))
            : [(int) $settingIds];

        if (empty($settingIds)) {
            return [
                'paginator' => new LengthAwarePaginator([], 0, $perPage, $page),
                'totalItems' => 0,
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
                'totalItems' => 0,
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
                'totalItems' => $totalItems,
                'allRows' => collect(),
            ];
        }

        $settingsMap = Setting::whereIn('id', $settingIds)->pluck('company_name', 'id')->toArray();

        $businessProductResults = [];

        foreach ($settingIds as $sId) {
            $transactions = Transaction::query()
                ->where('setting_id', $sId)
                ->whereIn('product_id', $productIds)
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

            $purchaseRefs = array_keys($purchaseRefs);
            $saleRefs = array_keys($saleRefs);

            $purchaseDateMap = $this->buildPurchaseDateMap($purchaseRefs, $sId);
            $saleDateMap = $this->buildSaleDateMap($saleRefs, $sId);
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

            $tanggalAwal = $filters->tanggalAwal;
            $tanggalAkhir = $filters->tanggalAkhir;

            foreach ($products as $product) {
                $productTransactions = $transactionsByProduct->get($product->id, collect());
                
                $runningStock = 0.0;
                $openingStock = 0.0;
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
                    $reference = $meta['display_reference'] ?? '-';

                    $runningStock += $delta;

                    if ($transactionDate->lt($tanggalAwal)) {
                        // This is before the start date, just accumulate the running totals
                        $openingStock = $runningStock;
                    } else {
                        if ($delta > 0) {
                            $periodStockIn += $delta;
                        } else {
                            $periodStockOut += abs($delta);
                        }
                        
                        if ($shouldLoadDetailForThisProduct) {
                            // This is within the period, add to ledger
                            $ledgerRows[] = [
                                'date' => $transactionDate->format('d/m/Y'),
                                'type_label' => \App\Support\Reports\InventoryTransactionTypeLabel::label(
                                    $type,
                                    $delta,
                                    \App\Support\Reports\InventoryTransactionTypeLabel::CONTEXT_DETAIL
                                ),
                                'reference' => $reference,
                                'description' => $transaction->reason ?? '-',
                                'mutation' => $delta,
                                'running_stock' => $runningStock,
                                'unit' => $product->product_unit ?? 'Pcs',
                            ];
                        }
                    }
                }

                $bData = [
                    'setting_id' => $sId,
                    'setting_name' => $settingsMap[$sId] ?? "Bisnis #$sId",
                    'opening_stock' => $openingStock,
                    'period_stock_in' => $periodStockIn,
                    'period_stock_out' => $periodStockOut,
                    'ending_stock' => $runningStock,
                ];

                if ($shouldLoadDetailForThisProduct) {
                    $bData['opening_row'] = [
                        'date' => $tanggalAwal ? $tanggalAwal->copy()->subDay()->format('d/m/Y') : '-',
                        'type_label' => \App\Support\Reports\InventoryTransactionTypeLabel::label(
                            'OPENING',
                            null,
                            \App\Support\Reports\InventoryTransactionTypeLabel::CONTEXT_DETAIL
                        ),
                        'reference' => '-',
                        'description' => '-',
                        'mutation' => '-',
                        'running_stock' => $openingStock,
                        'unit' => $product->product_unit ?? 'Pcs',
                    ];
                    $bData['ledger_rows'] = $ledgerRows;
                    $bData['subtotal'] = [
                        'stock' => $runningStock,
                        'unit' => $product->product_unit ?? 'Pcs',
                    ];
                }

                $businessProductResults[$product->id][$sId] = $bData;
            }
        }

        $isSingleBusiness = count($settingIds) <= 1;
        $singleSettingId = $settingIds[0] ?? null;

        $allGroupedRows = [];

        foreach ($products as $product) {
            $productBusinesses = $businessProductResults[$product->id] ?? [];

            $totalOpeningStock = 0.0;
            $totalPeriodStockIn = 0.0;
            $totalPeriodStockOut = 0.0;
            $totalEndingStock = 0.0;

            foreach ($settingIds as $settingId) {
                $businessData = $productBusinesses[$settingId] ?? [
                    'setting_id' => $settingId,
                    'setting_name' => $settingsMap[$settingId] ?? "Bisnis #$settingId",
                    'opening_stock' => 0.0,
                    'period_stock_in' => 0.0,
                    'period_stock_out' => 0.0,
                    'ending_stock' => 0.0,
                ];
                $totalOpeningStock += $businessData['opening_stock'];
                $totalPeriodStockIn += $businessData['period_stock_in'];
                $totalPeriodStockOut += $businessData['period_stock_out'];
                $totalEndingStock += $businessData['ending_stock'];
            }

            $singleBusinessData = $isSingleBusiness && $singleSettingId ? ($productBusinesses[$singleSettingId] ?? null) : null;

            $group = [
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'product_unit' => $product->product_unit ?? 'Pcs',
                'opening_stock' => $isSingleBusiness && $singleBusinessData ? $singleBusinessData['opening_stock'] : $totalOpeningStock,
                'period_stock_in' => $isSingleBusiness && $singleBusinessData ? $singleBusinessData['period_stock_in'] : $totalPeriodStockIn,
                'period_stock_out' => $isSingleBusiness && $singleBusinessData ? $singleBusinessData['period_stock_out'] : $totalPeriodStockOut,
                'ending_stock' => $isSingleBusiness && $singleBusinessData ? $singleBusinessData['ending_stock'] : $totalEndingStock,
                'businesses' => $productBusinesses,
            ];

            if ($loadDetails && ($specificProductsToLoadDetail === null || in_array($product->id, $specificProductsToLoadDetail))) {
                if ($isSingleBusiness && $singleBusinessData) {
                    $group['opening_row'] = $singleBusinessData['opening_row'] ?? null;
                    $group['ledger_rows'] = $singleBusinessData['ledger_rows'] ?? [];
                    $group['subtotal'] = $singleBusinessData['subtotal'] ?? null;
                }
            }

            $allGroupedRows[] = $group;
        }

        $resultsCollection = collect($allGroupedRows);

        $paginator = new LengthAwarePaginator(
            $paginateBeforeProcessing ? $resultsCollection : $resultsCollection->forPage($page, $perPage)->values(),
            $totalItems,
            $perPage,
            $page
        );

        return [
            'paginator' => $paginator,
            'totalItems' => $totalItems,
            'allRows' => $resultsCollection,
        ];
    }
}
