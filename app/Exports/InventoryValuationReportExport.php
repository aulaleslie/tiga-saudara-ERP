<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Adjustment\Entities\Transfer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;

class InventoryValuationReportExport implements FromArray, WithEvents, WithTitle
{
    protected array $filters;
    protected array $rowMeta = [
        'productHeaders' => [],
        'summaryRows' => [],
        'lastRow' => 0,
    ];

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        return 'Purchases by Supplier';
    }

    public function array(): array
    {
        $settingId = session('setting_id');
        $startDate = Carbon::parse($this->filters['startDate'])->startOfDay();
        $endDate = Carbon::parse($this->filters['endDate'])->endOfDay();
        $productId = $this->filters['productId'] ?? null;
        $locationId = $this->filters['locationId'] ?? null;

        $setting = Setting::with('currency')->find($settingId);
        $companyName = $setting?->company_name ?? 'Company';
        $currencyCode = $setting?->currency?->code ?? 'IDR';

        $products = Product::query()
            ->where('setting_id', $settingId)
            ->where('stock_managed', true)
            ->whereHas('transactions', function ($query) use ($settingId, $locationId) {
                $query->where('setting_id', $settingId)
                    ->whereIn('type', ['BUY', 'DISPATCH', 'SELL']);

                if ($locationId) {
                    $query->where('location_id', $locationId);
                }
            })
            ->when($productId, fn ($q) => $q->where('id', $productId))
            ->orderBy('product_name')
            ->get();

        $productIds = $products->pluck('id');
        $priceMap = $this->loadProductPriceMap($productIds, $settingId);

        $transactions = Transaction::query()
            ->where('setting_id', $settingId)
            ->whereIn('product_id', $productIds)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->get();

        [$purchasePriceMap, $salePriceMap, $saleNotes, $purchaseRefs, $saleRefs] = $this->loadTransactionPriceMaps(
            $transactions,
            $productIds,
            $settingId
        );

        $purchaseDateMap = $this->buildPurchaseDateMap($purchaseRefs, $settingId);
        $purchaseNumberMap = $this->buildPurchaseNumberMap($purchaseRefs, $settingId);
        $saleDateMap = $this->buildSaleDateMap($saleRefs, $settingId);
        $transferMeta = $this->loadTransferMeta($transactions);
        $transactionMeta = $this->buildTransactionMeta(
            $transactions,
            $purchaseDateMap,
            $saleDateMap,
            $transferMeta
        );

        $transactionsByProduct = $transactions->groupBy('product_id')->toBase();
        $eligibleProductIds = $this->resolveEligibleProductIds(
            $transactionsByProduct,
            $transactionMeta,
            $startDate,
            $endDate
        );

        $products = $products
            ->whereIn('id', $eligibleProductIds)
            ->values();

        $transactionsByProduct = $transactionsByProduct
            ->only($eligibleProductIds)
            ->map(function (Collection $group) use ($transactionMeta) {
                return $group->sort(function (Transaction $left, Transaction $right) use ($transactionMeta) {
                    return $this->compareTransactions($left, $right, $transactionMeta);
                })->values();
            });

        $rows = [];
        $rows[] = $this->padRow([$companyName]);
        $rows[] = $this->padRow(['Penilaian Persediaan Barang']);
        $rows[] = $this->padRow([
            $startDate->format('d/m/Y') . ' - ' . $endDate->format('d/m/Y'),
        ]);
        $rows[] = $this->padRow(['(dalam ' . $currencyCode . ')']);
        $rows[] = $this->padRow([]);
        $rows[] = [
            'Tanggal',
            'Transaksi',
            'No',
            'Deskripsi',
            'Mutasi',
            'Stok Barang',
            'Biaya Avg',
            'Harga Beli/Jual',
            'Nilai',
        ];

        $rowIndex = count($rows);

        foreach ($products as $product) {
            $productTransactions = $transactionsByProduct->get($product->id, collect());

            $fallbackAvg = (float) ($priceMap[$product->id]['average'] ?? $product->average_purchase_price ?? 0);
            $fallbackSale = (float) ($priceMap[$product->id]['sale'] ?? $product->sale_price ?? 0);
            $fallbackPurchase = (float) ($priceMap[$product->id]['last_purchase'] ?? $product->last_purchase_price ?? 0);

            $runningStock = 0.0;
            $runningAvg = 0.0;
            $opened = false;

            foreach ($productTransactions as $transaction) {
                $meta = $transactionMeta[$transaction->id] ?? null;
                $transactionDate = $meta['date'] ?? null;

                if (! $transactionDate || $transactionDate->gt($endDate)) {
                    continue;
                }

                $delta = $this->resolveDelta($transaction);
                if ($delta == 0.0) {
                    continue;
                }

                $type = strtoupper((string) $transaction->type);
                $reference = $meta['reference'] ?? $this->extractReference($transaction->reason);
                $displayNumber = $this->resolveDisplayNumber($type, $reference, $purchaseNumberMap);
                $unitPrice = $this->resolveUnitPrice(
                    $type,
                    $reference,
                    $product->id,
                    $purchasePriceMap,
                    $salePriceMap,
                    $fallbackPurchase,
                    $fallbackSale
                );
                $description = $this->resolveDescription(
                    $type,
                    $reference,
                    $saleNotes,
                    $transaction->reason
                );

                if ($transactionDate->lt($startDate)) {
                    $this->applyTransaction($type, $delta, $unitPrice, $runningStock, $runningAvg);
                    continue;
                }

                if (! $opened) {
                    $rowIndex++;
                    $this->rowMeta['productHeaders'][] = $rowIndex;
                    $rows[] = $this->padRow([
                        '(' . ($product->product_code ?? '') . ')  ' . $product->product_name,
                    ]);

                    if ($runningAvg == 0.0 && $fallbackAvg > 0) {
                        $runningAvg = $fallbackAvg;
                    }

                    $rowIndex++;
                $rows[] = [
                    $startDate->copy()->subDay()->format('d/m/Y'),
                    'Saldo Awal',
                    null,
                    null,
                    0,
                    (float) $runningStock,
                    (float) $runningAvg,
                    $fallbackSale ?: $fallbackPurchase,
                    (float) $runningStock * (float) $runningAvg,
                ];
                $opened = true;
            }

                $this->applyTransaction($type, $delta, $unitPrice, $runningStock, $runningAvg);

                $rows[] = [
                    $transactionDate->format('d/m/Y'),
                    $this->resolveTransactionLabel($type, $delta),
                    $displayNumber,
                    $description,
                    $delta,
                    $runningStock,
                    $runningAvg,
                    $unitPrice,
                    $runningStock * $runningAvg,
                ];
                $rowIndex++;
            }

            if (! $opened) {
                $rowIndex++;
                $this->rowMeta['productHeaders'][] = $rowIndex;
                $rows[] = $this->padRow([
                    '(' . ($product->product_code ?? '') . ') ' . $product->product_name,
                ]);

                if ($runningAvg == 0.0 && $fallbackAvg > 0) {
                    $runningAvg = $fallbackAvg;
                }

                $rowIndex++;
                $rows[] = [
                    $startDate->copy()->subDay()->format('d/m/Y'),
                    'Saldo Awal',
                    null,
                    null,
                    0,
                    (float) $runningStock,
                    (float) $runningAvg,
                    $fallbackSale ?: $fallbackPurchase,
                    (float) $runningStock * (float) $runningAvg,
                ];
            }

            $rowIndex++;
            $this->rowMeta['summaryRows'][] = $rowIndex;
            $rows[] = $this->padRow([
                null,
                null,
                null,
                null,
                '(' . $product->product_name . ') | Stok Tersedia:',
                $runningStock,
                null,
                'Nilai Stok: ',
                $runningStock * $runningAvg,
            ]);
        }

        $this->rowMeta['lastRow'] = count($rows);

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = max(1, $this->rowMeta['lastRow']);

                $sheet->mergeCells("A1:I1");
                $sheet->mergeCells("A2:I2");
                $sheet->mergeCells("A3:I3");
                $sheet->mergeCells("A4:I4");

                $sheet->getColumnDimension('A')->setWidth(15);
                $sheet->getColumnDimension('B')->setWidth(30);
                $sheet->getColumnDimension('C')->setWidth(10);
                $sheet->getColumnDimension('D')->setWidth(255);
                $sheet->getColumnDimension('E')->setWidth(20);
                $sheet->getColumnDimension('F')->setWidth(10);
                $sheet->getColumnDimension('G')->setWidth(10);
                $sheet->getColumnDimension('H')->setWidth(15);
                $sheet->getColumnDimension('I')->setWidth(15);

                $sheet->getStyle("A1:I{$lastRow}")
                    ->getFont()
                    ->setName('Arial')
                    ->setSize(12);

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(16);
                $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle('A1:A4')->getAlignment()->setHorizontal('center');
                $sheet->getStyle('A6:I6')->getFont()->setBold(true);

                foreach ($this->rowMeta['productHeaders'] as $row) {
                    $sheet->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);
                }
                foreach ($this->rowMeta['summaryRows'] as $row) {
                    $sheet->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);
                }

                if ($lastRow >= 7) {
                    $sheet->getStyle("A7:A{$lastRow}")
                        ->getNumberFormat()
                        ->setFormatCode('dd/mm/yyyy');
                    $sheet->getStyle("E7:E{$lastRow}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00;[Red]-#,##0.00');
                    $sheet->getStyle("F7:F{$lastRow}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00;[Red]-#,##0.00');
                    $sheet->getStyle("G7:G{$lastRow}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00;[Red]-#,##0.00');
                    $sheet->getStyle("H7:H{$lastRow}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00;[Red]-#,##0.00');
                    $sheet->getStyle("I7:I{$lastRow}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00;[Red]-#,##0.00');
                }
            },
        ];
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

    private function buildPurchaseNumberMap(array $references, int $settingId): array
    {
        if (empty($references)) {
            return [];
        }

        return Purchase::query()
            ->where('setting_id', $settingId)
            ->whereIn('reference', $references)
            ->pluck('supplier_purchase_number', 'reference')
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

    private function resolveDisplayNumber(string $type, ?string $reference, array $purchaseNumberMap): string
    {
        if ($type === 'BUY') {
            $number = $reference ? ($purchaseNumberMap[$reference] ?? null) : null;
            return $number ?: '-';
        }

        return $reference ?: '-';
    }

    private function resolveEligibleProductIds(
        Collection $transactionsByProduct,
        array $transactionMeta,
        Carbon $startDate,
        Carbon $endDate
    ): array {
        $eligible = [];

        foreach ($transactionsByProduct as $productId => $productTransactions) {
            $hasSaleInPeriod = false;
            $endingStock = 0.0;

            foreach ($productTransactions as $transaction) {
                $meta = $transactionMeta[$transaction->id] ?? null;
                $transactionDate = $meta['date'] ?? null;
                if (! $transactionDate || $transactionDate->gt($endDate)) {
                    continue;
                }

                $delta = $this->resolveDelta($transaction);
                if ($delta == 0.0) {
                    continue;
                }

                if (! $transactionDate->lt($startDate)) {
                    $type = strtoupper((string) $transaction->type);
                    if (in_array($type, ['DISPATCH', 'SELL'], true)) {
                        $hasSaleInPeriod = true;
                    }
                }

                $endingStock += $delta;
            }

            if ($hasSaleInPeriod || $endingStock != 0.0) {
                $eligible[] = $productId;
            }
        }

        return $eligible;
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

        if (preg_match('/#\\s*([A-Za-z0-9\\.\\-]+)/', $reason, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractTransferId(?string $reason): ?int
    {
        if (! $reason) {
            return null;
        }

        if (preg_match('/\\(#(\\d+)\\)/', $reason, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/#(\\d+)/', $reason, $matches)) {
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

    private function resolveDescription(string $type, ?string $reference, array $saleNotes, ?string $reason): string
    {
        if (in_array($type, ['DISPATCH', 'SELL'], true)) {
            return (string) ($saleNotes[$reference] ?? '');
        }

        if ($type === 'BUY') {
            return '';
        }

        return (string) ($reason ?? '');
    }

    private function resolveTransactionLabel(string $type, float $delta): string
    {
        return match ($type) {
            'BUY' => 'Purchase Invoice',
            'DISPATCH', 'SELL' => 'Sales Invoice',
            'TRF' => $delta >= 0 ? 'Transfer In' : 'Transfer Out',
            'ADJ' => $delta >= 0 ? 'Adjustment In' : 'Adjustment Out',
            'INIT' => 'Inisialisasi Stok',
            default => $type ?: 'Mutasi',
        };
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

    private function padRow(array $values): array
    {
        return array_pad($values, 9, null);
    }
}
