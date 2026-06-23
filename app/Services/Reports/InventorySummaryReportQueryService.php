<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Adjustment\Entities\Transfer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;

class InventorySummaryReportQueryService
{
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

    private function applyTransaction(string $type, float $delta, float $unitPrice, float &$stock, float &$avg): void
    {
        if ($type === 'BUY' && $delta > 0) {
            $newStock = $stock + $delta;
            if ($newStock > 0) {
                $avg = (($avg * $stock) + ($unitPrice * $delta)) / $newStock;
            } else {
                $avg = $unitPrice;
            }
            $stock = $newStock;
            return;
        }

        $stock += $delta;
    }

    private function loadProductPriceMap(Collection $productIds, int $settingId): array
    {
        if (! Schema::hasTable('product_prices') || $productIds->isEmpty()) {
            return [];
        }

        $prices = ProductPrice::query()
            ->where('setting_id', $settingId)
            ->whereIn('product_id', $productIds)
            ->get();

        $map = [];
        foreach ($prices as $price) {
            $map[$price->product_id] = [
                'average' => (float) ($price->average_purchase_price ?? 0),
                'sale' => (float) ($price->sale_price ?? 0),
                'last_purchase' => (float) ($price->last_purchase_price ?? 0),
            ];
        }

        return $map;
    }

    private function loadTransactionPriceMaps(Collection $transactions, Collection $productIds, int $settingId): array
    {
        $purchaseRefs = [];
        $saleRefs = [];

        foreach ($transactions as $transaction) {
            $type = strtoupper((string) $transaction->type);
            $reference = $this->extractReference($transaction->reason);

            if (! $reference) {
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

        $purchasePriceMap = $this->buildPurchasePriceMap($purchaseRefs, $productIds, $settingId);
        [$salePriceMap, $saleNotes] = $this->buildSalePriceMap($saleRefs, $productIds, $settingId);

        return [$purchasePriceMap, $salePriceMap, $saleNotes, $purchaseRefs, $saleRefs];
    }

    private function buildPurchaseDateMap(array $references, int $settingId): array
    {
        if (empty($references)) {
            return [];
        }

        return Purchase::query()
            ->where('setting_id', $settingId)
            ->whereIn('reference', $references)
            ->pluck('date', 'reference')
            ->all();
    }

    private function buildSaleDateMap(array $references, int $settingId): array
    {
        if (empty($references)) {
            return [];
        }

        return Sale::query()
            ->where('setting_id', $settingId)
            ->whereIn('reference', $references)
            ->pluck('date', 'reference')
            ->all();
    }

    private function loadTransferMeta(Collection $transactions): array
    {
        $transferIds = $transactions
            ->filter(fn (Transaction $transaction) => strtoupper((string) $transaction->type) === 'TRF')
            ->map(fn (Transaction $transaction) => $this->extractTransferId($transaction->reason))
            ->filter()
            ->unique()
            ->values();

        if ($transferIds->isEmpty()) {
            return [];
        }

        return Transfer::query()
            ->whereIn('id', $transferIds)
            ->get(['id', 'document_number', 'transfer_date', 'dispatched_at', 'received_at'])
            ->mapWithKeys(function (Transfer $transfer) {
                return [
                    $transfer->id => [
                        'document_number' => $transfer->document_number,
                        'transfer_date' => $transfer->transfer_date,
                        'dispatched_at' => $transfer->dispatched_at,
                        'received_at' => $transfer->received_at,
                    ],
                ];
            })
            ->all();
    }

    private function buildTransactionMeta(
        Collection $transactions,
        array $purchaseDateMap,
        array $saleDateMap,
        array $transferMeta
    ): array {
        $meta = [];

        foreach ($transactions as $transaction) {
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

            $meta[$transaction->id] = [
                'date' => $transactionDate,
                'date_key' => $transactionDate?->toDateString() ?? '9999-12-31',
                'reference' => $reference,
                'display_reference' => $reference ?: '-',
                'sort_reference' => $reference ?? '',
            ];
        }

        return $meta;
    }

    private function compareTransactions(Transaction $left, Transaction $right, array $transactionMeta): int
    {
        $leftMeta = $transactionMeta[$left->id] ?? null;
        $rightMeta = $transactionMeta[$right->id] ?? null;

        $leftDate = $leftMeta['date_key'] ?? '9999-12-31';
        $rightDate = $rightMeta['date_key'] ?? '9999-12-31';

        $dateComparison = strcmp($leftDate, $rightDate);
        if ($dateComparison !== 0) {
            return $dateComparison;
        }

        $leftReference = $leftMeta['sort_reference'] ?? '';
        $rightReference = $rightMeta['sort_reference'] ?? '';

        $referenceComparison = strnatcasecmp($leftReference, $rightReference);
        if ($referenceComparison !== 0) {
            return $referenceComparison;
        }

        return $left->id <=> $right->id;
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

        if ($reference) {
            if ($type === 'BUY' && isset($purchaseDateMap[$reference])) {
                return Carbon::parse($purchaseDateMap[$reference]);
            }

            if (in_array($type, ['DISPATCH', 'SELL'], true) && isset($saleDateMap[$reference])) {
                return Carbon::parse($saleDateMap[$reference]);
            }
        }

        if ($type === 'TRF') {
            $transferId = $this->extractTransferId($transaction->reason);
            $meta = $transferId ? ($transferMeta[$transferId] ?? null) : null;
            if ($meta) {
                $candidate = $delta >= 0
                    ? ($meta['received_at'] ?? $meta['transfer_date'] ?? $meta['dispatched_at'] ?? null)
                    : ($meta['dispatched_at'] ?? $meta['transfer_date'] ?? $meta['received_at'] ?? null);

                if ($candidate) {
                    return Carbon::parse($candidate);
                }
            }
        }

        return $transaction->created_at ? Carbon::parse($transaction->created_at) : null;
    }

    private function buildPurchasePriceMap(array $references, Collection $productIds, int $settingId): array
    {
        if (empty($references) || $productIds->isEmpty()) {
            return [];
        }

        $purchases = Purchase::query()
            ->where('setting_id', $settingId)
            ->whereIn('reference', $references)
            ->get(['id', 'reference']);

        if ($purchases->isEmpty()) {
            return [];
        }

        $referenceById = $purchases->pluck('reference', 'id')->all();
        $purchaseIds = array_keys($referenceById);

        $details = PurchaseDetail::query()
            ->select(['purchase_id', 'product_id', 'quantity', 'price', 'unit_price'])
            ->whereIn('purchase_id', $purchaseIds)
            ->whereIn('product_id', $productIds)
            ->get();

        $totals = [];
        foreach ($details as $detail) {
            $reference = $referenceById[$detail->purchase_id] ?? null;
            if (! $reference) {
                continue;
            }

            $qty = (float) ($detail->quantity ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $price = (float) ($detail->price ?? $detail->unit_price ?? 0);
            $totals[$reference][$detail->product_id]['qty'] = ($totals[$reference][$detail->product_id]['qty'] ?? 0) + $qty;
            $totals[$reference][$detail->product_id]['total'] = ($totals[$reference][$detail->product_id]['total'] ?? 0) + ($price * $qty);
        }

        $map = [];
        foreach ($totals as $reference => $products) {
            foreach ($products as $productId => $data) {
                if (! empty($data['qty'])) {
                    $map[$reference][$productId] = $data['total'] / $data['qty'];
                }
            }
        }

        return $map;
    }

    private function buildSalePriceMap(array $references, Collection $productIds, int $settingId): array
    {
        if (empty($references) || $productIds->isEmpty()) {
            return [[], []];
        }

        $sales = Sale::query()
            ->where('setting_id', $settingId)
            ->whereIn('reference', $references)
            ->get(['id', 'reference', 'note']);

        if ($sales->isEmpty()) {
            return [[], []];
        }

        $referenceById = $sales->pluck('reference', 'id')->all();
        $saleNotes = $sales->pluck('note', 'reference')->all();
        $saleIds = array_keys($referenceById);

        $details = SaleDetails::query()
            ->select(['sale_id', 'product_id', 'quantity', 'unit_price', 'price'])
            ->whereIn('sale_id', $saleIds)
            ->whereIn('product_id', $productIds)
            ->get();

        $totals = [];
        foreach ($details as $detail) {
            $reference = $referenceById[$detail->sale_id] ?? null;
            if (! $reference) {
                continue;
            }

            $qty = (float) ($detail->quantity ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $price = (float) ($detail->unit_price ?? $detail->price ?? 0);
            $totals[$reference][$detail->product_id]['qty'] = ($totals[$reference][$detail->product_id]['qty'] ?? 0) + $qty;
            $totals[$reference][$detail->product_id]['total'] = ($totals[$reference][$detail->product_id]['total'] ?? 0) + ($price * $qty);
        }

        $map = [];
        foreach ($totals as $reference => $products) {
            foreach ($products as $productId => $data) {
                if (! empty($data['qty'])) {
                    $map[$reference][$productId] = $data['total'] / $data['qty'];
                }
            }
        }

        return [$map, $saleNotes];
    }

    private function extractReference(?string $reason): ?string
    {
        if (! $reason) {
            return null;
        }

        if (preg_match('/#\s*([A-Za-z0-9\.\-]+)/', $reason, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractTransferId(?string $reason): ?int
    {
        if (! $reason) {
            return null;
        }

        if (preg_match('/\(#(\d+)\)/', $reason, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/#(\d+)/', $reason, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function resolveDelta(Transaction $transaction): float
    {
        $type = strtoupper((string) $transaction->type);
        $quantity = (float) ($transaction->quantity ?? 0);
        $diff = (float) ($transaction->after_quantity ?? 0) - (float) ($transaction->previous_quantity ?? 0);

        if ($type === 'ADJ' && $diff != 0.0) {
            return $diff;
        }

        if ($quantity != 0.0) {
            return $quantity;
        }

        return $diff;
    }

    private function resolveUnitPrice(
        string $type,
        ?string $reference,
        int $productId,
        array $purchasePriceMap,
        array $salePriceMap,
        float $fallbackPurchase,
        float $fallbackSale
    ): float {
        if (! $reference) {
            return match ($type) {
                'BUY' => $fallbackPurchase,
                'DISPATCH', 'SELL' => $fallbackSale,
                default => 0.0,
            };
        }

        if ($type === 'BUY') {
            return (float) ($purchasePriceMap[$reference][$productId] ?? $fallbackPurchase);
        }

        if (in_array($type, ['DISPATCH', 'SELL'], true)) {
            return (float) ($salePriceMap[$reference][$productId] ?? $fallbackSale);
        }

        return 0.0;
    }
}
