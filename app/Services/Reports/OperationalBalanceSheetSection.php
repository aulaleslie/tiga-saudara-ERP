<?php

namespace App\Services\Reports;

class OperationalBalanceSheetSection
{
    public string $name;
    /** @var array<int, OperationalBalanceSheetRow> */
    public array $rows;
    public float $total;

    /**
     * @param string $name
     * @param array<int, OperationalBalanceSheetRow> $rows
     */
    public function __construct(string $name, array $rows)
    {
        $this->name = $name;
        $this->rows = $rows;
        
        $this->total = array_reduce($rows, function ($carry, OperationalBalanceSheetRow $row) {
            return $carry + $row->amount;
        }, 0.0);
    }
}
