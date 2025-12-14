<?php

namespace App\Livewire\Product;

use Livewire\Component;

class UnitConfiguration extends Component
{
    public bool $locked = false;
    public bool $stockManaged = false;
    public bool $serialNumberRequired = false;
    public ?int $baseUnitId = null;
    public string $barcode = '';
    public ?int $productQuantity = null;
    public ?int $productStockAlert = null;

    /** @var array<int, array{id:int|string,name:string}> */
    public array $unitOptions = [];

    public array $conversions = [];
    public array $displayPrices = [];
    public array $rowKeys = [];
    public array $errors = [];

    public function mount(
        bool $locked = false,
        bool $initialStockManaged = false,
        bool $initialSerialRequired = false,
        ?int $initialBaseUnitId = null,
        ?string $initialBarcode = null,
        ?int $initialProductQuantity = null,
        ?int $initialStockAlert = null,
        array $unitOptions = [],
        array $initialConversions = [],
        array $errors = []
    ): void {
        $this->locked = $locked;
        $this->stockManaged = $locked ? true : (bool) $initialStockManaged;
        $this->serialNumberRequired = $locked
            ? (bool) $initialSerialRequired
            : (bool) $initialSerialRequired;
        $this->baseUnitId = $initialBaseUnitId;
        $this->barcode = $initialBarcode ?? '';
        $this->productQuantity = $initialProductQuantity;
        $this->productStockAlert = $initialStockAlert;
        $this->unitOptions = $unitOptions;
        $this->errors = $errors;

        $this->initConversions($initialConversions);
        $this->dispatchStockToggle();
    }

    public function updatedStockManaged($value): void
    {
        $this->stockManaged = (bool) $value;

        if (!$this->stockManaged && !$this->locked) {
            $this->serialNumberRequired = false;
            $this->baseUnitId = null;
            $this->barcode = '';
            $this->productStockAlert = null;
            $this->resetConversions();
        }

        $this->dispatchStockToggle();
    }

    public function addConversionRow(): void
    {
        if (!$this->stockManaged || $this->locked) {
            return;
        }

        $this->conversions[] = [
            'id'               => null,
            'unit_id'          => '',
            'conversion_factor'=> '',
            'barcode'          => '',
            'price'            => '',
        ];
        $this->displayPrices[] = '';
        $this->rowKeys[] = uniqid('conv_', true);
    }

    public function removeConversionRow(string $key): void
    {
        if ($this->locked) {
            return;
        }

        $index = array_search($key, $this->rowKeys, true);
        if ($index === false) {
            return;
        }

        unset($this->conversions[$index], $this->displayPrices[$index], $this->rowKeys[$index]);
        $this->conversions = array_values($this->conversions);
        $this->displayPrices = array_values($this->displayPrices);
        $this->rowKeys = array_values($this->rowKeys);
    }

    public function showRawPrice(int $i): void
    {
        $this->displayPrices[$i] = $this->conversions[$i]['price'] !== ''
            ? rtrim(rtrim((string) $this->conversions[$i]['price'], '0'), '.')
            : '';
    }

    public function syncPrice(int $i): void
    {
        $raw = $this->displayPrices[$i] ?? '';
        $clean = str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $raw));
        $num = $clean === '' ? null : (float) $clean;

        $this->conversions[$i]['price'] = $num ?? '';
        $this->displayPrices[$i] = $num === null ? '' : $this->formatCurrency($num);
    }

    public function updatedDisplayPrices($value, string $name): void
    {
        if (!preg_match('/displayPrices\.(\d+)/', $name, $m)) {
            return;
        }
        $i = (int) $m[1];
        $raw = is_string($value) ? $value : '';
        $clean = str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $raw));
        $num = $clean === '' ? null : (float) $clean;

        $this->conversions[$i]['price'] = $num ?? '';
        $this->displayPrices[$i] = $num === null ? '' : $this->formatCurrency($num);
    }

    public function render()
    {
        return view('livewire.product.unit-configuration');
    }

    private function formatCurrency(float $amount): string
    {
        $currency = settings()->currency ?? null;
        $symbol   = $currency->symbol ?? '';
        $decimal  = $currency->decimal_separator ?? '.';
        $thousand = $currency->thousand_separator ?? ',';

        return $symbol.number_format($amount, 2, $decimal, $thousand);
    }

    private function resetConversions(): void
    {
        $this->conversions = [];
        $this->displayPrices = [];
        $this->rowKeys = [];
    }

    private function dispatchStockToggle(): void
    {
        $this->dispatch('unit-config:stock-toggle', stockManaged: $this->stockManaged);
    }

    private function initConversions(array $conversions): void
    {
        $this->conversions = !empty($conversions) ? array_values($conversions) : [];

        foreach ($this->conversions as $i => $conv) {
            // Normalize nulls to empty strings so validation rules see a value or ''.
            $this->conversions[$i]['price'] = $conv['price'] ?? '';
            $this->displayPrices[$i] = ($conv['price'] ?? '') !== ''
                ? $this->formatCurrency((float) $conv['price'])
                : '';
            $this->rowKeys[$i] = $conv['id'] ?? uniqid('conv_', true);
        }
    }
}
