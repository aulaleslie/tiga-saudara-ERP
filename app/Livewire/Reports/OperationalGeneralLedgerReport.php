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

    public $applied_start_date;
    public $applied_end_date;
    public $applied_selected_buckets = [];

    protected $rules = [
        'start_date' => 'required|date',
        'end_date' => 'required|date|after_or_equal:start_date',
        'selected_buckets' => 'array'
    ];

    public function mount() {
        abort_if(Gate::denies('reports.access'), 403);

        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->format('Y-m-d');
        $this->selected_buckets = array_keys(OperationalGeneralLedgerBucketConfig::getLabels());

        $this->applied_start_date = $this->start_date;
        $this->applied_end_date = $this->end_date;
        $this->applied_selected_buckets = $this->selected_buckets;
    }

    public function render(OperationalGeneralLedgerReportService $reportService) {
        $settingId = session('setting_id');
        
        $filterData = new OperationalGeneralLedgerReportFilterData(
            $this->applied_start_date ?: now()->format('Y-m-d'),
            $this->applied_end_date ?: now()->format('Y-m-d'),
            $this->applied_selected_buckets
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
        
        $this->applied_start_date = $this->start_date;
        $this->applied_end_date = $this->end_date;
        $this->applied_selected_buckets = $this->selected_buckets;
    }

    public function resetFilters() {
        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->format('Y-m-d');
        $this->selected_buckets = array_keys(OperationalGeneralLedgerBucketConfig::getLabels());
        
        $this->applied_start_date = $this->start_date;
        $this->applied_end_date = $this->end_date;
        $this->applied_selected_buckets = $this->selected_buckets;
    }

    public function exportExcel() {
        $this->generateReport();

        $filters = [
            'startDate' => $this->applied_start_date ?: now()->format('Y-m-d'),
            'endDate' => $this->applied_end_date ?: now()->format('Y-m-d'),
            'settingId' => session('setting_id'),
            'bucketKeys' => $this->applied_selected_buckets
        ];
        
        $filename = sprintf('buku_besar_%s_sd_%s.xlsx', 
            Carbon::parse($filters['startDate'])->format('d-m-Y'),
            Carbon::parse($filters['endDate'])->format('d-m-Y')
        );

        return Excel::download(new OperationalGeneralLedgerReportExport($filters), $filename);
    }
}
