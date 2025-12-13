<?php

namespace Modules\Product\Livewire;

use Livewire\Component;

class ProductPriceSetup extends Component
{
    public string $type = 'purchase'; // 'purchase' or 'sale'
    public bool $isActive = false;
    public string|float $price = '';
    public int|string|null $taxId = null;
    public string $priceLabel = 'Harga Beli';
    public string $checkboxLabel = 'Saya Beli Barang Ini';
    public string $taxLabel = 'Pajak Beli';
    public string $fieldPrefix = 'purchase';

    /** @var array<int, array{id:int|string,name:string,value:int|float|null}> */
    public array $taxOptions = [];
    public ?string $priceError = null;
    public ?string $taxError = null;

    public function mount(
        string $type = 'purchase',
        bool $isActive = false,
        string|float $price = '',
        int|string|null $taxId = null,
        string $priceLabel = 'Harga Beli',
        string $checkboxLabel = 'Saya Beli Barang Ini',
        string $taxLabel = 'Pajak Beli',
        string $fieldPrefix = 'purchase',
        array $taxOptions = [],
        ?string $priceError = null,
        ?string $taxError = null
    ): void {
        $this->type = $type;
        $this->isActive = $isActive;
        $this->price = $price;
        $this->taxId = $taxId;
        $this->priceLabel = $priceLabel;
        $this->checkboxLabel = $checkboxLabel;
        $this->taxLabel = $taxLabel;
        $this->fieldPrefix = $fieldPrefix;
        $this->taxOptions = $taxOptions;
        $this->priceError = $priceError;
        $this->taxError = $taxError;

        $this->syncTaxDisabledState();
    }

    public function updatedIsActive($value): void
    {
        $this->syncTaxDisabledState();

        // When checkbox is toggled off, reset values
        if (!$value) {
            $this->price = '';
            $this->taxId = null;
        }
    }

    public function render()
    {
        return view('livewire.modules.product.product-price-setup');
    }

    private function syncTaxDisabledState(): void
    {
        $this->dispatch(
            'taxDropdownToggle',
            name: $this->fieldPrefix . '_tax_id',
            disabled: !$this->isActive
        );
    }
}
