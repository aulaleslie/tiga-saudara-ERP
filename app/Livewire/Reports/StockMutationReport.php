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
    use HasReportSettingScope;

    public $startDate, $endDate, $productId, $locationId, $mutationType;
    public $filterTriggered = false;
    public $isGlobal = false;

    protected $paginationTheme = 'bootstrap';

    public function mount($isGlobal = false)
    {
        $permission = $isGlobal ? 'stockMutationReports.global.access' : 'stockMutationReports.access';
        abort_unless(auth()->user()?->can($permission) ?? false, 403);

        $this->isGlobal = $isGlobal;
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        $this->mutationType = '';

        if ($this->isGlobal) {
            $available = $this->getAvailableSettings();
            $this->selectedSettingIds = array_map('intval', array_column($available, 'id'));
        } else {
            $this->selectedSettingIds = $this->defaultSettingSelection();
        }
    }

    public function updatedSelectedSettingIds(): void
    {
        $sanitizedIds = $this->getSanitizedSettingIds();
        if ($this->locationId) {
            $isValid = Location::whereIn('setting_id', $sanitizedIds)
                ->where('id', $this->locationId)
                ->exists();
            if (! $isValid) {
                $this->locationId = null;
            }
        }
        $this->resetPage();
    }

    public function applyFilters()
    {
        $this->filterTriggered = true;
        $this->resetPage();
    }

    public function exportExcel()
    {
        $permission = $this->isGlobal ? 'stockMutationReports.global.access' : 'stockMutationReports.access';
        abort_unless(auth()->user()?->can($permission) ?? false, 403);

        $sanitizedIds = $this->getSanitizedSettingIds();
        if (empty($sanitizedIds)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Pilih minimal satu bisnis.']);
            return null;
        }

        $filters = $this->exportFilters();
        $filename = $this->isGlobal ? 'laporan-mutasi-stok-global.xlsx' : 'laporan-mutasi-stok.xlsx';
        return Excel::download(new StockMutationReportExport($filters), $filename);
    }

    public function exportCsv()
    {
        $permission = $this->isGlobal ? 'stockMutationReports.global.access' : 'stockMutationReports.access';
        abort_unless(auth()->user()?->can($permission) ?? false, 403);

        $sanitizedIds = $this->getSanitizedSettingIds();
        if (empty($sanitizedIds)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Pilih minimal satu bisnis.']);
            return null;
        }

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
            'settingIds' => $this->getSanitizedSettingIds(),
        ];
    }

    public function getMutationsProperty(): Collection
    {
        if (!$this->filterTriggered) {
            return collect();
        }

        $settingIds = $this->getSanitizedSettingIds();

        if (empty($settingIds)) {
            return collect();
        }

        $isMulti = count($settingIds) > 1;
        $mutationType = $this->mutationType;
        $startDate = $this->startDate ? Carbon::parse($this->startDate)->startOfDay() : null;
        $endDate = $this->endDate ? Carbon::parse($this->endDate)->endOfDay() : null;

        $transactions = Transaction::query()
            ->with(['product', 'location.setting'])
            ->when($this->productId, fn($q) => $q->where('product_id', $this->productId))
            ->when($this->locationId, fn($q) => $q->where('location_id', $this->locationId))
            ->whereIn('setting_id', $settingIds)
            ->get();

        $purchaseDateMap = $this->buildPurchaseDateMap($transactions, $settingIds);
        $saleDateMap = $this->buildSaleDateMap($transactions, $settingIds);
        $transferMeta = $this->loadTransferMeta($transactions);

        return $transactions
            ->map(function (Transaction $transaction) use (
                $mutationType,
                $startDate,
                $endDate,
                $purchaseDateMap,
                $saleDateMap,
                $transferMeta,
                $isMulti
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

                $row = [
                    'date' => $transactionDate?->format('Y-m-d') ?? '-',
                    'product_name' => $transaction->product->product_name ?? '-',
                    'product_code' => $transaction->product->product_code ?? '-',
                ];

                if ($isMulti) {
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

    public function render()
    {
        $sanitizedSettingIds = $this->getSanitizedSettingIds();
        $isMulti = count($sanitizedSettingIds) > 1;

        $locations = Location::query()
            ->whereIn('setting_id', $sanitizedSettingIds)
            ->with('setting:id,company_name')
            ->orderBy('name')
            ->get()
            ->map(function (Location $loc) use ($isMulti) {
                $label = $loc->name;
                if ($isMulti && $loc->setting) {
                    $label .= ' — ' . $loc->setting->company_name;
                }
                return [
                    'id' => $loc->id,
                    'name' => $label,
                ];
            });

        return view('livewire.reports.stock-mutation-report', [
            'mutations' => $this->mutations,
            'products' => Product::orderBy('product_name')->get(),
            'locations' => $locations,
            'availableSettings' => $this->getAvailableSettings(),
            'selectedSettingIds' => $this->selectedSettingIds,
            'sanitizedSettingIds' => $sanitizedSettingIds,
            'isGlobal' => $this->isGlobal,
        ]);
    }
}
