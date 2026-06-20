<?php

namespace App\Livewire\Reports;

use App\Exports\OperationalGeneralLedgerReportExport;
use App\Services\Reports\OperationalGeneralLedgerBucketConfig;
use App\Services\Reports\OperationalGeneralLedgerReportFilterData;
use App\Services\Reports\OperationalGeneralLedgerReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

class OperationalGeneralLedgerReport extends Component
{
    public $start_date;
    public $end_date;
    public $selected_buckets = [];

    protected $rules = [
        'start_date' => 'required|date',
        'end_date' => 'required|date',
        'selected_buckets' => 'array'
    ];

    public function mount() {
        abort_if(Gate::denies('reports.access'), 403);

        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->format('Y-m-d');
        $this->selected_buckets = array_keys(OperationalGeneralLedgerBucketConfig::getLabels());
    }

    public function render(OperationalGeneralLedgerReportService $reportService) {
        $settingId = session('setting_id');
        
        $filterData = new OperationalGeneralLedgerReportFilterData(
            $this->start_date ?: now()->format('Y-m-d'),
            $this->end_date ?: now()->format('Y-m-d'),
            $this->selected_buckets
        );

        $report = $reportService->generate($settingId, $filterData);
        $bucketLabels = OperationalGeneralLedgerBucketConfig::getLabels();

        return view('livewire.reports.operational-general-ledger-report', [
            'report' => $report,
            'bucketLabels' => $bucketLabels
        ]);
    }

    public function generateReport() {
        $this->validate();
    }

    public function resetFilters() {
        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->format('Y-m-d');
        $this->selected_buckets = array_keys(OperationalGeneralLedgerBucketConfig::getLabels());
    }

    public function exportExcel() {
        $this->validate();

        $filters = [
            'startDate' => $this->start_date ?: now()->format('Y-m-d'),
            'endDate' => $this->end_date ?: now()->format('Y-m-d'),
            'settingId' => session('setting_id'),
            'bucketKeys' => $this->selected_buckets
        ];
        
        $filename = sprintf('buku_besar_%s_sd_%s.xlsx', 
            Carbon::parse($filters['startDate'])->format('d-m-Y'),
            Carbon::parse($filters['endDate'])->format('d-m-Y')
        );

        return Excel::download(new OperationalGeneralLedgerReportExport($filters), $filename);
    }
}
