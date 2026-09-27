<?php

namespace App\Services\Reports;

class OperationalCashFlowSection
{
    public string $name;
    /** @var array<int, OperationalCashFlowRow> */
    public array $rows;
    public float $total;

    /**
     * @param string $name
     * @param array<int, OperationalCashFlowRow> $rows
     */
    public function __construct(string $name, array $rows)
    {
        $this->name = $name;
        $this->rows = $rows;
        
        $this->total = array_reduce($rows, function ($carry, OperationalCashFlowRow $row) {
            return $carry + $row->amount;
        }, 0.0);
    }
}
