<?php

namespace App\Services\Reports;

class OperationalProfitLossReport
{
    public string $currencyCode;
    public string $periodLabel;

    public float $salesTotal;
    public float $saleReturnsTotal;
    public float $netRevenue;

    public float $salesCostTotal;
    public float $saleReturnCostTotal;
    public float $netSalesCost;

    public float $expensesTotal;
    public float $totalCost;

    public float $profitLoss;

    public function __construct(
        string $currencyCode,
        string $periodLabel,
        float $salesTotal,
        float $saleReturnsTotal,
        float $salesCostTotal,
        float $saleReturnCostTotal,
        float $expensesTotal
    ) {
        $this->currencyCode = $currencyCode;
        $this->periodLabel = $periodLabel;

        $this->salesTotal = $salesTotal;
        $this->saleReturnsTotal = $saleReturnsTotal;
        $this->netRevenue = $salesTotal - $saleReturnsTotal;

        $this->salesCostTotal = $salesCostTotal;
        $this->saleReturnCostTotal = $saleReturnCostTotal;
        $this->netSalesCost = $salesCostTotal - $saleReturnCostTotal;

        $this->expensesTotal = $expensesTotal;
        $this->totalCost = $this->netSalesCost + $expensesTotal;

        $this->profitLoss = $this->netRevenue - $this->totalCost;
    }
}
