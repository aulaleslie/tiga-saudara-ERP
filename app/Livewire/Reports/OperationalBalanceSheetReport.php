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
    use HasReportSettingScope;

    public $as_of_date;

    protected $rules = [
        'as_of_date' => 'required|date'
    ];

    public function mount() {
        abort_if(Gate::denies('reports.access'), 403);

        $this->as_of_date = now()->format('Y-m-d');
    }

    public function render(OperationalBalanceSheetReportService $reportService) {
        $availableSettings = $this->getAvailableSettings();
        $effectiveSettingIds = $this->getEffectiveSettingIds();
        $validatedSettingIds = $this->validateSettingIds($effectiveSettingIds, $availableSettings);

        $report = $reportService->generate(
            $validatedSettingIds, 
            $this->as_of_date ?: now()->format('Y-m-d')
        );

        return view('livewire.reports.operational-balance-sheet-report', [
            'report' => $report,
            'availableSettings' => $availableSettings,
            'scopeLabel' => $this->getScopeLabel($availableSettings, $validatedSettingIds)
        ]);
    }

    public function generateReport() {
        $this->validate();
    }

    public function exportExcel() {
        $this->validate();

        $availableSettings = $this->getAvailableSettings();
        $effectiveSettingIds = $this->getEffectiveSettingIds();
        $validatedSettingIds = $this->validateSettingIds($effectiveSettingIds, $availableSettings);

        $filters = [
            'asOfDate' => $this->as_of_date ?: now()->format('Y-m-d'),
            'settingIds' => $validatedSettingIds,
            'scopeLabel' => $this->getScopeLabel($availableSettings, $validatedSettingIds)
        ];
        
        $filename = sprintf('neraca_%s.xlsx', Carbon::parse($filters['asOfDate'])->format('d-m-Y'));

        return Excel::download(new OperationalBalanceSheetReportExport($filters), $filename);
    }
}
