<?php

namespace App\Livewire\Purchase;

use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;

class ProductCart extends Component
{
    public $listeners = ['productSelected', 'discountModalRefresh', 'taxCreated' => 'handleTaxCreated', 'document-business-context-changed' => 'handleBusinessContextChanged'];

    public $cart_instance;
    public $global_discount;
    public $global_tax;
    public $global_tax_id;
    public $shipping;
    public $quantity;
    public $check_quantity;
    public $discount_type;
    public $item_discount;
    public $unit_price;
    public $line_total;
    public $data;
    /** True once the document is received: quantity and row membership are locked. */
    public bool $monetaryOnly = false;
    public $quantityBreakdowns = [];
    public $product;
    public $selected_unit = [];
    public $available_units = [];

    public $taxes; // Collection of available taxes
    public $setting_id; // Current setting ID
    public ?int $selectedSettingId = null; // Selected business setting ID (from parent)
    public $product_tax = []; // Array to store selected tax IDs for each product

    public $is_tax_included = true;

    public $global_discount_type = 'percentage';
    public bool $isPkp = false;

    protected $rules = [
        'unit_price.*' => 'required|numeric|min:0', // Unit price per row.
        'quantity.*' => 'required|numeric|min:0.001', // Quantity must be at least 0.001.
        'item_discount.*' => 'nullable|numeric|min:0', // Discounts are optional and non-negative.
        'global_discount' => 'nullable|numeric|min:0|max:100',
        'shipping' => 'nullable|numeric|min:0', // Shipping is optional and non-negative.
    ];

    private function perfLog(string $message, array $context = []): void
    {
        if (! config('performance.livewire_hotpath_debug')) {
            return;
        }

        Log::info($message, $context);
    }

    public function mount($cartInstance, $data = null, ?int $selectedSettingId = null): void
    {
        $this->cart_instance = $cartInstance;
        $cart_items = Cart::instance($this->cart_instance)->content();
        $this->perfLog('mount() called at: ' . round(microtime(true) * 1000), [
            'cart_instance' => $cartInstance,
            'cart_items' => $cart_items,
        ]);
        $this->cart_instance = $cartInstance;
        $this->selectedSettingId = $selectedSettingId ?? (int) session('setting_id');
        $this->setting_id = $this->selectedSettingId;
        $this->isPkp = (bool) (Setting::query()->whereKey((int) $this->setting_id)->value('is_pkp') ?? false);
        $this->taxes = $this->loadTaxes();
        $this->perfLog('validated', [
            'data' => $data,
        ]);

        if (! $this->isPkp) {
            $this->is_tax_included = false;
        }

        // Always publish the cart's initial tax state so the parent form persists the same value.
        $this->dispatch('taxIncludedUpdated', (bool) $this->is_tax_included);
        $this->reconcileNonPkpPurchaseCartState();
        $this->reconcileMissingPkpTaxesInCart();

        $cart_items = Cart::instance($this->cart_instance)->content();

        if ($data) {
            $this->data = $data;
            $this->monetaryOnly = $data instanceof \Modules\Purchase\Entities\Purchase
                && $data->resolveEditMode() === \Modules\Purchase\Entities\Purchase::EDIT_MODE_MONETARY_ONLY;

            if ($data->discount_percentage > 0) {
                $this->global_discount_type = 'percentage';
                $this->global_discount = $data->discount_percentage;
            } else if ($data->discount_amount > 0) {
                $this->global_discount_type = 'fixed';
                $this->global_discount = $data->discount_amount;
            } else {
                $this->global_discount = 0;
            }

            $this->shipping = $data->shipping_amount;
            $this->is_tax_included = (bool) $data->is_tax_included;

            foreach ($cart_items as $cart_item) {
                $this->initializeCartItemAttributes($cart_item);
            }
            $this->dispatch('globalDiscountTypeUpdated', $this->global_discount_type);
            $this->dispatch('globalDiscountUpdated', $this->global_discount);
            $this->dispatch('shippingUpdated', $this->shipping);
        } else {
            $this->global_discount = 0;
            $this->shipping = 0.00;
            $this->check_quantity = [];
            $this->quantity = [];
            $this->unit_price = [];
            $this->line_total = [];
            $this->discount_type = [];
            $this->item_discount = [];
            $this->product_tax = [];

            // If there are existing cart items (e.g., from session), initialize their attributes
            foreach ($cart_items as $cart_item) {
                $this->initializeCartItemAttributes($cart_item);
            }
        }
    }

    private function getAvailableUnitsForCartItem($cart_item): array
    {
        $productId = (int) $cart_item->id;
        $productModel = Product::with(['baseUnit', 'unit', 'conversions.unit'])->find($productId);
        $units = [];

        if ($productModel) {
            $baseUnitId = (int) ($productModel->base_unit_id ?? $productModel->unit_id);
            $baseUnitName = $productModel->baseUnit?->name ?? $productModel->unit?->name ?? 'PCS';

            $units[] = [
                'id' => 'base_' . $baseUnitId,
                'unit_id' => $baseUnitId,
                'product_unit_conversion_id' => null,
                'name' => $baseUnitName,
                'factor' => 1.0,
            ];

            // Use centralized eligibility filter for new selections
            $eligible = $productModel->eligiblePurchaseConversions();
            foreach ($eligible as $conv) {
                $uName = $conv->unit?->name ?? 'UNIT';
                $units[] = [
                    'id' => 'conv_' . $conv->id,
                    'unit_id' => (int) $conv->unit_id,
                    'product_unit_conversion_id' => (int) $conv->id,
                    'name' => $uName,
                    'factor' => (float) $conv->conversion_factor,
                ];
            }
        }

        // Historical Snapshot Fallback for existing cart item
        $options = $cart_item->options;
        $selectedConvId = $options->product_unit_conversion_id ?? null;
        $unitId = $options->purchase_unit_id ?? null;

        if ($selectedConvId) {
            $optionId = 'conv_' . $selectedConvId;
            if (! collect($units)->firstWhere('id', $optionId)) {
                $units[] = [
                    'id' => $optionId,
                    'unit_id' => (int) ($unitId ?? 0),
                    'product_unit_conversion_id' => (int) $selectedConvId,
                    'name' => (string) ($options->unit_name ?? 'UNIT'),
                    'factor' => (float) ($options->conversion_factor ?? 1.0),
                ];
            }
        } elseif ($unitId && (int) $unitId !== (int) ($units[0]['unit_id'] ?? 0)) {
            $optionId = 'base_' . $unitId;
            if (! collect($units)->firstWhere('id', $optionId)) {
                $units[] = [
                    'id' => $optionId,
                    'unit_id' => (int) $unitId,
                    'product_unit_conversion_id' => null,
                    'name' => (string) ($options->unit_name ?? 'UNIT'),
                    'factor' => (float) ($options->conversion_factor ?? 1.0),
                ];
            }
        }

        return $units;
    }

    private function migrateRowKeys(string $oldRowId, string $newRowId): void
    {
        if ($oldRowId === $newRowId) {
            return;
        }

        foreach ([
            'check_quantity',
            'quantity',
            'unit_price',
            'line_total',
            'discount_type',
            'item_discount',
            'product_tax',
            'selected_unit',
            'available_units',
            'quantityBreakdowns',
        ] as $prop) {
            if (isset($this->{$prop}[$oldRowId])) {
                $this->{$prop}[$newRowId] = $this->{$prop}[$oldRowId];
                unset($this->{$prop}[$oldRowId]);
            }
        }
    }

    private function initializeCartItemAttributes($cart_item): void
    {
        $cartKey = $cart_item->rowId;
        $productId = (int) $cart_item->id;

        $this->check_quantity[$cartKey] = data_get($cart_item->options, 'stock') ?? 0;
        $this->quantity[$cartKey] = $cart_item->qty ?? 0;
        $this->unit_price[$cartKey] = $cart_item->price ?? 0;
        $this->line_total[$cartKey] = data_get($cart_item->options, 'sub_total') ?? 0;
        $discountType = data_get($cart_item->options, 'product_discount_type') ?? 'fixed';
        $this->discount_type[$cartKey] = $discountType;

        $storedDiscountInput = data_get($cart_item->options, 'product_discount_input');
        if (is_numeric($storedDiscountInput)) {
            $displayDiscount = (float) $storedDiscountInput;
        } elseif ($discountType === 'percentage') {
            $price = (float) ($cart_item->price ?? 0);
            $storedAmount = (float) (data_get($cart_item->options, 'product_discount') ?? 0);
            $displayDiscount = $price > 0 ? ($storedAmount / $price) * 100 : 0;
        } else {
            $displayDiscount = (float) (data_get($cart_item->options, 'product_discount') ?? 0);
        }

        $this->item_discount[$cartKey] = round(max(0, $displayDiscount), 2);
        $this->product_tax[$cartKey] = data_get($cart_item->options, 'product_tax');

        $units = $this->getAvailableUnitsForCartItem($cart_item);
        $this->available_units[$cartKey] = $units;

        $selectedConvId = data_get($cart_item->options, 'product_unit_conversion_id');
        if ($selectedConvId) {
            $this->selected_unit[$cartKey] = 'conv_' . $selectedConvId;
        } else {
            $unitId = data_get($cart_item->options, 'purchase_unit_id');
            if ($unitId) {
                $found = collect($units)->firstWhere('unit_id', (int) $unitId);
                $this->selected_unit[$cartKey] = $found['id'] ?? ('base_' . $unitId);
            } else {
                $baseUnitOption = collect($units)->firstWhere('is_base', true) ?? collect($units)->firstWhere('product_unit_conversion_id', null) ?? collect($units)->first();
                $this->selected_unit[$cartKey] = $baseUnitOption['id'] ?? 'base_1';
            }
        }

        $this->quantityBreakdowns[$cartKey] = $this->calculateConversionBreakdown($productId, (float) $cart_item->qty);
        $this->dispatch('globalDiscountTypeUpdated', $this->global_discount_type);
    }

    private function calculateConversionBreakdown(int $productId, int $quantity): string
    {
        if ($quantity < 1) {
            return '';
        }

        $productModel = Product::with('baseUnit', 'unit')->find($productId);
        $baseUnitId   = $productModel?->base_unit_id;
        $baseUnitName = $productModel?->baseUnit?->name
            ?? $productModel?->unit?->name
            ?? 'pc';

        // 1) get all conversions for this product, biggest first
        $conversions = ProductUnitConversion::with(['unit', 'baseUnit'])
            ->where('product_id', $productId)
            ->orderByDesc('conversion_factor')
            ->get();

        // If no conversions, just return the quantity in base unit
        if ($conversions->isEmpty()) {
            return "{$quantity} {$baseUnitName}(s)";
        }

        $parts = [];
        $remaining = $quantity;

        foreach ($conversions as $conv) {
            $factor = (int) $conv->conversion_factor;
            if ($factor < 1) {
                continue;
            }
            $count = intdiv($remaining, $factor);
            if ($count > 0) {
                // assume you have a relation to Unit for the name:
                $unitName = optional($conv->unit)->name ?? "unit";
                $parts[] = "{$count} {$unitName}(s)";
                $remaining -= $count * $factor;
            }
        }

        // 2) whatever is left is in the base unit:
        if ($remaining > 0) {
            // you can grab the base unit name however your schema defines it:
            $fallbackBaseUnitId = $conversions->first()->base_unit_id ?? $baseUnitId;
            $baseName = optional(Unit::find($fallbackBaseUnitId))->name ?? $baseUnitName;
            $parts[]  = "{$remaining} {$baseName}(s)";
        }

        return implode(', ', $parts);
    }

    public function handleTaxCreated($id, $name, $value, $product_id = null): void
    {
        $this->taxes = $this->loadTaxes(); // Refresh the taxes list

        if ($product_id) {
            $cartItem = Cart::instance($this->cart_instance)->content()->get($product_id);
            if ($cartItem) {
                $this->updateTax($cartItem->rowId, $cartItem->id, $id);
            }
        }
    }

    public function handleBusinessContextChanged(?int $settingId): void
    {
        if ($settingId === null) {
            $this->selectedSettingId = (int) session('setting_id');
        } else {
            $this->selectedSettingId = $settingId;
        }
        $this->rehydrateTaxContextForBusinessChange();
    }

    public function updatedSelectedSettingId(): void
    {
        $this->rehydrateTaxContextForBusinessChange();
    }

    private function rehydrateTaxContextForBusinessChange(): void
    {
        $previousPkpState = $this->isPkp;
        $this->setting_id = $this->selectedSettingId;
        $this->isPkp = (bool) (Setting::query()->whereKey((int) $this->setting_id)->value('is_pkp') ?? false);
        $this->taxes = $this->loadTaxes();

        $cart = Cart::instance($this->cart_instance);
        $cartItems = $cart->content();
        if ($cartItems->isEmpty()) {
            return;
        }

        // If business changed from PKP to non-PKP, remove tax data
        if ($previousPkpState && !$this->isPkp) {
            foreach ($cartItems as $item) {
                $newOptions = $item->options->toArray();
                unset($newOptions['product_tax']);
                $newOptions['product_tax_amount'] = 0.0;
                $newOptions['sub_total'] = $newOptions['sub_total_before_tax'] ?? $newOptions['sub_total'];
                $this->product_tax[$item->id] = null;
                $cart->update($item->rowId, ['options' => $newOptions]);
            }
        }
        // If business changed from non-PKP to PKP, reload taxes and leave tax selection empty unless default exists
        elseif (!$previousPkpState && $this->isPkp) {
            foreach ($cartItems as $item) {
                $newOptions = $item->options->toArray();
                // Leave tax empty initially, will be required before save
                $newOptions['product_tax'] = null;
                $newOptions['product_tax_amount'] = 0.0;
                $newOptions['sub_total'] = $newOptions['sub_total_before_tax'] ?? $newOptions['sub_total'];
                $this->product_tax[$item->id] = null;
                $cart->update($item->rowId, ['options' => $newOptions]);
            }
        }
    }

    private function loadTaxes()
    {
        return Tax::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    private function normalizeTaxId(mixed $taxId): ?int
    {
        if ($taxId === null || $taxId === '' || blank($taxId)) {
            return null;
        }

        return is_numeric($taxId) ? (int) $taxId : null;
    }

    private function resolvePersistedProductTax($cartItem): ?int
    {
        $storedTaxId = $this->normalizeTaxId($cartItem->options->get('product_tax'));
        if ($storedTaxId !== null) {
            return $storedTaxId;
        }

        return $this->normalizeTaxId($this->product_tax[$cartItem->id] ?? null);
    }

    private function syncProductTaxState($cartItem, mixed $taxId = null): ?int
    {
        $resolvedTaxId = $this->normalizeTaxId($taxId);
        if ($resolvedTaxId === null) {
            $resolvedTaxId = $this->resolvePersistedProductTax($cartItem);
        }

        $this->product_tax[$cartItem->id] = $resolvedTaxId;

        return $resolvedTaxId;
    }

    private function reconcileMissingPkpTaxesInCart(): void
    {
        if ($this->cart_instance !== 'purchase' || ! $this->isPkp) {
            return;
        }

        $cart = Cart::instance($this->cart_instance);
        $cartItems = $cart->content();

        foreach ($cartItems as $cartItem) {
            $existingTaxId = $cartItem->options->get('product_tax');
            if ($existingTaxId !== null && $existingTaxId !== '') {
                continue;
            }

            $productId = (int) $cartItem->id;
            $resolvedTaxId = $this->resolvePreferredPkpAutoTaxId($productId);
            if (! $resolvedTaxId) {
                continue;
            }

            $this->product_tax[$cartItem->rowId] = $resolvedTaxId;

            $discountAmount = (float) ($cartItem->options->product_discount ?? 0);
            $calculated = $this->calculateSubtotalAndTax(
                $cartItem->price,
                $cartItem->qty,
                $discountAmount,
                $resolvedTaxId
            );

            $updatedItem = $cart->update($cartItem->rowId, [
                'options' => array_merge($cartItem->options->toArray(), [
                    'product_tax' => $resolvedTaxId,
                    'sub_total' => $calculated['sub_total'],
                    'sub_total_before_tax' => $calculated['sub_total_before_tax'],
                    'product_tax_amount' => $calculated['product_tax_amount'],
                    \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
                ]),
            ]);
            $this->migrateRowKeys($cartItem->rowId, $updatedItem->rowId);
        }
    }

    private function reconcileNonPkpPurchaseCartState(): void
    {
        if ($this->cart_instance !== 'purchase' || $this->isPkp) {
            return;
        }

        $cart = Cart::instance($this->cart_instance);

        foreach ($cart->content() as $cartItem) {
            $normalizedUnitPrice = $this->resolveNonPkpUnitPrice($cartItem);
            $discountAmount = (float) ($cartItem->options->product_discount ?? 0);
            $calculated = $this->calculateSubtotalAndTax(
                $normalizedUnitPrice,
                $cartItem->qty,
                $discountAmount,
                null
            );

            // This runs on mount, not as a user edit. A row hydrated from a stored
            // document already carries an authoritative total; recomputing it from a
            // rounded unit price would drift it (1460000 -> 1216.67 * 1200 = 1460004).
            // Only strip tax and normalize the display price for such rows.
            $storedTotal = $this->resolveAuthoritativeNonPkpTotal($cartItem);
            $subTotal = $storedTotal ?? $calculated['sub_total'];
            $subTotalBeforeTax = $storedTotal ?? $calculated['sub_total_before_tax'];

            $updatedItem = $cart->update($cartItem->rowId, [
                'price' => $normalizedUnitPrice,
                'options' => array_merge($cartItem->options->toArray(), [
                    'unit_price' => $normalizedUnitPrice,
                    'product_tax' => null,
                    'sub_total' => $subTotal,
                    'sub_total_before_tax' => $subTotalBeforeTax,
                    'product_tax_amount' => 0,
                    \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
                ]),
            ]);

            $this->migrateRowKeys($cartItem->rowId, $updatedItem->rowId);
            $this->line_total[$updatedItem->rowId] = $subTotal;
            $this->product_tax[$updatedItem->rowId] = null;
        }
    }

    /**
     * Return the row's already-authoritative non-PKP total, or null when the row has
     * no consistent stored total and should be recalculated normally.
     */
    private function resolveAuthoritativeNonPkpTotal($cartItem): ?float
    {
        $subTotal = $cartItem->options->sub_total ?? null;
        $subTotalBeforeTax = $cartItem->options->sub_total_before_tax ?? null;

        if (! is_numeric($subTotal) || ! is_numeric($subTotalBeforeTax)) {
            return null;
        }

        // Non-PKP invariant: sub_total == sub_total_before_tax and tax == 0.
        if (round((float) $subTotal, 2) !== round((float) $subTotalBeforeTax, 2)) {
            return null;
        }

        return round((float) $subTotal, 2);
    }

    private function resolveNonPkpUnitPrice($cartItem): float
    {
        $quantity = max(1, (int) $cartItem->qty);
        $subTotalBeforeTax = (float) ($cartItem->options->sub_total_before_tax ?? $cartItem->options->sub_total ?? ($cartItem->price * $quantity));
        $discountAmount = (float) ($cartItem->options->product_discount ?? 0);

        return round(($subTotalBeforeTax / $quantity) + $discountAmount, 2);
    }

    private function resolveDefaultTaxId(): ?int
    {
        $defaultTax = $this->taxes->firstWhere('is_default', true);

        if ($defaultTax) {
            return (int) $defaultTax->id;
        }

        if ($this->isPkp && $this->taxes->isNotEmpty()) {
            return (int) $this->taxes->first()->id;
        }

        return null;
    }

    private function resolveProductPurchaseTaxIdForProduct(int $productId, ?array $productPayload = null): ?int
    {
        if ($productId <= 0) {
            return null;
        }

        $productPriceTaxId = ProductPrice::query()
            ->forProduct($productId)
            ->forSetting((int) $this->setting_id)
            ->value('purchase_tax_id');

        if ($productPriceTaxId) {
            return (int) $productPriceTaxId;
        }

        $payloadTaxId = $productPayload['purchase_tax_id'] ?? $productPayload['product_tax'] ?? null;

        return $payloadTaxId ? (int) $payloadTaxId : null;
    }

    private function resolvePreferredPkpAutoTaxId(?int $productId = null, ?array $productPayload = null): ?int
    {
        if ($this->cart_instance !== 'purchase' || ! $this->isPkp) {
            return null;
        }

        if ($productId) {
            $productTaxId = $this->resolveProductPurchaseTaxIdForProduct($productId, $productPayload);
            if ($productTaxId) {
                return $productTaxId;
            }

            return $this->resolveDefaultTaxId();
        }

        return null;
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $cart_items = Cart::instance($this->cart_instance)->content();

        $this->perfLog('render() called at: ' . round(microtime(true) * 1000), [
            'cart_instance' => $this->cart_instance,
            'cart_items' => $cart_items,
        ]);
        // Initialize totals
        $grand_total_before_tax = 0;
        $product_tax_amount = 0;
        $total_sub_total = 0;

        foreach ($cart_items as $item) {
            $grand_total_before_tax += $item->options->sub_total_before_tax ?? 0;
            $sub_total = $item->options->sub_total ?? 0;
            $sub_total_before_tax = $item->options->sub_total_before_tax ?? 0;

            // Calculate the tax amount for the item
            $product_tax_amount += $sub_total - $sub_total_before_tax;
            $total_sub_total += $sub_total;
        }

        $raw = $this->global_discount;
        $this->global_discount = is_numeric($raw) ? (float) $raw : 0;

        // Calculate global discount amount
        if ($this->global_discount_type == 'percentage') {
            $global_discount_amount = $total_sub_total * ($this->global_discount/100);
        } else {
            $global_discount_amount = $this->global_discount;
        }

        // Apply discount and shipping to calculate grand total
        $grand_total = ($total_sub_total - $global_discount_amount) + (float) $this->shipping;

        // Log the final totals for debugging
        $this->perfLog('Final totals calculated', [
            'grand_total' => $grand_total,
            'total_sub_total' => $total_sub_total,
            'global_discount' => $this->global_discount,
            'global_discount_amount' => $global_discount_amount,
            'shipping' => $this->shipping,
        ]);

        return view('livewire.purchase.product-cart', [
            'cart_items' => $cart_items,
            'grand_total' => $grand_total,
            'taxes' => $this->taxes,
            'product_tax_total' => $product_tax_amount,
            'grand_total_before_tax' => $grand_total_before_tax,
            'total_sub_total' => $total_sub_total,
            'global_discount_amount' => $global_discount_amount,
        ]);
    }

    public function productSelected($product): void
    {
        $cart = Cart::instance($this->cart_instance);

        $exists = $cart->search(function ($cartItem, $rowId) use ($product) {
            return $cartItem->id == $product['id'];
        });

        if ($exists->isNotEmpty()) {
            session()->flash('message', 'Produk sudah dimasukkan!');
            return;
        }

        $this->product = $product;

        $calc = $this->calculate($product);
        $defaultTaxId = $this->resolvePreferredPkpAutoTaxId((int) ($product['id'] ?? 0), $product);
        $taxCalculation = $this->calculateSubtotalAndTax(
            $calc['price'],
            1,
            0,
            $defaultTaxId
        );

        $cartItem = $cart->add([
            'id'     => $product['id'],
            'name'   => $product['product_name'],
            'qty'    => 1,
            'price'  => $calc['price'],   // unit price (may already include tax depending on flag)
            'weight' => 1,
            'options' => [
                'product_discount'        => 0.00,
                'product_discount_input'  => 0.00,
                'product_discount_type'   => 'fixed',
                'sub_total'               => $taxCalculation['sub_total'],
                'sub_total_before_tax'    => $taxCalculation['sub_total_before_tax'],
                'product_tax_amount'              => $taxCalculation['product_tax_amount'],
                'code'                    => $product['product_code'],
                'stock'                   => $product['product_quantity'],
                'unit'                    => $product['product_unit'],
                'last_purchase_price'     => $calc['last_purchase_price'],
                'average_purchase_price'  => $calc['average_purchase_price'],
                'product_tax'             => $defaultTaxId, // default per-product tax if any
                'unit_price'              => $calc['unit_price'],
                'pricing_source'          => 'automatic',
                // Newly added automatic row: the backend must price it.
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ],
        ]);

        $this->initializeCartItemAttributes($cartItem);
    }

    public function removeItem($row_id): void
    {
        $cart_item = Cart::instance($this->cart_instance)->content()->get($row_id);
        Cart::instance($this->cart_instance)->remove($row_id);
        if ($cart_item) {
            $rowId = $cart_item->rowId;
            unset($this->check_quantity[$rowId]);
            unset($this->quantity[$rowId]);
            unset($this->unit_price[$rowId]);
            unset($this->line_total[$rowId]);
            unset($this->discount_type[$rowId]);
            unset($this->item_discount[$rowId]);
            unset($this->product_tax[$rowId]);
            unset($this->selected_unit[$rowId]);
            unset($this->available_units[$rowId]);
            unset($this->quantityBreakdowns[$rowId]);
        }
    }

    public function updatedGlobalTax(): void
    {
        // Recalculate when global tax changes
        // Ensure that the new global tax is applied
        $this->recalculateCart();
    }

    public function updatedGlobalDiscount(): void
    {

        $this->recalculateCart();
    }

    private function resolveExactUnitPrice($cartItem): float
    {
        $factor = (float) ($cartItem->options->conversion_factor ?? 1.0);
        if ($factor <= 0) {
            $factor = 1.0;
        }

        $canonicalUnitPrice = $cartItem->options->canonical_unit_price ?? null;
        if (is_numeric($canonicalUnitPrice) && (float) $canonicalUnitPrice > 0) {
            return (float) $canonicalUnitPrice * $factor;
        }

        $storedSubTotal = $cartItem->options->sub_total_before_tax ?? $cartItem->options->sub_total ?? null;
        $qty = (float) $cartItem->qty;
        if (is_numeric($storedSubTotal) && $qty > 0) {
            $discountPerUnit = (float) ($cartItem->options->product_discount ?? 0);
            $grossSubTotal = ((float) $storedSubTotal) + ($discountPerUnit * $qty);
            if ($grossSubTotal > 0) {
                return $grossSubTotal / $qty;
            }
        }

        return (float) $cartItem->price;
    }

    public function updateQuantityDirect($row_id, $product_id, $value): void
    {
        $this->updateQuantity($row_id, $product_id, $value);
    }

    public function updateQuantity($row_id, $product_id, $newQty = null): void
    {
        $cart_item = Cart::instance($this->cart_instance)->content()->get($row_id);
        if (! $cart_item && $product_id) {
            $cart_item = Cart::instance($this->cart_instance)->search(fn ($item) => $item->id == $product_id)->first();
        }
        if (!$cart_item) return;
        $row_id = $cart_item->rowId;
        $productId = (int) $cart_item->id;

        if ($newQty !== null) {
            $this->quantity[$row_id] = max(0.001, (float) $newQty);
        }

        $qty = (float) ($this->quantity[$row_id] ?? $cart_item->qty);

        // Max quantity check
        $checkQty = $this->check_quantity[$row_id] ?? null;
        if ($checkQty !== null && $qty > $checkQty) {
            $this->quantity[$row_id] = $checkQty;
            $qty = $checkQty;
            session()->flash('message', 'Jumlah kuantitas tidak boleh melebihi stok yang tersedia!');
        }

        $exactUnitPrice = $this->resolveExactUnitPrice($cart_item);
        $displayUnitPrice = round($exactUnitPrice, 2);
        $this->unit_price[$row_id] = $displayUnitPrice;

        $pricingSource = $cart_item->options->pricing_source ?? 'automatic';
        $discountAmount = (float) ($cart_item->options->product_discount ?? 0);

        $calculated = $this->calculateSubtotalAndTax(
            $exactUnitPrice,
            $qty,
            $discountAmount,
            $this->syncProductTaxState($cart_item),
            $pricingSource
        );

        $updatedCartItem = Cart::instance($this->cart_instance)->update($row_id, [
            'qty' => $qty,
            'price' => $displayUnitPrice,
            'unit_price' => $displayUnitPrice,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $calculated['sub_total'],
                'sub_total_before_tax' => $calculated['sub_total_before_tax'],
                'product_tax_amount' => $calculated['product_tax_amount'],
                'entered_quantity' => $qty,
                'entered_unit_price' => $displayUnitPrice,
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ]),
        ]);

        $newRowId = $updatedCartItem->rowId;
        $this->migrateRowKeys($row_id, $newRowId);

        $this->line_total[$newRowId] = $calculated['sub_total'];
        $this->recalculateCart();
        $this->quantity[$newRowId] = $qty;
        $this->unit_price[$newRowId] = $displayUnitPrice;
        $this->line_total[$newRowId] = $calculated['sub_total'];
        $this->quantityBreakdowns[$newRowId] = $this->calculateConversionBreakdown(
            $productId,
            $qty
        );
        $this->recalculateCart();
    }

    /**
     * Calculate subtotal and tax for a cart item.
     *
     * @param float $price Per unit price
     * @param int $qty Quantity
     * @param float $discount Per unit discount
     * @param int|null $tax_id Tax ID
     * @return array
     */
    private function calculateSubtotalAndTax($price, $qty, $discount = 0, $tax_id = null, string $pricingSource = 'automatic')
    {
        // Validate inputs
        $price = max(0, (float) $price);
        $qty = max(1, (int) $qty);
        $discount = max(0.0, (float) $discount);
        $tax_id = ($this->cart_instance === 'purchase' && ! $this->isPkp) ? null : $tax_id;

        $effective_price = max(0.0, $price - $discount);

        // Initialize variables
        $subtotal_before_tax = 0;
        $tax_amount = 0;

        if ($this->is_tax_included) {
            // Case: Tax is included in the price
            if ($tax_id) {
                $tax = $this->taxes->find($tax_id);
                if ($tax) {
                    // Calculate price excluding tax
                    $price_ex_tax = $effective_price / (1 + $tax->value / 100);
                    $tax_amount_per_unit = $effective_price - $price_ex_tax;
                    $tax_amount = $tax_amount_per_unit * $qty;
                    $subtotal_before_tax = $price_ex_tax * $qty;
                } else {
                    // No tax applied, discount only
                    $subtotal_before_tax = $effective_price * $qty;
                }
            } else {
                // No tax applied
                $subtotal_before_tax = $effective_price * $qty;
            }
        } else {
            // Case: Tax is not included in the price
            $subtotal_before_tax = $effective_price * $qty;

            if ($tax_id) {
                $tax = $this->taxes->find($tax_id);
                if ($tax) {
                    // Calculate tax on subtotal before tax
                    $tax_amount = $subtotal_before_tax * ($tax->value / 100);
                } else {
                }
            }
        }

        $raw_subtotal = round($subtotal_before_tax + $tax_amount, 2);

        $isManuallyPriced = in_array($pricingSource, ['manual_unit_price', 'manual_line_total', 'manual']);
        $settingIncrement = (float) (Setting::query()->whereKey((int) ($this->setting_id ?: session('setting_id')))->value('row_total_rounding_increment') ?? 100.00);

        if (!$isManuallyPriced && $settingIncrement > 0) {
            $rounded_subtotal = \App\Support\RowTotalRoundingCalculator::round($raw_subtotal, $settingIncrement);
        } else {
            $rounded_subtotal = $raw_subtotal;
        }

        if ($rounded_subtotal !== $raw_subtotal && $this->is_tax_included && $tax_id && isset($tax)) {
            $price_ex_tax = $rounded_subtotal / (1 + $tax->value / 100);
            $subtotal_before_tax = round($price_ex_tax, 2);
            $tax_amount = round($rounded_subtotal - $subtotal_before_tax, 2);
        } elseif ($rounded_subtotal !== $raw_subtotal && !$this->is_tax_included && $tax_id && isset($tax)) {
            $subtotal_before_tax = round($rounded_subtotal / (1 + $tax->value / 100), 2);
            $tax_amount = round($rounded_subtotal - $subtotal_before_tax, 2);
        } elseif ($rounded_subtotal !== $raw_subtotal) {
            $subtotal_before_tax = $rounded_subtotal;
            $tax_amount = 0.0;
        }

        // Return recalculated values
        $roundedSubtotalBeforeTax = round($subtotal_before_tax, 2);
        return [
            'sub_total' => $rounded_subtotal, // Total with tax
            'tax_amount' => $tax_amount,
            'product_tax_amount' => $tax_amount,                      // Tax amount
            'sub_total_before_tax' => $roundedSubtotalBeforeTax,    // Total without tax
            'subtotal_before_tax' => $roundedSubtotalBeforeTax,
        ];
    }

    private function allocateTaxFromAuthoritativeTotal(float $committedTotal, ?int $taxId): array
    {
        $committedTotal = round($committedTotal, 2);

        $taxRate = 0;
        if ($taxId) {
            $tax = $this->taxes->find($taxId);
            if ($tax) {
                $taxRate = $tax->value / 100;
            }
        }

        if (!$taxId || $taxRate == 0) {
            return [
                'sub_total' => $committedTotal,
                'sub_total_before_tax' => $committedTotal,
                'product_tax_amount' => 0.0,
            ];
        }

        if ($this->is_tax_included) {
            $subtotal_before_tax = round($committedTotal / (1 + $taxRate), 2);
            $product_tax_amount = $committedTotal - $subtotal_before_tax;
        } else {
            $subtotal_before_tax = round($committedTotal / (1 + $taxRate), 2);
            $product_tax_amount = $committedTotal - $subtotal_before_tax;
        }

        return [
            'sub_total' => $committedTotal,
            'sub_total_before_tax' => $subtotal_before_tax,
            'product_tax_amount' => $product_tax_amount,
        ];
    }

    public function updatedDiscountType($value, $name)
    {
        $this->item_discount[$name] = 0;
    }

    public function discountModalRefresh($product_id, $row_id)
    {
        $this->updateQuantity($row_id, $product_id);
    }

    public function setDiscountType($row_id, $product_id, $discount_type): void
    {
        $this->discount_type[$row_id] = $discount_type;
        $this->setProductDiscount($row_id, $product_id);
    }

    public function setProductDiscount($row_id, $product_id): void
    {
        $cart_item = Cart::instance($this->cart_instance)->content()->get($row_id);
        if (! $cart_item && $product_id) {
            $cart_item = Cart::instance($this->cart_instance)->search(fn ($item) => $item->id == $product_id)->first();
        }
        if (!$cart_item) return;
        $row_id = $cart_item->rowId;

        $exactUnitPrice = $this->resolveExactUnitPrice($cart_item);
        $displayUnitPrice = round($exactUnitPrice, 2);
        $quantity = $cart_item->qty;

        $raw_discount_input = $this->item_discount[$row_id] ?? 0;
        $sanitized_discount_input = is_numeric($raw_discount_input) ? (float) $raw_discount_input : 0;

        $discType = $this->discount_type[$row_id] ?? 'fixed';

        // Limit between 0 and 100
        if ($discType === 'percentage') {
            if ($sanitized_discount_input > 100) {
                $sanitized_discount_input = 100;
                $this->item_discount[$row_id] = 100;
                session()->flash('message', 'Diskon tidak boleh lebih dari 100%');
            } elseif ($sanitized_discount_input < 0) {
                $sanitized_discount_input = 0;
                $this->item_discount[$row_id] = 0;
                session()->flash('message', 'Diskon tidak boleh kurang dari 0%');
            }
        }

        $this->item_discount[$row_id] = round($sanitized_discount_input, 2);

        $discount_amount = 0;
        if ($discType == 'fixed') {
            $discount_amount = $sanitized_discount_input;
        } elseif ($discType == 'percentage') {
            $discount_amount = $exactUnitPrice * ($sanitized_discount_input / 100);
        }

        if ($discount_amount > $exactUnitPrice) {
            $discount_amount = $exactUnitPrice;
        }

        $pricingSource = $cart_item->options->pricing_source ?? 'automatic';
        $updated_cart_data = $this->calculateSubtotalAndTax(
            $exactUnitPrice,
            $quantity,
            $discount_amount,
            $this->syncProductTaxState($cart_item),
            $pricingSource
        );

        $updatedCartItem = Cart::instance($this->cart_instance)->update($row_id, [
            'price' => $displayUnitPrice,
            'unit_price' => $displayUnitPrice,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $updated_cart_data['sub_total'],
                'sub_total_before_tax' => $updated_cart_data['sub_total_before_tax'],
                'product_tax_amount' => $updated_cart_data['product_tax_amount'],
                'product_discount' => $discount_amount,
                'product_discount_input' => $this->item_discount[$row_id],
                'product_discount_type' => $discType,
                'entered_product_discount_amount' => $this->item_discount[$row_id],
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ]),
        ]);

        $newRowId = $updatedCartItem->rowId;
        $this->migrateRowKeys($row_id, $newRowId);

        $this->line_total[$newRowId] = $updated_cart_data['sub_total'];
        $this->recalculateCart();
        session()->flash('discount_message' . $newRowId, 'Diskon berhasil diterapkan!');
    }

    public function updatePrice($row_id, $product_id): void
    {
        $cart_item = Cart::instance($this->cart_instance)->content()->get($row_id);
        if (! $cart_item && $product_id) {
            $cart_item = Cart::instance($this->cart_instance)->search(fn ($item) => $item->id == $product_id)->first();
        }
        if (!$cart_item) return;
        $row_id = $cart_item->rowId;

        // Validate and set new price
        $new_price = (float) ($this->unit_price[$row_id] ?? $cart_item->price);
        $factor = (float) ($cart_item->options->conversion_factor ?? 1.0);
        if ($factor <= 0) {
            $factor = 1.0;
        }
        $canonicalUnitPrice = $new_price / $factor;

        // if percentage, recalculate discount amount
        $discount_amount = (float) ($cart_item->options->product_discount ?? 0);
        $raw_discount_input = $this->item_discount[$row_id] ?? 0;
        $sanitized_discount_input = is_numeric($raw_discount_input) ? (float) $raw_discount_input : 0;
        $discType = $this->discount_type[$row_id] ?? 'fixed';
        if ($discType == 'percentage') {
            $discount_amount = $new_price * ($sanitized_discount_input / 100);
        }

        // Manual price update sets pricing_source to manual_unit_price
        $pricingSource = 'manual_unit_price';

        // Use calculateSubtotalAndTax function to calculate
        $calculated = $this->calculateSubtotalAndTax(
            $new_price,
            $cart_item->qty,
            $discount_amount,
            $this->syncProductTaxState($cart_item),
            $pricingSource
        );

        // Update cart item
        $updatedCartItem = Cart::instance($this->cart_instance)->update($row_id, [
            'price' => $new_price,
            'unit_price' => $new_price,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $calculated['sub_total'],
                'sub_total_before_tax' => $calculated['sub_total_before_tax'],
                'product_tax_amount' => $calculated['product_tax_amount'],
                'product_discount' => $discount_amount,
                'product_discount_input' => $sanitized_discount_input,
                'entered_unit_price' => $new_price,
                'canonical_unit_price' => $canonicalUnitPrice,
                'pricing_source' => $pricingSource,
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => false,
            ]),
        ]);

        $newRowId = $updatedCartItem->rowId;
        $this->migrateRowKeys($row_id, $newRowId);

        $this->line_total[$newRowId] = $calculated['sub_total'];
        $this->recalculateCart();
    }

    public function updateLineTotal($row_id, $product_id): void
    {
        $cart_item = Cart::instance($this->cart_instance)->content()->get($row_id);
        if (! $cart_item && $product_id) {
            $cart_item = Cart::instance($this->cart_instance)->search(fn ($item) => $item->id == $product_id)->first();
        }
        if (!$cart_item) return;
        $row_id = $cart_item->rowId;

        $requestedLineTotal = $this->line_total[$row_id] ?? $cart_item->options->sub_total;

        if (!is_numeric($requestedLineTotal) || $requestedLineTotal < 0) {
            $this->line_total[$row_id] = $cart_item->options->sub_total;
            session()->flash('message', 'Total baris tidak valid.');
            return;
        }

        $requestedLineTotal = (float) $requestedLineTotal;

        $qty = max(1, (int) $cart_item->qty);
        $tax_id = $this->syncProductTaxState($cart_item);

        // 1. Find tax rate
        $taxRate = 0;
        if ($tax_id) {
            $tax = $this->taxes->find($tax_id);
            if ($tax) {
                $taxRate = $tax->value / 100;
            }
        }

        // 2. Find effective price (per unit, before line tax but after discount)
        if ($this->is_tax_included) {
            $effective_price = $requestedLineTotal / $qty;
        } else {
            $effective_price = ($requestedLineTotal / $qty) / (1 + $taxRate);
        }

        // 3. Reverse discount to find base unit price
        $discount_type = $this->discount_type[$row_id] ?? 'fixed';
        $discount_input = $this->item_discount[$row_id] ?? 0;

        if ($discount_type === 'percentage') {
            $pct = min(100, max(0, (float) $discount_input)) / 100;
            if ($pct >= 1.0) {
                // 100% or more discount: only allow zero total
                if ($requestedLineTotal > 0.01) {
                    $this->line_total[$row_id] = $cart_item->options->sub_total;
                    session()->flash('message', 'Total baris lebih dari 0 tidak dimungkinkan dengan diskon 100%.');
                    return;
                }
                $base_price = 0;
            } else {
                $base_price = $effective_price / (1 - $pct);
            }
        } else {
            $base_price = $effective_price + (float) $discount_input;
        }

        $this->unit_price[$row_id] = round($base_price, 2);

        $allocated = $this->allocateTaxFromAuthoritativeTotal($requestedLineTotal, $tax_id);

        $discount_amount = $cart_item->options->product_discount ?? 0;
        if ($discount_type === 'percentage') {
            $discount_pct = min(100, max(0, (float) $discount_input)) / 100;
            $discount_amount = round($this->unit_price[$row_id] * $discount_pct, 2);
        }

        $updatedCartItem = Cart::instance($this->cart_instance)->update($row_id, [
            'price' => $this->unit_price[$row_id],
            'unit_price' => $this->unit_price[$row_id],
            'options' => array_merge($cart_item->options->toArray(), [
                'unit_price' => $this->unit_price[$row_id],
                'sub_total' => $allocated['sub_total'],
                'sub_total_before_tax' => $allocated['sub_total_before_tax'],
                'product_tax_amount' => $allocated['product_tax_amount'],
                'product_discount' => $discount_amount,
                'product_discount_input' => $discount_input,
                'product_discount_type' => $discount_type,
                'pricing_source' => 'manual_line_total',
                // The committed manual total is authoritative: never reconstructed.
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => false,
            ]),
        ]);

        $newRowId = $updatedCartItem->rowId;
        $this->migrateRowKeys($row_id, $newRowId);

        $this->line_total[$newRowId] = $allocated['sub_total'];
        $this->recalculateCart();
    }

    public function calculate(array $product, $new_price = null): array
    {
        $productId = (int) ($product['id'] ?? 0);

        $pp = ProductPrice::query()
            ->forProduct($productId)
            ->forSetting((int) $this->setting_id)
            ->with('purchaseTax')
            ->first();

        $unitPrice   = $new_price ?? ($pp?->last_purchase_price ?? ($product['last_purchase_price'] ?? 0));
        $avgPurchase = $pp?->average_purchase_price ?? ($product['average_purchase_price'] ?? null);
        $purchaseTaxId = $this->isPkp
            ? ($pp?->purchase_tax_id ?? ($product['purchase_tax_id'] ?? null))
            : null;
        $purchaseTaxId = $purchaseTaxId ? (int) $purchaseTaxId : null;

        // Qty=1 when adding the first time; use your existing calculator
        $calc = $this->calculateSubtotalAndTax(
            $unitPrice,
            1,                // qty
            0,                // per-unit discount on add
            $purchaseTaxId    // default purchase tax if present
        );

        return [
            'price'                   => (float) $unitPrice,
            'unit_price'              => (float) $unitPrice,
            'last_purchase_price'     => (float) $unitPrice,
            'average_purchase_price'  => $avgPurchase,
            'product_tax'             => $purchaseTaxId,                 // may be null
            'sub_total'               => $calc['sub_total'],
            'sub_total_before_tax'    => $calc['sub_total_before_tax'],
            'product_tax_amount'              => $calc['product_tax_amount'],
        ];
    }

    public function updateCartOptions($row_id, $product_id, $cart_item, $discount_amount): void
    {
        Cart::instance($this->cart_instance)->update($row_id, ['options' => [
            'sub_total'             => $cart_item->price * $cart_item->qty,
            'code'                  => $cart_item->options->code,
            'stock'                 => $cart_item->options->stock,
            'unit'                  => $cart_item->options->unit,
            'product_tax'           => $cart_item->options->product_tax,
            'unit_price'            => $cart_item->options->unit_price,
            'product_discount'      => $discount_amount,
            'product_discount_input'=> $this->item_discount[$row_id] ?? $discount_amount,
            'product_discount_type' => $this->discount_type[$row_id] ?? 'fixed',
            'last_purchase_price'   => $cart_item->options->last_purchase_price, // Preserve
            'average_purchase_price'=> $cart_item->options->average_purchase_price, // Preserve
        ]]);
    }

    public function recalculateCart()
    {
        foreach (Cart::instance($this->cart_instance)->content() as $item) {
            $this->line_total[$item->rowId] = $item->options->sub_total;
        }

        // Trigger a re-render to update totals
        $this->render();
    }

    public function updatedShipping()
    {
        $this->dispatch('shippingUpdated', $this->shipping);
    }

    public function updateTax($row_id, $product_id, $selectedTaxId = null)
    {
        $cart_item = Cart::instance($this->cart_instance)->content()->get($row_id);
        if (! $cart_item && $product_id) {
            $cart_item = Cart::instance($this->cart_instance)->search(fn ($item) => $item->id == $product_id)->first();
        }
        if (!$cart_item) return;
        $row_id = $cart_item->rowId;

        // Normalize the explicit selected value so this handler does not depend on deferred state.
        $tax_id = $this->normalizeTaxId($selectedTaxId);
        $this->product_tax[$row_id] = $tax_id;

        $exactUnitPrice = $this->resolveExactUnitPrice($cart_item);
        $displayUnitPrice = round($exactUnitPrice, 2);
        $pricingSource = $cart_item->options->pricing_source ?? 'manual';

        $updated_cart_data = $this->calculateSubtotalAndTax(
            $exactUnitPrice,
            $cart_item->qty,
            $cart_item->options->product_discount ?? 0,
            $tax_id,
            $pricingSource
        );

        $updatedCartItem = Cart::instance($this->cart_instance)->update($row_id, [
            'price' => $displayUnitPrice,
            'unit_price' => $displayUnitPrice,
            'options' => array_merge($cart_item->options->toArray(), [
                'product_tax' => $tax_id,
                'sub_total' => $updated_cart_data['sub_total'],
                'sub_total_before_tax' => $updated_cart_data['sub_total_before_tax'],
                'product_tax_amount' => $updated_cart_data['product_tax_amount'],
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ]),
        ]);

        $newRowId = $updatedCartItem->rowId;
        $this->migrateRowKeys($row_id, $newRowId);

        $this->line_total[$newRowId] = $updated_cart_data['sub_total'];
        $this->recalculateCart();
    }

    public function handleTaxIncluded()
    {
        $cart_items = Cart::instance($this->cart_instance)->content();

        $this->dispatch('taxIncludedUpdated', $this->is_tax_included);

        foreach ($cart_items as $cart_item) {
            $product_id = $cart_item->id;
            $row_id = $cart_item->rowId;

            // Retrieve required data for calculations
            $price = $cart_item->price;
            $quantity = $cart_item->qty;
            $discount = $cart_item->options->product_discount ?? 0;
            $tax_id = $this->syncProductTaxState($cart_item);
            $pricingSource = $cart_item->options->pricing_source ?? 'manual';

            // Calculate subtotal and tax using the helper function
            $calculated = $this->calculateSubtotalAndTax($price, $quantity, $discount, $tax_id, $pricingSource);

            // Update the cart item with the calculated values
            Cart::instance($this->cart_instance)->update($row_id, [
                'options' => array_merge($cart_item->options->toArray(), [
                    'product_tax' => $tax_id,
                    'sub_total' => $calculated['sub_total'],
                    'sub_total_before_tax' => $calculated['sub_total_before_tax'],
                    'product_tax_amount' => $calculated['product_tax_amount'],
                    \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
                ]),
            ]);

            $this->line_total[$product_id] = $calculated['sub_total'];
        }

        // Recalculate cart totals
        $this->recalculateCart();
    }

    public function setGlobalDiscountType($type): void
    {
        $this->global_discount_type = $type;
        $this->dispatch('globalDiscountTypeUpdated', $this->global_discount_type);
        $this->updateGlobalDiscount(); // Ensure recalculation happens
    }

    public function updateGlobalDiscount(): void
    {
        $raw = $this->global_discount;
        $this->global_discount = is_numeric($raw) ? (float) $raw : 0;

        $total_sub_total = 0;
        $cart_items = Cart::instance($this->cart_instance)->content();

        foreach ($cart_items as $item) {
            $total_sub_total += $item->options->sub_total ?? 0;
        }

        if ($this->global_discount_type === 'percentage') {
            if ($this->global_discount > 100) {
                $this->global_discount = 100;
                session()->flash('message', 'Diskon global tidak boleh lebih dari 100%');
            } elseif ($this->global_discount < 0) {
                $this->global_discount = 0;
                session()->flash('message', 'Diskon global tidak boleh kurang dari 0%');
            }
        } else { // fixed
            if ($this->global_discount > $total_sub_total) {
                $this->global_discount = $total_sub_total;
                session()->flash('message', 'Diskon global tidak boleh melebihi total setelah pajak!');
            } elseif ($this->global_discount < 0) {
                $this->global_discount = 0;
                session()->flash('message', 'Diskon global tidak boleh kurang dari 0!');
            }
        }
        $this->dispatch('globalDiscountUpdated', $this->global_discount);
        $this->recalculateCart();
    }

    public function updateUnit(string $rowId, string $unitKey): void
    {
        if ($this->monetaryOnly) {
            return;
        }

        $cart_item = Cart::instance($this->cart_instance)->content()->get($rowId);
        if (! $cart_item) {
            return;
        }

        $units = $this->available_units[$rowId] ?? [];
        if (empty($units)) {
            $units = $this->getAvailableUnitsForCartItem($cart_item);
            $this->available_units[$rowId] = $units;
        }

        $selectedOption = collect($units)->firstWhere('id', $unitKey);
        if (! $selectedOption) {
            return;
        }

        $oldFactorBd = \Brick\Math\BigDecimal::of((string) (data_get($cart_item->options, 'conversion_factor') ?? 1.0));
        if ($oldFactorBd->compareTo(\Brick\Math\BigDecimal::of('0')) <= 0) {
            $oldFactorBd = \Brick\Math\BigDecimal::of('1');
        }

        $newFactorBd = \Brick\Math\BigDecimal::of((string) ($selectedOption['factor'] ?? 1.0));
        if ($newFactorBd->compareTo(\Brick\Math\BigDecimal::of('0')) <= 0) {
            $newFactorBd = \Brick\Math\BigDecimal::of('1');
        }

        $currentQtyFloat = (float) ($this->quantity[$rowId] ?? $cart_item->qty);
        $currentPriceFloat = (float) ($this->unit_price[$rowId] ?? $cart_item->price);

        $qtyBd = \Brick\Math\BigDecimal::of((string) $currentQtyFloat);
        $priceBd = \Brick\Math\BigDecimal::of((string) $currentPriceFloat);

        // 1. Calculate canonical quantity (qty * oldFactor)
        $canonicalQtyBd = $qtyBd->multipliedBy($oldFactorBd);

        // 2. Convert to new unit quantity (canonicalQty / newFactor)
        try {
            $newQtyBd = $canonicalQtyBd->dividedBy($newFactorBd, 6, \Brick\Math\RoundingMode::HALF_UP);
        } catch (\Exception $e) {
            session()->flash('message', 'Jumlah kuantitas tidak dapat dikonversi ke satuan yang dipilih.');
            $selectedConvId = data_get($cart_item->options, 'product_unit_conversion_id');
            $unitId = data_get($cart_item->options, 'purchase_unit_id');
            $this->selected_unit[$rowId] = $selectedConvId ? 'conv_' . $selectedConvId : ('base_' . ($unitId ?? 1));
            return;
        }

        // Reject unsupported quantity precision (scale > 3)
        if ($newQtyBd->stripTrailingZeros()->getScale() > 3) {
            session()->flash('message', 'Jumlah kuantitas (' . $newQtyBd->toFloat() . ') tidak dapat dikonversi ke satuan ' . $selectedOption['name'] . ' tanpa melebihi batas 3 angka di belakang koma.');
            $selectedConvId = data_get($cart_item->options, 'product_unit_conversion_id');
            $unitId = data_get($cart_item->options, 'purchase_unit_id');
            $this->selected_unit[$rowId] = $selectedConvId ? 'conv_' . $selectedConvId : ('base_' . ($unitId ?? 1));
            return;
        }

        $newQty = $newQtyBd->toFloat();

        $oldFactorFloat = $oldFactorBd->toFloat();
        $newFactorFloat = $newFactorBd->toFloat();
        $currentQtyFloat = (float) ($this->quantity[$rowId] ?? $cart_item->qty);
        $currentPriceFloat = (float) ($this->unit_price[$rowId] ?? $cart_item->price);

        $canonicalBaseUnitPrice = data_get($cart_item->options, 'canonical_unit_price');
        if (! is_numeric($canonicalBaseUnitPrice) || (float) $canonicalBaseUnitPrice <= 0) {
            $canonicalBaseUnitPrice = $currentPriceFloat / $oldFactorFloat;
        } else {
            $canonicalBaseUnitPrice = (float) $canonicalBaseUnitPrice;
        }

        $newUnitPriceExact = $canonicalBaseUnitPrice * $newFactorFloat;
        $newPriceDisplay = round($newUnitPriceExact, 2);

        $discType = data_get($cart_item->options, 'product_discount_type') ?? $this->discount_type[$rowId] ?? 'fixed';
        $oldDiscountInput = (float) ($this->item_discount[$rowId] ?? data_get($cart_item->options, 'product_discount_input') ?? data_get($cart_item->options, 'entered_product_discount_amount') ?? 0);
        $oldDiscountAmount = (float) (data_get($cart_item->options, 'product_discount') ?? 0);

        if ($discType === 'percentage') {
            $newDiscountInput = $oldDiscountInput;
            $newDiscountAmount = $newUnitPriceExact * ($newDiscountInput / 100);
        } else {
            $oldDiscountPerUnit = $oldDiscountAmount > 0 ? $oldDiscountAmount : $oldDiscountInput;
            $newDiscountAmount = $oldDiscountPerUnit * ($newFactorFloat / $oldFactorFloat);
            $newDiscountInput = round($newDiscountAmount, 2);
        }

        $productModel = Product::with('baseUnit')->find($cart_item->id);
        $baseUnitName = $productModel?->baseUnit?->name ?? 'PCS';

        $calculated = $this->calculateSubtotalAndTax(
            $newUnitPriceExact,
            $newQty,
            $newDiscountAmount,
            $this->syncProductTaxState($cart_item),
            'manual_unit_price'
        );

        $updatedCartItem = Cart::instance($this->cart_instance)->update($rowId, [
            'qty' => $newQty,
            'price' => $newPriceDisplay,
            'unit_price' => $newPriceDisplay,
            'options' => array_merge($cart_item->options->toArray(), [
                'purchase_unit_id' => $selectedOption['unit_id'],
                'product_unit_conversion_id' => $selectedOption['product_unit_conversion_id'],
                'conversion_factor' => $newFactorFloat,
                'unit_name' => $selectedOption['name'],
                'base_unit_name' => $baseUnitName,
                'entered_quantity' => $newQty,
                // Carry the exact entered price, not the display-rounded one: at factor 1
                // the entered price *is* the canonical cost, so rounding here would
                // permanently lose precision for a repeating conversion price
                // (100,000 / 3). Normalization re-derives cost from this value, and the
                // rounded form remains what the input displays.
                'entered_unit_price' => $newUnitPriceExact,
                'unit_price' => $newUnitPriceExact,
                'pricing_source' => 'manual_unit_price',
                'entered_product_discount_amount' => $newDiscountInput,
                'product_discount' => $newDiscountAmount,
                'product_discount_input' => $newDiscountInput,
                'product_discount_type' => $discType,
                'canonical_unit_price' => $canonicalBaseUnitPrice,
                'sub_total' => $calculated['sub_total'],
                'sub_total_before_tax' => $calculated['sub_total_before_tax'],
                'product_tax_amount' => $calculated['product_tax_amount'],
                \App\Support\RowTotalRoundingCalculator::RECALC_FLAG => true,
            ]),
        ]);

        $newRowId = $updatedCartItem->rowId;

        $this->migrateRowKeys($rowId, $newRowId);

        $this->quantity[$newRowId] = $newQty;
        $this->unit_price[$newRowId] = $newPriceDisplay;
        $this->selected_unit[$newRowId] = $unitKey;
        $this->available_units[$newRowId] = $units;
        $this->discount_type[$newRowId] = $discType;
        $this->item_discount[$newRowId] = $newDiscountInput;
        $this->line_total[$newRowId] = $calculated['sub_total'];
        $this->quantityBreakdowns[$newRowId] = $this->calculateConversionBreakdown((int) $cart_item->id, (int) round($canonicalQtyBd->toFloat()));
        $this->recalculateCart();
    }
}
