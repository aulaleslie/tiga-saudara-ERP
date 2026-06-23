<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InventoryReplaySupport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;

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

    public function getDetail(InventoryDetailReportFilterData $filters, int $settingId, int $perPage = 15, int $page = 1): array
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
                'allRows' => collect()
            ];
        }

        $transactions = Transaction::query()
            ->where('setting_id', $settingId)
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

        foreach ($products as $product) {
            $productTransactions = $transactionsByProduct->get($product->id, collect());
            
            $runningStock = 0.0;
            $openingStock = 0.0;
            
            $ledgerRows = [];

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
                    // This is within the period, add to ledger
                    $ledgerRows[] = [
                        'date' => $transactionDate->format('d/m/Y'),
                        'type_label' => self::TYPE_LABELS[$type] ?? $type,
                        'reference' => $reference,
                        'description' => $transaction->reason ?? '-',
                        'mutation' => $delta,
                        'running_stock' => $runningStock,
                        'unit' => $product->product_unit ?? 'Pcs',
                    ];
                }
            }

            $group = [
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'product_unit' => $product->product_unit ?? 'Pcs',
                'opening_row' => [
                    'date' => $tanggalAwal ? $tanggalAwal->copy()->subDay()->format('d/m/Y') : '-',
                    'type_label' => self::TYPE_LABELS['OPENING'],
                    'reference' => '-',
                    'description' => '-',
                    'mutation' => '-',
                    'running_stock' => $openingStock,
                    'unit' => $product->product_unit ?? 'Pcs',
                ],
                'ledger_rows' => $ledgerRows,
                'subtotal' => [
                    'stock' => $runningStock,
                    'unit' => $product->product_unit ?? 'Pcs',
                ],
            ];

            $allGroupedRows[] = $group;
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
            'totalItems' => $resultsCollection->count(),
            'allRows' => $resultsCollection,
        ];
    }
}
