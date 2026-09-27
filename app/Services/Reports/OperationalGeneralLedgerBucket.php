<?php

namespace App\Services\Reports;

class OperationalGeneralLedgerBucket
{
    public string $key;
    public string $label;
    public float $beginningBalance;
    public float $periodDebit;
    public float $periodCredit;
    public float $endingBalance;

    /** @var OperationalGeneralLedgerMovementRow[] */
    public array $rows;

    public function __construct(
        string $key,
        string $label,
        float $beginningBalance,
        float $periodDebit,
        float $periodCredit,
        float $endingBalance,
        array $rows = []
    ) {
        $this->key = $key;
        $this->label = $label;
        $this->beginningBalance = $beginningBalance;
        $this->periodDebit = $periodDebit;
        $this->periodCredit = $periodCredit;
        $this->endingBalance = $endingBalance;
        $this->rows = $rows;
    }
}
