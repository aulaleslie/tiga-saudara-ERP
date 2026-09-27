<?php

namespace App\Livewire\Reports;

use App\Exports\ProfitLossReportExport;
use App\Services\Reports\OperationalProfitLossReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Setting\Entities\Setting;

class ProfitLossReport extends Component
{
    use HasReportSettingScope;

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
        $this->selectedSettingIds = [];
    }

    public function render(OperationalProfitLossReportService $reportService) {
        $availableSettings = Setting::query()
            ->orderBy('company_name')
            ->select('id', 'company_name')
            ->get()
            ->toArray();

        $effectiveSettingIds = $this->getEffectiveSettingIds();
        $validatedSettingIds = $this->validateSettingIds($effectiveSettingIds, $availableSettings);

        $report = null;
        if ($this->start_date && $this->end_date) {
            $report = $reportService->generate(
                $validatedSettingIds,
                $this->start_date,
                $this->end_date
            );
        } else {
            $report = $reportService->generate($validatedSettingIds, null, null);
        }

        return view('livewire.reports.profit-loss-report', [
            'report' => $report,
            'availableSettings' => $availableSettings,
            'effectiveSettingIds' => $validatedSettingIds,
            'scopeLabel' => $this->getScopeLabel($availableSettings, $validatedSettingIds)
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
            'settingIds' => $this->getEffectiveSettingIds()
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
