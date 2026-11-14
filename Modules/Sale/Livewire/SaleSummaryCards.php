<?php

namespace Modules\Sale\Livewire;

use Carbon\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Services\GlobalSaleSummaryQuery;

class SaleSummaryCards extends Component
{
    private const PIUTANG_BELUM_TERTAGIH_PAYMENT_STATUSES = ['UNPAID', 'PARTIAL'];

    public $settingId;

    #[Locked]
    public bool $globalMode = false;

    #[Locked]
    public ?int $customerId = null;

    // Global mode filters
    /** @var array<int>|null */
    public ?array $globalBusinessFilters = null;
    public ?string $documentDateFrom = null;
    public ?string $documentDateTo = null;
    public ?string $dueDateFrom = null;
    public ?string $dueDateTo = null;

    // Durable summary card selection (stored server-side, restored across refreshes)
    public ?string $selectedCardFilter = null;

    // Global mode: deferred summary state. Totals are calculated by loadSummary() (wire:init),
    // never during the initial render, and retained per normalized summary inputs.
    /** @var array{outstanding: array, overdue: array, recent: array}|null */
    public ?array $summary = null;
    public ?string $summaryInputsHash = null;
    public bool $summaryInitialized = false;
    public ?string $summaryError = null;

    public function mount(bool $globalMode = false, ?array $globalBusinessFilters = null, ?string $documentDateFrom = null, ?string $documentDateTo = null, ?string $dueDateFrom = null, ?string $dueDateTo = null, ?string $selectedCardFilter = null, ?int $customerId = null)
    {
        abort_if($globalMode && !\auth()->user()->can('salePayments.global.access'), 403);

        $this->globalMode = $globalMode;
        $this->customerId = $customerId;
        $this->settingId = $globalMode ? null : session('setting_id');
        $this->globalBusinessFilters = $globalBusinessFilters ?? [];
        $this->documentDateFrom = $documentDateFrom;
        $this->documentDateTo = $documentDateTo;
        $this->dueDateFrom = $dueDateFrom;
        $this->dueDateTo = $dueDateTo;
        $this->selectedCardFilter = $selectedCardFilter;
    }

    #[On('global-sale-filters-changed')]
    public function handleFiltersChanged($globalBusinessFilters = null, $documentDateFrom = null, $documentDateTo = null, $dueDateFrom = null, $dueDateTo = null, $selectedCardFilter = null, bool $forceRefresh = false)
    {
        if (!$this->globalMode) {
            return;
        }

        $this->authorizeGlobal();

        $this->globalBusinessFilters = $globalBusinessFilters ?? [];
        $this->documentDateFrom = $documentDateFrom;
        $this->documentDateTo = $documentDateTo;
        $this->dueDateFrom = $dueDateFrom;
        $this->dueDateTo = $dueDateTo;
        $this->selectedCardFilter = $selectedCardFilter;

        // Before initialization the pending loadSummary() call will use these latest inputs.
        if (!$this->summaryInitialized) {
            return;
        }

        $this->refreshSummary($forceRefresh);
    }

    /** Deferred initial summary load (wire:init). */
    public function loadSummary(): void
    {
        if (!$this->globalMode) {
            return;
        }

        $this->authorizeGlobal();
        $this->summaryInitialized = true;
        $this->refreshSummary(false);
    }

    /** Recalculate with the current applied inputs after a failure. */
    public function retrySummary(): void
    {
        if (!$this->globalMode) {
            return;
        }

        $this->authorizeGlobal();
        $this->summaryInitialized = true;
        $this->refreshSummary(true);
    }

    protected function authorizeGlobal(): void
    {
        abort_unless(\auth()->user()?->can('salePayments.global.access'), 403);
    }

    protected function refreshSummary(bool $force): void
    {
        $hash = $this->computeInputsHash();

        if (!$force && $this->summary !== null && $this->summaryError === null && $this->summaryInputsHash === $hash) {
            return;
        }

        try {
            $this->summary = app(GlobalSaleSummaryQuery::class)->summarize(
                $this->customerId,
                $this->globalBusinessFilters ?? [],
                $this->documentDateFrom,
                $this->documentDateTo,
                $this->dueDateFrom,
                $this->dueDateTo
            );
            $this->summaryInputsHash = $hash;
            $this->summaryError = null;
        } catch (\Throwable $e) {
            report($e);
            // Never present previous totals as current for the new inputs.
            $this->summary = null;
            $this->summaryInputsHash = null;
            $this->summaryError = 'Ringkasan gagal dimuat.';
        }
    }

    public function workspaceKey(): string
    {
        return $this->customerId ? 'customer-' . $this->customerId : 'standalone';
    }

    protected function computeInputsHash(): string
    {
        $business = array_map('intval', $this->globalBusinessFilters ?? []);
        sort($business);

        return md5(json_encode([
            'customer_id' => $this->customerId,
            'business' => array_values(array_unique($business)),
            'doc_from' => $this->documentDateFrom ?: null,
            'doc_to' => $this->documentDateTo ?: null,
            'due_from' => $this->dueDateFrom ?: null,
            'due_to' => $this->dueDateTo ?: null,
        ]));
    }

    public function toggleCardFilter(?string $type = null, $seq = null)
    {
        // Toggle: clicking the same card clears it, clicking different card selects it
        if ($this->selectedCardFilter === $type) {
            $this->selectedCardFilter = null;
        } else {
            $this->selectedCardFilter = $type;
        }

        // Dispatch to table to apply the card filter
        $this->dispatch('sale-filter', type: $this->selectedCardFilter, workspace: $this->workspaceKey(), seq: $seq);
    }

    public function render()
    {
        return view('sale::livewire.sale-summary-cards');
    }

    public function getPiutangBelumTertagihProperty()
    {
        if ($this->globalMode) {
            return $this->summary['outstanding'] ?? null;
        }

        $query = Sale::query()
            ->approvedUp()
            ->where('setting_id', $this->settingId)
            ->where('due_amount', '>', 0);

        $result = $query->selectRaw('COUNT(*) as cnt, SUM(due_amount) as total')
                        ->first();

        return [
            'count' => (int) ($result->cnt ?? 0),
            'total' => (float) ($result->total ?? 0),
        ];
    }

    public function getPiutangTelatProperty()
    {
        if ($this->globalMode) {
            return $this->summary['overdue'] ?? null;
        }

        $query = Sale::query()
            ->approvedUp()
            ->where('due_date', '<', Carbon::today())
            ->where('setting_id', $this->settingId)
            ->where('due_amount', '>', 0);

        $result = $query->selectRaw('COUNT(*) as cnt, SUM(due_amount) as total')
                        ->first();

        return [
            'count' => (int) ($result->cnt ?? 0),
            'total' => (float) ($result->total ?? 0),
        ];
    }

    public function getPenerimaanProperty()
    {
        $thirtyDaysAgo = Carbon::today()->subDays(30)->format('Y-m-d');

        if ($this->globalMode) {
            return $this->summary['recent'] ?? null;
        }

        // Non-global mode: keep existing behavior (use approvedUp for normal workflow)
        $query = SalePayment::active()
            ->where('date', '>=', $thirtyDaysAgo)
            ->where('date', '<=', Carbon::today()->endOfDay())
            ->whereHas('sale', function ($q) {
                $q->approvedUp()
                  ->where('setting_id', $this->settingId);
            });

        $result = $query->selectRaw('COUNT(DISTINCT sale_id) as cnt, SUM(amount) as total')
                        ->first();

        if ($result && $result->cnt > 0) {
            return [
                'count' => (int) ($result->cnt ?? 0),
                'total' => (float) ($result->total ?? 0),
            ];
        }

        $fallbackResult = Sale::query()
            ->where('setting_id', $this->settingId)
            ->whereBetween('date', [$thirtyDaysAgo, Carbon::today()->endOfDay()])
            ->where('payment_status', 'PAID')
            ->whereIn('status', [
                Sale::STATUS_APPROVED,
                Sale::STATUS_DISPATCHED_PARTIALLY,
                Sale::STATUS_DISPATCHED,
            ])
            ->selectRaw('COUNT(*) as cnt, SUM(paid_amount) as total')
            ->first();

        return [
            'count' => (int) ($fallbackResult->cnt ?? 0),
            'total' => (float) ($fallbackResult->total ?? 0),
        ];
    }
}
