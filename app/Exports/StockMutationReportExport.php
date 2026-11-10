<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Adjustment\Entities\Transfer;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;

class StockMutationReportExport implements FromCollection, WithHeadings, WithEvents
{
    protected array $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function collection(): Collection
    {
        $settingIds = $this->filters['settingIds'] ?? [(int) session('setting_id')];
        $isMultiBusiness = count($settingIds) > 1;
        $startDate = $this->filters['startDate'] ?? null;
        $endDate = $this->filters['endDate'] ?? null;
        $productId = $this->filters['productId'] ?? null;
        $locationId = $this->filters['locationId'] ?? null;
        $mutationType = $this->filters['mutationType'] ?? '';
        $startDateFilter = $startDate ? Carbon::parse($startDate)->startOfDay() : null;
        $endDateFilter = $endDate ? Carbon::parse($endDate)->endOfDay() : null;

        if (empty($settingIds)) {
            return collect();
        }

        $transactions = Transaction::query()
            ->with(['product', 'location.setting'])
            ->when($productId, fn($q) => $q->where('product_id', $productId))
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereIn('setting_id', $settingIds)
            ->get();

        $purchaseDateMap = $this->buildPurchaseDateMap($transactions, $settingIds);
        $saleDateMap = $this->buildSaleDateMap($transactions, $settingIds);
        $transferMeta = $this->loadTransferMeta($transactions);

        return $transactions
            ->map(function (Transaction $transaction) use (
                $mutationType,
                $startDateFilter,
                $endDateFilter,
                $purchaseDateMap,
                $saleDateMap,
                $transferMeta,
                $isMultiBusiness
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

                if ($startDateFilter && $transactionDate && $transactionDate->lt($startDateFilter)) {
                    return null;
                }

                if ($endDateFilter && $transactionDate && $transactionDate->gt($endDateFilter)) {
                    return null;
                }

                if ($mutationType === 'IN' && $delta <= 0) {
                    return null;
                }

                if ($mutationType === 'OUT' && $delta >= 0) {
                    return null;
                }

                $reference = $this->resolveReference($transaction, $transferMeta);

                $row = [
                    'date' => $transactionDate?->format('d/m/Y') ?? '-',
                    'product_code' => $transaction->product->product_code ?? '-',
                    'product_name' => $transaction->product->product_name ?? '-',
                ];

                if ($isMultiBusiness) {
                    $row['business'] = $transaction->location?->setting?->company_name ?? ($transaction->setting?->company_name ?? '-');
                }

                $row['location'] = $transaction->location->name ?? '-';
                $row['type'] = $this->resolveTypeLabel($transaction, $delta);
                $row['qty_in'] = $delta > 0 ? $delta : 0;
                $row['qty_out'] = $delta < 0 ? abs($delta) : 0;
                $row['reference'] = $reference;
                $row['sort_date'] = $transactionDate?->toDateString() ?? '9999-12-31';
                $row['sort_reference'] = $reference === '-' ? '' : $reference;
                $row['sort_id'] = $transaction->id;

                return $row;
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

    public function headings(): array
    {
        $settingIds = $this->filters['settingIds'] ?? [(int) session('setting_id')];
        if (count($settingIds) > 1) {
            return [
                'Tanggal',
                'Kode Produk',
                'Nama Produk',
                'Bisnis',
                'Lokasi',
                'Tipe',
                'Qty Masuk',
                'Qty Keluar',
                'Referensi',
            ];
        }

        return [
            'Tanggal',
            'Kode Produk',
            'Nama Produk',
            'Lokasi',
            'Tipe',
            'Qty Masuk',
            'Qty Keluar',
            'Referensi',
        ];
    }

    public function registerEvents(): array
    {
        $startDate = $this->filters['startDate'] ?? '-';
        $endDate = $this->filters['endDate'] ?? '-';
        $settingIds = $this->filters['settingIds'] ?? [(int) session('setting_id')];
        $lastCol = count($settingIds) > 1 ? 'I' : 'H';

        return [
            AfterSheet::class => function (AfterSheet $event) use ($startDate, $endDate, $lastCol) {
                $sheet = $event->sheet->getDelegate();

                // Insert header rows at the top
                $sheet->insertNewRowBefore(1, 2);

                // Title row
                $sheet->setCellValue('A1', 'LAPORAN MUTASI STOK');
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

                // Period row
                $periodText = sprintf(
                    'Periode: %s s/d %s',
                    $startDate ? date('d/m/Y', strtotime($startDate)) : '-',
                    $endDate ? date('d/m/Y', strtotime($endDate)) : '-'
                );
                $sheet->setCellValue('A2', $periodText);
                $sheet->mergeCells("A2:{$lastCol}2");
                $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

                // Style header row (now row 3)
                $sheet->getStyle("A3:{$lastCol}3")->getFont()->setBold(true);
            },
        ];
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
        return \App\Support\Reports\InventoryTransactionTypeLabel::label(
            (string) $transaction->type,
            $delta,
            \App\Support\Reports\InventoryTransactionTypeLabel::CONTEXT_MUTATION
        );
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

    private function buildPurchaseDateMap(Collection $transactions, array $settingIds): array
    {
        $refs = [];
        foreach ($transactions as $t) {
            if (strtoupper((string) $t->type) === 'BUY') {
                $ref = $this->extractReference($t->reason);
                if ($ref) {
                    $refs[] = $ref;
                }
            }
        }

        if (empty($refs) || empty($settingIds)) {
            return [];
        }

        return Purchase::query()
            ->whereIn('setting_id', $settingIds)
            ->whereIn('reference', array_unique($refs))
            ->get(['setting_id', 'reference', 'date'])
            ->mapWithKeys(fn ($p) => ["{$p->setting_id}_{$p->reference}" => $p->date])
            ->all();
    }

    private function buildSaleDateMap(Collection $transactions, array $settingIds): array
    {
        $refs = [];
        foreach ($transactions as $t) {
            if (in_array(strtoupper((string) $t->type), ['DISPATCH', 'SELL'], true)) {
                $ref = $this->extractReference($t->reason);
                if ($ref) {
                    $refs[] = $ref;
                }
            }
        }

        if (empty($refs) || empty($settingIds)) {
            return [];
        }

        return Sale::query()
            ->whereIn('setting_id', $settingIds)
            ->whereIn('reference', array_unique($refs))
            ->get(['setting_id', 'reference', 'date'])
            ->mapWithKeys(fn ($s) => ["{$s->setting_id}_{$s->reference}" => $s->date])
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
        $settingId = (int) $transaction->setting_id;

        if ($reference) {
            $key = "{$settingId}_{$reference}";
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
            ->get(['id', 'document_number', 'dispatched_at', 'received_at'])
            ->mapWithKeys(function (Transfer $transfer) {
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
