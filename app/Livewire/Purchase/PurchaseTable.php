<?php

namespace App\Livewire\Purchase;

use Livewire\Component;
use Livewire\WithPagination;
use Modules\Purchase\Entities\Purchase;

class PurchaseTable extends Component
{
    use WithPagination;

    public $searchText = '';
    public $search = '';
    public $perPage = 10;
    public $sortField = 'created_at';
    public $sortDirection = 'desc';
    public $settingId;
    public $statusFilter = null;
    public $purchaseId = null;

    protected $updatesQueryString = ['search', 'page', 'sortField', 'sortDirection'];

    public function mount($settingId = null, $statusFilter = null, $purchaseId = null)
    {
        // if you pass it in from the parent, use that; otherwise, fall back to the logged-in user’s
        $this->settingId = $settingId ?? session('setting_id');
        $this->statusFilter = $statusFilter;
        $this->purchaseId = $purchaseId;
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
        $query = Purchase::query()
            ->with(['supplier', 'tags'])
            ->where('setting_id', $this->settingId)
            ->when(! empty($this->statusFilter), function ($q) {
                $q->whereIn('status', [
                    Purchase::STATUS_APPROVED,
                    Purchase::STATUS_RECEIVED_PARTIALLY,
                ]);
            })
            ->when(! empty($this->purchaseId), function ($q) {
                $q->where('id', $this->purchaseId);
            })
            ->when($this->search, function ($q) {
                $q->where(function ($qq) {
                    $search = $this->search;
                    $qq->where('reference', 'like', "%{$search}%")
                        ->orWhereHas('supplier', function ($q2) use ($search) {
                            $q2->where('supplier_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('tags', function ($q2) use ($search) {
                            $q2->whereRaw(
                                "LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, '$.en'))) LIKE ?",
                                ['%' . strtolower($search) . '%']
                            );
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
}
