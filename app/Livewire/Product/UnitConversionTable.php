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
    public array $locations = [];

    public function mount(array $conversions = [], array $locations = []): void
    {
        $this->locations = $locations;

        // If conversions are passed during mount, use them; otherwise, use old input or an empty array
        $incomingConversions = !empty($conversions) ? $conversions : old('conversions', []);
        $this->conversions = $this->normalizeConversions($incomingConversions);

        // Ensure errors are passed correctly to the component
        $this->errors = session('errors') ? session('errors')->getBag('default')->toArray() : [];

        // Load units based on the current setting ID
        $currentSettingId = session('setting_id');
        $this->units = Unit::where('setting_id', $currentSettingId)->pluck('name', 'id')->toArray();
    }

    public function addConversionRow(): void
    {
        $this->conversions[] = [
            'unit_id' => '',
            'conversion_factor' => '',
            'barcode' => '',
            'locations' => $this->normalizeLocationPrices([]),
        ];
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
            'conversions.*.locations.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    public function submitForm(): void
    {
        // Perform validation before submitting the form
        $validatedData = $this->validate([
            'conversions.*.unit_id' => ['required', 'integer', 'not_in:0'],
            'conversions.*.conversion_factor' => ['required', 'numeric', 'min:0.0001'],
            'conversions.*.locations.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        Log::error('Validation failed', $validatedData);
        // Emit the data or make a request to the store endpoint
        $this->emit('storeProduct', $validatedData);
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.product.unit-conversion-table', [
            'units' => $this->units,
            'locations' => $this->locations,
        ]);
    }

    private function normalizeConversions(array $conversions): array
    {
        if (empty($conversions)) {
            return [];
        }

        return array_map(function (array $conversion) {
            return [
                'unit_id' => $conversion['unit_id'] ?? '',
                'conversion_factor' => $conversion['conversion_factor'] ?? '',
                'barcode' => $conversion['barcode'] ?? '',
                'locations' => $this->normalizeLocationPrices($conversion['locations'] ?? []),
            ];
        }, $conversions);
    }

    private function normalizeLocationPrices(array $locationPrices): array
    {
        if (empty($this->locations)) {
            return [];
        }

        $normalized = [];

        foreach ($this->locations as $location) {
            $existing = collect($locationPrices)->firstWhere('location_id', $location['id'] ?? null);

            $normalized[] = [
                'location_id' => $location['id'] ?? null,
                'price' => $existing['price'] ?? '',
            ];
        }

        return $normalized;
    }
}
