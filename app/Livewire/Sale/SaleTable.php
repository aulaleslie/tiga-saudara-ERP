<?php

namespace App\Livewire\Sale;

use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Sale\Entities\Sale;
use Carbon\Carbon;

class SaleTable extends Component
{
    use WithPagination;

    public $searchText = '';
    #[Url]
    public $search = '';
    public $perPage = 10;
    #[Url]
    public $sortField = 'created_at';
    #[Url]
    public $sortDirection = 'desc';
    public $settingId;
    public $statusFilter = null;
    public $saleId = null;
    #[Url]
    public $showArchived = false;

    #[Locked]
    public bool $globalMode = false;

    public ?string $paymentStatusFilter = null;
    /** @var array<string>|null */
    public ?array $paymentStatusFilters = null;
    public bool $overdueOnly = false;
    public bool $dueAmountOnly = false;
    public bool $paidLast30DaysOnly = false;
    /** @var array<string>|null */
    public ?array $cardStatusFilter = null;

    // Global mode filters: draft state (not yet applied)
    public ?int $draftGlobalBusinessFilter = null;
    public ?string $draftDocumentDateFrom = null;
    public ?string $draftDocumentDateTo = null;

    // Global mode filters: applied state (synced to query)
    #[Url]
    public ?int $globalBusinessFilter = null;
    #[Url]
    public ?string $documentDateFrom = null;
    #[Url]
    public ?string $documentDateTo = null;

    // Summary card selection state (for durable selection across refreshes)
    #[Url]
    public ?string $selectedCardFilter = null;

    public function mount($settingId = null, $statusFilter = null, $saleId = null, bool $globalMode = false)
    {
        abort_if($globalMode && !\auth()->user()->can('salePayments.global.access'), 403);

        $this->globalMode = $globalMode;
        $this->settingId = $globalMode ? null : ($settingId ?? session('setting_id'));
        $this->statusFilter = $statusFilter;
        $this->saleId = $saleId;

        // Initialize draft state from applied state (from query string)
        $this->draftGlobalBusinessFilter = $this->globalBusinessFilter;
        $this->draftDocumentDateFrom = $this->documentDateFrom;
        $this->draftDocumentDateTo = $this->documentDateTo;

        $this->normalizeDateRange();

        // Apply card filter from URL if set
        if ($this->selectedCardFilter !== null) {
            $this->applyCardFilterType($this->selectedCardFilter);
        }
    }

    private function normalizeDateRange()
    {
        if (!empty($this->documentDateFrom) && !empty($this->documentDateTo)) {
            if ($this->documentDateFrom > $this->documentDateTo) {
                [$this->documentDateFrom, $this->documentDateTo] = [$this->documentDateTo, $this->documentDateFrom];
            }
        }
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function applyGlobalFilters()
    {
        // Normalize date range if both dates supplied
        if (!empty($this->draftDocumentDateFrom) && !empty($this->draftDocumentDateTo)) {
            if ($this->draftDocumentDateFrom > $this->draftDocumentDateTo) {
                [$this->draftDocumentDateFrom, $this->draftDocumentDateTo] = [$this->draftDocumentDateTo, $this->draftDocumentDateFrom];
            }
        }

        // Copy draft to applied state
        $this->globalBusinessFilter = $this->draftGlobalBusinessFilter;
        $this->documentDateFrom = $this->draftDocumentDateFrom;
        $this->documentDateTo = $this->draftDocumentDateTo;

        // Reset pagination and dispatch event to summary cards
        $this->resetPage();
        $this->dispatch('global-sale-filters-changed',
            globalBusinessFilter: $this->globalBusinessFilter,
            documentDateFrom: $this->documentDateFrom,
            documentDateTo: $this->documentDateTo,
        );
    }

    public function resetGlobalFilters()
    {
        // Clear both draft and applied state
        $this->draftGlobalBusinessFilter = null;
        $this->draftDocumentDateFrom = null;
        $this->draftDocumentDateTo = null;

        $this->globalBusinessFilter = null;
        $this->documentDateFrom = null;
        $this->documentDateTo = null;

        // Reset pagination and dispatch event
        $this->resetPage();
        $this->dispatch('global-sale-filters-changed',
            globalBusinessFilter: null,
            documentDateFrom: null,
            documentDateTo: null,
        );
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

    public function sortBy($field)
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    #[On('sale-filter')]
    public function applySaleFilter($type = null)
    {
        $this->applyCardFilterType($type);
        $this->resetPage();
    }

    private function applyCardFilterType(?string $type)
    {
        $this->paymentStatusFilter = null;
        $this->paymentStatusFilters = null;
        $this->overdueOnly = false;
        $this->dueAmountOnly = false;
        $this->paidLast30DaysOnly = false;
        $this->cardStatusFilter = null;
        $this->selectedCardFilter = null;

        $approvedAndAbove = [
            Sale::STATUS_APPROVED,
            Sale::STATUS_DISPATCHED_PARTIALLY,
            Sale::STATUS_DISPATCHED,
        ];

        if ($type === 'unpaid') {
            $this->paymentStatusFilters = ['UNPAID', 'PARTIAL'];
            $this->dueAmountOnly = true;
            $this->cardStatusFilter = $approvedAndAbove;
            $this->selectedCardFilter = 'unpaid';
        } elseif ($type === 'overdue') {
            $this->paymentStatusFilters = ['UNPAID', 'PARTIAL'];
            $this->overdueOnly = true;
            $this->cardStatusFilter = $approvedAndAbove;
            $this->selectedCardFilter = 'overdue';
        } elseif ($type === 'paid') {
            $this->paymentStatusFilter = null; // Filtered via paidLast30DaysOnly instead
            $this->paidLast30DaysOnly = true;
            $this->cardStatusFilter = $approvedAndAbove;
            $this->selectedCardFilter = 'paid';
        } else {
            $this->selectedCardFilter = null;
        }
    }

    public function render()
    {
        $statuses = null;
        if (!empty($this->statusFilter) && !empty($this->cardStatusFilter)) {
            $statuses = array_intersect((array) $this->statusFilter, $this->cardStatusFilter);
        } elseif (!empty($this->statusFilter)) {
            $statuses = (array) $this->statusFilter;
        } elseif (!empty($this->cardStatusFilter)) {
            $statuses = $this->cardStatusFilter;
        }

        // Global mode uses approved-up eligibility by default
        if ($this->globalMode && !$statuses) {
            $statuses = [
                Sale::STATUS_APPROVED,
                Sale::STATUS_DISPATCHED_PARTIALLY,
                Sale::STATUS_DISPATCHED,
            ];
        }

        $query = ($this->showArchived ? Sale::archived() : Sale::query())
            ->with(['customer', 'saleDetails', 'tags', 'posCheckout.transaction', 'checkoutSale.checkout.transaction', 'tenantSetting']);

        // Apply setting scope only if not in global mode
        if (!$this->globalMode) {
            $query->where('setting_id', $this->settingId);
        } else {
            // Global mode excludes archived sales
            $query->whereNull('archived_at');

            // Apply business filter if set
            if (!empty($this->globalBusinessFilter)) {
                $query->where('setting_id', $this->globalBusinessFilter);
            }

            // Apply document date range filter if set
            if (!empty($this->documentDateFrom)) {
                $query->where('date', '>=', $this->documentDateFrom);
            }
            if (!empty($this->documentDateTo)) {
                $query->where('date', '<=', $this->documentDateTo);
            }
        }

        $query->when($statuses !== null, function ($q) use ($statuses) {
                $q->whereIn('status', $statuses);
            })
            ->when(! empty($this->saleId), function ($q) {
                $q->where('id', $this->saleId);
            })
            ->when(! empty($this->paymentStatusFilter), function ($q) {
                $q->where('payment_status', $this->paymentStatusFilter);
            })
            ->when(! empty($this->paymentStatusFilters), function ($q) {
                $q->whereIn('payment_status', $this->paymentStatusFilters);
            })
            ->when($this->dueAmountOnly, function ($q) {
                if ($this->globalMode) {
                    $q->whereLiveDueAmountGreaterThan(0);
                } else {
                    $q->where('due_amount', '>', 0);
                }
            })
            ->when($this->overdueOnly, function ($q) {
                $q->where('due_date', '<', Carbon::today());
                if ($this->globalMode) {
                    $q->whereLiveDueAmountGreaterThan(0);
                } else {
                    $q->where('due_amount', '>', 0);
                }
            })
            ->when($this->paidLast30DaysOnly, function ($q) {
                $thirtyDaysAgo = Carbon::today()->subDays(30)->format('Y-m-d');
                if ($this->globalMode) {
                    // Global mode: fully paid sales with active payment in past 30 days
                    $q->whereLiveDueAmountLessThanOrEqual(0)
                      ->whereHas('salePayments', function ($pq) use ($thirtyDaysAgo) {
                          $pq->where('date', '>=', $thirtyDaysAgo)
                             ->where('status', \Modules\Sale\Entities\SalePayment::STATUS_ACTIVE);
                      });
                } else {
                    $q->where(function ($sub) use ($thirtyDaysAgo) {
                        $sub->whereHas('salePayments', function ($pq) use ($thirtyDaysAgo) {
                            $pq->where('date', '>=', $thirtyDaysAgo)
                               ->where('status', \Modules\Sale\Entities\SalePayment::STATUS_ACTIVE);
                        })->orWhere('date', '>=', $thirtyDaysAgo);
                    });
                }
            })
            ->when($this->search, function ($q) {
                $q->where(function ($qq) {
                    $search = $this->search;
                    $qq->where('reference', 'like', "%{$search}%")
                        ->orWhere('imported_sales_reference_number', 'like', "%{$search}%")
                        ->orWhere('tax_ref_no', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($q2) use ($search) {
                            $q2->where('customer_name', 'like', "%{$search}%")
                                ->orWhere('contact_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('saleDetails', function ($q2) use ($search) {
                            $q2->where('product_name', 'like', "%{$search}%")
                               ->orWhere('product_code', 'like', "%{$search}%");
                        })
                        ->orWhereHas('tags', function ($q2) use ($search) {
                            $q2->where('name->en', 'like', "%{$search}%");
                        })
                        ->orWhereHas('posCheckout', function ($q2) use ($search) {
                            $q2->where('receipt_number', 'like', "%{$search}%")
                               ->orWhereHas('transaction', function ($q3) use ($search) {
                                   $q3->where('code', 'like', "%{$search}%");
                               });
                        })
                        ->orWhereHas('checkoutSale.checkout', function ($q2) use ($search) {
                            $q2->where('receipt_number', 'like', "%{$search}%")
                               ->orWhereHas('transaction', function ($q3) use ($search) {
                                   $q3->where('code', 'like', "%{$search}%");
                               });
                        })
                        ->orWhereHas('bundleItems', function ($q2) use ($search) {
                            $q2->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy($this->sortField, $this->sortDirection);

        $sales = $query->paginate($this->perPage);

        return view('livewire.sale.sale-table', compact('sales'), [
            'globalMode' => $this->globalMode,
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
