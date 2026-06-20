<?php

namespace App\Livewire\Reports;

use App\Exports\OperationalBalanceSheetReportExport;
use App\Services\Reports\OperationalBalanceSheetReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

class OperationalBalanceSheetReport extends Component
{
    public $as_of_date;

    protected $rules = [
        'as_of_date' => 'required|date'
    ];

    public function mount() {
        abort_if(Gate::denies('reports.access'), 403);

        $this->as_of_date = now()->format('Y-m-d');
    }

    public function render(OperationalBalanceSheetReportService $reportService) {
        $settingId = session('setting_id');
        
        $report = $reportService->generate(
            $settingId, 
            $this->as_of_date ?: now()->format('Y-m-d')
        );

        return view('livewire.reports.operational-balance-sheet-report', [
            'report' => $report
        ]);
    }

    public function generateReport() {
        $this->validate();
    }

    public function exportExcel() {
        $this->validate();

        $filters = [
            'asOfDate' => $this->as_of_date ?: now()->format('Y-m-d'),
            'settingId' => session('setting_id')
        ];
        
        $filename = sprintf('neraca_%s.xlsx', Carbon::parse($filters['asOfDate'])->format('d-m-Y'));

        return Excel::download(new OperationalBalanceSheetReportExport($filters), $filename);
    }
}
