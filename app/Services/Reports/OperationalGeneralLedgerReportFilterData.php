<?php

namespace App\Services\Reports;

class OperationalGeneralLedgerReportFilterData
{
    public string $startDate;
    public string $endDate;
    public array $bucketKeys;

    public function __construct(
        ?string $startDate = null,
        ?string $endDate = null,
        ?array $bucketKeys = null
    ) {
        $this->startDate = $startDate ?? now()->format('Y-m-d');
        $this->endDate = $endDate ?? now()->format('Y-m-d');
        
        $validKeys = array_keys(OperationalGeneralLedgerBucketConfig::getLabels());
        
        if ($bucketKeys === null) {
            $this->bucketKeys = $validKeys;
        } else {
            $this->bucketKeys = array_intersect($bucketKeys, $validKeys);
        }
    }
}
