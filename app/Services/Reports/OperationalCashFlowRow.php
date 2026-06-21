<?php

namespace App\Services\Reports;

class OperationalCashFlowRow
{
    public string $name;
    public float $amount;

    public function __construct(string $name, float $amount)
    {
        $this->name = $name;
        $this->amount = $amount;
    }
}
