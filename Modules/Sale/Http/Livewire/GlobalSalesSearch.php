<?php

namespace Modules\Sale\Http\Livewire;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Sale\Services\SerialNumberSearchService;
use Modules\Sale\Services\SalesOrderFormatter;
use Illuminate\Support\Facades\Log;

class GlobalSalesSearch extends Component
{
    use WithPagination;

    public string $query = '';
    public string $searchType = 'all'; // all, serial, reference, customer
    public array $searchResultsData = [];
    public array $paginationInfo = [];
    public int $perPage = 20;
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';

    protected SerialNumberSearchService $searchService;
    protected SalesOrderFormatter $formatter;
    protected $settingId;

    public function boot(
        SerialNumberSearchService $searchService,
        SalesOrderFormatter $formatter
    ): void {
        $this->searchService = $searchService;
        $this->formatter = $formatter;
    }

    public function mount(): void
    {
        $this->settingId = session('setting_id'); // Keep for audit trail but don't use for filtering
        $this->searchResultsData = [];

        Log::info('GlobalSalesSearch::mount called', [
            'settingId' => $this->settingId
        ]);
    }

    public function render(): Factory|View|Application
    {
        return view('sale::livewire.global-sales-search');
    }

    public function updatedQuery(): void
    {
        // Ensure settingId is set for audit trail (session might not be available during Livewire updates)
        $this->settingId = session('setting_id');

        Log::info('GlobalMenuSearch::updatedQuery called', [
            'query' => $this->query,
            'query_length' => strlen($this->query ?? ''),
            'settingId' => $this->settingId
        ]);

        $this->resetPage();
        $this->performSearch();
    }

    public function updatedSearchType(): void
    {
        // Ensure settingId is set for audit trail
        $this->settingId = session('setting_id');

        $this->resetPage();
        $this->performSearch();
    }

    public function performSearch(): void
    {
        // Debug log to check if method is called
        Log::info('GlobalSalesSearch::performSearch START', [
            'query' => $this->query,
            'query_length' => strlen($this->query ?? ''),
            'query_hex' => $this->query ? bin2hex($this->query) : null,
            'searchType' => $this->searchType,
            'settingId' => $this->settingId,
            'timestamp' => now()->toISOString()
        ]);

        if (empty($this->query)) {
            Log::info('GlobalMenuSearch::performSearch EARLY RETURN - empty query');
            $this->searchResultsData = [];
            return;
        }

        Log::info('GlobalMenuSearch::performSearch CONTINUING - query not empty', [
            'query' => $this->query
        ]);

        try {
            // Build filters based on search type and query
            $searchFilters = $this->buildSearchFilters();

            Log::info('GlobalMenuSearch::performSearch called', [
                'query' => $this->query,
                'searchType' => $this->searchType,
                'searchFilters' => $searchFilters,
                'settingId' => $this->settingId
            ]);

            $query = $this->searchService->buildQuery($searchFilters, null); // Pass null for global search

            // Apply sorting (no tenant filtering for global search)
            $query->orderBy($this->sortBy, $this->sortDirection);

            // Paginate results
            $paginatedResults = $query->paginate($this->perPage);
            
            // Convert to array that Livewire can serialize
            $items = $paginatedResults->items();
            $this->searchResultsData = is_array($items) ? $items : $items->all();
            $this->paginationInfo = [
                'current_page' => $paginatedResults->currentPage(),
                'per_page' => $paginatedResults->perPage(),
                'total' => $paginatedResults->total(),
                'last_page' => $paginatedResults->lastPage(),
                'from' => $paginatedResults->firstItem(),
                'to' => $paginatedResults->lastItem(),
                'has_pages' => $paginatedResults->hasPages(),
            ];

                        Log::info('GlobalMenuSearch::performSearch results', [
                'results_count' => count($this->searchResultsData),
                'total_results' => $this->paginationInfo['total'] ?? 0,
                'current_page' => $this->paginationInfo['current_page'] ?? 1,
                'per_page' => $this->perPage,
                'has_results' => count($this->searchResultsData) > 0,
                'first_result_id' => count($this->searchResultsData) > 0 ? $this->searchResultsData[0]->id : null,
                'first_result_reference' => count($this->searchResultsData) > 0 ? $this->searchResultsData[0]->reference : null
            ]);

        } catch (\Exception $e) {
            Log::error('GlobalMenuSearch::performSearch failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query' => $this->query,
                'searchType' => $this->searchType
            ]);

            $this->searchResultsData = [];
            session()->flash('error', 'Search failed: ' . $e->getMessage());
        }
    }

    protected function buildSearchFilters(): array
    {
        $filters = [];

        Log::info('GlobalMenuSearch::buildSearchFilters', [
            'query' => $this->query,
            'searchType' => $this->searchType
        ]);

        // Add query-based filters based on search type
        if (!empty($this->query)) {
            switch ($this->searchType) {
                case 'serial':
                    $filters['serial_number'] = $this->query;
                    break;
                case 'reference':
                    $filters['sale_reference'] = $this->query;
                    break;
                case 'customer':
                    $filters['customer_name'] = $this->query;
                    break;
                case 'pos_transaction':
                    $filters['pos_transaction'] = $this->query;
                    break;
                case 'product_name':
                    $filters['product_name'] = $this->query;
                    break;
                case 'all':
                default:
                    // Search across multiple fields
                    $filters['serial_number'] = $this->query;
                    $filters['sale_reference'] = $this->query;
                    $filters['customer_name'] = $this->query;
                    $filters['pos_transaction'] = $this->query;
                    $filters['product_name'] = $this->query;
                    break;
            }
        }

        Log::info('GlobalMenuSearch::buildSearchFilters final', [
            'final_filters' => $filters
        ]);

        return $filters;
    }

    public function clearSearch(): void
    {
        $this->query = '';
        $this->searchType = 'all';
        $this->searchResultsData = [];
        $this->resetPage();
    }

    public function sortBy($column): void
    {
        // Ensure settingId is set for audit trail
        $this->settingId = session('setting_id');

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->performSearch();
    }

    public function gotoPage($page): void
    {
        $this->setPage($page);
        $this->performSearch();
    }

    public function viewSale($saleId): void
    {
        // Emit event to parent component or redirect
        $this->dispatch('viewSale', $saleId);
    }

    public function exportResults(): void
    {
        // TODO: Implement export functionality
        session()->flash('info', 'Export functionality will be implemented in Phase 5');
    }

    public function getSuggestions(): Collection
    {
        if (empty($this->query)) {
            return Collection::empty();
        }

        try {
            // Get autocomplete suggestions from API
            $response = \Illuminate\Support\Facades\Http::get(route('api.global-sales-search.suggest'), [
                'q' => $this->query,
                'type' => $this->searchType,
            ]);

            if ($response->successful()) {
                return collect($response->json()['suggestions'] ?? []);
            }
        } catch (\Exception $e) {
            // Log error but don't break the UI
        }

        return Collection::empty();
    }

    public function getSearchResultsProperty()
    {
        return $this->searchResultsData ?? collect();
    }

    public function getStatusBadgeClass($status): string
    {
        return match($status) {
            'DRAFTED' => 'warning',
            'APPROVED' => 'success',
            'DISPATCHED' => 'info',
            'RETURNED' => 'danger',
            default => 'secondary',
        };
    }
}