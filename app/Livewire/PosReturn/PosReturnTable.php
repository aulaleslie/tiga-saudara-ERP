<?php

namespace App\Livewire\PosReturn;

use Livewire\Component;
use Livewire\WithPagination;
use Modules\Pos\Entities\PosReturn;

class PosReturnTable extends Component
{
    use WithPagination;

    public $search = '';
    public $perPage = 10;
    public $sortField = 'created_at';
    public $sortDirection = 'desc';
    public $statusFilter = null;

    protected $queryString = ['search', 'page', 'sortField', 'sortDirection', 'statusFilter'];

    public function updatedSearch()
    {
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
        $query = PosReturn::query()
            ->where('setting_id', settings()->id)
            ->when($this->statusFilter, function ($q) {
                $q->where('status', $this->statusFilter);
            })
            ->when($this->search, function ($q) {
                $q->where(function ($qq) {
                    $search = $this->search;
                    $qq->where('reference', 'like', "%{$search}%")
                        ->orWhere('transaction_code', 'like', "%{$search}%")
                        ->orWhere('receipt_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%");
                });
            })
            ->orderBy($this->sortField, $this->sortDirection);

        $returns = $query->paginate($this->perPage);

        return view('livewire.pos-return.pos-return-table', compact('returns'));
    }
}
