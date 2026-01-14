<?php

namespace App\Livewire\PurchaseReturn;

use Livewire\Component;
use Modules\Setting\Entities\Location;

class LocationSearchDropdownPerLine extends Component
{
    public $index;
    public $product_id;
    public $selected;
    public $error;
    public $search = '';
    public $open = false;

    public function mount($index, $product_id = null, $selected = null, $error = null)
    {
        $this->index = $index;
        $this->product_id = $product_id;
        $this->selected = $selected;
        $this->error = $error;
    }

    public function select($locationId)
    {
        $location = Location::find($locationId);
        $this->selected = $locationId;
        $this->open = false;
        $this->search = '';
        
        $this->dispatch('locationSelected', $this->index, [
            'id' => $location->id,
            'name' => $location->name,
        ])->to(PurchaseReturnTable::class);
    }

    public function toggleDropdown()
    {
        $this->open = !$this->open;
    }

    public function closeDropdown()
    {
        $this->open = false;
    }

    public function getLocationsProperty()
    {
        if (!$this->product_id) {
            return collect();
        }

        return \Modules\Product\Entities\ProductStock::with(['location.setting'])
            ->where('product_id', $this->product_id)
            ->where('quantity', '>', 0)
            ->when($this->search, function($query) {
                $query->whereHas('location', function($q) {
                    $q->where('name', 'like', '%' . $this->search . '%')
                      ->orWhereHas('setting', function($q2) {
                          $q2->where('company_name', 'like', '%' . $this->search . '%');
                      });
                });
            })
            ->limit(10)
            ->get()
            ->map(function ($stock) {
                return [
                    'id' => $stock->location_id,
                    'name' => $stock->location->name,
                    'label' => ($stock->location->setting->company_name ?? 'N/A') . ' - ' . $stock->location->name,
                ];
            });
    }

    public function getSelectedLabelProperty()
    {
        if (!$this->selected) {
            return null;
        }

        $location = Location::with('setting')->find($this->selected);
        if (!$location) {
            return null;
        }

        return ($location->setting->company_name ?? 'N/A') . ' - ' . $location->name;
    }

    public function render()
    {
        return view('livewire.purchase-return.location-search-dropdown-per-line', [
            'locations' => $this->locations,
            'selectedLabel' => $this->selected_label,
        ]);
    }
}
