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

    public $selectedItem = null;
    public $itemDetails = null;

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
        $this->settingId = session('setting_id');
        $this->searchResultsData = [];
    }

    public function render(): Factory|View|Application
    {
        return view('sale::livewire.global-sales-search');
    }

    public function updatedQuery(): void
    {
        $this->resetPage();
        $this->performSearch();
    }

    public function updatedSearchType(): void
    {
        $this->resetPage();
        $this->performSearch();
    }

    public function performSearch(): void
    {
        if (empty($this->query)) {
            $this->searchResultsData = [];
            return;
        }

        try {
            // Use the unified keyword search across Sales and POS
            $searchFilters = ['search' => $this->query];

            $paginatedResults = $this->searchService->getUnifiedPagination(
                $searchFilters,
                null, // Global search
                $this->perPage,
                $this->getPage()
            );
            
            // Results are returned as mapped arrays from service
            $this->searchResultsData = collect($paginatedResults->items())->toArray();
            
            $this->paginationInfo = [
                'current_page' => $paginatedResults->currentPage(),
                'per_page' => $paginatedResults->perPage(),
                'total' => $paginatedResults->total(),
                'last_page' => $paginatedResults->lastPage(),
                'from' => $paginatedResults->firstItem(),
                'to' => $paginatedResults->lastItem(),
                'has_pages' => $paginatedResults->hasPages(),
            ];

        } catch (\Exception $e) {
            Log::error('GlobalMenuSearch::performSearch failed', [
                'error' => $e->getMessage(),
                'query' => $this->query
            ]);

            $this->searchResultsData = [];
            session()->flash('error', 'Search failed: ' . $e->getMessage());
        }
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

    public function viewSale($id, $type): void
    {
        $this->fetchDetails($id, $type);
        $this->dispatch('open-detail-modal');
    }

    public function fetchDetails($id, $type): void
    {
        $this->selectedItem = $id;
        $this->itemDetails = null;

        try {
            if ($type === 'sale') {
                $sale = \Modules\Sale\Entities\Sale::where('id', $id)
                    ->withoutGlobalScopes()
                    ->with(['saleDetails.product', 'customer', 'location', 'seller'])
                    ->firstOrFail();
                
                $this->itemDetails = [
                    'type' => 'sale',
                    'id' => $sale->id,
                    'reference' => $sale->reference,
                    'customer' => $sale->customer->customer_name ?? 'Walking Customer',
                    'date' => $sale->date,
                    'total' => $sale->total_amount,
                    'status' => $sale->status,
                    'status_label' => $sale->status,
                    'items' => $sale->saleDetails->map(function($detail) {
                        // Resolve serial strings
                        $serials = [];
                        if (!empty($detail->serial_number_ids)) {
                            $serials = \Modules\Product\Entities\ProductSerialNumber::whereIn('id', $detail->serial_number_ids)
                                ->pluck('serial_number')
                                ->toArray();
                        }

                        return [
                            'product' => $detail->product_name ?? ($detail->product->product_name ?? 'N/A'),
                            'code' => $detail->product_code ?? ($detail->product->product_code ?? 'N/A'),
                            'quantity' => $detail->quantity,
                            'price' => $detail->unit_price,
                            'subtotal' => $detail->sub_total,
                            'serials' => $serials
                        ];
                    })->toArray()
                ];
            } else {
                $transaction = \Modules\Pos\Entities\PosTransaction::where('id', $id)
                    ->withoutGlobalScopes()
                    ->with(['lines.serials', 'customer', 'completedCheckout'])
                    ->firstOrFail();

                // Resolve total
                $total = 0;
                if (!empty($transaction->snapshot_totals['grand_total'])) {
                    $total = $transaction->snapshot_totals['grand_total'];
                } elseif (!empty($transaction->snapshot_totals['total_amount'])) {
                    $total = $transaction->snapshot_totals['total_amount'];
                }

                $this->itemDetails = [
                    'type' => 'pos',
                    'id' => $transaction->id,
                    'reference' => $transaction->code,
                    'customer' => $transaction->customer->customer_name ?? ($transaction->metadata['customer_name'] ?? 'Walking Customer'),
                    'date' => $transaction->created_at,
                    'total' => $total,
                    'status' => $transaction->status,
                    'status_label' => $transaction->status,
                    'items' => $transaction->lines->map(fn($line) => [
                        'product' => $line->product_name_snapshot,
                        'code' => $line->product_code_snapshot,
                        'quantity' => $line->qty,
                        'price' => $line->unit_price,
                        'subtotal' => $line->qty * $line->unit_price,
                        'serials' => $line->serials->pluck('serial_number')->toArray()
                    ])->toArray()
                ];
            }
            
        } catch (\Exception $e) {
            Log::error('Fetch details failed', ['id' => $id, 'type' => $type, 'error' => $e->getMessage()]);
            session()->flash('error', 'Gagal memuat detail: ' . $e->getMessage());
        }
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
        $status = strtoupper($status);
        return match($status) {
            'COMPLETED', 'PAID', 'SUCCESS', 'APPROVED' => 'success',
            'PENDING', 'AWAITING SETTLEMENT', 'DRAFTED' => 'warning',
            'REJECTED', 'CANCELLED', 'FAILED', 'RETURNED' => 'danger',
            'DISPATCHED' => 'info',
            default => 'secondary',
        };
    }
}
