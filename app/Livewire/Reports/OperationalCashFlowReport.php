<?php

namespace App\Livewire\Reports;

use App\Exports\OperationalCashFlowReportExport;
use App\Services\Reports\OperationalCashFlowReportFilterData;
use App\Services\Reports\OperationalCashFlowReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

class OperationalCashFlowReport extends Component
{
    use HasReportSettingScope;

    public $start_date;
    public $end_date;

    public $applied_start_date;
    public $applied_end_date;

    public $period_preset = 'today';

    protected $rules = [
        'start_date' => 'required|date',
        'end_date' => 'required|date|after_or_equal:start_date',
    ];

    public function mount() {
        abort_if(Gate::denies('reports.access'), 403);

        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->format('Y-m-d');

        $this->applied_start_date = $this->start_date;
        $this->applied_end_date = $this->end_date;
    }

    public function updatedPeriodPreset($value) {
        if ($value === 'today') {
            $this->start_date = now()->format('Y-m-d');
            $this->end_date = now()->format('Y-m-d');
        } elseif ($value === 'this_week') {
            $this->start_date = now()->startOfWeek()->format('Y-m-d');
            $this->end_date = now()->endOfWeek()->format('Y-m-d');
        } elseif ($value === 'this_month') {
            $this->start_date = now()->startOfMonth()->format('Y-m-d');
            $this->end_date = now()->endOfMonth()->format('Y-m-d');
        } elseif ($value === 'this_year') {
            $this->start_date = now()->startOfYear()->format('Y-m-d');
            $this->end_date = now()->endOfYear()->format('Y-m-d');
        }
    }

    public function updatedStartDate() {
        $this->period_preset = 'custom';
    }

    public function updatedEndDate() {
        $this->period_preset = 'custom';
    }

    public function render(OperationalCashFlowReportService $reportService) {
        $availableSettings = $this->getAvailableSettings();
        $effectiveSettingIds = $this->getEffectiveSettingIds();
        $validatedSettingIds = $this->validateSettingIds($effectiveSettingIds, $availableSettings);
        
        $filterData = new OperationalCashFlowReportFilterData(
            $this->applied_start_date,
            $this->applied_end_date
        );

        $report = $reportService->generate($validatedSettingIds, $filterData);

        return view('livewire.reports.operational-cash-flow-report', [
            'report' => $report,
            'availableSettings' => $availableSettings,
            'scopeLabel' => $this->getScopeLabel($availableSettings, $validatedSettingIds)
        ]);
    }

    public function generateReport() {
        $this->validate();
        
        $this->applied_start_date = $this->start_date;
        $this->applied_end_date = $this->end_date;
    }

    public function resetFilters() {
        $this->period_preset = 'today';
        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->format('Y-m-d');
        
        $this->applied_start_date = $this->start_date;
        $this->applied_end_date = $this->end_date;
    }

    public function exportExcel() {
        $this->generateReport();

        $availableSettings = $this->getAvailableSettings();
        $effectiveSettingIds = $this->getEffectiveSettingIds();
        $validatedSettingIds = $this->validateSettingIds($effectiveSettingIds, $availableSettings);

        $filters = [
            'startDate' => $this->applied_start_date,
            'endDate' => $this->applied_end_date,
            'settingIds' => $validatedSettingIds,
            'scopeLabel' => $this->getScopeLabel($availableSettings, $validatedSettingIds)
        ];
        
        $filename = sprintf('arus_kas_%s_sd_%s.xlsx', 
            Carbon::parse($filters['startDate'])->format('d-m-Y'),
            Carbon::parse($filters['endDate'])->format('d-m-Y')
        );

        return Excel::download(new OperationalCashFlowReportExport($filters), $filename);
    }
    
    public function exportCsv() {
        $this->generateReport();

        $availableSettings = $this->getAvailableSettings();
        $effectiveSettingIds = $this->getEffectiveSettingIds();
        $validatedSettingIds = $this->validateSettingIds($effectiveSettingIds, $availableSettings);

        $filters = [
            'startDate' => $this->applied_start_date,
            'endDate' => $this->applied_end_date,
            'settingIds' => $validatedSettingIds,
            'isCsv' => true,
            'scopeLabel' => $this->getScopeLabel($availableSettings, $validatedSettingIds)
        ];
        
        $filename = sprintf('arus_kas_%s_sd_%s.csv', 
            Carbon::parse($filters['startDate'])->format('d-m-Y'),
            Carbon::parse($filters['endDate'])->format('d-m-Y')
        );

        return Excel::download(new OperationalCashFlowReportExport($filters), $filename, \Maatwebsite\Excel\Excel::CSV);
    }
}
