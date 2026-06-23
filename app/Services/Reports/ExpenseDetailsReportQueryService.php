<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Modules\Expense\Entities\Expense;
use Carbon\Carbon;

class ExpenseDetailsReportQueryService
{
    public function build(ExpenseDetailsReportFilterData $filter): Builder
    {
        $scopeSettingId = $filter->scopeSettingId ?: session('setting_id');

        $query = Expense::with(['category', 'tags'])
            ->leftJoin('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->select('expenses.*')
            ->where('expenses.setting_id', $scopeSettingId)
            ->where('expenses.status', Expense::STATUS_APPROVED)
            ->whereNull('expenses.archived_at')
            ->whereRaw('DATE(expenses.date) >= ?', [$filter->startDate])
            ->whereRaw('DATE(expenses.date) <= ?', [$filter->endDate]);

        // Category filter
        if (!empty($filter->categoryIds)) {
            $query->whereIn('expenses.category_id', $filter->categoryIds);
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

    public function applySort(Builder $query, string $sortDirection): void
    {
        $direction = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

        // Sort by category_name then category_id so that two categories sharing the
        // same name still order deterministically and never depend on DB row order.
        $query->orderBy('expense_categories.category_name', 'asc')
              ->orderBy('expenses.category_id', 'asc')
              ->orderBy('expenses.date', $direction)
              ->orderBy('expenses.id', 'asc');
    }

    /**
     * Build the query with sorting already applied. Prefer this over calling
     * build() + applySort() separately so call sites can never forget to sort.
     */
    public function buildSorted(ExpenseDetailsReportFilterData $filter): Builder
    {
        $query = $this->build($filter);
        $this->applySort($query, $filter->sortDirection);

        return $query;
    }

    public static function mapRow(Expense $expense): array
    {
        $rawDate = $expense->getRawOriginal('date');
        $formattedDate = $rawDate ? Carbon::parse($rawDate)->format('d/m/Y') : '-';

        return [
            'Kategori / Tanggal' => $formattedDate,
            'Transaksi' => 'Expense',
            'Nomor' => $expense->reference ?? '-',
            'Keterangan' => $expense->details ?? '',
            'Jumlah' => (float) $expense->amount,
        ];
    }

    public static function groupAndSummarize($expenses): array
    {
        $groups = [];
        $grandTotal = 0.0;
        $transactionCount = 0;

        foreach ($expenses as $expense) {
            $categoryName = $expense->category?->category_name ?? '(Tanpa Kategori)';
            // Key on category_id (not name) so two distinct categories sharing the
            // same name don't merge into a single group. Fall back to a stable key
            // for uncategorized expenses.
            $groupKey = $expense->category_id ?? '__none__';
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'category_name' => $categoryName,
                    'rows' => [],
                    'subtotal' => 0.0,
                ];
            }

            $amount = (float) $expense->amount;
            $groups[$groupKey]['rows'][] = self::mapRow($expense);
            $groups[$groupKey]['subtotal'] += $amount;
            $grandTotal += $amount;
            $transactionCount++;
        }

        return [
            'groups' => array_values($groups),
            'grandTotal' => $grandTotal,
            'transactionCount' => $transactionCount,
        ];
    }
}
