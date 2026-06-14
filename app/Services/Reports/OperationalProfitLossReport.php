<?php

namespace App\Services\Reports;

class OperationalProfitLossReport
{
    public string $currencyCode;
    public string $periodLabel;

    public float $salesTotal;
    public float $saleReturnsTotal;
    public float $netRevenue;

    public float $purchasesTotal;
    public float $purchaseReturnsTotal;
    public float $expensesTotal;
    public float $totalCost;

    public float $profitLoss;

    public function __construct(
        string $currencyCode,
        string $periodLabel,
        float $salesTotal,
        float $saleReturnsTotal,
        float $purchasesTotal,
        float $purchaseReturnsTotal,
        float $expensesTotal
    ) {
        $this->currencyCode = $currencyCode;
        $this->periodLabel = $periodLabel;

        $this->salesTotal = $salesTotal;
        $this->saleReturnsTotal = $saleReturnsTotal;
        $this->netRevenue = $salesTotal - $saleReturnsTotal;

        $this->purchasesTotal = $purchasesTotal;
        $this->purchaseReturnsTotal = $purchaseReturnsTotal;
        $this->expensesTotal = $expensesTotal;
        $this->totalCost = $purchasesTotal - $purchaseReturnsTotal + $expensesTotal;

        $this->profitLoss = $this->netRevenue - $this->totalCost;
    }
}
