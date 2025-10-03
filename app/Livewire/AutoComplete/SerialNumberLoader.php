<?php

namespace App\Livewire\AutoComplete;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\ProductSerialNumber;

class SerialNumberLoader extends Component
{
    public $query = '';  // User input for search
    public $search_results = []; // search results
    public $index; // Row index (or key) in table, passed from parent
    public $isFocused = false;
    public $query_count = 0;
    public $how_many = 10; // Limit for search results
    public $location_id = 0;
    public $product_id = 0;
    public $is_taxed = false;
    public $is_broken = false;
    public $serialIndex;
    public $productCompositeKey;
    public $is_dispatch;

    public function mount($locationId = 0, $productId = 0, $isTaxed = false, $isBroken = false, $serialIndex = null, $productCompositeKey = null, $isDispatch = null): void
    {
        $this->location_id = $locationId;
        $this->product_id = $productId;
        $this->is_taxed = $isTaxed;
        $this->is_broken = $isBroken;
        $this->serialIndex = $serialIndex;
        $this->productCompositeKey = $productCompositeKey;
        $this->is_dispatch = $isDispatch;
    }

    public function updatedQuery(): void
    {
        if ($this->isFocused) {
            $this->searchSerialNumbers();
        } else {
            $this->search_results = [];
        }
    }

    public function resetQueryAfterDelay(): void
    {
        sleep(1); // Small delay before closing
        $this->isFocused = false;
    }

    public function searchSerialNumbers(): void
    {
        if ($this->query) {
            Log::info('search serial number', [
                'query' => $this->query,
                'is_taxed' => $this->is_taxed,
                'is_broken' => $this->is_broken,
                'is_dispatch' => $this->is_dispatch,
                'serialIndex' => $this->serialIndex,
                'productCompositeKey' => $this->productCompositeKey,
            ]);

            $baseQuery = ProductSerialNumber::where('serial_number', 'like', '%' . $this->query . '%')
                ->when(
                    $this->location_id,
                    fn($query) => $query->where('location_id', $this->location_id)
                )
                ->when($this->product_id > 0, fn($query) => $query->where('product_id', $this->product_id))
                ->when($this->is_taxed,
                    fn($query) => $query->whereNotNull('tax_id')->where('tax_id', '>', 0),
                    fn($query) => $query->where(function ($q) {
                        $q->whereNull('tax_id')->orWhere('tax_id', 0);
                    })
                )
                ->when($this->is_broken, fn($query) => $query->where('is_broken', true))
                ->when($this->is_dispatch, fn($query) => $query->whereNull('dispatch_detail_id'));

            $this->query_count = $baseQuery->count();

            $this->search_results = (clone $baseQuery)
                ->limit($this->how_many)
                ->get();
        }
    }

    public function selectSerialNumber($serialNumberId): void
    {
        $serialNumber = ProductSerialNumber::query()
            ->whereKey($serialNumberId)
            ->when(
                $this->location_id,
                fn($query) => $query->where('location_id', $this->location_id)
            )
            ->first();

        if ($serialNumber) {
            $this->search_results = [$serialNumber];
            $this->query = "$serialNumber->serial_number";

            // Dispatch event with both productCompositeKey and serialIndex
            $this->dispatch('serialNumberSelected', [
                'serialNumber' => $serialNumber,
                'productCompositeKey' => $this->productCompositeKey,
                'serialIndex' => $this->serialIndex,
            ]);

            $this->isFocused = false;
            $this->query_count = 0;
        }
    }

    public function loadMore(): void
    {
        $this->how_many += 10; // Load more results
        $this->searchSerialNumbers();
    }

    public function resetQuery(): void
    {
        $this->search_results = [];
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.auto-complete.serial-number-loader');
    }
}
