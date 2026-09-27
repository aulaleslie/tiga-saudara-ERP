<?php

namespace App\Services\Reports;

class OperationalCashFlowReportFilterData
{
    public string $startDate;
    public string $endDate;

    public function __construct(
        ?string $startDate = null,
        ?string $endDate = null
    ) {
        $this->startDate = $startDate ?? now()->format('Y-m-d');
        $this->endDate = $endDate ?? now()->format('Y-m-d');
    }
}
