<?php

namespace App\Livewire\Pos;

use App\Models\User;
use App\Support\GlobalPaymentSearchQuery;
use Carbon\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosSettlementProjectionService;
use Modules\Setting\Entities\Setting;

class GlobalPosPaymentTable extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public $searchText = '';
    #[Url]
    public $search = '';
    public $perPage = 10;
    #[Url]
    public $sortField = 'created_at';
    #[Url]
    public $sortDirection = 'desc';

    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public bool $globalMode = true;

    #[Locked]
    public int $tableRefreshId = 0;

    // Filters: draft state
    /** @var array<int>|null */
    public ?array $draftGlobalBusinessFilters = null;
    public ?string $draftTransactionDateFrom = null;
    public ?string $draftTransactionDateTo = null;
    public ?string $draftDueDateFrom = null;
    public ?string $draftDueDateTo = null;
    public ?string $draftPaymentStatusFilter = null;
    public ?int $draftCashierUserId = null;
    public ?int $draftTerminalId = null;

    // Filters: applied state
    #[Url]
    /** @var array<int>|null */
    public ?array $globalBusinessFilters = null;
    #[Url]
    public ?string $transactionDateFrom = null;
    #[Url]
    public ?string $transactionDateTo = null;
    #[Url]
    public ?string $dueDateFrom = null;
    #[Url]
    public ?string $dueDateTo = null;
    #[Url]
    public ?string $paymentStatusFilter = null;
    #[Url]
    public ?int $cashierUserId = null;
    #[Url]
    public ?int $terminalId = null;

    // Summary card selection state
    #[Url]
    public ?string $selectedCardFilter = null;

    public function mount(?int $customerId = null, bool $globalMode = true)
    {
        abort_if(!\auth()->user()->can('posPayments.global.access'), 403);

        $this->globalMode = $globalMode;
        $this->customerId = $customerId;

        if ($this->globalBusinessFilters === null) {
            $this->globalBusinessFilters = [];
        }

        $this->draftGlobalBusinessFilters = $this->globalBusinessFilters ?? [];
        $this->draftTransactionDateFrom = $this->transactionDateFrom;
        $this->draftTransactionDateTo = $this->transactionDateTo;
        $this->draftDueDateFrom = $this->dueDateFrom;
        $this->draftDueDateTo = $this->dueDateTo;
        $this->draftPaymentStatusFilter = $this->paymentStatusFilter;
        $this->draftCashierUserId = $this->cashierUserId;
        $this->draftTerminalId = $this->terminalId;

        $this->normalizeDateRanges();
    }

    private function normalizeDateRanges(): void
    {
        if (!empty($this->transactionDateFrom) && !empty($this->transactionDateTo)) {
            if ($this->transactionDateFrom > $this->transactionDateTo) {
                [$this->transactionDateFrom, $this->transactionDateTo] = [$this->transactionDateTo, $this->transactionDateFrom];
            }
        }
        if (!empty($this->dueDateFrom) && !empty($this->dueDateTo)) {
            if ($this->dueDateFrom > $this->dueDateTo) {
                [$this->dueDateFrom, $this->dueDateTo] = [$this->dueDateTo, $this->dueDateFrom];
            }
        }
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function searchSubmit()
    {
        $this->search = $this->searchText;
        $this->resetPage();
    }

    public function clearSearch()
    {
        $this->search = '';
        $this->searchText = '';
        $this->resetPage();
    }

    public function applyFilter()
    {
        $this->globalBusinessFilters = $this->draftGlobalBusinessFilters ?? [];
        $this->transactionDateFrom = $this->draftTransactionDateFrom;
        $this->transactionDateTo = $this->draftTransactionDateTo;
        $this->dueDateFrom = $this->draftDueDateFrom;
        $this->dueDateTo = $this->draftDueDateTo;
        $this->paymentStatusFilter = $this->draftPaymentStatusFilter;
        $this->cashierUserId = $this->draftCashierUserId;
        $this->terminalId = $this->draftTerminalId;

        $this->normalizeDateRanges();
        $this->resetPage();

        $this->dispatch('global-pos-filters-changed',
            globalBusinessFilters: $this->globalBusinessFilters,
            transactionDateFrom: $this->transactionDateFrom,
            transactionDateTo: $this->transactionDateTo,
            dueDateFrom: $this->dueDateFrom,
            dueDateTo: $this->dueDateTo,
            selectedCardFilter: $this->selectedCardFilter
        );
    }

    public function resetFilters()
    {
        $this->draftGlobalBusinessFilters = [];
        $this->draftTransactionDateFrom = null;
        $this->draftTransactionDateTo = null;
        $this->draftDueDateFrom = null;
        $this->draftDueDateTo = null;
        $this->draftPaymentStatusFilter = null;
        $this->draftCashierUserId = null;
        $this->draftTerminalId = null;

        $this->globalBusinessFilters = [];
        $this->transactionDateFrom = null;
        $this->transactionDateTo = null;
        $this->dueDateFrom = null;
        $this->dueDateTo = null;
        $this->paymentStatusFilter = null;
        $this->cashierUserId = null;
        $this->terminalId = null;
        $this->selectedCardFilter = null;

        $this->resetPage();

        $this->dispatch('global-pos-filters-changed',
            globalBusinessFilters: [],
            transactionDateFrom: null,
            transactionDateTo: null,
            dueDateFrom: null,
            dueDateTo: null,
            selectedCardFilter: null
        );
    }

    #[On('pos-filter')]
    public function applyCardFilter($type = null)
    {
        $this->selectedCardFilter = $type;
        $this->resetPage();

        // Signals the workspace-level Alpine overlay (see
        // global-payments/partials/workspace.blade.php) that the card-filter operation
        // has fully completed: this component's own state update, re-render, and DOM
        // morph. Dispatched as a browser event so the sibling PosSummaryCards component
        // (which started the overlay on click, before either component's Livewire
        // request began) does not need any direct reference to this component.
        $this->dispatch('pos-card-filter-applied');
    }

    public function sortBy($field)
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function render()
    {
        /** @var PosSettlementProjectionService $projectionService */
        $projectionService = app(PosSettlementProjectionService::class);

        $query = $projectionService->baseEligibleQuery()
            ->with(array_merge(
                PosSettlementProjectionService::reachableSalesEagerLoad(),
                [
                    'setting',
                    'customer',
                    'creator',
                    'completedCheckout.terminal',
                    'completedCheckout.cashier',
                ]
            ));

        // Customer filter
        if (!empty($this->customerId)) {
            $query->where('pos_transactions.customer_id', $this->customerId);
        }

        // Business filter
        if (!empty($this->globalBusinessFilters)) {
            $query->whereIn('pos_transactions.setting_id', $this->globalBusinessFilters);
        }

        // Transaction date filter
        if (!empty($this->transactionDateFrom)) {
            $query->whereDate('pos_transactions.created_at', '>=', $this->transactionDateFrom);
        }
        if (!empty($this->transactionDateTo)) {
            $query->whereDate('pos_transactions.created_at', '<=', $this->transactionDateTo);
        }

        // Cashier filter
        if (!empty($this->cashierUserId)) {
            $query->whereHas('completedCheckout', function ($cq) {
                $cq->where('cashier_user_id', $this->cashierUserId);
            });
        }

        // Terminal filter
        if (!empty($this->terminalId)) {
            $query->whereHas('completedCheckout', function ($cq) {
                $cq->where('terminal_id', $this->terminalId);
            });
        }

        // Search
        if (!empty($this->search)) {
            GlobalPaymentSearchQuery::applyPosTransactionSearch($query, $this->search);
        }

        // Sorting
        $allowedSortFields = ['created_at', 'code', 'id'];
        $field = in_array($this->sortField, $allowedSortFields, true) ? "pos_transactions.{$this->sortField}" : 'pos_transactions.created_at';
        $direction = strtolower($this->sortDirection) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($field, $direction);

        // Stable secondary sort: rows sharing the same value on the primary sort column
        // (e.g. identical created_at timestamps, which the projection-derived-filter and
        // pagination tests both exercise) would otherwise be returned in an order SQL is
        // free to vary between requests, which can shift a tied row across the page
        // boundary and cause it to appear on both pages or be skipped entirely.
        if ($field !== 'pos_transactions.id') {
            $query->orderBy('pos_transactions.id', $direction);
        }

        // Projection-derived filters (payment status, card selection, due date) cannot be
        // expressed in SQL, so when any are active we must evaluate the projection over the
        // complete filtered set first and constrain the query to the matching IDs before
        // paginating. Otherwise pagination would run on the unfiltered set and produce
        // partial/empty pages and incorrect totals.
        if ($this->selectedCardFilter || $this->paymentStatusFilter || $this->dueDateFrom || $this->dueDateTo) {
            $allMatching = (clone $query)->get();

            $matchingIds = $allMatching->filter(function ($trx) use ($projectionService) {
                $proj = $projectionService->project($trx);

                if ($this->paymentStatusFilter && strtolower($proj['payment_status']) !== strtolower($this->paymentStatusFilter)) {
                    return false;
                }

                if ($this->selectedCardFilter === 'unpaid' && $proj['live_due'] <= 0) {
                    return false;
                }
                if ($this->selectedCardFilter === 'overdue' && !$proj['is_overdue']) {
                    return false;
                }
                if ($this->selectedCardFilter === 'paid' && !$proj['has_recent_payment_30d']) {
                    return false;
                }

                if (!empty($this->dueDateFrom) && ($proj['effective_due_date'] === null || $proj['effective_due_date'] < $this->dueDateFrom)) {
                    return false;
                }
                if (!empty($this->dueDateTo) && ($proj['effective_due_date'] === null || $proj['effective_due_date'] > $this->dueDateTo)) {
                    return false;
                }

                return true;
            })->pluck('id')->all();

            $query->whereIn('pos_transactions.id', $matchingIds ?: [0]);
        }

        // Fetch paginated results
        $paginator = $query->paginate($this->perPage);

        // Map projections to paginated items
        $items = $paginator->getCollection();
        foreach ($items as $trx) {
            $trx->projection = $projectionService->project($trx);
        }

        $availableSettings = Setting::query()
            ->orderBy('company_name')
            ->select('id', 'company_name')
            ->get()
            ->toArray();

        $cashiers = User::query()
            ->orderBy('name')
            ->select('id', 'name')
            ->get();

        $terminals = PosTerminal::query()
            ->orderBy('name')
            ->select('id', 'name', 'code')
            ->get();

        return view('livewire.pos.global-pos-payment-table', [
            'transactions' => $paginator,
            'availableSettings' => $availableSettings,
            'cashiers' => $cashiers,
            'terminals' => $terminals,
        ]);
    }

    public function sortIcon($field)
    {
        if ($field !== $this->sortField) return '';
        if ($this->sortDirection === 'asc') {
            return '<i class="bi bi-caret-up-fill text-primary ms-1"></i>';
        }
        return '<i class="bi bi-caret-down-fill text-primary ms-1"></i>';
    }
}
