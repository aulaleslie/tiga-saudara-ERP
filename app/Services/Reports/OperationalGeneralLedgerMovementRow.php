<?php

namespace App\Services\Reports;

class OperationalGeneralLedgerMovementRow
{
    public string $date;
    public string $sourceType;
    public string $reference;
    public string $description;
    public float $debit;
    public float $credit;
    public float $balance;
    public ?string $tag;

    public function __construct(
        string $date,
        string $sourceType,
        string $reference,
        string $description,
        float $debit,
        float $credit,
        float $balance,
        ?string $tag = null
    ) {
        $this->date = $date;
        $this->sourceType = $sourceType;
        $this->reference = $reference;
        $this->description = $description;
        $this->debit = $debit;
        $this->credit = $credit;
        $this->balance = $balance;
        $this->tag = $tag;
    }
}
