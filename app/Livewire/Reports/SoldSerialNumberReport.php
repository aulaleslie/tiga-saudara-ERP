<?php

namespace App\Livewire\Reports;

use App\Exports\SoldSerialNumberReportExport;
use App\Services\Reports\SoldSerialNumberReportFilterData;
use App\Services\Reports\SoldSerialNumberReportQueryService;
use App\Services\Reports\SoldSerialNumberReportValidator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Product\Entities\Product;

class SoldSerialNumberReport extends Component
{
    use HasReportSettingScope;
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $startDate = '';
    public string $endDate = '';
    public array $productIds = [];
    public string $productSearch = '';

    public bool $hasAppliedFilters = false;
    public ?array $appliedFilters = null;
    public ?string $appliedFilterHash = null;

    public int $perPage = 25;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('saleReports.access'), 403);

        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        $this->selectedSettingIds = $this->defaultSettingSelection();
    }

    public function toggleProduct(int $productId): void
    {
        if (in_array($productId, $this->productIds, true)) {
            $this->productIds = array_values(array_filter(
                $this->productIds,
                fn (int $id): bool => $id !== $productId
            ));
        } else {
            $this->productIds[] = $productId;
            $this->productIds = array_values(array_unique(array_map('intval', $this->productIds)));
        }
    }

    public function removeProduct(int $productId): void
    {
        $this->productIds = array_values(array_filter(
            $this->productIds,
            fn (int $id): bool => $id !== $productId
        ));
    }

    public function clearProducts(): void
    {
        $this->productIds = [];
    }

    public function getHasPendingFilterChangesProperty(): bool
    {
        if (! $this->hasAppliedFilters || $this->appliedFilterHash === null) {
            return false;
        }

        $currentFilter = new SoldSerialNumberReportFilterData(
            startDate: $this->startDate,
            endDate: $this->endDate,
            productIds: $this->productIds,
            scopeSettingIds: $this->getEffectiveSettingIds(),
        );

        return $currentFilter->hash() !== $this->appliedFilterHash;
    }

    public function generateReport(): void
    {
        abort_unless(auth()->user()?->can('saleReports.access'), 403);

        $this->resetValidation();

        $validator = new SoldSerialNumberReportValidator();
        $validated = $validator->validate([
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'productIds' => $this->productIds,
            'scopeSettingIds' => $this->selectedSettingIds,
        ]);

        $effectiveSettingIds = $this->getEffectiveSettingIds();
        $this->selectedSettingIds = $effectiveSettingIds;

        $filter = new SoldSerialNumberReportFilterData(
            startDate: $validated['startDate'],
            endDate: $validated['endDate'],
            productIds: $validated['productIds'],
            scopeSettingIds: $effectiveSettingIds,
        );

        $this->appliedFilters = $filter->toArray();
        $this->appliedFilterHash = $filter->hash();
        $this->hasAppliedFilters = true;
        $this->resetPage();
    }

    public function exportExcel()
    {
        abort_unless(auth()->user()?->can('saleReports.access'), 403);

        if (! $this->hasAppliedFilters || $this->appliedFilters === null) {
            $this->addError('export', 'Terapkan filter terlebih dahulu sebelum mengekspor.');

            return null;
        }

        $currentFilter = new SoldSerialNumberReportFilterData(
            startDate: $this->startDate,
            endDate: $this->endDate,
            productIds: $this->productIds,
            scopeSettingIds: $this->getEffectiveSettingIds(),
        );

        if ($currentFilter->hash() !== $this->appliedFilterHash) {
            $this->addError('export', 'Filter telah diubah. Klik Tampilkan Laporan terlebih dahulu sebelum mengekspor.');

            return null;
        }

        $sanitizedFilter = new SoldSerialNumberReportFilterData(
            startDate: $this->appliedFilters['startDate'],
            endDate: $this->appliedFilters['endDate'],
            productIds: $this->appliedFilters['productIds'],
            scopeSettingIds: $this->getEffectiveSettingIds(),
        );

        $filename = sprintf(
            'sold-serial-number-report-%s-to-%s.xlsx',
            $sanitizedFilter->startDate,
            $sanitizedFilter->endDate
        );

        return Excel::download(new SoldSerialNumberReportExport($sanitizedFilter), $filename);
    }

    public function getSearchResultsProperty(): Collection
    {
        $query = Product::query()
            ->select(['id', 'product_name', 'product_code'])
            ->orderBy('product_name');

        $tokens = array_values(array_filter(
            preg_split('/\s+/', trim($this->productSearch)) ?: []
        ));

        foreach ($tokens as $token) {
            $like = '%' . $token . '%';
            $query->where(function ($q) use ($like): void {
                $q->where('product_name', 'like', $like)
                    ->orWhere('product_code', 'like', $like);
            });
        }

        return $query->limit(26)->get();
    }

    public function getSelectedProductsProperty(): Collection
    {
        if ($this->productIds === []) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $this->productIds)
            ->orderBy('product_name')
            ->get(['id', 'product_name', 'product_code']);
    }

    public function render()
    {
        $rows = new LengthAwarePaginator([], 0, $this->perPage, $this->getPage());

        if ($this->hasAppliedFilters && $this->appliedFilters !== null) {
            $appliedSettingIds = $this->getSanitizedSettingIds($this->appliedFilters['scopeSettingIds'] ?? []);
            if ($appliedSettingIds === []) {
                $appliedSettingIds = $this->defaultSettingSelection();
            }

            $filter = SoldSerialNumberReportFilterData::fromArray([
                ...$this->appliedFilters,
                'scopeSettingIds' => $appliedSettingIds,
            ]);

            $allRows = (new SoldSerialNumberReportQueryService())->project($filter);
            $page = max(1, (int) $this->getPage());
            $items = $allRows->forPage($page, $this->perPage)->values();

            $rows = new LengthAwarePaginator(
                $items,
                $allRows->count(),
                $this->perPage,
                $page,
                ['path' => request()->url(), 'pageName' => 'page']
            );
        }

        $rawResults = $this->searchResults;
        $hasMoreSearchResults = $rawResults->count() > 25;
        $searchResults = $rawResults->take(25);

        return view('livewire.reports.sold-serial-number-report', [
            'rows' => $rows,
            'availableSettings' => $this->getAvailableSettings(),
            'searchResults' => $searchResults,
            'hasMoreSearchResults' => $hasMoreSearchResults,
            'selectedProducts' => $this->selectedProducts,
            'hasPendingFilterChanges' => $this->hasPendingFilterChanges,
        ]);
    }
}
