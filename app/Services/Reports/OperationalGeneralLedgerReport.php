<?php

namespace App\Services\Reports;

class OperationalGeneralLedgerReport
{
    public string $currencyCode;
    public string $startDate;
    public string $endDate;
    public string $sourceNote;

    /** @var OperationalGeneralLedgerBucket[] */
    public array $buckets;

    public function __construct(
        string $currencyCode,
        string $startDate,
        string $endDate,
        string $sourceNote,
        array $buckets
    ) {
        $this->currencyCode = $currencyCode;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->sourceNote = $sourceNote;
        $this->buckets = $buckets;
    }
}
