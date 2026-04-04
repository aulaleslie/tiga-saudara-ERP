<?php

namespace App\Livewire\Modules\Product\Modals;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Services\ProductCreator;
use Modules\Product\Support\ProductCreateValidation;
use Modules\Setting\Entities\Unit;
use Throwable;

class ProductQuickAddModal extends Component
{
    public bool $showModal = false;
    public string $context = 'purchase';

    public $product_name;
    public $product_code;
    public $barcode;
    public $category_id;
    public $brand_id;
    public $base_unit_id;
    public bool $serial_number_required = false;

    public bool $stock_managed = true;
    public $product_stock_alert;

    public bool $is_purchased = true;
    public bool $is_sold = false;

    public $purchase_price;
    public $sale_price;
    public $tier_1_price;
    public $tier_2_price;
    public $purchase_tax_id;
    public $sale_tax_id;

    public array $conversions = [];
    public array $displayPrices = [];
    public array $rowKeys = [];

    public int $formResetVersion = 1;

    protected $listeners = [
        'openProductModal' => 'openModal',
        'categoryDropdownSelected' => 'handleCategorySelected',
        'brandDropdownSelected' => 'handleBrandSelected',
        'unitDropdownSelected' => 'handleUnitSelected',
        'taxDropdownSelected' => 'handleTaxSelected',
    ];

    public function mount(): void
    {
        $this->applyPurchaseDefaults();
    }

    public function openModal(array $params = []): void
    {
        $this->context = $this->normalizeContext($params['context'] ?? null);
        $this->resetForm();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
        $this->context = 'purchase';
    }

    public function resetForm(): void
    {
        $this->reset([
            'product_name',
            'product_code',
            'barcode',
            'category_id',
            'brand_id',
            'base_unit_id',
            'serial_number_required',
            'product_stock_alert',
            'is_purchased',
            'is_sold',
            'purchase_price',
            'sale_price',
            'tier_1_price',
            'tier_2_price',
            'purchase_tax_id',
            'sale_tax_id',
        ]);

        $this->conversions = [];
        $this->displayPrices = [];
        $this->rowKeys = [];
        $this->formResetVersion++;
        $this->resetErrorBag();
        $this->resetValidation();
        $this->applyContextDefaults();

        $this->dispatch('product-modal-reset');
    }

    public function handleCategorySelected($name, $value): void
    {
        if ($name === 'category_id') {
            $this->category_id = $value;
        }
    }

    public function handleBrandSelected($name, $value): void
    {
        if ($name === 'brand_id') {
            $this->brand_id = $value;
        }
    }

    public function handleUnitSelected($name, $value): void
    {
        if ($name === 'base_unit_id') {
            $this->base_unit_id = $value;
            return;
        }

        if (Str::startsWith($name, 'conversions.')) {
            $parts = explode('.', $name);
            if (isset($parts[1]) && isset($this->conversions[$parts[1]])) {
                $this->conversions[$parts[1]]['unit_id'] = $value;
            }
        }
    }

    public function handleTaxSelected($name, $value): void
    {
        if ($name === 'purchase_tax_id') {
            $this->purchase_tax_id = $value;
            return;
        }

        if ($name === 'sale_tax_id') {
            $this->sale_tax_id = $value;
        }
    }

    public function addConversionRow(): void
    {
        if (! $this->stock_managed) {
            return;
        }

        $this->conversions[] = [
            'id' => null,
            'unit_id' => '',
            'conversion_factor' => '',
            'barcode' => '',
            'price' => '',
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

    public function updatedStockManaged($value): void
    {
        if (! (bool) $value) {
            $this->stock_managed = true;
        }
    }

    public function updatedIsPurchased($value): void
    {
        if (! (bool) $value) {
            $this->is_purchased = true;
        }
    }

    public function updatedIsSold($value): void
    {
        if ($this->isSalesContext()) {
            $this->is_sold = true;
            return;
        }

        if ((bool) $value) {
            return;
        }

        $this->sale_price = null;
        $this->tier_1_price = null;
        $this->tier_2_price = null;
        $this->sale_tax_id = null;
    }

    public function showRawPrice(int $index): void
    {
        if (! isset($this->conversions[$index])) {
            return;
        }

        $this->displayPrices[$index] = $this->conversions[$index]['price'] !== ''
            ? rtrim(rtrim((string) $this->conversions[$index]['price'], '0'), '.')
            : '';
    }

    public function syncPrice(int $index): void
    {
        $raw = $this->displayPrices[$index] ?? '';
        $clean = str_replace(',', '.', preg_replace('/[^\d,\.]/', '', (string) $raw));
        $num = $clean === '' ? null : (float) $clean;

        if (isset($this->conversions[$index])) {
            $this->conversions[$index]['price'] = $num ?? '';
        }

        $this->displayPrices[$index] = $num === null ? '' : number_format($num, 2, '.', '');
    }

    public function updatedDisplayPrices($value, string $name): void
    {
        if (! preg_match('/displayPrices\.(\d+)/', $name, $matches)) {
            return;
        }

        $index = (int) $matches[1];
        $raw = is_string($value) ? $value : '';
        $clean = str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $raw));
        $num = $clean === '' ? null : (float) $clean;

        if (isset($this->conversions[$index])) {
            $this->conversions[$index]['price'] = $num ?? '';
        }
    }

    public function save(): void
    {
        $payload = $this->buildValidationPayload();
        $validator = Validator::make(
            $payload,
            ProductCreateValidation::rules($payload),
            ProductCreateValidation::messages()
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        try {
            /** @var ProductCreator $creator */
            $creator = app(ProductCreator::class);
            $product = $creator->create($validated);
            $productData = $this->buildProductPayload($product);

            $this->dispatch('productSelected', $productData);
            $this->dispatch('productCreated', $productData);

            $this->closeModal();
            session()->flash('success', $this->isSalesContext()
                ? 'Produk berhasil dibuat untuk penjualan.'
                : 'Produk berhasil ditambahkan dan dimasukkan ke keranjang!');
        } catch (Throwable $e) {
            Log::error('Quick Add Product Error', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            session()->flash('error', 'Gagal menambahkan produk: ' . $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.modules.product.modals.product-quick-add-modal');
    }

    private function applyContextDefaults(): void
    {
        if ($this->isSalesContext()) {
            $this->applySalesDefaults();
            return;
        }

        $this->applyPurchaseDefaults();
    }

    private function applyPurchaseDefaults(): void
    {
        $this->stock_managed = true;
        $this->is_purchased = true;
        $this->is_sold = false;
        $this->serial_number_required = false;
        $this->product_stock_alert = null;
    }

    private function applySalesDefaults(): void
    {
        $this->stock_managed = true;
        $this->is_purchased = true;
        $this->is_sold = true;
        $this->serial_number_required = false;
        $this->product_stock_alert = null;
    }

    private function buildValidationPayload(): array
    {
        $normalizedSalePrice = $this->emptyToNull($this->sale_price);
        $normalizedTier1Price = $this->emptyToNull($this->tier_1_price);
        $normalizedTier2Price = $this->emptyToNull($this->tier_2_price);

        if ($this->isSalesContext() && $normalizedSalePrice !== null) {
            $normalizedTier1Price ??= $normalizedSalePrice;
            $normalizedTier2Price ??= $normalizedSalePrice;
        }

        $payload = [
            'product_name' => $this->product_name,
            'product_code' => $this->emptyToNull($this->product_code),
            'barcode' => $this->emptyToNull($this->barcode),
            'category_id' => $this->emptyToNull($this->category_id),
            'brand_id' => $this->emptyToNull($this->brand_id),
            'base_unit_id' => $this->emptyToNull($this->base_unit_id),
            'serial_number_required' => (bool) $this->serial_number_required,
            'stock_managed' => true,
            'product_stock_alert' => $this->emptyToNull($this->product_stock_alert),
            'is_purchased' => true,
            'is_sold' => $this->isSalesContext() ? true : (bool) $this->is_sold,
            'purchase_price' => $this->emptyToNull($this->purchase_price),
            'sale_price' => $normalizedSalePrice,
            'tier_1_price' => $normalizedTier1Price,
            'tier_2_price' => $normalizedTier2Price,
            'purchase_tax_id' => $this->emptyToNull($this->purchase_tax_id),
            'sale_tax_id' => $this->emptyToNull($this->sale_tax_id),
            'conversions' => array_values($this->conversions),
        ];

        $payload = array_merge($payload, ProductCreateValidation::normalize($payload));
        $payload['stock_managed'] = true;
        $payload['is_purchased'] = true;

        return $payload;
    }

    private function buildProductPayload(Product $product): array
    {
        $settingId = (int) (session('setting_id') ?? $product->setting_id ?? 0);
        $product->loadMissing('baseUnit:id,name');

        $priceRow = null;
        if ($settingId > 0) {
            $priceRow = ProductPrice::query()
                ->forProduct($product->id)
                ->forSetting($settingId)
                ->first();
        }
        $priceRow = $priceRow ?: ProductPrice::query()->forProduct($product->id)->first();

        $unitName = $product->baseUnit?->name
            ?? ($product->base_unit_id ? Unit::query()->whereKey($product->base_unit_id)->value('name') : null)
            ?? 'pc';

        return [
            'id' => $product->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'display_name' => $product->product_name . ' | ' . $product->product_code,
            'product_quantity' => (int) ($product->product_quantity ?? 0),
            'product_unit' => $unitName,
            'last_purchase_price' => (float) ($priceRow?->last_purchase_price ?? 0),
            'average_purchase_price' => (float) ($priceRow?->average_purchase_price ?? 0),
            'purchase_tax_id' => $priceRow?->purchase_tax_id,
            'sale_price' => (float) ($priceRow?->sale_price ?? 0),
            'tier_1_price' => (float) ($priceRow?->tier_1_price ?? 0),
            'tier_2_price' => (float) ($priceRow?->tier_2_price ?? 0),
            'sale_tax_id' => $priceRow?->sale_tax_id,
            'base_unit_name' => $unitName,
            'serial_number_required' => (bool) $product->serial_number_required,
        ];
    }

    private function isSalesContext(): bool
    {
        return $this->context === 'sale';
    }

    private function normalizeContext(mixed $context): string
    {
        return $context === 'sale' ? 'sale' : 'purchase';
    }

    private function emptyToNull(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
