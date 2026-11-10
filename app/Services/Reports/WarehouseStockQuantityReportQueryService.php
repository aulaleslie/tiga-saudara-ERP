<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;

class WarehouseStockQuantityReportQueryService
{
    public function build(WarehouseStockQuantityReportFilterData $filter): Collection
    {
        $settingIds = !empty($filter->selectedSettingIds)
            ? $filter->selectedSettingIds
            : ($filter->scopeSettingId ? [$filter->scopeSettingId] : [(int) session('setting_id')]);
        $settingIds = array_values(array_unique(array_filter(array_map('intval', (array) $settingIds))));

        if (empty($settingIds)) {
            return collect();
        }

        $availableLocations = Location::query()
            ->whereIn('setting_id', $settingIds)
            ->where('is_active', true)
            ->get(['id', 'setting_id', 'name']);

        if ($availableLocations->isEmpty()) {
            return collect();
        }

        $allAvailableWarehouseIds = $availableLocations->pluck('id')->toArray();
        $warehouseIds = !empty($filter->warehouseIds)
            ? array_values(array_intersect($allAvailableWarehouseIds, $filter->warehouseIds))
            : $allAvailableWarehouseIds;

        if (empty($warehouseIds)) {
            return collect();
        }

        $locationSettingMap = $availableLocations->pluck('setting_id', 'id')->all();

        $asOfDate = $filter->asOfDate ? Carbon::parse($filter->asOfDate)->endOfDay() : now()->endOfDay();

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

        $products = Product::query()
            ->whereIn('id', $candidateProductIds)
            ->where('stock_managed', true)
            ->with(['category', 'unit'])
            ->get();

        if ($products->isEmpty()) {
            return collect();
        }

        $productIds = $products->pluck('id');

        $transactions = Transaction::query()
            ->whereIn('setting_id', $settingIds)
            ->whereIn('product_id', $productIds)
            ->whereIn('location_id', $warehouseIds)
            ->where('created_at', '<=', $asOfDate)
            ->get();

        $purchaseRefs = [];
        $saleRefs = [];

        foreach ($transactions as $transaction) {
            $type = strtoupper((string) $transaction->type);
            $reference = $this->extractReference($transaction->reason);

            if (! $reference) {
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
        $transactionsByProductAndLocation = $transactions->groupBy(['product_id', 'location_id']);

        $results = [];

        foreach ($products as $product) {
            $row = new \stdClass();
            $row->product_id = $product->id;
            $row->product_code = $product->product_code ?? '';
            $row->product_name = $product->product_name;
            $row->product_unit = $product->unit?->short_name ?? $product->product_unit ?? 'PCS';

            $totalQty = 0.0;
            $businessTotals = [];
            foreach ($settingIds as $sId) {
                $businessTotals[$sId] = 0.0;
            }

            foreach ($warehouseIds as $warehouseId) {
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

                    if ($transactionDate === null) {
                        continue;
                    }

                    if ($transactionDate->gt($asOfDate)) {
                        continue;
                    }

                    $locationQty += $delta;
                }

                $row->{"qty_loc_{$warehouseId}"} = $locationQty;
                $totalQty += $locationQty;

                $wSettingId = $locationSettingMap[$warehouseId] ?? null;
                if ($wSettingId !== null && isset($businessTotals[$wSettingId])) {
                    $businessTotals[$wSettingId] += $locationQty;
                }
            }

            foreach ($settingIds as $sId) {
                $row->{"qty_business_{$sId}"} = $businessTotals[$sId] ?? 0.0;
            }
            $row->business_totals = $businessTotals;
            $row->total_quantity = $totalQty;
            $results[] = $row;
        }

        $resultsCollection = collect($results);

        // Apply sorting
        $sortField = $filter->sortField;
        $sortDirection = $filter->sortDirection === 'desc';

        if ($sortField === 'product_name' || $sortField === 'product_code' || $sortField === 'total_quantity') {
            $resultsCollection = $resultsCollection->sortBy($sortField, SORT_REGULAR, $sortDirection)->values();
        }

        return $resultsCollection;
    }

    public function paginate(WarehouseStockQuantityReportFilterData $filter): LengthAwarePaginator
    {
        $allResults = $this->build($filter);
        $perPage = $filter->perPage;
        $page = $filter->page;

        return new LengthAwarePaginator(
            $allResults->forPage($page, $perPage)->values(),
            $allResults->count(),
            $perPage,
            $page
        );
    }

    private function resolveDelta(Transaction $transaction): float
    {
        $type = strtoupper((string) $transaction->type);
        $quantity = (float) ($transaction->quantity ?? 0);

        $prevLocation = (float) ($transaction->previous_quantity_at_location ?? 0);
        $afterLocation = (float) ($transaction->after_quantity_at_location ?? 0);
        $diffLocation = $afterLocation - $prevLocation;

        if ($type === 'ADJ' && $diffLocation != 0.0) {
            return $diffLocation;
        }

        $diffTotal = (float) ($transaction->after_quantity ?? 0) - (float) ($transaction->previous_quantity ?? 0);

        if ($type === 'ADJ' && $diffTotal != 0.0) {
            return $diffTotal;
        }

        if ($quantity != 0.0) {
            return $quantity;
        }

        return $diffLocation != 0.0 ? $diffLocation : $diffTotal;
    }

    private function buildPurchaseDateMap(array $references, int $settingId): array
    {
        if (empty($references)) {
            return [];
        }

        return \Modules\Purchase\Entities\Purchase::query()
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

        return \Modules\Sale\Entities\Sale::query()
            ->where('setting_id', $settingId)
            ->whereIn('reference', $references)
            ->pluck('date', 'reference')
            ->all();
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

        return \Modules\Adjustment\Entities\Transfer::query()
            ->whereIn('id', $transferIds)
            ->get(['id', 'document_number', 'dispatched_at', 'received_at'])
            ->mapWithKeys(function (\Modules\Adjustment\Entities\Transfer $transfer) {
                return [
                    $transfer->id => [
                        'document_number' => $transfer->document_number,
                        'dispatched_at' => $transfer->dispatched_at,
                        'received_at' => $transfer->received_at,
                    ],
                ];
            })
            ->all();
    }
}
