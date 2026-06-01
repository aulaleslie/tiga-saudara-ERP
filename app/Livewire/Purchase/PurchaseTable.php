<?php

namespace App\Livewire\Purchase;

use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Purchase\Entities\Purchase;
use Carbon\Carbon;

class PurchaseTable extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $searchText = '';
    public $search = '';
    public $perPage = 10;
    public $sortField = 'created_at';
    public $sortDirection = 'desc';
    public $settingId;
    public $statusFilter = null;
    public $purchaseId = null;
    public $supplierId = null;
    public $showArchived = false;

    public ?string $paymentStatusFilter = null;
    public bool $overdueOnly = false;
    public bool $dueAmountOnly = false;
    public bool $paidLast30DaysOnly = false;
    /** @var array<string>|null */
    public ?array $cardStatusFilter = null;

    protected $updatesQueryString = ['search', 'page', 'sortField', 'sortDirection', 'showArchived'];

    public function mount($settingId = null, $statusFilter = null, $purchaseId = null, $supplierId = null)
    {
        // if you pass it in from the parent, use that; otherwise, fall back to the logged-in user’s
        $this->settingId = $settingId ?? session('setting_id');
        $this->statusFilter = $statusFilter;
        $this->purchaseId = $purchaseId;
        $this->supplierId = $supplierId;
    }

    public function updatedSearch()
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
        $this->overdueOnly = false;
        $this->dueAmountOnly = false;
        $this->paidLast30DaysOnly = false;
        $this->cardStatusFilter = null;

        $approvedAndAbove = [
            Purchase::STATUS_APPROVED,
            Purchase::STATUS_RECEIVED_PARTIALLY,
            Purchase::STATUS_RECEIVED,
        ];

        if ($type === 'unpaid') {
            $this->paymentStatusFilter = 'UNPAID';
            $this->dueAmountOnly = true;
            $this->cardStatusFilter = $approvedAndAbove;
        } elseif ($type === 'overdue') {
            $this->paymentStatusFilter = 'UNPAID';
            $this->overdueOnly = true;
            $this->cardStatusFilter = $approvedAndAbove;
        } elseif ($type === 'paid') {
            $this->paymentStatusFilter = null; // Filtered via paidLast30DaysOnly instead
            $this->paidLast30DaysOnly = true;
            $this->cardStatusFilter = $approvedAndAbove;
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

        $query = ($this->showArchived ? Purchase::archived() : Purchase::query())
            ->with(['supplier', 'tags', 'purchaseDetails'])
            ->where('setting_id', $this->settingId)
            ->when($statuses !== null, function ($q) use ($statuses) {
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
            ->when($this->dueAmountOnly, function ($q) {
                $q->where('due_amount', '>', 0);
            })
            ->when($this->overdueOnly, function ($q) {
                $q->where('due_date', '<', Carbon::today())
                  ->where('due_amount', '>', 0);
            })
            ->when($this->paidLast30DaysOnly, function ($q) {
                $thirtyDaysAgo = Carbon::today()->subDays(30)->format('Y-m-d');
                $q->where('payment_status', 'PAID')
                  ->where(function ($sub) use ($thirtyDaysAgo) {
                    $sub->whereHas('purchasePayments', function ($pq) use ($thirtyDaysAgo) {
                        $pq->where('date', '>=', $thirtyDaysAgo)
                           ->where('status', \Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE);
                    })->orWhere('date', '>=', $thirtyDaysAgo);
                });
            })
            ->when($this->search, function ($q) {
                $q->where(function ($qq) {
                    $search = $this->search;
                    $qq->where('reference', 'like', "%{$search}%")
                        ->orWhere('supplier_purchase_number', 'like', "%{$search}%")
                        ->orWhere('tax_ref_no', 'like', "%{$search}%")
                        ->orWhereHas('supplier', function ($q2) use ($search) {
                            $q2->where('supplier_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('tags', function ($q2) use ($search) {
                            $q2->where('name->en', 'like', "%{$search}%");
                        })
                        ->orWhereHas('purchaseDetails.product', function ($q2) use ($search) {
                            $q2->where('product_name', 'like', "%{$search}%");
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
