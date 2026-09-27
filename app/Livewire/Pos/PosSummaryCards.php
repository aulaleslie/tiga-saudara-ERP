<?php

namespace App\Livewire\Pos;

use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Pos\Services\PosSettlementProjectionService;

class PosSummaryCards extends Component
{
    #[Locked]
    public bool $globalMode = true;

    #[Locked]
    public ?int $customerId = null;

    /** @var array<int>|null */
    public ?array $globalBusinessFilters = null;
    public ?string $transactionDateFrom = null;
    public ?string $transactionDateTo = null;
    public ?string $dueDateFrom = null;
    public ?string $dueDateTo = null;

    public ?string $selectedCardFilter = null;

    public function mount(
        bool $globalMode = true,
        ?array $globalBusinessFilters = null,
        ?string $transactionDateFrom = null,
        ?string $transactionDateTo = null,
        ?string $dueDateFrom = null,
        ?string $dueDateTo = null,
        ?string $selectedCardFilter = null,
        ?int $customerId = null
    ) {
        abort_if(!\auth()->user()->can('posPayments.global.access'), 403);

        $this->globalMode = $globalMode;
        $this->customerId = $customerId;
        $this->globalBusinessFilters = $globalBusinessFilters ?? [];
        $this->transactionDateFrom = $transactionDateFrom;
        $this->transactionDateTo = $transactionDateTo;
        $this->dueDateFrom = $dueDateFrom;
        $this->dueDateTo = $dueDateTo;
        $this->selectedCardFilter = $selectedCardFilter;
    }

    #[On('global-pos-filters-changed')]
    public function handleFiltersChanged($globalBusinessFilters = null, $transactionDateFrom = null, $transactionDateTo = null, $dueDateFrom = null, $dueDateTo = null, $selectedCardFilter = null)
    {
        $this->globalBusinessFilters = $globalBusinessFilters ?? [];
        $this->transactionDateFrom = $transactionDateFrom;
        $this->transactionDateTo = $transactionDateTo;
        $this->dueDateFrom = $dueDateFrom;
        $this->dueDateTo = $dueDateTo;
        $this->selectedCardFilter = $selectedCardFilter;
    }

    public function toggleCardFilter(?string $type = null)
    {
        if ($this->selectedCardFilter === $type) {
            $this->selectedCardFilter = null;
        } else {
            $this->selectedCardFilter = $type;
        }

        $this->dispatch('pos-filter', type: $this->selectedCardFilter);
    }

    public function getSummaryDataProperty()
    {
        /** @var PosSettlementProjectionService $projectionService */
        $projectionService = app(PosSettlementProjectionService::class);

        return $projectionService->calculateSummaryCards(
            $this->customerId,
            $this->globalBusinessFilters ?? [],
            $this->transactionDateFrom,
            $this->transactionDateTo,
            $this->dueDateFrom,
            $this->dueDateTo
        );
    }

    public function render()
    {
        return view('livewire.pos.pos-summary-cards', [
            'summary' => $this->summaryData,
        ]);
    }
}
