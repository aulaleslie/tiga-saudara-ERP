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

    public ?array $summary = null;
    public ?string $summaryInputsHash = null;

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

        $this->summary = $this->calculateSummary();
        $this->summaryInputsHash = $this->computeInputsHash(
            $this->globalBusinessFilters,
            $this->transactionDateFrom,
            $this->transactionDateTo,
            $this->dueDateFrom,
            $this->dueDateTo
        );
    }

    #[On('global-pos-filters-changed')]
    public function handleFiltersChanged(
        $globalBusinessFilters = null,
        $transactionDateFrom = null,
        $transactionDateTo = null,
        $dueDateFrom = null,
        $dueDateTo = null,
        $selectedCardFilter = null,
        bool $forceRefresh = false
    ) {
        $newBusinessFilters = $globalBusinessFilters ?? [];
        $newInputsHash = $this->computeInputsHash(
            $newBusinessFilters,
            $transactionDateFrom,
            $transactionDateTo,
            $dueDateFrom,
            $dueDateTo
        );

        $this->globalBusinessFilters = $newBusinessFilters;
        $this->transactionDateFrom = $transactionDateFrom;
        $this->transactionDateTo = $transactionDateTo;
        $this->dueDateFrom = $dueDateFrom;
        $this->dueDateTo = $dueDateTo;
        $this->selectedCardFilter = $selectedCardFilter;

        if ($forceRefresh || $this->summary === null || $this->summaryInputsHash !== $newInputsHash) {
            $this->summaryInputsHash = $newInputsHash;
            $this->summary = $this->calculateSummary();
        }
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

    protected function computeInputsHash(
        array $businessFilters,
        ?string $trxFrom,
        ?string $trxTo,
        ?string $dueFrom,
        ?string $dueTo
    ): string {
        $sortedBusiness = $businessFilters;
        sort($sortedBusiness);

        return md5(json_encode([
            'customer_id' => $this->customerId,
            'business' => $sortedBusiness,
            'trx_from' => $trxFrom,
            'trx_to' => $trxTo,
            'due_from' => $dueFrom,
            'due_to' => $dueTo,
        ]));
    }

    protected function calculateSummary(): array
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

    public function getSummaryDataProperty(): array
    {
        return $this->summary ?? $this->calculateSummary();
    }

    public function render()
    {
        if ($this->summary === null) {
            $this->summary = $this->calculateSummary();
            $this->summaryInputsHash = $this->computeInputsHash(
                $this->globalBusinessFilters ?? [],
                $this->transactionDateFrom,
                $this->transactionDateTo,
                $this->dueDateFrom,
                $this->dueDateTo
            );
        }

        return view('livewire.pos.pos-summary-cards', [
            'summary' => $this->summary,
        ]);
    }
}
