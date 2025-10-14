<?php

namespace App\Livewire\SalesReturn;

use App\Support\SalesReturn\SaleReturnEligibilityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Fluent;
use Livewire\Component;
use Modules\Sale\Entities\Sale;

class SaleReferenceSearch extends Component
{
    public string $query = '';
    public Collection $searchResults;
    public int $howMany = 5;
    public int $highlightedIndex = -1;

    protected SaleReturnEligibilityService $eligibilityService;

    protected SaleReturnEligibilityService $eligibilityService;

    public function mount(): void
    {
        $this->eligibilityService = App::make(SaleReturnEligibilityService::class);
        $this->searchResults = Collection::empty();
    }

    public function render()
    {
        return view('livewire.sales-return.sale-reference-search');
    }

    public function updatedQuery(): void
    {
        $this->highlightedIndex = -1;

        if (empty($this->query)) {
            $this->searchResults = Collection::empty();
            return;
        }

        $sales = Sale::query()
            ->where('reference', 'like', '%' . $this->query . '%')
            ->whereIn('status', SaleReturnEligibilityService::ELIGIBLE_STATUSES)
            ->orderByDesc('date')
            ->limit($this->howMany)
            ->get(['id', 'reference', 'customer_name', 'status', 'date']);

        $this->searchResults = $sales
            ->map(function (Sale $sale) {
                if (! $this->eligibilityService->isSaleEligible($sale)) {
                    return null;
                }

                $summary = $this->eligibilityService->summariseSale($sale);

                if ($summary['returnable_lines'] === 0) {
                    return null;
                }

                $saleDate = $sale->getAttribute('date');

                return new Fluent([
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
                ]);
            })
            ->filter()
            ->values();

        if ($this->searchResults->isNotEmpty()) {
            $this->highlightedIndex = 0;
        }
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
        $this->highlightedIndex = -1;
    }

    public function highlightNext(): void
    {
        if ($this->searchResults->isEmpty()) {
            return;
        }

        $count = $this->searchResults->count();
        $this->highlightedIndex = ($this->highlightedIndex + 1) % $count;
    }

    public function highlightPrevious(): void
    {
        if ($this->searchResults->isEmpty()) {
            return;
        }

        $count = $this->searchResults->count();
        if ($this->highlightedIndex <= 0) {
            $this->highlightedIndex = $count - 1;
            return;
        }

        $this->highlightedIndex--;
    }

    public function selectExactMatch(): void
    {
        if ($this->searchResults->isEmpty()) {
            $this->updatedQuery();
        }

        if ($this->searchResults->isEmpty()) {
            return;
        }

        if ($this->highlightedIndex >= 0) {
            $selected = $this->searchResults->get($this->highlightedIndex);
            $selectedId = $this->getResultId($selected);

            if ($selected && $selectedId) {
                $this->selectSale($selectedId);
                return;
            }
        }

        $query = trim($this->query);

        $match = $this->searchResults->first(function ($result) use ($query) {
            $reference = $this->getResultReference($result);

            return $reference && strcasecmp($reference, $query) === 0;
        });

        if (! $match) {
            $match = $this->searchResults->first();
        }

        if ($match) {
            $matchId = $this->getResultId($match);

            if ($matchId) {
                $this->selectSale($matchId);
            }
        }
    }

    public function selectSale(int $saleId): void
    {
        $sale = $this->searchResults
            ->first(function ($result) use ($saleId) {
                if ($result instanceof Fluent) {
                    return $result->id === $saleId;
                }

                if (is_array($result)) {
                    return ($result['id'] ?? null) === $saleId;
                }

                return false;
            });

        if (! $sale) {
            $sale = Sale::query()
                ->whereIn('status', SaleReturnEligibilityService::ELIGIBLE_STATUSES)
                ->find($saleId, ['id', 'reference', 'customer_name']);

            if (! $sale) {
                return;
            }

            $payload = [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'customer_name' => $sale->customer_name,
                'rows' => [],
            ];
        } else {
            $payload = $sale instanceof Fluent ? $sale->toArray() : $sale;
        }

        $this->dispatch('saleReferenceSelected', $payload);

        $this->resetQuery();
    }

    private function getResultId($result): ?int
    {
        if ($result instanceof Fluent) {
            return $result->id ?? null;
        }

        if (is_array($result)) {
            return $result['id'] ?? null;
        }

        return null;
    }

    private function getResultReference($result): ?string
    {
        if ($result instanceof Fluent) {
            return $result->reference ?? null;
        }

        if (is_array($result)) {
            return $result['reference'] ?? null;
        }

        return null;
    }
}
