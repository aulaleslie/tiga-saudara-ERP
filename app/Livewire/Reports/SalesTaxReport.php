<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use App\Services\Reports\SalesTaxReportFilterData;
use App\Services\Reports\SalesTaxReportQueryService;
use App\Services\Reports\SalesTaxReportValidator;
use App\Services\Reports\SalesTaxReportSnapshotService;
use App\Exports\SalesTaxReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Validation\ValidationException;

class SalesTaxReport extends Component
{
    public $startDate;
    public $endDate;
    public $periodPreset = 'Bulan ini';
    public $settingId;

    public $filterTriggered = false;
    public $appliedFilters = [];

    public function mount()
    {
        $this->settingId = session('setting_id');
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = false;
    }

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->startDate = $this->appliedFilters['startDate'] ?? $this->startDate;
            $this->endDate = $this->appliedFilters['endDate'] ?? $this->endDate;
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? 'Bulan ini';
        }
    }

    public function resetFilters(): void
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->periodPreset = 'Bulan ini';
    }

    public function updatedPeriodPreset($value)
    {
        if ($value === 'Hari ini') {
            $this->startDate = now()->format('Y-m-d');
            $this->endDate = now()->format('Y-m-d');
        } elseif ($value === 'Kemarin') {
            $this->startDate = now()->subDay()->format('Y-m-d');
            $this->endDate = now()->subDay()->format('Y-m-d');
        } elseif ($value === 'Minggu ini') {
            $this->startDate = now()->startOfWeek()->format('Y-m-d');
            $this->endDate = now()->endOfWeek()->format('Y-m-d');
        } elseif ($value === 'Minggu lalu') {
            $this->startDate = now()->subWeek()->startOfWeek()->format('Y-m-d');
            $this->endDate = now()->subWeek()->endOfWeek()->format('Y-m-d');
        } elseif ($value === 'Bulan ini') {
            $this->startDate = now()->startOfMonth()->format('Y-m-d');
            $this->endDate = now()->endOfMonth()->format('Y-m-d');
        } elseif ($value === 'Bulan lalu') {
            $this->startDate = now()->subMonth()->startOfMonth()->format('Y-m-d');
            $this->endDate = now()->subMonth()->endOfMonth()->format('Y-m-d');
        } elseif ($value === 'Tahun ini') {
            $this->startDate = now()->startOfYear()->format('Y-m-d');
            $this->endDate = now()->endOfYear()->format('Y-m-d');
        } elseif ($value === 'Tahun lalu') {
            $this->startDate = now()->subYear()->startOfYear()->format('Y-m-d');
            $this->endDate = now()->subYear()->endOfYear()->format('Y-m-d');
        }
    }

    private function exportFilters(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'periodPreset' => $this->periodPreset,
        ];
    }

    public function applyFilters(
        SalesTaxReportValidator $validator,
        SalesTaxReportQueryService $queryService,
        SalesTaxReportSnapshotService $snapshotService
    ) {
        $filterArray = $this->exportFilters();
        
        try {
            $validated = $validator->validate($filterArray);
            
            $this->appliedFilters = $validated;
            $this->filterTriggered = true;

            $filter = SalesTaxReportFilterData::fromArray($this->appliedFilters);
            $filter->scopeSettingId = $this->settingId;
            $count = $queryService->build($filter)->count();
            $snapshotService->createSnapshot($filter, $count);
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    public function exportExcel(
        SalesTaxReportSnapshotService $snapshotService, 
        SalesTaxReportQueryService $queryService
    ) {
        $filter = SalesTaxReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);

        $filename = "SalesTaxReport_{$filter->startDate}_{$filter->endDate}.xlsx";

        return Excel::download(new SalesTaxReportExport($query, $filter, false), $filename);
    }

    public function exportCsv(
        SalesTaxReportSnapshotService $snapshotService, 
        SalesTaxReportQueryService $queryService
    ) {
        $filter = SalesTaxReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);

        $filename = "sales_tax_report_{$filter->startDate}_{$filter->endDate}.csv";

        return Excel::download(new SalesTaxReportExport($query, $filter, true), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    public function render(SalesTaxReportQueryService $queryService)
    {
        $groupedData = [];
        
        if ($this->filterTriggered) {
            $filter = new SalesTaxReportFilterData(
                startDate: $this->appliedFilters['startDate'] ?? $this->startDate,
                endDate: $this->appliedFilters['endDate'] ?? $this->endDate,
                periodPreset: $this->appliedFilters['periodPreset'] ?? 'Bulan ini',
                scopeSettingId: $this->settingId
            );

            $rows = $queryService->build($filter);

            $groupedByTax = $rows->groupBy('tax_name');

            foreach ($groupedByTax as $taxGroup => $items) {
                $taxTotal = 0;
                $transactions = [];
                foreach (['Penjualan', 'Pembelian'] as $type) {
                    $item = $items->firstWhere('transaction_type', $type);
                    if ($item) {
                        $transactions[] = [
                            'type' => $type,
                            'dpp' => $item->dpp,
                            'tax_rate' => floatval($item->tax_rate),
                            'total_tax' => $item->total_tax,
                        ];
                        // Assuming Penjualan adds to tax and Pembelian reduces it
                        // (standard VAT behavior: output tax - input tax)
                        if ($type === 'Penjualan') {
                            $taxTotal += $item->total_tax;
                        } else {
                            $taxTotal -= $item->total_tax;
                        }
                    }
                }
                
                $groupedData[] = [
                    'tax_group' => $taxGroup,
                    'transactions' => $transactions,
                    'subtotal_tax' => $taxTotal,
                ];
            }
        }

        return view('livewire.reports.sales-tax-report', [
            'groupedData' => collect($groupedData),
        ]);
    }
}
