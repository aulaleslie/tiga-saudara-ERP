<?php

namespace App\Livewire\SalesReturn;

use App\Livewire\SalesReturn\SaleReturnCreateForm;
use Illuminate\Support\Collection;
use Livewire\Component;
use Modules\Sale\Entities\Sale;

class SaleReferenceSearch extends Component
{
    public string $query = '';
    public Collection $searchResults;
    public int $howMany = 5;

    public function mount(): void
    {
        $this->searchResults = Collection::empty();
    }

    public function render()
    {
        return view('livewire.sales-return.sale-reference-search');
    }

    public function updatedQuery(): void
    {
        if (empty($this->query)) {
            $this->searchResults = Collection::empty();
            return;
        }

        $this->searchResults = Sale::query()
            ->where('reference', 'like', '%' . $this->query . '%')
            ->orderByDesc('date')
            ->limit($this->howMany)
            ->get(['id', 'reference', 'customer_name', 'status']);
    }

    public function loadMore(): void
    {
        $this->howMany += 5;
        $this->updatedQuery();
    }

    public function resetQuery(): void
    {
        $this->query = '';
        $this->howMany = 5;
        $this->searchResults = Collection::empty();
    }

    public function selectSale(int $saleId): void
    {
        $sale = Sale::query()->find($saleId, ['id', 'reference', 'customer_name']);

        if (! $sale) {
            return;
        }

        $this->dispatch('saleReferenceSelected', [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'customer_name' => $sale->customer_name,
        ])->to(SaleReturnCreateForm::class);

        $this->resetQuery();
    }
}
