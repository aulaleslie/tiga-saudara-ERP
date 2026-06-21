<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use App\Services\Reports\SupplierPayablesReportFilterData;
use App\Services\Reports\SupplierPayablesReportQueryService;
use App\Services\Reports\SupplierPayablesReportValidator;
use App\Services\Reports\SupplierPayablesReportSnapshotService;
use App\Exports\SupplierPayablesReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Modules\People\Entities\Supplier;
use Spatie\Tags\Tag;
use Illuminate\Validation\ValidationException;

class SupplierPayablesReport extends Component
{
    use WithPagination;

    public $endDate;
    public $dueDateUntil;
    public $settingId;
    public $sortField = 'supplier_name';
    public $sortDirection = 'asc';

    public $supplierIds = [];
    public $tagIds = [];
    public $tagLogic = 'Salah satu';
    public $periodPreset = '';

    public $filterTriggered = false;
    
    public $appliedFilters = [];

    // Searchable filter states
    public $supplierSearch = '';
    public $supplierOptions = [];
    public $tagSearch = '';
    public $tagOptions = [];

    // Selected labels for display pills
    public $supplierLabels = [];
    public $tagLabels = [];

    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        $this->settingId = session('setting_id');
        $this->endDate = now()->format('Y-m-d');
        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = false;
    }

    public function updatedPeriodPreset($value): void
    {
        match ($value) {
            'today'      => $this->endDate = now()->format('Y-m-d'),
            'this_week'  => $this->endDate = now()->endOfWeek()->format('Y-m-d'),
            'this_month' => $this->endDate = now()->endOfMonth()->format('Y-m-d'),
            'this_year'  => $this->endDate = now()->endOfYear()->format('Y-m-d'),
            default      => null,
        };
    }

    // Select/Remove Methods
    public function selectSupplier(int $id, string $name): void
    {
        if (!in_array($id, $this->supplierIds)) {
            $this->supplierIds[] = $id;
            $this->supplierLabels[$id] = $name;
        }
        $this->supplierSearch = '';
        $this->supplierOptions = [];
    }

    public function removeSupplier(int $id): void
    {
        $this->supplierIds = array_values(array_diff($this->supplierIds, [$id]));
        unset($this->supplierLabels[$id]);
    }

    public function selectTag(int $id, string $name): void
    {
        if (!in_array($id, $this->tagIds)) {
            $this->tagIds[] = $id;
            $this->tagLabels[$id] = $name;
        }
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function removeTag(int $id): void
    {
        $this->tagIds = array_values(array_diff($this->tagIds, [$id]));
        unset($this->tagLabels[$id]);
    }

    // Search Methods
    public function updatedSupplierSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->supplierOptions = [];
            return;
        }
        $settingId = $this->settingId ?: session('setting_id');
        $this->supplierOptions = Supplier::query()
            ->where('setting_id', $settingId)
            ->whereRaw('LOWER(supplier_name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)->get(['id', 'supplier_name'])->toArray();
    }

    public function updatedTagSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->tagOptions = [];
            return;
        }
        $locale = app()->getLocale();
        $this->tagOptions = Tag::query()
            ->where(fn ($q) => $q->containing($value, $locale)->orWhere(fn($sq) => $sq->containing($value, 'en')))
            ->limit(10)->get(['id', 'name'])->toArray();
    }

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->endDate = $this->appliedFilters['endDate'] ?? $this->endDate;
            $this->dueDateUntil = $this->appliedFilters['dueDateUntil'] ?? $this->dueDateUntil;
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? '';
            $this->supplierIds = $this->appliedFilters['supplierIds'] ?? [];
            $this->supplierLabels = $this->appliedFilters['supplierLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->tagLogic = $this->appliedFilters['tagLogic'] ?? 'Salah satu';
            $this->sortField = $this->appliedFilters['sortField'] ?? 'supplier_name';
            $this->sortDirection = $this->appliedFilters['sortDirection'] ?? 'asc';
        }
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function resetFilters(): void
    {
        $this->endDate = now()->format('Y-m-d');
        $this->dueDateUntil = null;
        $this->periodPreset = '';
        $this->supplierIds = [];
        $this->supplierLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->tagLogic = 'Salah satu';
        $this->sortField = 'supplier_name';
        $this->sortDirection = 'asc';
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    private function exportFilters(): array
    {
        return [
            'endDate' => $this->endDate,
            'dueDateUntil' => $this->dueDateUntil,
            'periodPreset' => $this->periodPreset,
            'supplierIds' => $this->supplierIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
        ];
    }

    public function applyFilters(
        SupplierPayablesReportValidator $validator,
        SupplierPayablesReportQueryService $queryService,
        SupplierPayablesReportSnapshotService $snapshotService
    ) {
        $filterArray = $this->exportFilters();
        
        try {
            $validated = $validator->validate($filterArray);
            
            $this->appliedFilters = array_merge($validated, [
                'periodPreset' => $this->periodPreset,
                'supplierLabels' => $this->supplierLabels,
                'tagLabels' => $this->tagLabels,
            ]);
            
            $this->filterTriggered = true;

            $filter = SupplierPayablesReportFilterData::fromArray($this->appliedFilters);
            $filter->scopeSettingId = $this->settingId;
            $count = $queryService->build($filter)->count();
            $snapshotService->createSnapshot($filter, $count);
            
            $this->resetPage();
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    public function exportExcel(
        SupplierPayablesReportSnapshotService $snapshotService, 
        SupplierPayablesReportQueryService $queryService
    ) {
        $filter = SupplierPayablesReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "hutang_supplier_{$filter->endDate}.xlsx";

        return Excel::download(new SupplierPayablesReportExport($query, $filter, false), $filename);
    }

    public function exportCsv(
        SupplierPayablesReportSnapshotService $snapshotService, 
        SupplierPayablesReportQueryService $queryService
    ) {
        $filter = SupplierPayablesReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "hutang_supplier_{$filter->endDate}.csv";

        return Excel::download(new SupplierPayablesReportExport($query, $filter, true), $filename, \Maatwebsite\Excel\Excel::CSV);
    }

    public function exportPdf(
        SupplierPayablesReportSnapshotService $snapshotService, 
        SupplierPayablesReportQueryService $queryService
    ) {
        $filter = SupplierPayablesReportFilterData::fromArray($this->exportFilters());
        $filter->scopeSettingId = $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

        $filename = "hutang_supplier_{$filter->endDate}.pdf";

        return Excel::download(new SupplierPayablesReportExport($query, $filter, false), $filename, \Maatwebsite\Excel\Excel::DOMPDF);
    }

    public function render(SupplierPayablesReportQueryService $queryService)
    {
        $purchases = collect();
        
        if ($this->filterTriggered) {
            $filter = new SupplierPayablesReportFilterData(
                endDate: $this->appliedFilters['endDate'] ?? $this->endDate,
                scopeSettingId: $this->settingId,
                dueDateUntil: $this->appliedFilters['dueDateUntil'] ?? null,
                supplierIds: $this->appliedFilters['supplierIds'] ?? [],
                tagIds: $this->appliedFilters['tagIds'] ?? [],
                tagLogic: $this->appliedFilters['tagLogic'] ?? 'Salah satu',
                sortField: $this->appliedFilters['sortField'] ?? 'supplier_name',
                sortDirection: $this->appliedFilters['sortDirection'] ?? 'asc'
            );

            $query = $queryService->build($filter);
            $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

            $baseQuery = clone $query;

            $purchases = $query->paginate(15);

            // Compute running totals per supplier
            $runningTotals = [];
            $runningJumlahTotals = [];
            $previousPageLastSupplierId = null;
            $previousRows = collect();
            
            if ($purchases->currentPage() > 1) {
                $offset = ($purchases->currentPage() - 1) * $purchases->perPage();
                $previousQuery = clone $baseQuery;
                $previousQuery->setEagerLoads([]);
                
                // For sub_total calculation from previous pages
                $previousRows = $previousQuery->limit($offset)->get();
                
                if ($previousRows->isNotEmpty()) {
                    $previousPageLastSupplierId = $previousRows->last()->supplier_id;
                }
                
                foreach ($previousRows as $row) {
                    $supplierId = $row->supplier_id;
                    if ($supplierId) {
                        $runningTotals[$supplierId] = ($runningTotals[$supplierId] ?? 0) + $row->saldo;
                        $runningJumlahTotals[$supplierId] = ($runningJumlahTotals[$supplierId] ?? 0) + $row->total_amount;
                    }
                }
            }
            
            $currentPageSupplierIds = $purchases->pluck('supplier_id')->unique()->toArray();
            $currentPageTotalPurchases = [];
            if (!empty($currentPageSupplierIds)) {
                $purchasesCountQuery = clone $baseQuery;
                $purchasesCountQuery->setEagerLoads([]);
                $purchasesCountQuery->getQuery()->columns = [];
                $purchasesCountQuery->getQuery()->orders = [];

                $currentPageTotalPurchases = $purchasesCountQuery->select('purchases.supplier_id', \Illuminate\Support\Facades\DB::raw('count(*) as total'))
                    ->whereIn('purchases.supplier_id', $currentPageSupplierIds)
                    ->groupBy('purchases.supplier_id')
                    ->pluck('total', 'supplier_id')
                    ->toArray();
            }

            $currentSupplierRowsProcessed = [];
            foreach ($currentPageSupplierIds as $sId) {
                if (isset($runningTotals[$sId])) {
                    $currentSupplierRowsProcessed[$sId] = $previousRows->where('supplier_id', $sId)->count();
                } else {
                    $currentSupplierRowsProcessed[$sId] = 0;
                }
            }

            $purchases->getCollection()->transform(function ($purchase) use (&$runningTotals, &$runningJumlahTotals, &$currentSupplierRowsProcessed, $currentPageTotalPurchases) {
                $supplierId = $purchase->supplier_id;
                if (!isset($runningTotals[$supplierId])) {
                    $runningTotals[$supplierId] = 0;
                    $runningJumlahTotals[$supplierId] = 0;
                }

                $purchase->previous_running_total = $runningTotals[$supplierId];
                $purchase->previous_running_jumlah = $runningJumlahTotals[$supplierId];
                $currentSupplierRowsProcessed[$supplierId]++;
                $isLastPurchaseInSupplier = $currentSupplierRowsProcessed[$supplierId] == ($currentPageTotalPurchases[$supplierId] ?? 1);
                
                $purchase->is_last_detail = $isLastPurchaseInSupplier;
                $runningTotals[$supplierId] += $purchase->saldo;
                $runningJumlahTotals[$supplierId] += $purchase->total_amount;

                return $purchase;
            });

            $grandTotal = 0;
            $grandTotalJumlah = 0;
            if ($purchases->total() > 0) {
                $totalQuery = clone $baseQuery;
                $totalQuery->setEagerLoads([]);
                $totals = \Illuminate\Support\Facades\DB::table(\Illuminate\Support\Facades\DB::raw("({$totalQuery->toSql()}) as sub"))
                    ->mergeBindings($totalQuery->getQuery())
                    ->select(
                        \Illuminate\Support\Facades\DB::raw('SUM(sub.saldo) as total_saldo'),
                        \Illuminate\Support\Facades\DB::raw('SUM(sub.total_amount) as total_jumlah')
                    )
                    ->first();
                $grandTotal = round($totals->total_saldo ?? 0, 2);
                $grandTotalJumlah = round($totals->total_jumlah ?? 0, 2);
            }
            
            $nextPageFirstSupplierId = null;
            if ($purchases->hasMorePages()) {
                $nextOffset = $purchases->currentPage() * $purchases->perPage();
                $nextQuery = clone $baseQuery;
                $nextQuery->setEagerLoads([]);
                $nextRow = $nextQuery->skip($nextOffset)->first();
                if ($nextRow) {
                    $nextPageFirstSupplierId = $nextRow->supplier_id;
                }
            }
        }

        return view('livewire.reports.supplier-payables-report', [
            'purchases' => $purchases,
            'previousPageLastSupplierId' => $previousPageLastSupplierId ?? null,
            'nextPageFirstSupplierId' => $nextPageFirstSupplierId ?? null,
            'grandTotal' => $grandTotal ?? 0,
            'grandTotalJumlah' => $grandTotalJumlah ?? 0,
        ]);
    }
}
