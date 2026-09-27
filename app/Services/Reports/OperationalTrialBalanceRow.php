<?php

namespace App\Services\Reports;

class OperationalTrialBalanceRow
{
    public string $code;
    public string $label;
    public string $normalBalance;
    public float $openingDebit;
    public float $openingCredit;
    public float $periodDebit;
    public float $periodCredit;
    public float $endingDebit;
    public float $endingCredit;

    public function __construct(
        string $code,
        string $label,
        string $normalBalance,
        float $openingDebit,
        float $openingCredit,
        float $periodDebit,
        float $periodCredit,
        float $endingDebit,
        float $endingCredit
    ) {
        $this->code = $code;
        $this->label = $label;
        $this->normalBalance = $normalBalance;
        $this->openingDebit = $openingDebit;
        $this->openingCredit = $openingCredit;
        $this->periodDebit = $periodDebit;
        $this->periodCredit = $periodCredit;
        $this->endingDebit = $endingDebit;
        $this->endingCredit = $endingCredit;
    }
}
