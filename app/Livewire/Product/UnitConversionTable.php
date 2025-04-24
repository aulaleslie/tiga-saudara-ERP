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

    public function mount(array $conversions = []): void
    {
        $this->conversions = !empty($conversions)
            ? $conversions
            : old('conversions', []);
        $this->errors = session('errors')
            ? session('errors')->getBag('default')->toArray()
            : [];
        $currentSettingId = session('setting_id');
        $this->units = Unit::where('setting_id', $currentSettingId)
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
    }

    public function removeConversionRow($index): void
    {
        unset($this->conversions[$index]);
        $this->conversions = array_values($this->conversions);
    }

    public function updated($propertyName): void
    {
        // existing validation...
        $this->validateOnly($propertyName, [
            'conversions.*.unit_id'           => ['required_with:conversions.*.conversion_factor', 'integer', 'not_in:0'],
            'conversions.*.conversion_factor' => ['required_with:conversions.*.unit_id', 'numeric', 'min:0.0001'],
        ]);
    }

    public function formatPrice(int $index): void
    {
        $raw = $this->conversions[$index]['price'] ?? '';

        // strip out anything but digits and dot
        $number = (float) preg_replace('/[^\d\.]/', '', $raw);

        // if empty or zero, clear it
        if ($number === 0.0 && trim($raw) === '') {
            $this->conversions[$index]['price'] = '';
            return;
        }

        // format with commas as thousands sep, dot as decimal, two places
        $this->conversions[$index]['price'] =
            'Rp ' . number_format($number, 2, '.', ',');
    }

    public function unformatPrice(int $index): void
    {
        $raw = $this->conversions[$index]['price'] ?? '';
        // strip everything except digits and decimal point
        $clean = preg_replace('/[^\d\.]/', '', $raw);
        // put it back so the user sees e.g. "1500000.00" instead of "Rp 1,500,000.00"
        $this->conversions[$index]['price'] = $clean;
    }

    public function submitForm(): void
    {
        // before emitting, normalize all prices back to numeric:
        $normalized = collect($this->conversions)->map(function($c) {
            $n = floatval(str_replace([',', 'Rp', ' '], '', $c['price'] ?? '0'));
            $c['price'] = $n;
            return $c;
        })->toArray();

        // include price in your validation if you want:
        $validated = $this->validate([
            'conversions.*.unit_id'           => ['required', 'integer', 'not_in:0'],
            'conversions.*.conversion_factor' => ['required', 'numeric', 'min:0.0001'],
            'conversions.*.price'             => ['required', 'numeric', 'min:0'],
        ], [], [], $normalized);

        // emit with the clean numbers
        $this->emit('storeProduct', $normalized);
    }

    public function render()
    {
        return view('livewire.product.unit-conversion-table', [
            'units' => $this->units,
        ]);
    }
}
