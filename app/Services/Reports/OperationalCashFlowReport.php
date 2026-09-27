<?php

namespace App\Services\Reports;

class OperationalCashFlowReport
{
    public string $currencyCode;
    public string $periodLabel;
    public string $sourceNote;

    public OperationalCashFlowSection $operatingActivities;
    public OperationalCashFlowSection $investingActivities;
    public OperationalCashFlowSection $financingActivities;

    public OperationalCashFlowSummaryRow $netCashIncrease;
    public OperationalCashFlowSummaryRow $openingCash;
    public OperationalCashFlowSummaryRow $bankRevaluation;
    public OperationalCashFlowSummaryRow $endingCash;

    public function __construct(
        string $currencyCode,
        string $periodLabel,
        string $sourceNote,
        OperationalCashFlowSection $operatingActivities,
        OperationalCashFlowSection $investingActivities,
        OperationalCashFlowSection $financingActivities,
        OperationalCashFlowSummaryRow $netCashIncrease,
        OperationalCashFlowSummaryRow $openingCash,
        OperationalCashFlowSummaryRow $bankRevaluation,
        OperationalCashFlowSummaryRow $endingCash
    ) {
        $this->currencyCode = $currencyCode;
        $this->periodLabel = $periodLabel;
        $this->sourceNote = $sourceNote;

        $this->operatingActivities = $operatingActivities;
        $this->investingActivities = $investingActivities;
        $this->financingActivities = $financingActivities;

        $this->netCashIncrease = $netCashIncrease;
        $this->openingCash = $openingCash;
        $this->bankRevaluation = $bankRevaluation;
        $this->endingCash = $endingCash;
    }
}
