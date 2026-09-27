<?php

namespace Modules\Product\Livewire;

use Livewire\Component;

class SalePriceSetup extends Component
{
    public bool $isActive = false;
    public string|float $price = '';
    public string|float $tier1Price = '';
    public string|float $tier2Price = '';
    public int|string|null $taxId = null;
    public string $checkboxLabel = 'Saya Jual Barang Ini';

    /** @var array<int, array{id:int|string,name:string,value:int|float|null}> */
    public array $taxOptions = [];
    public ?string $priceError = null;
    public ?string $taxError = null;
    public ?string $tier1Error = null;
    public ?string $tier2Error = null;

    public function mount(
        bool $isActive = false,
        string|float $price = '',
        string|float $tier1Price = '',
        string|float $tier2Price = '',
        int|string|null $taxId = null,
        string $checkboxLabel = 'Saya Jual Barang Ini',
        array $taxOptions = [],
        ?string $priceError = null,
        ?string $taxError = null,
        ?string $tier1Error = null,
        ?string $tier2Error = null
    ): void {
        $this->isActive = $isActive;
        $this->price = $price;
        $this->tier1Price = $tier1Price;
        $this->tier2Price = $tier2Price;
        $this->taxId = $taxId;
        $this->checkboxLabel = $checkboxLabel;
        $this->taxOptions = $taxOptions;
        $this->priceError = $priceError;
        $this->taxError = $taxError;
        $this->tier1Error = $tier1Error;
        $this->tier2Error = $tier2Error;

        $this->syncTaxDisabledState();
    }

    public function updatedIsActive($value): void
    {
        $this->syncTaxDisabledState();

        // When checkbox is toggled off, reset values
        if (!$value) {
            $this->price = '';
            $this->tier1Price = '';
            $this->tier2Price = '';
            $this->taxId = null;
        }
    }

    public function render()
    {
        return view('livewire.modules.product.sale-price-setup');
    }

    private function syncTaxDisabledState(): void
    {
        $this->dispatch(
            'taxDropdownToggle',
            name: 'sale_tax_id',
            disabled: !$this->isActive
        );
    }
}
