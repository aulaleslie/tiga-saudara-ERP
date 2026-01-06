<?php

namespace App\Livewire\Reports;

use App\Exports\StockMutationReportExport;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Adjustment\Entities\Transfer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;

class StockMutationReport extends Component
{
    use WithPagination;

    public $startDate, $endDate, $productId, $locationId, $mutationType;
    public $filterTriggered = false;
    public $isGlobal = false;

    protected $paginationTheme = 'bootstrap';

    public function mount($isGlobal = false)
    {
        $this->isGlobal = $isGlobal;
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        $this->mutationType = '';
    }

    public function applyFilters()
    {
        $this->filterTriggered = true;
        $this->resetPage();
    }

    public function exportExcel()
    {
        $filters = $this->exportFilters();
        $filename = $this->isGlobal ? 'laporan-mutasi-stok-global.xlsx' : 'laporan-mutasi-stok.xlsx';
        return Excel::download(new StockMutationReportExport($filters), $filename);
    }

    public function exportCsv()
    {
        $filters = $this->exportFilters();
        $filename = $this->isGlobal ? 'laporan-mutasi-stok-global.csv' : 'laporan-mutasi-stok.csv';
        return Excel::download(new StockMutationReportExport($filters), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    private function exportFilters(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'productId' => $this->productId,
            'locationId' => $this->locationId,
            'mutationType' => $this->mutationType,
            'isGlobal' => $this->isGlobal,
        ];
    }

    public function getMutationsProperty(): Collection
    {
        if (!$this->filterTriggered) {
            return collect();
        }

        $settingId = session('setting_id');
        $mutationType = $this->mutationType;
        $startDate = $this->startDate ? Carbon::parse($this->startDate)->startOfDay() : null;
        $endDate = $this->endDate ? Carbon::parse($this->endDate)->endOfDay() : null;

        $transactions = Transaction::query()
            ->with(['product', 'location'])
            ->when($this->productId, fn($q) => $q->where('product_id', $this->productId))
            ->when($this->locationId, fn($q) => $q->where('location_id', $this->locationId))
            ->when(!$this->isGlobal, fn($q) => $q->where('setting_id', $settingId))
            ->get();

        [$purchaseRefs, $saleRefs] = $this->collectTransactionReferences($transactions);
        $purchaseDateMap = $this->buildPurchaseDateMap($purchaseRefs, $settingId);
        $saleDateMap = $this->buildSaleDateMap($saleRefs, $settingId);
        $transferMeta = $this->loadTransferMeta($transactions);

        return $transactions
            ->map(function (Transaction $transaction) use (
                $mutationType,
                $startDate,
                $endDate,
                $purchaseDateMap,
                $saleDateMap,
                $transferMeta
            ) {
                $delta = $this->resolveDelta($transaction);
                if ($delta == 0.0) {
                    return null;
                }

                $rawReference = $this->extractReference($transaction->reason);
                $transactionDate = $this->resolveTransactionDate(
                    $transaction,
                    $delta,
                    $rawReference,
                    $purchaseDateMap,
                    $saleDateMap,
                    $transferMeta
                );

                if ($startDate && $transactionDate && $transactionDate->lt($startDate)) {
                    return null;
                }

                if ($endDate && $transactionDate && $transactionDate->gt($endDate)) {
                    return null;
                }

                if ($mutationType === 'IN' && $delta <= 0) {
                    return null;
                }

                if ($mutationType === 'OUT' && $delta >= 0) {
                    return null;
                }

                $reference = $this->resolveReference($transaction, $transferMeta);

                return [
                    'date' => $transactionDate?->format('Y-m-d') ?? '-',
                    'product_name' => $transaction->product->product_name ?? '-',
                    'product_code' => $transaction->product->product_code ?? '-',
                    'location' => $transaction->location->name ?? '-',
                    'type' => $this->resolveTypeLabel($transaction, $delta),
                    'qty_in' => $delta > 0 ? $delta : 0,
                    'qty_out' => $delta < 0 ? abs($delta) : 0,
                    'reference' => $reference,
                    'sort_date' => $transactionDate?->toDateString() ?? '9999-12-31',
                    'sort_reference' => $reference === '-' ? '' : $reference,
                    'sort_id' => $transaction->id,
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right) {
                $dateComparison = strcmp($left['sort_date'], $right['sort_date']);
                if ($dateComparison !== 0) {
                    return $dateComparison;
                }

                $referenceComparison = strnatcasecmp($left['sort_reference'], $right['sort_reference']);
                if ($referenceComparison !== 0) {
                    return $referenceComparison;
                }

                return $left['sort_id'] <=> $right['sort_id'];
            })
            ->values()
            ->map(function (array $row) {
                unset($row['sort_date'], $row['sort_reference'], $row['sort_id']);
                return $row;
            })
            ->values();
    }

    private function resolveDelta(Transaction $transaction): float
    {
        $type = strtoupper((string) $transaction->type);
        $quantity = (float) ($transaction->quantity ?? 0);
        $diff = (float) ($transaction->after_quantity_at_location ?? 0)
            - (float) ($transaction->previous_quantity_at_location ?? 0);

        if ($type === 'ADJ' && $diff != 0.0) {
            return $diff;
        }

        if ($quantity != 0.0) {
            return $quantity;
        }

        return $diff;
    }

    private function resolveTypeLabel(Transaction $transaction, float $delta): string
    {
        $type = strtoupper((string) $transaction->type);

        return match ($type) {
            'BUY' => 'Penerimaan Pembelian',
            'DISPATCH', 'SELL' => 'Pengiriman Penjualan',
            'TRF' => $delta >= 0 ? 'Transfer Masuk' : 'Transfer Keluar',
            'ADJ' => $delta >= 0 ? 'Penyesuaian Tambah' : 'Penyesuaian Kurang',
            'INIT' => 'Inisialisasi Stok',
            default => $type ?: 'Mutasi Stok',
        };
    }

    private function resolveReference(Transaction $transaction, array $transferMeta): string
    {
        $type = strtoupper((string) $transaction->type);

        if ($type === 'TRF') {
            $transferId = $this->extractTransferId($transaction->reason);
            if ($transferId) {
                return $transferMeta[$transferId]['document_number'] ?? (string) $transferId;
            }
        }

        $reference = $this->extractReference($transaction->reason);

        return $reference ?: '-';
    }

    private function collectTransactionReferences(Collection $transactions): array
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

        return [array_keys($purchaseRefs), array_keys($saleRefs)];
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

    public function render()
    {
        $settingId = session('setting_id');

        return view('livewire.reports.stock-mutation-report', [
            'mutations' => $this->mutations,
            'products' => Product::orderBy('product_name')->get(),
            'locations' => $this->isGlobal
                ? Location::orderBy('name')->get()
                : Location::where('setting_id', $settingId)->orderBy('name')->get(),
            'isGlobal' => $this->isGlobal,
        ]);
    }
}
