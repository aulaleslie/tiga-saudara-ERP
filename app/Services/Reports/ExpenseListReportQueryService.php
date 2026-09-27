<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Expense\Entities\Expense;
use Modules\Setting\Entities\Tax;

class ExpenseListReportQueryService
{
    public function build(ExpenseListReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        $query = Expense::with(['supplier', 'category', 'detailRows.tax', 'tags'])
            ->where('expenses.setting_id', $scopeSettingId)
            ->where('expenses.status', Expense::STATUS_APPROVED)
            ->whereNull('expenses.archived_at')
            ->whereRaw('DATE(expenses.date) >= ?', [$filter->startDate])
            ->whereRaw('DATE(expenses.date) <= ?', [$filter->endDate]);

        // Supplier filter
        if (!empty($filter->supplierIds)) {
            $query->whereIn('expenses.supplier_id', $filter->supplierIds);
        }

        // Tag filter
        if (!empty($filter->tagIds)) {
            if ($filter->tagLogic === 'Mencakup semua') {
                foreach ($filter->tagIds as $tagId) {
                    $query->whereHas('tags', fn($q) => $q->where('tags.id', $tagId));
                }
            } else {
                $query->whereHas('tags', fn($q) => $q->whereIn('tags.id', $filter->tagIds));
            }
        }

        return $query;
    }

    public function applySort(Builder $query, string $sortField, string $sortDirection): void
    {
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        match ($sortField) {
            'date' => $query->orderBy('expenses.date', $direction)
                            ->orderBy('expenses.id', 'asc'),
            'amount' => $query->orderBy('expenses.amount', $direction)
                              ->orderBy('expenses.id', 'asc'),
            'status' => $query->orderBy('expenses.status', $direction)
                              ->orderBy('expenses.id', 'asc'),
            'outstanding' => $query->orderBy('expenses.id', $direction),
            default => $query->orderBy('expenses.date', 'desc')
                             ->orderBy('expenses.id', 'asc'),
        };
    }

    /**
     * Map an expense to summary-mode row values.
     */
    public static function mapSummaryRow(Expense $expense): array
    {
        $taxAmount = self::calculateTotalTax($expense);

        return [
            'Tanggal' => $expense->getRawOriginal('date'),
            'Transaksi' => 'Expense',
            'Nomor' => $expense->reference ?? '-',
            'Kategori' => $expense->category?->category_name ?? '-',
            'Deskripsi' => $expense->details ?? '-',
            'Supplier' => $expense->supplier?->supplier_name ?? '-',
            'Jumlah' => (float) $expense->amount,
            'Tax' => $taxAmount,
            'Status' => 'Paid',
            'Sisa Tagihan' => 0,
        ];
    }

    /**
     * Map expense detail rows for detail-mode.
     */
    public static function mapDetailRows(Expense $expense): array
    {
        $details = $expense->detailRows;

        if ($details->isEmpty()) {
            // No structured details — render a single row using header data
            return [self::mapSummaryRow($expense)];
        }

        $rows = [];
        $isTaxIncluded = (bool) $expense->is_tax_included;

        foreach ($details as $detail) {
            $detailAmount = (float) $detail->amount;
            $detailTax = 0.0;

            if ($detail->tax && $detail->tax->value > 0) {
                $taxRate = (float) $detail->tax->value;
                if ($isTaxIncluded) {
                    $base = $detailAmount / (1 + ($taxRate / 100));
                    $detailTax = $detailAmount - $base;
                } else {
                    $detailTax = ($detailAmount * $taxRate) / 100;
                }
            }

            $rows[] = [
                'Tanggal' => $expense->getRawOriginal('date'),
                'Transaksi' => 'Expense',
                'Nomor' => $expense->reference ?? '-',
                'Kategori' => $expense->category?->category_name ?? '-',
                'Deskripsi' => $detail->name ?? '-',
                'Supplier' => $expense->supplier?->supplier_name ?? '-',
                'Jumlah' => $detailAmount,
                'Tax' => round($detailTax, 2),
                'Status' => 'Paid',
                'Sisa Tagihan' => 0,
            ];
        }

        return $rows;
    }

    /**
     * Calculate total tax for an expense from its detail rows.
     */
    public static function calculateTotalTax(Expense $expense): float
    {
        $totalTax = 0.0;
        $isTaxIncluded = (bool) $expense->is_tax_included;

        foreach ($expense->detailRows as $detail) {
            if ($detail->tax && $detail->tax->value > 0) {
                $taxRate = (float) $detail->tax->value;
                $amount = (float) $detail->amount;

                if ($isTaxIncluded) {
                    $base = $amount / (1 + ($taxRate / 100));
                    $totalTax += ($amount - $base);
                } else {
                    $totalTax += ($amount * $taxRate) / 100;
                }
            }
        }

        return round($totalTax, 2);
    }

    /**
     * Compute summary totals for a collection of expenses.
     * Returns totals based on header amounts to avoid double-counting.
     */
    public static function computeTotals($expenses): array
    {
        $totalJumlah = 0.0;
        $totalTax = 0.0;
        $totalOutstanding = 0.0;

        foreach ($expenses as $expense) {
            $totalJumlah += (float) $expense->amount;
            $totalTax += self::calculateTotalTax($expense);
        }

        return [
            'Jumlah' => round($totalJumlah, 2),
            'Tax' => round($totalTax, 2),
            'Sisa Tagihan' => 0,
        ];
    }
}
