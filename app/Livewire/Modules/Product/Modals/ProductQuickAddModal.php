<?php

namespace App\Livewire\Modules\Product\Modals;

use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class ProductQuickAddModal extends Component
{
    public $showModal = false;

    // Basic Product Fields
    public $product_name;
    public $product_code;
    public $barcode;
    public $category_id;
    public $brand_id;
    public $unit_id; // Maps to base_unit_id
    public $note;
    public bool $serial_number_required = false;

    // Stock Management
    public $stock_managed = false;
    public $product_stock_alert = 0;

    // Settings
    public $is_purchased = true;
    public $is_sold = true;

    // Price Fields
    public $purchase_price;
    public $sale_price;
    public $tier_1_price;
    public $tier_2_price;
    public $purchase_tax_id;
    public $sale_tax_id;

    // Unit Configuration State
    public array $conversions = [];
    public array $displayPrices = []; // For conversion prices
    public array $rowKeys = [];
    public array $unitOptions = []; // For UnitSearchDropdown options reuse if needed

    public $formResetVersion = 1;

    protected $listeners = [
        'openProductModal' => 'openModal',
        'categoryDropdownSelected' => 'handleCategorySelected',
        'brandDropdownSelected' => 'handleBrandSelected',
        'unitDropdownSelected' => 'handleUnitSelected',
        'taxDropdownSelected' => 'handleTaxSelected',
    ];

    public function mount()
    {
        // define unit options if needed, but dropdowns handle themselves usually
        // We initialize conversions array
    }

    public function openModal()
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function resetForm()
    {
        $this->reset([
            'product_name', 'product_code', 'barcode', 'category_id', 'brand_id', 'unit_id', 'note',
            'stock_managed', 'product_stock_alert',
            'is_purchased', 'is_sold',
            'purchase_price', 'sale_price', 'tier_1_price', 'tier_2_price',
            'purchase_tax_id', 'sale_tax_id', 'serial_number_required'
        ]);
        $this->conversions = [];
        $this->displayPrices = [];
        $this->rowKeys = [];
        $this->formResetVersion++;
        $this->dispatch('product-modal-reset'); // Helper for clearing dropdowns if they listen
    }

    // --- Event Handlers for Dropdowns ---

    public function handleCategorySelected($name, $value)
    {
        if ($name === 'category_id') {
            $this->category_id = $value;
        }
    }

    public function handleBrandSelected($name, $value)
    {
        if ($name === 'brand_id') {
            $this->brand_id = $value;
        }
    }

    public function handleUnitSelected($name, $value)
    {
        if ($name === 'unit_id') {
            $this->unit_id = $value;
        } elseif (Str::startsWith($name, 'conversions.')) {
            // conversions.0.unit_id
            $parts = explode('.', $name);
            if (isset($parts[1])) {
                $index = $parts[1];
                if (isset($this->conversions[$index])) {
                    $this->conversions[$index]['unit_id'] = $value;
                }
            }
        }
    }

    public function handleTaxSelected($name, $value)
    {
        if ($name === 'purchase_tax_id') {
            $this->purchase_tax_id = $value;
        } elseif ($name === 'sale_tax_id') {
            $this->sale_tax_id = $value;
        }
    }

    // --- Unit Configuration Logic ---

    public function addConversionRow()
    {
        if (!$this->stock_managed) {
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

    public function removeConversionRow($key)
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
        if (! $value) {
            $this->serial_number_required = false;
            $this->product_stock_alert = 0;
            $this->conversions = [];
            $this->displayPrices = [];
            $this->rowKeys = [];
        }
    }

    public function showRawPrice($index)
    {
        if (isset($this->conversions[$index])) {
            $this->displayPrices[$index] = $this->conversions[$index]['price'] !== ''
                ? rtrim(rtrim((string) $this->conversions[$index]['price'], '0'), '.')
                : '';
        }
    }

    public function syncPrice($index)
    {
        $raw = $this->displayPrices[$index] ?? '';
        $clean = str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $raw));
        $num = $clean === '' ? null : (float) $clean;

        if (isset($this->conversions[$index])) {
            $this->conversions[$index]['price'] = $num ?? '';
        }
        $this->displayPrices[$index] = $num === null ? '' : number_format($num, 2);
    }

    // --- Save Logic ---

    public function save()
    {
        $this->validate([
            'product_name' => 'required|string|max:255',
            'product_code' => 'nullable|string|max:255|unique:products,product_code',
            'category_id' => 'required',
            'unit_id' => 'required',
            'purchase_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_managed' => 'boolean',
            'serial_number_required' => 'boolean',
        ]);

        try {
            DB::beginTransaction();

            $settingId = session('setting_id') ?? 1;
            $settingIds = Setting::pluck('id');
            if ($settingIds->isEmpty()) $settingIds = collect([$settingId]);

            // Auto-generate Code
            if (empty($this->product_code)) {
                $lastSku = Product::where('product_code', 'like', 'SKU-%')
                    ->orderByRaw("CAST(SUBSTRING(product_code, 5) AS UNSIGNED) DESC")
                    ->value('product_code');
                $nextNumber = 1;
                if ($lastSku) {
                    $nextNumber = (int) substr($lastSku, 4) + 1;
                }
                $this->product_code = 'SKU-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
            }

            // Create Product
            $product = Product::create([
                'product_name' => $this->product_name,
                'product_code' => $this->product_code,
                'barcode' => $this->barcode,
                'category_id' => $this->category_id,
                'brand_id' => $this->brand_id,
                'base_unit_id' => $this->unit_id,
                'unit_id' => $this->unit_id, // Map both for safety? Model relies on base_unit_id for logic mostly
                'product_stock_alert' => $this->product_stock_alert,
                'stock_managed' => $this->stock_managed ? 1 : 0,
                'serial_number_required' => $this->serial_number_required ? 1 : 0,
                'is_purchased' => $this->is_purchased,
                'is_sold' => $this->is_sold,
                'product_note' => $this->note,
                'setting_id' => $settingId,
                // Defaults
                'product_quantity' => 0,
                'product_cost' => 0,
                'product_price' => 0,
                'product_order_tax' => 0,
                'product_tax_type' => 0,
                'purchase_price' => 0,
                'sale_price' => 0,
            ]);

            // Create ProductPrice
            ProductPrice::seedForSettings(
                $product->id,
                [
                    'sale_price' => $this->sale_price ?: 0,
                    'tier_1_price' => $this->tier_1_price ?: 0,
                    'tier_2_price' => $this->tier_2_price ?: 0,
                    'last_purchase_price' => $this->purchase_price ?: 0,
                    'average_purchase_price' => $this->purchase_price ?: 0,
                    'purchase_tax_id' => $this->purchase_tax_id,
                    'sale_tax_id' => $this->sale_tax_id,
                ],
                $settingIds
            );

            // Create Conversions
            if (!empty($this->conversions)) {
                foreach ($this->conversions as $conversion) {
                    if (empty($conversion['unit_id'])) continue;
                    
                    $price = (float) ($conversion['price'] ?? 0);
                    $newConv = $product->conversions()->create([
                        'unit_id' => $conversion['unit_id'],
                        'base_unit_id' => $this->unit_id,
                        'conversion_factor' => $conversion['conversion_factor'] ?? 1,
                        'barcode' => $conversion['barcode'] ?? null,
                    ]);

                    ProductUnitConversionPrice::seedForSettings(
                        $newConv->id,
                        $price,
                        $settingIds
                    );
                }
            }

            DB::commit();

            // Dispatch event to add to cart
            // We mimic the structure expected by ProductCart::productSelected
            
            // Re-fetch product with relations just in case, or construct manually
            // SearchProduct sends array structure.
            // We'll construct it carefully.
            
            // Need unit name
            $unitName = Unit::where('id', $this->unit_id)->value('name') ?? 'pc';
            
            $productData = [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'display_name' => $product->product_name . ' | ' . $product->product_code,
                'product_quantity' => 0,
                'product_unit' => $unitName,
                'last_purchase_price' => (float)$this->purchase_price,
                'average_purchase_price' => (float)$this->purchase_price,
                'purchase_tax_id' => $this->purchase_tax_id,
                'base_unit_name' => $unitName,
                'serial_number_required' => (bool) $this->serial_number_required,
                // Additional fields for ProductCart to be safe
                'product_id' => $product->id, 
            ];

            $this->dispatch('productSelected', $productData);

            // And also productCreated for SearchProduct to refresh
            $this->dispatch('productCreated', $productData);

            $this->closeModal();
            session()->flash('success', 'Produk berhasil ditambahkan dan dimasukkan ke keranjang!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Quick Add Product Error: ' . $e->getMessage());
            session()->flash('error', 'Gagal menambahkan produk: ' . $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.modules.product.modals.product-quick-add-modal');
    }
}
