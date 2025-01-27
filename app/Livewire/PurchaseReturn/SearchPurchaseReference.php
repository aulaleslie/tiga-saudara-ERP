<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Purchase\Entities\Purchase;

class SearchPurchaseReference extends Component
{
    public string $query = '';
    public $search_results;
    public int $how_many = 5;

    public function mount(): void
    {
        $this->search_results = Collection::empty();
        Log::info('SearchPurchaseReference component mounted.');
    }

    public function render()
    {
        Log::info('Rendering SearchPurchaseReference component.');
        return view('livewire.purchase-return.search-purchase-reference');
    }

    public function updatedQuery(): void
    {
        Log::info('Query updated.', ['query' => $this->query]);

        try {
            // Fetch purchase references based on query
            $this->search_results = Purchase::where('payment_status', 'paid') // Only paid purchases
            ->where('reference', 'like', '%' . $this->query . '%')
                ->take($this->how_many)
                ->get();

            Log::info('Search results fetched.', ['results_count' => $this->search_results->count()]);
        } catch (\Exception $e) {
            Log::error('Error fetching search results.', [
                'query' => $this->query,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function loadMore(): void
    {
        $this->how_many += 5;
        Log::info('Loading more results.', ['how_many' => $this->how_many]);

        try {
            $this->updatedQuery(); // Reuse the updatedQuery method
        } catch (\Exception $e) {
            Log::error('Error loading more results.', [
                'query' => $this->query,
                'how_many' => $this->how_many,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resetQuery(): void
    {
        Log::info('Resetting query.');
        $this->query = '';
        $this->how_many = 5;
        $this->search_results = Collection::empty();
    }

    public function selectReference($reference): void
    {
        Log::info('Reference selected.', ['reference' => $reference]);

        try {
            $this->query = $reference; // Set the selected reference
            $this->dispatch('referenceSelected', $reference); // Dispatch event for parent component
            $this->resetQuery();
        } catch (\Exception $e) {
            Log::error('Error selecting reference.', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
