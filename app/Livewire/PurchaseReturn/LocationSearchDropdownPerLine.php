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
        $query = Location::query();

        if ($this->search) {
            $query->where('name', 'like', '%' . $this->search . '%');
        }

        return $query->limit(10)->get();
    }

    public function getSelectedLabelProperty()
    {
        if (!$this->selected) {
            return null;
        }

        return Location::find($this->selected)?->name;
    }

    public function render()
    {
        return view('livewire.purchase-return.location-search-dropdown-per-line', [
            'locations' => $this->locations,
            'selectedLabel' => $this->selected_label,
        ]);
    }
}
