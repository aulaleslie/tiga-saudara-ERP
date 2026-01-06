<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\JournalItem;

class ProfitLossReportExport implements FromArray, WithEvents, WithTitle
{
    protected array $filters;
    protected array $rowMeta = [
        'boldRows' => [],
        'amountRows' => [],
        'lastRow' => 0,
    ];

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function title(): string
    {
        $startDate = $this->parseDate($this->filters['startDate'] ?? null);
        $endDate = $this->parseDate($this->filters['endDate'] ?? null);

        if (! $startDate || ! $endDate) {
            return 'Laba Rugi';
        }

        return $startDate->format('d-m-Y') . '_' . $endDate->format('d-m-Y');
    }

    public function array(): array
    {
        $startDate = $this->parseDate($this->filters['startDate'] ?? null);
        $endDate = $this->parseDate($this->filters['endDate'] ?? null);

        $setting = settings();
        $companyName = $setting?->company_name ?? 'Company';
        $currencyCode = $setting?->currency?->code ?? 'IDR';
        $periodLabel = $this->formatPeriod($startDate, $endDate);

        $accountsByCategory = $this->loadAccountBalances($startDate, $endDate);
        [$salesTotal, $salesDiscount] = $this->loadSalesTotals($startDate, $endDate);
        $cogsAmount = $this->calculateCostOfGoodsSold($startDate, $endDate);

        $revenueAccounts = $this->mergeRevenueAccounts(
            $accountsByCategory['Pendapatan'],
            $salesTotal,
            $salesDiscount
        );
        $hppAccounts = $this->mergeCostAccounts(
            $accountsByCategory['Harga Pokok Penjualan'],
            $cogsAmount
        );
        $operatingAccounts = $accountsByCategory['Beban'];
        $otherIncomeAccounts = $accountsByCategory['Pendapatan Lainnya'];
        $otherExpenseAccounts = $accountsByCategory['Beban Lainnya'];

        $revenueTotal = $this->sumAmounts($revenueAccounts);
        $hppTotal = $this->sumAmounts($hppAccounts);
        $grossProfit = $revenueTotal - $hppTotal;
        $operatingExpenseTotal = $this->sumAmounts($operatingAccounts);
        $operatingProfit = $grossProfit - $operatingExpenseTotal;
        $otherIncomeTotal = $this->sumAmounts($otherIncomeAccounts);
        $otherExpenseTotal = $this->sumAmounts($otherExpenseAccounts);
        $otherTotal = $otherIncomeTotal + $otherExpenseTotal;
        $netProfit = $operatingProfit + $otherTotal;

        $rows = [];

        $this->addRow($rows, [$companyName], true);
        $this->addRow($rows, ['Laba Rugi'], true);
        $this->addRow($rows, [$periodLabel], true);
        $this->addRow($rows, ['(dalam ' . $currencyCode . ')'], true);
        $this->addRow($rows, [''], true);
        $this->addRow($rows, []);
        $this->addRow($rows, ['Tanggal', '', $periodLabel], true);

        $this->addRow($rows, ['Pendapatan'], true);
        foreach ($revenueAccounts as $account) {
            $this->addRow($rows, [$account['account_number'], $account['name'], $account['amount']], false, true);
        }
        $this->addRow($rows, ['Total dari Pendapatan', '', $revenueTotal], true, true);

        $this->addRow($rows, ['Beban Pokok Pendapatan'], true);
        foreach ($hppAccounts as $account) {
            $this->addRow($rows, [$account['account_number'], $account['name'], $account['amount']], false, true);
        }
        $this->addRow($rows, ['Total dari Beban Pokok Pendapatan', '', $hppTotal], true, true);

        $this->addRow($rows, ['Laba Kotor', '', $grossProfit], true, true);

        $this->addRow($rows, ['Beban Operasional'], true);
        foreach ($operatingAccounts as $account) {
            $this->addRow($rows, [$account['account_number'], $account['name'], $account['amount']], false, true);
        }
        $this->addRow($rows, ['Total dari Beban Operasional', '', $operatingExpenseTotal], true, true);

        $this->addRow($rows, ['Laba Operasional', '', $operatingProfit], true, true);

        $this->addRow($rows, ['Pendapatan (Beban Lain-lain)'], true);

        $this->addRow($rows, ['Pendapatan Lain-Lain'], true);
        foreach ($otherIncomeAccounts as $account) {
            $this->addRow($rows, [$account['account_number'], $account['name'], $account['amount']], false, true);
        }

        $this->addRow($rows, ['Beban Lain-Lain'], true);
        foreach ($otherExpenseAccounts as $account) {
            $this->addRow($rows, [$account['account_number'], $account['name'], $account['amount']], false, true);
        }

        $this->addRow($rows, ['Total dari Pendapatan (Beban Lain-lain)', '', $otherTotal], true, true);
        $this->addRow($rows, ['Laba (Rugi)', '', $netProfit], true, true);

        $this->rowMeta['lastRow'] = count($rows);

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $this->rowMeta['lastRow'] ?? 0;

                if ($lastRow === 0) {
                    return;
                }

                $sheet->getStyle("A1:H{$lastRow}")
                    ->getFont()
                    ->setName('Arial')
                    ->setSize(12);

                $sheet->mergeCells('A1:H1');
                $sheet->mergeCells('A2:H2');
                $sheet->mergeCells('A3:H3');
                $sheet->mergeCells('A4:H4');
                $sheet->mergeCells('A5:H5');
                $sheet->getStyle('A1:H5')->getAlignment()->setHorizontal('center');

                foreach ($this->rowMeta['boldRows'] as $row) {
                    $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
                }

                foreach ($this->rowMeta['amountRows'] as $row) {
                    $sheet->getStyle("C{$row}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00;[Red]-#,##0.00');
                }

                $sheet->getColumnDimension('A')->setWidth(75.6);
                $sheet->getColumnDimension('B')->setWidth(33.6);
                $sheet->getColumnDimension('C')->setWidth(46.8);
                $sheet->getColumnDimension('D')->setWidth(3.6);
            },
        ];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value);
    }

    private function formatPeriod(?Carbon $startDate, ?Carbon $endDate): string
    {
        $startText = $startDate ? $startDate->format('d/m/Y') : '-';
        $endText = $endDate ? $endDate->format('d/m/Y') : '-';

        return $startText . ' - ' . $endText;
    }

    private function loadAccountBalances(?Carbon $startDate, ?Carbon $endDate): array
    {
        $settingId = session('setting_id');
        $accountsByCategory = [
            'Pendapatan' => [],
            'Harga Pokok Penjualan' => [],
            'Beban' => [],
            'Pendapatan Lainnya' => [],
            'Beban Lainnya' => [],
        ];

        $items = JournalItem::query()
            ->join('journals', 'journal_items.journal_id', '=', 'journals.id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_items.chart_of_account_id')
            ->select(
                'chart_of_accounts.account_number',
                'chart_of_accounts.name',
                'chart_of_accounts.category'
            )
            ->selectRaw("SUM(CASE WHEN journal_items.type = 'debit' THEN journal_items.amount ELSE 0 END) as total_debit")
            ->selectRaw("SUM(CASE WHEN journal_items.type = 'credit' THEN journal_items.amount ELSE 0 END) as total_credit")
            ->when($settingId, fn ($q) => $q->where('chart_of_accounts.setting_id', $settingId))
            ->when($startDate, fn ($q) => $q->whereDate('journals.transaction_date', '>=', $startDate->toDateString()))
            ->when($endDate, fn ($q) => $q->whereDate('journals.transaction_date', '<=', $endDate->toDateString()))
            ->groupBy('chart_of_accounts.account_number', 'chart_of_accounts.name', 'chart_of_accounts.category')
            ->orderBy('chart_of_accounts.account_number')
            ->get();

        foreach ($items as $item) {
            $category = $item->category;
            if (! array_key_exists($category, $accountsByCategory)) {
                continue;
            }

            $totalDebit = (float) $item->total_debit;
            $totalCredit = (float) $item->total_credit;
            if (abs($totalDebit) < 0.00001 && abs($totalCredit) < 0.00001) {
                continue;
            }

            $accountsByCategory[$category][] = [
                'account_number' => $item->account_number,
                'name' => $item->name,
                'amount' => $this->calculateNet($category, $totalDebit, $totalCredit),
            ];
        }

        return $accountsByCategory;
    }

    private function loadSalesTotals(?Carbon $startDate, ?Carbon $endDate): array
    {
        $settingId = session('setting_id');
        $query = Sale::query()
            ->when($settingId, fn ($q) => $q->where('setting_id', $settingId))
            ->when($startDate, fn ($q) => $q->whereDate('date', '>=', $startDate->toDateString()))
            ->when($endDate, fn ($q) => $q->whereDate('date', '<=', $endDate->toDateString()));

        $salesTotal = (float) (clone $query)->sum('total_amount');
        $salesDiscount = (float) (clone $query)->sum('discount_amount');

        return [$salesTotal, $salesDiscount];
    }

    private function calculateCostOfGoodsSold(?Carbon $startDate, ?Carbon $endDate): float
    {
        $settingId = session('setting_id');

        $salesQuantities = SaleDetails::query()
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->select('sale_details.product_id')
            ->selectRaw('SUM(sale_details.quantity) as total_quantity')
            ->when($settingId, fn ($q) => $q->where('sales.setting_id', $settingId))
            ->when($startDate, fn ($q) => $q->whereDate('sales.date', '>=', $startDate->toDateString()))
            ->when($endDate, fn ($q) => $q->whereDate('sales.date', '<=', $endDate->toDateString()))
            ->whereNotNull('sale_details.product_id')
            ->groupBy('sale_details.product_id')
            ->pluck('total_quantity', 'sale_details.product_id')
            ->all();

        if (empty($salesQuantities)) {
            return 0.0;
        }

        $productIds = array_map('intval', array_keys($salesQuantities));

        $fallbackProductPrices = ProductPrice::query()
            ->when($settingId, fn ($q) => $q->where('setting_id', $settingId))
            ->whereIn('product_id', $productIds)
            ->pluck('average_purchase_price', 'product_id')
            ->all();

        $fallbackProductAverages = Product::query()
            ->whereIn('id', $productIds)
            ->pluck('average_purchase_price', 'id')
            ->all();

        $totalCost = 0.0;

        foreach ($salesQuantities as $productId => $quantity) {
            $avgPrice = $fallbackProductPrices[$productId]
                ?? $fallbackProductAverages[$productId]
                ?? 0;

            $totalCost += (float) $avgPrice * (float) $quantity;
        }

        return $totalCost;
    }

    private function calculateNet(string $category, float $debit, float $credit): float
    {
        $creditBalanceCategories = ['Pendapatan', 'Pendapatan Lainnya'];

        if (in_array($category, $creditBalanceCategories, true)) {
            return $credit - $debit;
        }

        return $debit - $credit;
    }

    private function mergeRevenueAccounts(array $journalAccounts, float $salesTotal, float $salesDiscount): array
    {
        $reserved = ['4-40000', '4-40100'];
        $filtered = array_values(array_filter(
            $journalAccounts,
            fn (array $account) => ! in_array($account['account_number'], $reserved, true)
        ));

        $salesDiscount = -abs($salesDiscount);

        return array_merge([
            [
                'account_number' => '4-40000',
                'name' => 'Pendapatan',
                'amount' => $salesTotal,
            ],
            [
                'account_number' => '4-40100',
                'name' => 'Diskon Penjualan',
                'amount' => $salesDiscount,
            ],
        ], $filtered);
    }

    private function mergeCostAccounts(array $journalAccounts, float $cogsAmount): array
    {
        $reserved = ['5-50000'];
        $filtered = array_values(array_filter(
            $journalAccounts,
            fn (array $account) => ! in_array($account['account_number'], $reserved, true)
        ));

        return array_merge([
            [
                'account_number' => '5-50000',
                'name' => 'Beban Pokok Pendapatan',
                'amount' => $cogsAmount,
            ],
        ], $filtered);
    }

    private function sumAmounts(array $accounts): float
    {
        return array_reduce(
            $accounts,
            fn (float $carry, array $account) => $carry + (float) $account['amount'],
            0.0
        );
    }

    private function addRow(array &$rows, array $cells, bool $bold = false, bool $amount = false): void
    {
        $rows[] = $this->padRow($cells);
        $rowIndex = count($rows);

        if ($bold) {
            $this->rowMeta['boldRows'][] = $rowIndex;
        }

        if ($amount) {
            $this->rowMeta['amountRows'][] = $rowIndex;
        }
    }

    private function padRow(array $cells, int $length = 8): array
    {
        return array_pad($cells, $length, '');
    }
}
