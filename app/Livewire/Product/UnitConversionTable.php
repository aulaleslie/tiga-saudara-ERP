<?php

namespace App\Livewire\Product;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Modules\Setting\Entities\Unit;

class UnitConversionTable extends Component
{
    public array $conversions = [];

    public function mount(): void
    {
        $this->conversions = old('conversions', []);
    }

    public function addConversionRow(): void
    {
        $this->conversions[] = ['unit_id' => '', 'conversion_factor' => '', 'barcode' => ''];
    }

    public function removeConversionRow($index): void
    {
        unset($this->conversions[$index]);
        $this->conversions = array_values($this->conversions); // Re-index the array
    }

    public function updated($propertyName): void
    {
        $this->validateOnly($propertyName, [
            'conversions.*.unit_id' => ['required_with:conversions.*.conversion_factor', 'integer', 'not_in:0'],
            'conversions.*.conversion_factor' => ['required_with:conversions.*.unit_id', 'numeric', 'min:0.0001'],
        ]);
    }

    public function submitForm(): void
    {
        $validatedData = $this->validate([
            'conversions.*.unit_id' => ['required_with:conversions.*.conversion_factor', 'integer', 'not_in:0'],
            'conversions.*.conversion_factor' => ['required_with:conversions.*.unit_id', 'numeric', 'min:0.0001'],
        ]);

        // Handle the form submission logic here

        $this->emit('formSubmitted', $validatedData);
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $currentSettingId = session('setting_id');

        return view('livewire.product.unit-conversion-table', [
            'units' => Unit::where('setting_id', $currentSettingId)->pluck('name', 'id'),
        ]);
    }
}
