<?php

namespace App\Services\Reports;

class OperationalBalanceSheetReport
{
    public string $currencyCode;
    public string $asOfDate;
    public string $sourceNote;

    public OperationalBalanceSheetSection $assets;
    public OperationalBalanceSheetSection $liabilities;
    public OperationalBalanceSheetSection $equity;

    public function __construct(
        string $currencyCode,
        string $asOfDate,
        string $sourceNote,
        OperationalBalanceSheetSection $assets,
        OperationalBalanceSheetSection $liabilities,
        OperationalBalanceSheetSection $equity
    ) {
        $this->currencyCode = $currencyCode;
        $this->asOfDate = $asOfDate;
        $this->sourceNote = $sourceNote;

        $this->assets = $assets;
        $this->liabilities = $liabilities;
        $this->equity = $equity;
    }
}
