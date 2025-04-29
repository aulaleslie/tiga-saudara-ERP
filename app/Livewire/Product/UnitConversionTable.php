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
    public array $displayPrices  = [];
    public array $errors = [];
    public array $units = [];

    public function mount(array $conversions = []): void
    {
        $this->conversions = !empty($conversions)
            ? $conversions
            : old('conversions', []);

        foreach ($this->conversions as $i => $conv) {
            $this->displayPrices[$i] = $conv['price'] && $conv['price'] !== ''
                ? $this->formatCurrency($conv['price'])
                : '';
        }

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
        $this->displayPrices[] = '';
    }

    public function removeConversionRow(int $i): void
    {
        unset($this->conversions[$i], $this->displayPrices[$i]);
        $this->conversions   = array_values($this->conversions);
        $this->displayPrices = array_values($this->displayPrices);
    }

    public function updated($propertyName): void
    {
        // existing validation...
        $this->validateOnly($propertyName, [
            'conversions.*.unit_id'           => ['required_with:conversions.*.conversion_factor', 'integer', 'not_in:0'],
            'conversions.*.conversion_factor' => ['required_with:conversions.*.unit_id', 'numeric', 'min:0.0001'],
        ]);
    }

    public function showRawPrice(int $i): void
    {
        $this->displayPrices[$i] = $this->conversions[$i]['price'] !== ''
            ? rtrim(rtrim($this->conversions[$i]['price'], '0'), '.')  // 1234.00 → 1234
            : '';
    }

    public function syncPrice(int $i): void  // stays the same
    {
        $raw   = $this->displayPrices[$i] ?? '';
        $clean = str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $raw));
        $num   = $clean === '' ? null : (float) $clean;

        $this->conversions[$i]['price'] = $num ?? '';
        $this->displayPrices[$i]        = $num === null ? '' : $this->formatCurrency($num);
    }

    private function formatCurrency(float $amount): string
    {
        // “Rp 1.234,56”
        return 'Rp '.number_format($amount, 2, ',', '.');
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
