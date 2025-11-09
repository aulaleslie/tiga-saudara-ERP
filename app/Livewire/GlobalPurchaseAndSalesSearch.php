<?php

namespace App\Livewire;

use App\Services\GlobalPurchaseAndSalesSearchService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class GlobalPurchaseAndSalesSearch extends Component
{
    use WithPagination;

    // Search properties
    public string $query = '';
    public string $searchType = 'all';
    public int $perPage = 20;

    // UI state
    public bool $showResults = false;
    public bool $isLoading = false;

    // Results
    public array $searchResults = [];
    public int $totalResults = 0;
    public ?int $responseTime = null;

    protected $listeners = [
        'searchPerformed' => 'handleSearchResults'
    ];

    public function mount()
    {
        // Check permission
        Gate::authorize('globalPurchaseAndSalesSearch.access');
    }

    /**
     * Perform search when query changes (debounced)
     */
    public function updatedQuery()
    {
        $this->resetPage();
        $this->performSearch();
    }

    /**
     * Perform search
     */
    public function performSearch()
    {
        if (empty($this->query)) {
            $this->clearResults();
            return;
        }

        $this->isLoading = true;
        $this->showResults = true;

        try {
            $user = Auth::user();
            $settingId = $user->setting_id ?? null;
            $effectiveSettingId = null; // Always global search like the global sales search

            $searchService = app(GlobalPurchaseAndSalesSearchService::class);

            $this->searchResults = match($this->searchType) {
                'serial' => $searchService->searchBySerialNumber($this->query, $effectiveSettingId, $this->perPage),
                'reference' => $this->searchReferences($this->query, $effectiveSettingId, $this->perPage),
                'party' => $this->searchParties($this->query, $effectiveSettingId, $this->perPage),
                'all' => $searchService->searchCombined($this->query, $effectiveSettingId, $this->perPage),
            };

            $this->totalResults = $this->searchResults['total'] ?? 0;
            $this->responseTime = $this->searchResults['response_time_ms'] ?? null;

        } catch (\Exception $e) {
            $this->addError('search', 'Search failed: ' . $e->getMessage());
            $this->clearResults();
        } finally {
            $this->isLoading = false;
        }
    }

    /**
     * Search references (helper method)
     */
    private function searchReferences(string $query, ?int $settingId, int $limit): array
    {
        $searchService = app(GlobalPurchaseAndSalesSearchService::class);
        $purchaseResults = $searchService->searchByPurchaseReference($query, $settingId, $limit, 1);
        $salesResults = $searchService->searchBySalesReference($query, $settingId, $limit, 1);

        // Combine results
        $combinedResults = array_merge($purchaseResults['results'], $salesResults['results']);
        usort($combinedResults, fn($a, $b) => strtotime($b['date']) <=> strtotime($a['date']));

        return [
            'results' => array_slice($combinedResults, 0, $limit),
            'total' => count($combinedResults),
            'response_time_ms' => ($purchaseResults['response_time_ms'] ?? 0) + ($salesResults['response_time_ms'] ?? 0)
        ];
    }

    /**
     * Search parties (helper method)
     */
    private function searchParties(string $query, ?int $settingId, int $limit): array
    {
        $searchService = app(GlobalPurchaseAndSalesSearchService::class);
        $supplierResults = $searchService->searchBySupplier($query, $settingId, $limit, 1);
        $customerResults = $searchService->searchByCustomer($query, $settingId, $limit, 1);

        // Combine results
        $combinedResults = array_merge($supplierResults['results'], $customerResults['results']);
        usort($combinedResults, fn($a, $b) => strtotime($b['date']) <=> strtotime($a['date']));

        return [
            'results' => array_slice($combinedResults, 0, $limit),
            'total' => count($combinedResults),
            'response_time_ms' => ($supplierResults['response_time_ms'] ?? 0) + ($customerResults['response_time_ms'] ?? 0)
        ];
    }

    /**
     * Clear search
     */
    public function clearSearch(): void
    {
        $this->query = '';
        $this->searchType = 'all';
        $this->searchResults = [];
        $this->totalResults = 0;
        $this->responseTime = null;
        $this->showResults = false;
        $this->resetPage();
    }

    /**
     * Export results (placeholder)
     */
    public function exportResults(): void
    {
        // TODO: Implement export functionality
        session()->flash('info', 'Fitur ekspor akan diimplementasikan di Phase 5');
    }

    public function updatedSearchType(): void
    {
        $this->resetPage();
        $this->performSearch();
    }

    /**
     * Handle search results from AJAX calls
     */
    public function handleSearchResults($results)
    {
        $this->searchResults = $results;
        $this->totalResults = count($results);
        $this->isLoading = false;
        $this->showResults = true;
    }

    /**
     * Get status badge CSS class
     */
    public function getStatusBadgeClass($status): string
    {
        return match(strtolower($status)) {
            'completed', 'paid', 'received' => 'success',
            'pending', 'partial' => 'warning',
            'cancelled', 'returned' => 'danger',
            default => 'secondary'
        };
    }

    /**
     * Translate status to Indonesian
     */
    public function translateStatus($status): string
    {
        return match(strtolower($status)) {
            'drafted' => 'Draf',
            'approved' => 'Disetujui',
            'pending' => 'Menunggu',
            'completed' => 'Selesai',
            'paid' => 'Dibayar',
            'received' => 'Diterima',
            'partial' => 'Sebagian',
            'cancelled' => 'Dibatalkan',
            'returned' => 'Dikembalikan',
            'waiting_approval' => 'Menunggu Persetujuan',
            'rejected' => 'Ditolak',
            default => ucfirst($status)
        };
    }

    public function render()
    {
        return view('livewire.global-purchase-and-sales-search', [
            'searchTypes' => [
                'all' => 'Semua Tipe',
                'serial' => 'Nomor Seri',
                'reference' => 'Nomor Referensi',
                'party' => 'Supplier/Pelanggan'
            ]
        ]);
    }
}
