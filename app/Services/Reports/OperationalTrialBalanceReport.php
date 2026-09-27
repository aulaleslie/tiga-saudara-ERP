<?php

namespace App\Services\Reports;

class OperationalTrialBalanceReport
{
    public string $currencyCode;
    public string $startDate;
    public string $endDate;
    public string $sourceNote;

    /** @var OperationalTrialBalanceCategory[] */
    public array $categories;

    public float $grandTotalOpeningDebit;
    public float $grandTotalOpeningCredit;
    public float $grandTotalPeriodDebit;
    public float $grandTotalPeriodCredit;
    public float $grandTotalEndingDebit;
    public float $grandTotalEndingCredit;

    public function __construct(
        string $currencyCode,
        string $startDate,
        string $endDate,
        string $sourceNote,
        array $categories,
        float $grandTotalOpeningDebit,
        float $grandTotalOpeningCredit,
        float $grandTotalPeriodDebit,
        float $grandTotalPeriodCredit,
        float $grandTotalEndingDebit,
        float $grandTotalEndingCredit
    ) {
        $this->currencyCode = $currencyCode;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->sourceNote = $sourceNote;
        $this->categories = $categories;
        $this->grandTotalOpeningDebit = $grandTotalOpeningDebit;
        $this->grandTotalOpeningCredit = $grandTotalOpeningCredit;
        $this->grandTotalPeriodDebit = $grandTotalPeriodDebit;
        $this->grandTotalPeriodCredit = $grandTotalPeriodCredit;
        $this->grandTotalEndingDebit = $grandTotalEndingDebit;
        $this->grandTotalEndingCredit = $grandTotalEndingCredit;
    }
}
