<?php

namespace App\Livewire\Product;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Setting\Entities\Unit;

class UnitConversionTable extends Component
{
    public array $conversions = [];
    public array $errors = [];
    public array $units = [];
    public bool  $locked = false;
    public array $rowKeys = [];

    protected $listeners = [
        'unitConversion:reset' => 'resetRows',
        'stock:lock'           => 'setLocked', // 🔁 new
    ];

    public function setLocked($locked): void
    {
        Log::debug("setLocked: ", [
            'locked' => $locked,
        ]);
        $this->locked = (bool) $locked;
    }

    public function resetRows(): void
    {
        $this->conversions   = [];
        $this->rowKeys       = [];
    }

    public function mount(array $conversions = []): void
    {
        $this->conversions = !empty($conversions)
            ? $conversions
            : old('conversions', []);

        foreach ($this->conversions as $i => $conv) {
            $this->rowKeys[$i] = $conv['id'] ?? uniqid('conv_', true);
        }

        $this->errors = session('errors')
            ? session('errors')->getBag('default')->toArray()
            : [];
        $this->units = Unit::orderBy('name')->get()
            ->pluck('name', 'id')
            ->toArray();
    }

    public function addConversionRow(): void
    {
        $this->conversions[] = [
            'unit_id'           => '',
            'conversion_factor' => '',
            'barcode'           => '',
            'price'             => '',  // still empty raw
        ];
        $this->rowKeys[] = uniqid('conv_', true);
    }

    public function removeConversionRow(string $key): void
    {
        $index = array_search($key, $this->rowKeys, true);
        if ($index === false) {
            return;
        }

        unset($this->conversions[$index], $this->rowKeys[$index]);
        $this->conversions   = array_values($this->conversions);
        $this->rowKeys       = array_values($this->rowKeys);
    }

    public function updateConversionPrice(int $index, string $rawValue): void
    {
        // Parse the currency input to extract numeric value
        $numericValue = (float) str_replace([',', ' '], ['', ''], $rawValue);
        $this->conversions[$index]['price'] = $numericValue;
    }

    public function updated($propertyName): void
    {
        // existing validation...
        $this->validateOnly($propertyName, [
            'conversions.*.unit_id'           => ['required_with:conversions.*.conversion_factor', 'integer', 'not_in:0'],
            'conversions.*.conversion_factor' => ['required_with:conversions.*.unit_id', 'numeric', 'min:0.0001'],
            'conversions.*.price'             => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    public function submitForm(): void
    {
        // include price in your validation if you want:
        $validated = $this->validate([
            'conversions.*.unit_id'           => ['required', 'integer', 'not_in:0'],
            'conversions.*.conversion_factor' => ['required', 'numeric', 'min:0.0001'],
            'conversions.*.price'             => ['required', 'numeric', 'min:0'],
        ]);

        // emit with the clean numbers
        $this->emit('storeProduct', $validated['conversions']);
    }

    public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.product.unit-conversion-table', [
            'units' => $this->units,
        ]);
    }
}
