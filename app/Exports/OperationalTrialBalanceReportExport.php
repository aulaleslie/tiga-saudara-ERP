<?php

namespace App\Exports;

use App\Services\Reports\OperationalTrialBalanceReportService;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class OperationalTrialBalanceReportExport implements FromView, ShouldAutoSize
{
    public array $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function view(): View
    {
        $reportService = app(OperationalTrialBalanceReportService::class);
        
        $report = $reportService->generate(
            $this->filters['settingId'], 
            $this->filters['startDate'] ?? null,
            $this->filters['endDate'] ?? null
        );

        return view('exports.operational-trial-balance-report', [
            'report' => $report,
            'setting' => \Modules\Setting\Entities\Setting::find($this->filters['settingId'])
        ]);
    }
}
