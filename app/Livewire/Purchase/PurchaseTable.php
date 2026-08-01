<?php

namespace App\Livewire\Purchase;

use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Purchase\Entities\Purchase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;

class PurchaseTable extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

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
    public $purchaseId = null;
    public $supplierId = null;
    #[Url]
    public $showArchived = false;

    public ?string $paymentStatusFilter = null;
    /** @var array<string>|null */
    public ?array $paymentStatusFilters = null;
    public bool $overdueOnly = false;
    public bool $dueAmountOnly = false;
    public bool $paidLast30DaysOnly = false;
    /** @var array<string>|null */
    public ?array $cardStatusFilter = null;

    #[Locked]
    public bool $globalMode = false;

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

    public function mount($settingId = null, $statusFilter = null, $purchaseId = null, $supplierId = null, $globalMode = false)
    {
        $this->globalMode = $globalMode;

        if ($this->globalMode) {
            abort_if(Gate::denies('purchasePayments.global.access'), 403);
        }

        if (!$this->globalMode) {
            $this->settingId = $settingId ?? session('setting_id');
        }
        $this->statusFilter = $statusFilter;
        $this->purchaseId = $purchaseId;
        $this->supplierId = $supplierId;

        // Initialize draft state from applied state (from query string)
        $this->draftGlobalBusinessFilter = $this->globalBusinessFilter;
        $this->draftDocumentDateFrom = $this->documentDateFrom;
        $this->draftDocumentDateTo = $this->documentDateTo;

        $this->normalizeDateRange();
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

    public function updatedPerPage()
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
        $this->dispatch('global-purchase-filters-changed',
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
        $this->dispatch('global-purchase-filters-changed',
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

    #[On('purchase-filter')]
    public function applyPurchaseFilter($type = null)
    {
        $this->paymentStatusFilter = null;
        $this->paymentStatusFilters = null;
        $this->overdueOnly = false;
        $this->dueAmountOnly = false;
        $this->paidLast30DaysOnly = false;
        $this->cardStatusFilter = null;
        $this->selectedCardFilter = null;

        $approvedAndAbove = [
            Purchase::STATUS_APPROVED,
            Purchase::STATUS_RECEIVED_PARTIALLY,
            Purchase::STATUS_RECEIVED,
        ];

        if ($type === 'unpaid') {
            $this->paymentStatusFilters = $this->globalMode ? null : ['UNPAID', 'PARTIAL'];
            $this->dueAmountOnly = true;
            $this->cardStatusFilter = $this->globalMode ? [Purchase::STATUS_RECEIVED] : $approvedAndAbove;
            $this->selectedCardFilter = 'unpaid';
        } elseif ($type === 'overdue') {
            $this->paymentStatusFilters = $this->globalMode ? null : ['UNPAID', 'PARTIAL'];
            $this->overdueOnly = true;
            $this->cardStatusFilter = $this->globalMode ? [Purchase::STATUS_RECEIVED] : $approvedAndAbove;
            $this->selectedCardFilter = 'overdue';
        } elseif ($type === 'paid') {
            $this->paymentStatusFilter = null; // Filtered via paidLast30DaysOnly instead
            $this->paidLast30DaysOnly = true;
            $this->cardStatusFilter = $this->globalMode ? [Purchase::STATUS_RECEIVED] : $approvedAndAbove;
            $this->selectedCardFilter = 'paid';
        } else {
            $this->selectedCardFilter = null;
        }

        $this->resetPage();
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

        $query = ($this->showArchived && !$this->globalMode ? Purchase::archived() : Purchase::query())
            ->with(['supplier', 'tags', 'purchaseDetails', 'tenantSetting'])
            ->withSum(['purchasePayments as active_payments_sum' => function($q) {
                $q->where('status', \Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE);
            }], 'amount')
            ->when(!$this->globalMode, function ($q) {
                $q->where('setting_id', $this->settingId);
            })
            ->when($this->globalMode, function ($q) {
                $q->whereNull('archived_at')
                  ->where('status', Purchase::STATUS_RECEIVED);

                // Apply business filter if set
                if (!empty($this->globalBusinessFilter)) {
                    $q->where('setting_id', $this->globalBusinessFilter);
                }

                // Apply document date range filter if set
                if (!empty($this->documentDateFrom)) {
                    $q->where('date', '>=', $this->documentDateFrom);
                }
                if (!empty($this->documentDateTo)) {
                    $q->where('date', '<=', $this->documentDateTo);
                }
            })
            ->when($statuses !== null && !$this->globalMode, function ($q) use ($statuses) {
                $q->whereIn('status', $statuses);
            })
            ->when(! empty($this->purchaseId), function ($q) {
                $q->where('id', $this->purchaseId);
            })
            ->when(! empty($this->supplierId), function ($q) {
                $q->where('supplier_id', $this->supplierId);
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
                    $q->whereLiveDueAmountLessThanOrEqual(0)
                      ->whereHas('purchasePayments', function ($pq) use ($thirtyDaysAgo) {
                          $pq->where('date', '>=', $thirtyDaysAgo)
                             ->where('date', '<=', Carbon::today()->endOfDay())
                             ->where('status', \Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE);
                      });
                } else {
                    $q->where('payment_status', 'PAID')
                      ->where(function ($sub) use ($thirtyDaysAgo) {
                        $sub->whereHas('purchasePayments', function ($pq) use ($thirtyDaysAgo) {
                            $pq->where('date', '>=', $thirtyDaysAgo)
                               ->where('date', '<=', Carbon::today()->endOfDay())
                               ->where('status', \Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE);
                        })->orWhere(function ($q2) use ($thirtyDaysAgo) {
                            $q2->where('date', '>=', $thirtyDaysAgo)
                               ->where('date', '<=', Carbon::today()->endOfDay());
                        });
                    });
                }
            })
            ->when($this->search, function ($q) {
                $q->where(function ($qq) {
                    $search = $this->search;
                    $qq->where('reference', 'like', "%{$search}%")
                        ->orWhere('supplier_purchase_number', 'like', "%{$search}%")
                        ->orWhere('tax_ref_no', 'like', "%{$search}%")
                        ->orWhere('supplier_reference_no', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%")
                        ->orWhereHas('supplier', function ($q2) use ($search) {
                            $q2->where('supplier_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('tags', function ($q2) use ($search) {
                            $q2->where('name->en', 'like', "%{$search}%");
                        })
                        ->orWhereHas('purchaseDetails', function ($q2) use ($search) {
                            $q2->where('product_name', 'like', "%{$search}%")
                               ->orWhere('product_code', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy($this->sortField, $this->sortDirection);

        $purchases = $query->paginate($this->perPage);

        $view = empty($this->statusFilter)
            ? 'livewire.purchase.purchase-table'
            : 'livewire.purchase.purchase-receiving-table';

        return view($view, compact('purchases'));
    }

    public function sortIcon($field)
    {
        if ($field !== $this->sortField) return '';
        if ($this->sortDirection === 'asc') {
            return '<i class="bi bi-caret-up-fill text-primary ms-1"></i>';
        }
        return '<i class="bi bi-caret-down-fill text-primary ms-1"></i>';
    }

    public function formatDate(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        return Carbon::parse($value)->format('d M Y');
    }
}
