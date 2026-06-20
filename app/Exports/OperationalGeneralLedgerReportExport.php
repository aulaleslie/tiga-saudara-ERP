<?php

namespace App\Exports;

use App\Services\Reports\OperationalGeneralLedgerReportFilterData;
use App\Services\Reports\OperationalGeneralLedgerReportService;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class OperationalGeneralLedgerReportExport implements FromView, ShouldAutoSize
{
    private array $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function view(): View
    {
        $reportService = app(OperationalGeneralLedgerReportService::class);
        
        $filterData = new OperationalGeneralLedgerReportFilterData(
            $this->filters['startDate'] ?? null,
            $this->filters['endDate'] ?? null,
            $this->filters['bucketKeys'] ?? []
        );

        $report = $reportService->generate(
            $this->filters['settingId'], 
            $filterData
        );

        return view('exports.operational-general-ledger-report', [
            'report' => $report
        ]);
    }
}
