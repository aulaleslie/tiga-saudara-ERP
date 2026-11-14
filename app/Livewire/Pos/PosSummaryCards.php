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
    public bool $summaryInitialized = false;
    public ?string $summaryError = null;

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
        // Summary projection is deferred to loadSummary() (wire:init) so the initial render stays cheap.
    }

    public function loadSummary(): void
    {
        $this->authorizeGlobal();
        $this->summaryInitialized = true;
        $this->refreshSummary(false);
    }

    public function retrySummary(): void
    {
        $this->authorizeGlobal();
        $this->summaryInitialized = true;
        $this->refreshSummary(true);
    }

    protected function authorizeGlobal(): void
    {
        abort_unless(\auth()->user()?->can('posPayments.global.access'), 403);
    }

    protected function currentInputsHash(): string
    {
        return $this->computeInputsHash(
            $this->globalBusinessFilters ?? [],
            $this->transactionDateFrom,
            $this->transactionDateTo,
            $this->dueDateFrom,
            $this->dueDateTo
        );
    }

    protected function refreshSummary(bool $force): void
    {
        $hash = $this->currentInputsHash();

        if (!$force && $this->summary !== null && $this->summaryError === null && $this->summaryInputsHash === $hash) {
            return;
        }

        try {
            $this->summary = $this->calculateSummary();
            $this->summaryInputsHash = $hash;
            $this->summaryError = null;
        } catch (\Throwable $e) {
            report($e);
            $this->summary = null;
            $this->summaryInputsHash = null;
            $this->summaryError = 'Ringkasan gagal dimuat.';
        }
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
        $this->authorizeGlobal();

        $this->globalBusinessFilters = $globalBusinessFilters ?? [];
        $this->transactionDateFrom = $transactionDateFrom;
        $this->transactionDateTo = $transactionDateTo;
        $this->dueDateFrom = $dueDateFrom;
        $this->dueDateTo = $dueDateTo;
        $this->selectedCardFilter = $selectedCardFilter;

        // Before initialization the pending loadSummary() call uses these latest inputs.
        if (!$this->summaryInitialized) {
            return;
        }

        $this->refreshSummary($forceRefresh);
    }

    public function toggleCardFilter(?string $type = null, $seq = null)
    {
        if ($this->selectedCardFilter === $type) {
            $this->selectedCardFilter = null;
        } else {
            $this->selectedCardFilter = $type;
        }

        $this->dispatch('pos-filter', type: $this->selectedCardFilter, workspace: $this->workspaceKey(), seq: $seq);
    }

    public function workspaceKey(): string
    {
        return $this->customerId ? 'customer-' . $this->customerId : 'standalone';
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

    public function getSummaryDataProperty(): ?array
    {
        // Never calculate during render; unknown totals stay null until loadSummary() completes.
        return $this->summary;
    }

    public function render()
    {
        return view('livewire.pos.pos-summary-cards', [
            'summary' => $this->summary,
        ]);
    }
}
