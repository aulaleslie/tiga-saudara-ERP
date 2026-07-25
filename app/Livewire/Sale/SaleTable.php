<?php

namespace App\Livewire\Sale;

use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Sale\Entities\Sale;
use Carbon\Carbon;

class SaleTable extends Component
{
    use WithPagination;

    public $searchText = '';
    public $search = '';
    public $perPage = 10;
    public $sortField = 'created_at';
    public $sortDirection = 'desc';
    public $settingId;
    public $statusFilter = null;
    public $saleId = null;
    public $showArchived = false;

    public ?string $paymentStatusFilter = null;
    /** @var array<string>|null */
    public ?array $paymentStatusFilters = null;
    public bool $overdueOnly = false;
    public bool $dueAmountOnly = false;
    public bool $paidLast30DaysOnly = false;
    /** @var array<string>|null */
    public ?array $cardStatusFilter = null;

    protected $updatesQueryString = ['search', 'page', 'sortField', 'sortDirection', 'showArchived'];

    public function mount($settingId = null, $statusFilter = null, $saleId = null)
    {
        $this->settingId = $settingId ?? session('setting_id');
        $this->statusFilter = $statusFilter;
        $this->saleId = $saleId;
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

    #[On('sale-filter')]
    public function applySaleFilter($type = null)
    {
        $this->paymentStatusFilter = null;
        $this->paymentStatusFilters = null;
        $this->overdueOnly = false;
        $this->dueAmountOnly = false;
        $this->paidLast30DaysOnly = false;
        $this->cardStatusFilter = null;

        $approvedAndAbove = [
            Sale::STATUS_APPROVED,
            Sale::STATUS_DISPATCHED_PARTIALLY,
            Sale::STATUS_DISPATCHED,
        ];

        if ($type === 'unpaid') {
            $this->paymentStatusFilters = ['UNPAID', 'PARTIAL'];
            $this->dueAmountOnly = true;
            $this->cardStatusFilter = $approvedAndAbove;
        } elseif ($type === 'overdue') {
            $this->paymentStatusFilters = ['UNPAID', 'PARTIAL'];
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

        $query = ($this->showArchived ? Sale::archived() : Sale::query())
            ->with(['customer', 'saleDetails', 'tags', 'posCheckout.transaction', 'checkoutSale.checkout.transaction'])
            ->where('setting_id', $this->settingId)
            ->when($statuses !== null, function ($q) use ($statuses) {
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
                    $sub->whereHas('salePayments', function ($pq) use ($thirtyDaysAgo) {
                        $pq->where('date', '>=', $thirtyDaysAgo)
                           ->where('status', \Modules\Sale\Entities\SalePayment::STATUS_ACTIVE);
                    })->orWhere('date', '>=', $thirtyDaysAgo);
                });
            })
            ->when($this->search, function ($q) {
                $q->where(function ($qq) {
                    $search = $this->search;
                    $qq->where('reference', 'like', "%{$search}%")
                        ->orWhere('imported_sales_reference_number', 'like', "%{$search}%")
                        ->orWhere('tax_ref_no', 'like', "%{$search}%")
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
                        });
                });
            })
            ->orderBy($this->sortField, $this->sortDirection);

        $sales = $query->paginate($this->perPage);

        return view('livewire.sale.sale-table', compact('sales'));
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
