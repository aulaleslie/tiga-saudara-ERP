<?php

namespace App\Livewire\AutoComplete;

use App\Support\SalesReturn\SaleReturnEligibilityService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Livewire\Component;
use Modules\Sale\Entities\Sale;

class SaleReferenceLoader extends Component
{
    public string $query = '';

    /** @var array<int, array<string, mixed>> */
    public array $search_results = [];

    public int $how_many = 5;

    public int $highlightedIndex = -1;

    public bool $isFocused = false;

    public int $query_count = 0;

    /** @var array<string, mixed>|null */
    public ?array $selectedSale = null;

    protected SaleReturnEligibilityService $eligibilityService;

    public function boot(SaleReturnEligibilityService $eligibilityService): void
    {
        $this->eligibilityService = $eligibilityService;
    }

    public function mount(?int $saleId = null, ?string $saleReference = null): void
    {
        if ($saleId) {
            $payload = $this->buildSalePayload($saleId);

            if ($payload) {
                $this->selectedSale = $payload;
                $this->query = $payload['reference'] ?? ($saleReference ?? '');
            }
        } elseif ($saleReference) {
            $this->query = $saleReference;
        }
    }

    public function updatedQuery(): void
    {
        $this->highlightedIndex = -1;

        if ($this->selectedSale && Str::lower(trim($this->query)) !== Str::lower((string) ($this->selectedSale['reference'] ?? ''))) {
            $this->selectedSale = null;
        }

        if (trim($this->query) === '') {
            $this->search_results = [];
            $this->query_count = 0;
            return;
        }

        $this->searchSales();
    }

    public function updatedIsFocused($value): void
    {
        if ($value && trim($this->query) !== '') {
            $this->searchSales();
        }
    }

    public function resetQueryAfterDelay(): void
    {
        sleep(1);
        $this->isFocused = false;
    }

    public function searchSales(): void
    {
        if (trim($this->query) === '') {
            $this->search_results = [];
            $this->query_count = 0;
            return;
        }

        $query = $this->buildSearchQuery();

        $this->query_count = (clone $query)->count();

        $sales = $query
            ->limit($this->how_many)
            ->get();

        $results = [];

        foreach ($sales as $sale) {
            if (! $this->eligibilityService->isSaleEligible($sale)) {
                continue;
            }

            $summary = $this->eligibilityService->summariseSale($sale);

            if ($summary['returnable_lines'] === 0) {
                continue;
            }

            $saleDate = $sale->getAttribute('date');

            $results[] = [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'customer_name' => $sale->customer_name,
                'status' => $sale->status,
                'date' => $saleDate ? (string) $saleDate : null,
                'returnable_lines' => $summary['returnable_lines'],
                'total_available_quantity' => $summary['total_available_quantity'],
                'requires_serials' => $summary['requires_serials'],
                'bundle_lines' => $summary['bundle_lines'],
                'rows' => $summary['rows']->map(fn ($row) => $row)->all(),
            ];
        }

        $this->search_results = array_values($results);
        $this->highlightedIndex = empty($this->search_results) ? -1 : 0;
    }

    public function loadMore(): void
    {
        $this->how_many += 5;
        $this->searchSales();
    }

    public function resetQuery(): void
    {
        $this->search_results = [];
        $this->query_count = 0;
        $this->highlightedIndex = -1;
        $this->isFocused = false;
    }

    public function highlightNext(): void
    {
        if (empty($this->search_results)) {
            return;
        }

        $count = count($this->search_results);
        $this->highlightedIndex = ($this->highlightedIndex + 1) % $count;
    }

    public function highlightPrevious(): void
    {
        if (empty($this->search_results)) {
            return;
        }

        $count = count($this->search_results);

        if ($this->highlightedIndex <= 0) {
            $this->highlightedIndex = $count - 1;
            return;
        }

        $this->highlightedIndex--;
    }

    public function selectExactMatch(): void
    {
        if (empty($this->search_results)) {
            $this->searchSales();
        }

        if (empty($this->search_results)) {
            return;
        }

        if ($this->highlightedIndex >= 0 && isset($this->search_results[$this->highlightedIndex])) {
            $this->selectSale($this->search_results[$this->highlightedIndex]['id']);
            return;
        }

        $query = trim($this->query);

        $match = collect($this->search_results)->first(function ($result) use ($query) {
            return isset($result['reference']) && strcasecmp($result['reference'], $query) === 0;
        });

        if (! $match) {
            $match = $this->search_results[0] ?? null;
        }

        if ($match && isset($match['id'])) {
            $this->selectSale($match['id']);
        }
    }

    public function selectSale(int $saleId): void
    {
        $payload = collect($this->search_results)->firstWhere('id', $saleId);

        if (! $payload) {
            $payload = $this->buildSalePayload($saleId);
        }

        if (! $payload) {
            return;
        }

        $this->selectedSale = $payload;
        $this->query = $payload['reference'] ?? '';

        $this->dispatch('saleReferenceSelected', $payload);

        $this->search_results = [];
        $this->query_count = 0;
        $this->highlightedIndex = -1;
        $this->isFocused = false;
        $this->how_many = 5;
    }

    public function clearSelection(): void
    {
        $this->selectedSale = null;
        $this->query = '';
        $this->search_results = [];
        $this->query_count = 0;
        $this->highlightedIndex = -1;
        $this->how_many = 5;
        $this->dispatch('saleReferenceSelected', ['id' => null]);
    }

    protected function buildSalePayload(int $saleId): ?array
    {
        $sale = Sale::find($saleId);

        if (! $sale || ! $this->eligibilityService->isSaleEligible($sale)) {
            return null;
        }

        $summary = $this->eligibilityService->summariseSale($sale);

        if ($summary['returnable_lines'] === 0) {
            return null;
        }

        $saleDate = $sale->getAttribute('date');

        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'customer_name' => $sale->customer_name,
            'status' => $sale->status,
            'date' => $saleDate ? (string) $saleDate : null,
            'returnable_lines' => $summary['returnable_lines'],
            'total_available_quantity' => $summary['total_available_quantity'],
            'requires_serials' => $summary['requires_serials'],
            'bundle_lines' => $summary['bundle_lines'],
            'rows' => $summary['rows']->map(fn ($row) => $row)->all(),
        ];
    }

    protected function buildSearchQuery(): Builder
    {
        return Sale::query()
            ->where('reference', 'like', '%' . $this->query . '%')
            ->whereIn('status', SaleReturnEligibilityService::ELIGIBLE_STATUSES)
            ->orderByDesc('date');
    }

    public function render(): View|Factory|Application
    {
        return view('livewire.auto-complete.sale-reference-loader');
    }
}
