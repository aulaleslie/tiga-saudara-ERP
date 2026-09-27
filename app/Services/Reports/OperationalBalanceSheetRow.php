<?php

namespace App\Services\Reports;

class OperationalBalanceSheetRow
{
    public string $name;
    public float $amount;

    public function __construct(string $name, float $amount)
    {
        $this->name = $name;
        $this->amount = $amount;
    }
}
