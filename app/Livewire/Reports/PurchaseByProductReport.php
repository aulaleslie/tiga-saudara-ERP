<?php

namespace App\Livewire\Reports;

use Livewire\Component;
use Livewire\WithPagination;
use App\Services\Reports\PurchaseByProductReportFilterData;
use App\Services\Reports\PurchaseByProductReportQueryService;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Spatie\Tags\Tag;
use App\Services\Reports\PurchaseByProductReportValidator;
use App\Services\Reports\PurchaseByProductReportSnapshotService;
use App\Exports\PurchaseByProductReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Gate;
use Modules\Setting\Entities\Setting;

class PurchaseByProductReport extends Component
{
    use WithPagination;

    public $startDate, $endDate;
    public $settingId;
    public $sortField = 'product_name';
    public $sortDirection = 'asc';

    public $supplierIds = [];
    public $tagIds = [];
    public $tagLogic = 'Salah satu';
    public $categoryIds = [];
    public $categoryLogic = 'Salah satu';
    public $productIds = [];
    public $periodPreset = '';
    public $scopeSettingId;
    public $canViewGlobal = false;
    public $settings = [];

    public $filterTriggered = false;
    public $appliedFilters = [];

    // Searchable filter states
    public $supplierSearch = '';
    public $supplierOptions = [];
    public $tagSearch = '';
    public $tagOptions = [];
    public $categorySearch = '';
    public $categoryOptions = [];
    public $productSearch = '';
    public $productOptions = [];

    // Selected labels for display pills
    public $supplierLabels = [];
    public $tagLabels = [];
    public $categoryLabels = [];
    public $productLabels = [];

    protected $paginationTheme = 'bootstrap';

    public function mount()
    {
        abort_if(Gate::denies('purchaseReports.access'), 403);

        $this->canViewGlobal = auth()->user()->hasPermissionTo('purchaseReports.global.access');
        $this->settingId = session('setting_id');
        $this->scopeSettingId = $this->settingId;

        if ($this->canViewGlobal) {
            $this->settings = Setting::all(['id', 'company_name'])->toArray();
        }

        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->appliedFilters = $this->exportFilters();
        $this->filterTriggered = false;
    }

    public function updatedPeriodPreset($value): void
    {
        match ($value) {
            'today'      => [$this->startDate, $this->endDate] = [now()->format('Y-m-d'), now()->format('Y-m-d')],
            'this_week'  => [$this->startDate, $this->endDate] = [now()->startOfWeek()->format('Y-m-d'), now()->endOfWeek()->format('Y-m-d')],
            'this_month' => [$this->startDate, $this->endDate] = [now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d')],
            'this_year'  => [$this->startDate, $this->endDate] = [now()->startOfYear()->format('Y-m-d'), now()->endOfYear()->format('Y-m-d')],
            'last_month' => [$this->startDate, $this->endDate] = [now()->subMonth()->startOfMonth()->format('Y-m-d'), now()->subMonth()->endOfMonth()->format('Y-m-d')],
            'last_year'  => [$this->startDate, $this->endDate] = [now()->subYear()->startOfYear()->format('Y-m-d'), now()->subYear()->endOfYear()->format('Y-m-d')],
            default      => null,
        };
    }

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

    public function selectCategory(int $id, string $name): void
    {
        if (!in_array($id, $this->categoryIds)) {
            $this->categoryIds[] = $id;
            $this->categoryLabels[$id] = $name;
        }
        $this->categorySearch = '';
        $this->categoryOptions = [];
    }

    public function removeCategory(int $id): void
    {
        $this->categoryIds = array_values(array_diff($this->categoryIds, [$id]));
        unset($this->categoryLabels[$id]);
    }

    public function selectProduct(int $id, string $name): void
    {
        if (!in_array($id, $this->productIds)) {
            $this->productIds[] = $id;
            $this->productLabels[$id] = $name;
        }
        $this->productSearch = '';
        $this->productOptions = [];
    }

    public function removeProduct(int $id): void
    {
        $this->productIds = array_values(array_diff($this->productIds, [$id]));
        unset($this->productLabels[$id]);
    }

    public function updatedSupplierSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->supplierOptions = [];
            return;
        }
        $effectiveScopeId = $this->canViewGlobal ? $this->scopeSettingId : $this->settingId;
        $this->supplierOptions = Supplier::query()
            ->where('setting_id', $effectiveScopeId)
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

    public function updatedCategorySearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->categoryOptions = [];
            return;
        }
        $effectiveScopeId = $this->canViewGlobal ? $this->scopeSettingId : $this->settingId;
        $this->categoryOptions = Category::query()
            ->where('setting_id', $effectiveScopeId)
            ->whereRaw('LOWER(category_name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)->get(['id', 'category_name'])->toArray();
    }

    public function updatedProductSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->productOptions = [];
            return;
        }
        $effectiveScopeId = $this->canViewGlobal ? $this->scopeSettingId : $this->settingId;
        $this->productOptions = Product::query()
            ->where('setting_id', $effectiveScopeId)
            ->whereRaw('LOWER(product_name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)->get(['id', 'product_name'])->toArray();
    }

    public function cancelFilters(): void
    {
        if (!empty($this->appliedFilters)) {
            $this->startDate = $this->appliedFilters['startDate'] ?? $this->startDate;
            $this->endDate = $this->appliedFilters['endDate'] ?? $this->endDate;
            $this->periodPreset = $this->appliedFilters['periodPreset'] ?? '';
            $this->supplierIds = $this->appliedFilters['supplierIds'] ?? [];
            $this->supplierLabels = $this->appliedFilters['supplierLabels'] ?? [];
            $this->tagIds = $this->appliedFilters['tagIds'] ?? [];
            $this->tagLabels = $this->appliedFilters['tagLabels'] ?? [];
            $this->categoryIds = $this->appliedFilters['categoryIds'] ?? [];
            $this->categoryLabels = $this->appliedFilters['categoryLabels'] ?? [];
            $this->productIds = $this->appliedFilters['productIds'] ?? [];
            $this->productLabels = $this->appliedFilters['productLabels'] ?? [];
            $this->tagLogic = $this->appliedFilters['tagLogic'] ?? 'Salah satu';
            $this->categoryLogic = $this->appliedFilters['categoryLogic'] ?? 'Salah satu';
            $this->sortField = $this->appliedFilters['sortField'] ?? 'product_name';
            $this->sortDirection = $this->appliedFilters['sortDirection'] ?? 'asc';
            $this->scopeSettingId = $this->appliedFilters['scopeSettingId'] ?? $this->settingId;
        }
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->productSearch = '';
        $this->productOptions = [];
    }

    public function resetFilters(): void
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->endOfMonth()->format('Y-m-d');
        $this->periodPreset = '';
        $this->supplierIds = [];
        $this->supplierLabels = [];
        $this->tagIds = [];
        $this->tagLabels = [];
        $this->categoryIds = [];
        $this->categoryLabels = [];
        $this->productIds = [];
        $this->productLabels = [];
        $this->tagLogic = 'Salah satu';
        $this->categoryLogic = 'Salah satu';
        $this->sortField = 'product_name';
        $this->sortDirection = 'asc';
        $this->scopeSettingId = $this->settingId;
        
        $this->supplierSearch = '';
        $this->supplierOptions = [];
        $this->tagSearch = '';
        $this->tagOptions = [];
        $this->categorySearch = '';
        $this->categoryOptions = [];
        $this->productSearch = '';
        $this->productOptions = [];
    }

    private function exportFilters(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'periodPreset' => $this->periodPreset,
            'supplierIds' => $this->supplierIds,
            'tagIds' => $this->tagIds,
            'tagLogic' => $this->tagLogic,
            'categoryIds' => $this->categoryIds,
            'categoryLogic' => $this->categoryLogic,
            'productIds' => $this->productIds,
            'sortField' => $this->sortField,
            'sortDirection' => $this->sortDirection,
            'scopeSettingId' => $this->scopeSettingId,
        ];
    }

    public function applyFilters(
        PurchaseByProductReportValidator $validator,
        PurchaseByProductReportQueryService $queryService,
        PurchaseByProductReportSnapshotService $snapshotService
    ) {
        $filterArray = $this->exportFilters();
        
        try {
            $validated = $validator->validate($filterArray);
            
            $this->appliedFilters = array_merge($validated, [
                'periodPreset' => $this->periodPreset,
                'supplierLabels' => $this->supplierLabels,
                'tagLabels' => $this->tagLabels,
                'categoryLabels' => $this->categoryLabels,
                'productLabels' => $this->productLabels,
            ]);
            
            $this->filterTriggered = true;

            $filter = PurchaseByProductReportFilterData::fromArray($this->appliedFilters);
            $filter->scopeSettingId = $this->canViewGlobal ? $this->appliedFilters['scopeSettingId'] : $this->settingId;
            $count = $queryService->build($filter)->count();
            $snapshotService->createSnapshot($filter, $count);
            
            $this->resetPage();
        } catch (ValidationException $e) {
            $this->filterTriggered = false;
            throw $e;
        }
    }

    public function exportExcel(
        PurchaseByProductReportSnapshotService $snapshotService, 
        PurchaseByProductReportQueryService $queryService
    ) {
        abort_if(Gate::denies('purchaseReports.access'), 403);

        if (!$this->filterTriggered) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $pendingFilterArray = $this->exportFilters();
        $filter = PurchaseByProductReportFilterData::fromArray($pendingFilterArray);
        $filter->scopeSettingId = $this->canViewGlobal ? $pendingFilterArray['scopeSettingId'] : $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Kondisi filter telah berubah. Silakan terapkan filter kembali sebelum mengekspor.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);
        $fileName = 'purchase_by_product_' . now()->format('Y-m-d') . '_' . $filter->startDate . '_' . $filter->endDate . '.xlsx';
        return Excel::download(new PurchaseByProductReportExport($query, $filter, false), $fileName);
    }

    public function exportCsv(
        PurchaseByProductReportSnapshotService $snapshotService, 
        PurchaseByProductReportQueryService $queryService
    ) {
        abort_if(Gate::denies('purchaseReports.access'), 403);

        if (!$this->filterTriggered) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Silakan terapkan filter terlebih dahulu sebelum mengekspor data.']);
            return;
        }

        $pendingFilterArray = $this->exportFilters();
        $filter = PurchaseByProductReportFilterData::fromArray($pendingFilterArray);
        $filter->scopeSettingId = $this->canViewGlobal ? $pendingFilterArray['scopeSettingId'] : $this->settingId;

        if (!$snapshotService->isValidForExport($filter)) {
            $this->dispatch('alert', ['type' => 'error', 'message' => 'Kondisi filter telah berubah. Silakan terapkan filter kembali sebelum mengekspor.']);
            return;
        }

        $query = $queryService->build($filter);
        $queryService->applySort($query, $filter->sortField, $filter->sortDirection);
        $fileName = 'purchase_by_product_' . now()->format('Y-m-d') . '_' . $filter->startDate . '_' . $filter->endDate . '.csv';
        return Excel::download(new PurchaseByProductReportExport($query, $filter, true), $fileName, \Maatwebsite\Excel\Excel::CSV);
    }

    public function render(PurchaseByProductReportQueryService $queryService)
    {
        $products = collect();
        $grandTotalPurchase = 0;
        $grandTotalReturn = 0;

        if ($this->filterTriggered) {
            $filter = PurchaseByProductReportFilterData::fromArray($this->appliedFilters);
            $filter->scopeSettingId = $this->canViewGlobal ? $this->appliedFilters['scopeSettingId'] : $this->settingId;

            $query = $queryService->build($filter);
            $queryService->applySort($query, $filter->sortField, $filter->sortDirection);

            $products = $query->paginate(15);
            $totals = $queryService->calculateGrandTotal($filter);
            
            $grandTotalPurchase = $totals['purchase_value'];
            $grandTotalReturn = $totals['return_value'];
        }

        return view('livewire.reports.purchase-by-product-report', [
            'products' => $products,
            'grandTotalPurchase' => $grandTotalPurchase,
            'grandTotalReturn' => $grandTotalReturn,
        ]);
    }
}
