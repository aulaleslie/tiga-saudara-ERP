<?php

namespace App\Livewire\Sale;

use Livewire\Component;
use Livewire\WithPagination;
use Modules\Sale\Entities\Sale;

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

    protected $updatesQueryString = ['search', 'page', 'sortField', 'sortDirection'];

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

    public function render()
    {
        $query = Sale::query()
            ->with(['customer', 'saleDetails', 'posReceipt', 'posSession', 'tags'])
            ->where('setting_id', $this->settingId)
            ->when(! empty($this->statusFilter), function ($q) {
                $q->whereIn('status', (array) $this->statusFilter);
            })
            ->when(! empty($this->saleId), function ($q) {
                $q->where('id', $this->saleId);
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
                            $q2->where('product_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('tags', function ($q2) use ($search) {
                            $q2->where('name->en', 'like', "%{$search}%");
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
