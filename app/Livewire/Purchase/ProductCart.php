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
    public $listeners = ['productSelected', 'discountModalRefresh', 'taxCreated' => 'handleTaxCreated'];

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
    public $quantityBreakdowns = [];
    public $product;

    public $taxes; // Collection of available taxes
    public $setting_id; // Current setting ID
    public $product_tax = []; // Array to store selected tax IDs for each product

    public $is_tax_included = true;

    public $global_discount_type = 'percentage';
    public bool $isPkp = false;

    protected $rules = [
        'unit_price.*' => 'required|numeric|min:0', // Unit price per row.
        'quantity.*' => 'required|integer|min:1', // Quantity must be at least 1.
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

    public function mount($cartInstance, $data = null): void
    {
        $this->cart_instance = $cartInstance;
        $cart_items = Cart::instance($this->cart_instance)->content();
        $this->perfLog('mount() called at: ' . round(microtime(true) * 1000), [
            'cart_instance' => $cartInstance,
            'cart_items' => $cart_items,
        ]);
        $this->cart_instance = $cartInstance;
        $this->setting_id = session('setting_id');
        $this->isPkp = (bool) (Setting::query()->whereKey((int) $this->setting_id)->value('is_pkp') ?? false);
        $this->taxes = $this->loadTaxes();
        $this->perfLog('validated', [
            'data' => $data,
        ]);

        if ($data) {
            $this->data = $data;

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

            $cart_items = Cart::instance($this->cart_instance)->content();

            foreach ($cart_items as $cart_item) {
                $this->initializeCartItemAttributes($cart_item);
                $qty = $this->quantity[$cart_item->id] ?? $cart_item->qty;
                $this->quantityBreakdowns[$cart_item->id] = $this->calculateConversionBreakdown($cart_item->id, $qty);
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
                $qty = $this->quantity[$cart_item->id] ?? $cart_item->qty;
                $this->quantityBreakdowns[$cart_item->id] = $this->calculateConversionBreakdown($cart_item->id, $qty);
                // Default discount type to fixed to match legacy behavior
                $this->discount_type[$cart_item->id] = $this->discount_type[$cart_item->id] ?? 'fixed';
            }
        }

        if (! $this->isPkp) {
            $this->is_tax_included = false;
        }

        // Always publish the cart's initial tax state so the parent form persists the same value.
        $this->dispatch('taxIncludedUpdated', (bool) $this->is_tax_included);
        $this->reconcileNonPkpPurchaseCartState();
        $this->reconcileMissingPkpTaxesInCart();
    }

    private function initializeCartItemAttributes($cart_item): void
    {
        $this->check_quantity[$cart_item->id] = $cart_item->options->stock ?? 0;
        $this->quantity[$cart_item->id] = $cart_item->qty ?? 0;
        $this->unit_price[$cart_item->id] = $cart_item->price ?? 0;
        $this->line_total[$cart_item->id] = $cart_item->options->sub_total ?? 0;
        $discountType = $cart_item->options->product_discount_type ?? 'fixed';
        $this->discount_type[$cart_item->id] = $discountType;

        $storedDiscountInput = $cart_item->options->product_discount_input ?? null;
        if (is_numeric($storedDiscountInput)) {
            $displayDiscount = (float) $storedDiscountInput;
        } elseif ($discountType === 'percentage') {
            $price = (float) ($cart_item->price ?? 0);
            $storedAmount = (float) ($cart_item->options->product_discount ?? 0);
            $displayDiscount = $price > 0 ? ($storedAmount / $price) * 100 : 0;
        } else {
            $displayDiscount = (float) ($cart_item->options->product_discount ?? 0);
        }

        $this->item_discount[$cart_item->id] = round(max(0, $displayDiscount), 2);
        $this->product_tax[$cart_item->id] = $cart_item->options->product_tax ?? null;

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
            $cartItem = Cart::instance($this->cart_instance)->get($product_id);
            if ($cartItem) {
                $this->updateTax($cartItem->rowId, $cartItem->id, $id);
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

            $this->product_tax[$productId] = $resolvedTaxId;

            $discountAmount = (float) ($cartItem->options->product_discount ?? 0);
            $calculated = $this->calculateSubtotalAndTax(
                $cartItem->price,
                $cartItem->qty,
                $discountAmount,
                $resolvedTaxId
            );

            $cart->update($cartItem->rowId, [
                'options' => array_merge($cartItem->options->toArray(), [
                    'product_tax' => $resolvedTaxId,
                    'sub_total' => $calculated['sub_total'],
                    'sub_total_before_tax' => $calculated['sub_total_before_tax'],
                    'product_tax_amount' => $calculated['product_tax_amount'],
                ]),
            ]);
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

            $cart->update($cartItem->rowId, [
                'price' => $normalizedUnitPrice,
                'options' => array_merge($cartItem->options->toArray(), [
                    'unit_price' => $normalizedUnitPrice,
                    'product_tax' => null,
                    'sub_total' => $calculated['sub_total'],
                    'sub_total_before_tax' => $calculated['sub_total_before_tax'],
                    'product_tax_amount' => 0,
                ]),
            ]);

            $this->product_tax[$cartItem->id] = null;
        }
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

        $this->line_total[$product['id']] = $taxCalculation['sub_total'];

        $cart->add([
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
            ],
        ]);

        $this->check_quantity[$product['id']]  = $product['product_quantity'];
        $this->quantity[$product['id']]        = 1;
        $this->discount_type[$product['id']]   = 'fixed';
        $this->item_discount[$product['id']]   = 0;
        $this->product_tax[$product['id']]     = $defaultTaxId; // mirror options
        $this->quantityBreakdowns[$product['id']] = $this->calculateConversionBreakdown($product['id'], 1);
    }

    public function removeItem($row_id): void
    {
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);
        Cart::instance($this->cart_instance)->remove($row_id);
        unset($this->quantity[$cart_item->id]);
        unset($this->unit_price[$cart_item->id]);
        unset($this->item_discount[$cart_item->id]);
        unset($this->product_tax[$cart_item->id]);
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

    public function updateQuantityDirect($row_id, $product_id, $value): void
    {
        $newQty = max(1, (int) $value);
        $this->quantity[$product_id] = $newQty;
        $this->updateQuantity($row_id, $product_id);
    }

    public function updateQuantity($row_id, $product_id): void
    {
        $cart_item = Cart::instance($this->cart_instance)->search(function ($item) use ($product_id) {
            return $item->id == $product_id;
        })->first();
        if (!$cart_item) return;
        $row_id = $cart_item->rowId;

        if ($this->quantity[$product_id] <= 0) {
            $this->quantity[$product_id] = 1;
            session()->flash('message', 'Jumlah barang dipesan minimal 1!');
            return;
        }

        if ($this->cart_instance == 'sale' || $this->cart_instance == 'purchase_return') {
            if ($this->check_quantity[$product_id] < $this->quantity[$product_id]) {
                session()->flash('message', 'The requested quantity is not available in stock.');
                $this->quantity[$product_id] = $this->check_quantity[$product_id];
                return;
            }
        }

        // Use calculateSubtotalAndTax function to calculate
        $calculated = $this->calculateSubtotalAndTax(
            $this->unit_price[$product_id] ?? $cart_item->price,
            $this->quantity[$product_id],
            $cart_item->options->product_discount ?? 0,
            $this->syncProductTaxState($cart_item)
        );

        $this->quantityBreakdowns[$product_id] = $this->calculateConversionBreakdown(
            $product_id,
            $this->quantity[$product_id]
        );

        // Update cart item
        Cart::instance($this->cart_instance)->update($row_id, [
            'qty' => $this->quantity[$product_id],
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $calculated['sub_total'],
                'sub_total_before_tax' => $calculated['sub_total_before_tax'],
                'product_tax_amount' => $calculated['product_tax_amount'],
            ]),
        ]);

        $this->line_total[$product_id] = $calculated['sub_total'];
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
    private function calculateSubtotalAndTax($price, $qty, $discount = 0, $tax_id = null)
    {
        // Validate inputs
        $price = max(0, (float) $price); // Ensure price is non-negative
        $qty = max(1, (int) $qty);
        $discount = max(0.0, (float) $discount);

        // Option A: Discount is off the tax-inclusive price (or base price if tax excluded)
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

        // Return recalculated values
        return [
            'sub_total' => $subtotal_before_tax + $tax_amount, // Total with tax
            'product_tax_amount' => $tax_amount,                      // Tax amount
            'sub_total_before_tax' => $subtotal_before_tax,    // Total without tax
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
        $this->discount_type[$product_id] = $discount_type;
        $this->setProductDiscount($row_id, $product_id);
    }

    public function setProductDiscount($row_id, $product_id): void
    {
        $cart_item = Cart::instance($this->cart_instance)->search(function ($item) use ($product_id) {
            return $item->id == $product_id;
        })->first();
        if (!$cart_item) return;
        $row_id = $cart_item->rowId;

        $unit_price = $this->unit_price[$product_id] ?? $cart_item->price;
        $quantity = $cart_item->qty;

        $raw_discount_input = $this->item_discount[$product_id] ?? 0;
        $sanitized_discount_input = is_numeric($raw_discount_input) ? (float) $raw_discount_input : 0;

        // Limit between 0 and 100
        if ($this->discount_type[$product_id] === 'percentage') {
            if ($sanitized_discount_input > 100) {
                $sanitized_discount_input = 100;
                $this->item_discount[$product_id] = 100;
                session()->flash('message', 'Diskon tidak boleh lebih dari 100%');
            } elseif ($sanitized_discount_input < 0) {
                $sanitized_discount_input = 0;
                $this->item_discount[$product_id] = 0;
                session()->flash('message', 'Diskon tidak boleh kurang dari 0%');
            }
        }

        $this->item_discount[$product_id] = round($sanitized_discount_input, 2);

        $discount_amount = 0;
        if ($this->discount_type[$product_id] == 'fixed') {
            $discount_amount = $sanitized_discount_input;
        } elseif ($this->discount_type[$product_id] == 'percentage') {
            $discount_amount = $unit_price * ($sanitized_discount_input / 100);
        }

        if ($discount_amount > $unit_price) {
            $discount_amount = $unit_price;
        }

        $adjusted_price = $unit_price;
        $updated_cart_data = $this->calculateSubtotalAndTax(
            $adjusted_price,
            $quantity,
            $discount_amount,
            $this->syncProductTaxState($cart_item)
        );

        Cart::instance($this->cart_instance)->update($row_id, [
            'unit_price' => $this->is_tax_included ? $adjusted_price : $unit_price,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $updated_cart_data['sub_total'],
                'sub_total_before_tax' => $updated_cart_data['sub_total_before_tax'],
                'product_tax_amount' => $updated_cart_data['product_tax_amount'],
                'product_discount' => $discount_amount,
                'product_discount_input' => $this->item_discount[$product_id],
                'product_discount_type' => $this->discount_type[$product_id],
            ]),
        ]);

        $this->line_total[$product_id] = $updated_cart_data['sub_total'];
        $this->recalculateCart();
        session()->flash('discount_message' . $product_id, 'Diskon berhasil diterapkan!');
    }

    public function updatePrice($row_id, $product_id): void
    {
        $cart_item = Cart::instance($this->cart_instance)->search(function ($item) use ($product_id) {
            return $item->id == $product_id;
        })->first();
        if (!$cart_item) return;
        $row_id = $cart_item->rowId;

        // Validate and set new price
        $new_price = $this->unit_price[$product_id] ?? $cart_item->price;

        // if percentage, recalculate discount amount
        $discount_amount = $cart_item->options->product_discount;
        $raw_discount_input = $this->item_discount[$product_id] ?? 0;
        $sanitized_discount_input = is_numeric($raw_discount_input) ? (float) $raw_discount_input : 0;
        if ($this->discount_type[$product_id] == 'percentage') {
            $discount_amount = $new_price * ($sanitized_discount_input / 100);
        }

        // Use calculateSubtotalAndTax function to calculate
        $calculated = $this->calculateSubtotalAndTax(
            $new_price,
            $cart_item->qty,
            $discount_amount ?? 0,
            $this->syncProductTaxState($cart_item)
        );

        // Update cart item
        Cart::instance($this->cart_instance)->update($row_id, [
            'price' => $new_price,
            'unit_price' => $new_price,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $calculated['sub_total'],
                'sub_total_before_tax' => $calculated['sub_total_before_tax'],
                'product_tax_amount' => $calculated['product_tax_amount'],
                'product_discount' => $discount_amount,
                'product_discount_input' => $sanitized_discount_input,
            ]),
        ]);

        $this->line_total[$product_id] = $calculated['sub_total'];
        $this->recalculateCart();
    }

    public function updateLineTotal($row_id, $product_id): void
    {
        $cart_item = Cart::instance($this->cart_instance)->search(function ($item) use ($product_id) {
            return $item->id == $product_id;
        })->first();
        if (!$cart_item) return;
        $row_id = $cart_item->rowId;

        $requestedLineTotal = $this->line_total[$product_id] ?? $cart_item->options->sub_total;

        if (!is_numeric($requestedLineTotal) || $requestedLineTotal < 0) {
            $this->line_total[$product_id] = $cart_item->options->sub_total;
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
        $discount_type = $this->discount_type[$product_id] ?? 'fixed';
        $discount_input = $this->item_discount[$product_id] ?? 0;

        if ($discount_type === 'percentage') {
            $pct = min(100, max(0, (float) $discount_input)) / 100;
            if ($pct >= 1.0) {
                // 100% or more discount: only allow zero total
                if ($requestedLineTotal > 0.01) {
                    $this->line_total[$product_id] = $cart_item->options->sub_total;
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

        // Let updatePrice handle the recalculation and saving to cart
        $this->unit_price[$product_id] = round($base_price, 2);

        $this->updatePrice($row_id, $product_id);

        // Because of rounding, the new subtotal might be slightly different. Update our local state.
        $updated_cart_item = Cart::instance($this->cart_instance)->search(function ($item) use ($product_id) {
            return $item->id == $product_id;
        })->first();
        if ($updated_cart_item) {
            $this->line_total[$product_id] = $updated_cart_item->options->sub_total;
        }
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
            'product_discount_input'=> $this->item_discount[$product_id] ?? $discount_amount,
            'product_discount_type' => $this->discount_type[$product_id],
            'last_purchase_price'   => $cart_item->options->last_purchase_price, // Preserve
            'average_purchase_price'=> $cart_item->options->average_purchase_price, // Preserve
        ]]);
    }

    public function recalculateCart()
    {
        foreach (Cart::instance($this->cart_instance)->content() as $item) {
            $this->line_total[$item->id] = $item->options->sub_total;
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
        $cart_item = Cart::instance($this->cart_instance)->search(function ($item) use ($product_id) {
            return $item->id == $product_id;
        })->first();
        if (!$cart_item) return;
        $row_id = $cart_item->rowId;

        // Normalize the explicit selected value so this handler does not depend on deferred state.
        $tax_id = $this->normalizeTaxId($selectedTaxId);
        $this->product_tax[$product_id] = $tax_id;

        // Initialize tax amount and validate the tax ID
        $tax_amount = 0;
        if ($tax_id) {
            $tax = Tax::find($tax_id);
            if ($tax) {

                // Use reusable helper to calculate values
                $updated_cart_data = $this->calculateSubtotalAndTax(
                    $cart_item->price,
                    $cart_item->qty,
                    $cart_item->options->product_discount ?? 0,
                    $tax_id
                );

                // Update the cart row
                Cart::instance($this->cart_instance)->update($row_id, [
                    'options' => array_merge($cart_item->options->toArray(), [
                        'product_tax' => $tax_id,
                        'sub_total' => $updated_cart_data['sub_total'],
                        'sub_total_before_tax' => $updated_cart_data['sub_total_before_tax'],
                        'product_tax_amount' => $updated_cart_data['product_tax_amount'],
                    ]),
                ]);

                $this->line_total[$product_id] = $updated_cart_data['sub_total'];
                // Trigger cart recalculation
                $this->recalculateCart();

            } else {
                session()->flash('message', 'Invalid tax selected.');
            }
        } else {
            $updated_cart_data = $this->calculateSubtotalAndTax(
                $cart_item->price,
                $cart_item->qty,
                $cart_item->options->product_discount ?? 0,
                $tax_id
            );

            Cart::instance($this->cart_instance)->update($row_id, [
                'options' => array_merge($cart_item->options->toArray(), [
                    'product_tax' => $tax_id,
                    'sub_total' => $updated_cart_data['sub_total'],
                    'sub_total_before_tax' => $updated_cart_data['sub_total_before_tax'],
                    'product_tax_amount' => $updated_cart_data['product_tax_amount'],
                ]),
            ]);

            $this->line_total[$product_id] = $updated_cart_data['sub_total'];
            $this->recalculateCart();
        }
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

            // Calculate subtotal and tax using the helper function
            $calculated = $this->calculateSubtotalAndTax($price, $quantity, $discount, $tax_id);

            // Update the cart item with the calculated values
            Cart::instance($this->cart_instance)->update($row_id, [
                'options' => array_merge($cart_item->options->toArray(), [
                    'product_tax' => $tax_id,
                    'sub_total' => $calculated['sub_total'],
                    'sub_total_before_tax' => $calculated['sub_total_before_tax'],
                    'product_tax_amount' => $calculated['product_tax_amount'],
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
}
