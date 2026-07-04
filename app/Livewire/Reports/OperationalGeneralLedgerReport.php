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
    use HasReportSettingScope;

    public $start_date;
    public $end_date;
    public $selected_buckets = [];

    public $applied_start_date;
    public $applied_end_date;
    public $applied_selected_buckets = [];
    
    public $expandedBuckets = [];
    public $loadedBucketDetails = [];

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
        $availableSettings = $this->getAvailableSettings();
        $effectiveSettingIds = $this->getEffectiveSettingIds();
        $validatedSettingIds = $this->validateSettingIds($effectiveSettingIds, $availableSettings);
        
        $filterData = new OperationalGeneralLedgerReportFilterData(
            $this->applied_start_date ?: now()->format('Y-m-d'),
            $this->applied_end_date ?: now()->format('Y-m-d'),
            $this->applied_selected_buckets
        );

        $report = $reportService->getSummary($validatedSettingIds, $filterData);
        $bucketLabels = OperationalGeneralLedgerBucketConfig::getLabels();

        return view('livewire.reports.operational-general-ledger-report', [
            'report' => $report,
            'bucketLabels' => $bucketLabels,
            'availableSettings' => $availableSettings,
            'scopeLabel' => $this->getScopeLabel($availableSettings, $validatedSettingIds)
        ]);
    }
    
    public function toggleBucket($bucketKey) {
        if (in_array($bucketKey, $this->expandedBuckets)) {
            $this->expandedBuckets = array_diff($this->expandedBuckets, [$bucketKey]);
        } else {
            $this->expandedBuckets[] = $bucketKey;
            
            if (!isset($this->loadedBucketDetails[$bucketKey])) {
                $reportService = app(OperationalGeneralLedgerReportService::class);
                $availableSettings = $this->getAvailableSettings();
                $effectiveSettingIds = $this->getEffectiveSettingIds();
                $validatedSettingIds = $this->validateSettingIds($effectiveSettingIds, $availableSettings);
                
                $filterData = new OperationalGeneralLedgerReportFilterData(
                    $this->applied_start_date ?: now()->format('Y-m-d'),
                    $this->applied_end_date ?: now()->format('Y-m-d'),
                    $this->applied_selected_buckets
                );
                
                $bucketDetail = $reportService->getBucketDetail($validatedSettingIds, $filterData, $bucketKey);
                
                if ($bucketDetail) {
                    $rows = [];
                    foreach ($bucketDetail->rows as $row) {
                        $rows[] = [
                            'date' => $row->date,
                            'sourceType' => $row->sourceType,
                            'reference' => $row->reference,
                            'description' => $row->description,
                            'debit' => $row->debit,
                            'credit' => $row->credit,
                            'runningBalance' => $row->runningBalance,
                            'tag' => $row->tag,
                        ];
                    }
                    $this->loadedBucketDetails[$bucketKey] = $rows;
                }
            }
        }
    }
    
    public function updatedSelectedSettingIds() {
        $this->expandedBuckets = [];
        $this->loadedBucketDetails = [];
    }

    public function generateReport() {
        $this->validate();
        
        $this->applied_start_date = $this->start_date;
        $this->applied_end_date = $this->end_date;
        $this->applied_selected_buckets = $this->selected_buckets;
        
        $this->expandedBuckets = [];
        $this->loadedBucketDetails = [];
    }

    public function resetFilters() {
        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->format('Y-m-d');
        $this->selected_buckets = array_keys(OperationalGeneralLedgerBucketConfig::getLabels());
        
        $this->applied_start_date = $this->start_date;
        $this->applied_end_date = $this->end_date;
        $this->applied_selected_buckets = $this->selected_buckets;
        
        $this->expandedBuckets = [];
        $this->loadedBucketDetails = [];
    }

    public function exportExcel() {
        // We still call generateReport if inputs changed, but actually the button might just be for the applied filters.
        // Wait, export Excel historically called generateReport() to commit the current input state.
        $this->generateReport();

        $availableSettings = $this->getAvailableSettings();
        $effectiveSettingIds = $this->getEffectiveSettingIds();
        $validatedSettingIds = $this->validateSettingIds($effectiveSettingIds, $availableSettings);

        $filters = [
            'startDate' => $this->applied_start_date ?: now()->format('Y-m-d'),
            'endDate' => $this->applied_end_date ?: now()->format('Y-m-d'),
            'settingIds' => $validatedSettingIds,
            'bucketKeys' => $this->applied_selected_buckets,
            'scopeLabel' => $this->getScopeLabel($availableSettings, $validatedSettingIds)
        ];
        
        $filename = sprintf('buku_besar_%s_sd_%s.xlsx', 
            Carbon::parse($filters['startDate'])->format('d-m-Y'),
            Carbon::parse($filters['endDate'])->format('d-m-Y')
        );

        return Excel::download(new OperationalGeneralLedgerReportExport($filters), $filename);
    }
}
