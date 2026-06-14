<?php

namespace App\Livewire\Reports;

use App\Exports\ProfitLossReportExport;
use App\Services\Reports\OperationalProfitLossReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

class ProfitLossReport extends Component
{
    public $start_date;
    public $end_date;
    
    // We don't make $report a public property because Value Objects with complex types
    // don't hydrate back well in Livewire. We just pass it to the view.

    protected $rules = [
        'start_date' => 'required|date|before_or_equal:end_date',
        'end_date'   => 'required|date|after_or_equal:start_date'
    ];

    public function mount() {
        abort_if(Gate::denies('reports.access'), 403);

        $this->start_date = '';
        $this->end_date = '';
    }

    public function render(OperationalProfitLossReportService $reportService) {
        $settingId = session('setting_id');
        
        $report = null;
        if ($this->start_date && $this->end_date) {
            $report = $reportService->generate(
                $settingId, 
                $this->start_date, 
                $this->end_date
            );
        } else {
            // Provide a default empty report or calculate all time? 
            // The original logic calculated all time if start_date/end_date were missing, 
            // but the rules require them. We will calculate all time if not set.
            $report = $reportService->generate($settingId, null, null);
        }

        return view('livewire.reports.profit-loss-report', [
            'report' => $report
        ]);
    }

    public function generateReport() {
        $this->validate();
    }

    public function exportExcel() {
        $this->validate();

        $filters = $this->exportFilters();
        $filename = $this->buildFilename();

        return Excel::download(new ProfitLossReportExport($filters), $filename);
    }

    private function exportFilters(): array {
        return [
            'startDate' => $this->start_date ?: null,
            'endDate' => $this->end_date ?: null,
            'settingId' => session('setting_id')
        ];
    }

    private function buildFilename(): string {
        $start = $this->formatDateForFilename($this->start_date);
        $end = $this->formatDateForFilename($this->end_date);

        return sprintf('profit_loss_%s_%s.xlsx', $start, $end);
    }

    private function formatDateForFilename(?string $date): string {
        if (! $date) {
            return 'unknown';
        }

        return Carbon::parse($date)->format('d-m-Y');
    }
}
