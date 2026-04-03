<?php

namespace App\Livewire\Product;

use Livewire\Component;
use Modules\Product\Support\ProductConversionPriceNormalizer;

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
        if (!$this->stockManaged) {
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

        $index = array_search($key, $this->rowKeys, true);
        if ($index === false) {
            return;
        }

        unset($this->conversions[$index], $this->displayPrices[$index], $this->rowKeys[$index]);
        $this->conversions = array_values($this->conversions);
        $this->displayPrices = array_values($this->displayPrices);
        $this->rowKeys = array_values($this->rowKeys);
    }

    public function updatedConversions(): void
    {
        $this->syncConversionState();
    }

    // NOTE: showRawPrice() and syncPrice() methods removed as of fix-nominal-field-formatting-consistency
    // Conversion table price formatting is now handled exclusively by jQuery maskMoney
    // on the visible input, with Livewire managing only the hidden input for data storage.
    // No updatedDisplayPrices() listener needed anymore since visible input no longer has wire:model

    public function render()
    {
        return view('livewire.product.unit-configuration');
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
        $this->syncConversionState();
    }

    private function syncConversionState(): void
    {
        $existingRowKeys = $this->rowKeys;
        $this->conversions = array_values($this->conversions);
        $this->displayPrices = [];
        $this->rowKeys = [];

        foreach ($this->conversions as $i => $conv) {
            $normalizedPrice = ProductConversionPriceNormalizer::normalizePrice($conv['price'] ?? '');

            $this->conversions[$i]['price'] = $normalizedPrice;
            $this->displayPrices[$i] = ProductConversionPriceNormalizer::isCanonicalNumeric($normalizedPrice)
                ? ProductConversionPriceNormalizer::formatDisplay($normalizedPrice)
                : (string) $normalizedPrice;
            $this->rowKeys[$i] = $existingRowKeys[$i] ?? ($conv['id'] ?? uniqid('conv_', true));
        }
    }
}
