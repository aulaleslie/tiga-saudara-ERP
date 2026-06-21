<?php

namespace App\Services\Reports;

class OperationalTrialBalanceCategory
{
    public string $categoryName;
    
    /** @var OperationalTrialBalanceRow[] */
    public array $rows;

    public float $totalOpeningDebit;
    public float $totalOpeningCredit;
    public float $totalPeriodDebit;
    public float $totalPeriodCredit;
    public float $totalEndingDebit;
    public float $totalEndingCredit;

    public function __construct(
        string $categoryName,
        array $rows,
        float $totalOpeningDebit,
        float $totalOpeningCredit,
        float $totalPeriodDebit,
        float $totalPeriodCredit,
        float $totalEndingDebit,
        float $totalEndingCredit
    ) {
        $this->categoryName = $categoryName;
        $this->rows = $rows;
        $this->totalOpeningDebit = $totalOpeningDebit;
        $this->totalOpeningCredit = $totalOpeningCredit;
        $this->totalPeriodDebit = $totalPeriodDebit;
        $this->totalPeriodCredit = $totalPeriodCredit;
        $this->totalEndingDebit = $totalEndingDebit;
        $this->totalEndingCredit = $totalEndingCredit;
    }
}
