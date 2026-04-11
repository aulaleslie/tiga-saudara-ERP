<?php

namespace App\Livewire\Sale;

use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;

class ProductCart extends Component
{
    public $listeners = ['productSelected', 'discountModalRefresh', 'customerSelected', 'taxCreated' => 'handleTaxCreated'];

    public $cart_instance;
    public $global_discount;
    public $customerId;
    public $global_tax;
    public $global_tax_id;
    public $shipping;
    public $quantity;
    public $check_quantity;
    public $discount_type;
    public $item_discount;
    public $unit_price;
    public $data;

    public $taxes;
    public $product_tax = []; // Array to store selected tax IDs for each product

    public $is_tax_included = true;
    public $customer;

    public $global_discount_type = 'percentage';

    public $pendingProduct = null;
    public $bundleOptions = [];
    public $selectedBundle = [
        'name' => '',
        'price' => 0,
        'items' => []
    ];

    public $quantityBreakdowns = [];
    public $priceBreakdowns = [];

    public ?int $settingId = null;
    public bool $isPkp = false;

    protected $rules = [
        'unit_price.*' => 'required|numeric|min:0', // Unit price per row.
        'quantity.*' => 'required|integer|min:1', // Quantity must be at least 1.
        'item_discount.*' => 'nullable|numeric|min:0', // Discounts are optional and non-negative.
        'global_discount' => 'nullable|numeric|min:0|max:100',
        'shipping' => 'nullable|numeric|min:0', // Shipping is optional and non-negative.
        'product_tax_id.*' => 'nullable|integer|exists:taxes,id', // Validate selected tax ID.
        'is_tax_included' => 'nullable|boolean', // Boolean flag for tax inclusion.
    ];

    public function mount($cartInstance, $data = null): void
    {
        $this->cart_instance = $cartInstance;
        $this->settingId = (int) session('setting_id');
        $this->isPkp = (bool) (Setting::query()->whereKey((int) $this->settingId)->value('is_pkp') ?? false);
        $this->taxes = $this->loadTaxes();

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
            $this->is_tax_included = $data->is_tax_included;
        } else {
            $this->global_discount = 0;
            $this->shipping = 0.00;
            $this->check_quantity = [];
            $this->quantity = [];
            $this->unit_price = [];
            $this->discount_type = [];
            $this->item_discount = [];
            $this->product_tax = [];
        }

        $cart_items = Cart::instance($this->cart_instance)->content();

        foreach ($cart_items as $cart_item) {
            $this->initializeCartItemAttributes($cart_item);

            $this->quantityBreakdowns[$cart_item->id] = $this->calculateConversionBreakdown(
                $cart_item->options->product_id,
                $this->quantity[$cart_item->id] ?? $cart_item->qty
            );
        }

        $this->reconcileNonPkpSaleCartState();
        $this->reconcileMissingPkpTaxesInCart();

        if ($data) {
            $this->dispatch('taxIncludedUpdated', (bool) $this->is_tax_included);
            $this->dispatch('globalDiscountTypeUpdated', $this->global_discount_type);
            $this->dispatch('globalDiscountUpdated', (float) $this->global_discount);
            $this->dispatch('shippingUpdated', (float) $this->shipping);
        }
    }

    private function initializeCartItemAttributes($cart_item): void
    {
        $this->check_quantity[$cart_item->id] = $cart_item->options->stock ?? 0;
        $this->quantity[$cart_item->id] = $cart_item->qty ?? 0;
        $this->unit_price[$cart_item->id] = $cart_item->price ?? 0;
        $this->discount_type[$cart_item->id] = $cart_item->options->product_discount_type ?? 'fixed';
        $this->item_discount[$cart_item->id] = $cart_item->options->product_discount ?? 0;
        $this->product_tax[$cart_item->id] = $cart_item->options->product_tax ?? null;
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
        if ($this->cart_instance !== 'sale' || ! $this->isPkp) {
            return;
        }

        $cart = Cart::instance($this->cart_instance);
        $cartItems = $cart->content();

        foreach ($cartItems as $cartItem) {
            $existingTaxId = $cartItem->options->get('product_tax');
            if ($existingTaxId !== null && $existingTaxId !== '') {
                continue;
            }

            $rowKey = (string) $cartItem->id;
            $productId = (int) ($cartItem->options->get('product_id') ?? 0);
            $resolvedTaxId = $this->resolvePreferredPkpAutoTaxId($productId);
            if (! $resolvedTaxId) {
                continue;
            }

            $this->product_tax[$rowKey] = $resolvedTaxId;

            $discountAmount = (float) ($cartItem->options->product_discount ?? 0);
            $calculated = $this->calculateSubtotalAndTax(
                $cartItem->price,
                $cartItem->qty,
                $discountAmount,
                $resolvedTaxId
            );

            [$updatedBundleItems, $bundleTotal] = $this->recalculateBundleItems(
                $cartItem->options->bundle_items ?? [],
                (int) $cartItem->qty,
                (int) $cartItem->qty
            );

            $newSubTotal = $calculated['sub_total'] + $bundleTotal;
            $newSubTotalBeforeTax = $calculated['subtotal_before_tax'] + $bundleTotal;

            $cart->update($cartItem->rowId, [
                'options' => array_merge($cartItem->options->toArray(), [
                    'product_tax' => $resolvedTaxId,
                    'sub_total' => $newSubTotal,
                    'sub_total_before_tax' => $newSubTotalBeforeTax,
                    'bundle_items' => $updatedBundleItems,
                    'bundle_price' => $bundleTotal,
                ]),
            ]);
        }
    }

    private function reconcileNonPkpSaleCartState(): void
    {
        if ($this->cart_instance !== 'sale' || $this->isPkp) {
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

            [$updatedBundleItems, $bundleTotal] = $this->recalculateBundleItems(
                $cartItem->options->bundle_items ?? [],
                (int) $cartItem->qty,
                (int) $cartItem->qty
            );

            $newSubTotal = $calculated['sub_total'] + $bundleTotal;
            $newSubTotalBeforeTax = $calculated['subtotal_before_tax'] + $bundleTotal;

            $cart->update($cartItem->rowId, [
                'price' => $normalizedUnitPrice,
                'options' => array_merge($cartItem->options->toArray(), [
                    'unit_price' => $normalizedUnitPrice,
                    'product_tax' => null,
                    'sub_total' => $newSubTotal,
                    'sub_total_before_tax' => $newSubTotalBeforeTax,
                    'product_tax_amount' => 0,
                    'bundle_items' => $updatedBundleItems,
                    'bundle_price' => $bundleTotal,
                ]),
            ]);

            $this->product_tax[$cartItem->id] = null;
        }
    }

    private function resolveNonPkpUnitPrice($cartItem): float
    {
        $quantity = max(1, (int) $cartItem->qty);
        $bundlePrice = (float) ($cartItem->options->bundle_price ?? 0);
        $subTotalBeforeTax = (float) ($cartItem->options->sub_total_before_tax ?? $cartItem->options->sub_total ?? ($cartItem->price * $quantity));
        $discountAmount = (float) ($cartItem->options->product_discount ?? 0);
        $parentSubTotalBeforeTax = max(0, $subTotalBeforeTax - $bundlePrice);

        return round(($parentSubTotalBeforeTax / $quantity) + $discountAmount, 2);
    }

    private function resolveDefaultTaxId(): ?int
    {
        $defaultTax = $this->taxes->firstWhere('is_default', true);

        if ($defaultTax) {
            return (int) $defaultTax->id;
        }

        if ($this->isPkp) {
            $firstTax = $this->taxes->first();

            return $firstTax ? (int) $firstTax->id : null;
        }

        return null;
    }

    private function resolveLatestTaxId(): ?int
    {
        $latestTaxId = Tax::query()->latest('id')->value('id');

        return $latestTaxId ? (int) $latestTaxId : null;
    }

    private function resolveProductSaleTaxIdForProduct(int $productId, ?array $productPayload = null): ?int
    {
        if ($productId <= 0) {
            return null;
        }

        $settingId = $this->settingId ?: (int) session('setting_id');
        if (! $this->settingId && $settingId) {
            $this->settingId = $settingId;
        }

        $productPriceTaxId = null;
        if ($settingId) {
            $productPriceTaxId = ProductPrice::query()
                ->forProduct($productId)
                ->forSetting((int) $settingId)
                ->value('sale_tax_id');
        }

        if ($productPriceTaxId) {
            return (int) $productPriceTaxId;
        }

        $payloadTaxId = $productPayload['sale_tax_id'] ?? $productPayload['product_tax'] ?? null;

        return $payloadTaxId ? (int) $payloadTaxId : null;
    }

    private function resolvePreferredPkpAutoTaxId(?int $productId = null, ?array $productPayload = null): ?int
    {
        if ($this->cart_instance !== 'sale' || ! $this->isPkp) {
            return null;
        }

        if ($productId) {
            $taxId = $this->resolveProductSaleTaxIdForProduct($productId, $productPayload);
            if ($taxId) {
                return $taxId;
            }
        }

        return $this->resolveDefaultTaxId();
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $cart_items = Cart::instance($this->cart_instance)->content();

        Log::info('render', [
            'cart' => $cart_items->toArray(),
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
            $product_tax_amount += ($sub_total - $sub_total_before_tax);
            $total_sub_total += $sub_total;
        }

        $raw = $this->global_discount;
        $this->global_discount = is_numeric($raw) ? (float) $raw : 0;

        // Calculate global discount amount
        if ($this->global_discount_type == 'percentage') {
            $global_discount_amount = $total_sub_total * ($this->global_discount / 100);
        } else {
            $global_discount_amount = $this->global_discount;
        }

        // Apply discount and shipping to calculate grand total
        $grand_total = ($total_sub_total - $global_discount_amount) + (float)$this->shipping;

        // Log the final totals for debugging
        Log::info('Final totals calculated', [
            'product_tax_amount' => $product_tax_amount,
            'grand_total' => $grand_total,
            'total_sub_total' => $total_sub_total,
            'global_discount' => $this->global_discount,
            'global_discount_amount' => $global_discount_amount,
            'shipping' => $this->shipping,
            'discountType' => $this->discount_type,
            'quantity' => $this->quantity,
            'customer_id' => $this->customerId,
        ]);

        return view('livewire.sale.product-cart', [
            'cart_items' => $cart_items,
            'grand_total' => $grand_total,
            'taxes' => $this->taxes,
            'product_tax_total' => $product_tax_amount,
            'grand_total_before_tax' => $grand_total_before_tax,
            'total_sub_total' => $total_sub_total,
            'global_discount_amount' => $global_discount_amount,
            'customer_id' => $this->customerId,
        ]);
    }

    public function customerSelected($customer): void
    {
        if (is_object($customer) && method_exists($customer, 'toArray')) {
            $customer = $customer->toArray();
        } elseif (!is_array($customer)) {
            $customerModel = Customer::find(is_numeric($customer) ? (int) $customer : null);

            if (! $customerModel) {
                Log::warning('customerSelected received invalid payload', ['customer' => $customer]);
                return;
            }

            $customer = [
                'id' => $customerModel->id,
                'tier' => $customerModel->tier,
                'payment_term_id' => $customerModel->payment_term_id,
                'contact_name' => $customerModel->contact_name,
                'customer_name' => $customerModel->customer_name,
            ];
        }

        if (! isset($customer['id'])) {
            Log::warning('customerSelected payload missing id', ['customer' => $customer]);
            return;
        }

        $this->customer = $customer;
        $this->customerId = $customer['id'];

        $cart = Cart::instance($this->cart_instance);
        $cart_items = $cart->content();

        if ($cart_items->isNotEmpty()) {
            foreach ($cart_items as $cart_item) {
                // Reconstruct the parent product array for recalculation.
                $product = [
                    'id' => $cart_item->options->product_id,
                    'sale_price' => $cart_item->options->sale_price,
                    'tier_1_price' => $cart_item->options->tier_1_price ?? $cart_item->price,
                    'tier_2_price' => $cart_item->options->tier_2_price ?? $cart_item->price,
                ];

                // First, get the new unit price using the calculate() method.
                // This returns the price for a single unit based on the customer's tier.
                $newPriceCalc = $this->calculate($product);
                $resolvedPrices = $newPriceCalc['resolved_prices'] ?? $this->resolveProductPricing($product);

                // Apply cascading price logic if needed (override unit price)
                if (!in_array($this->customer['tier'] ?? '', ['WHOLESALER', 'RESELLER'])) {
                    $productId = $cart_item->options->product_id;
                    $qty = $this->quantity[$cart_item->id] ?? $cart_item->qty;
                    $defaultUnitPrice = $newPriceCalc['unit_price']; // use initial tier-based price
                    $cascadedResult = $this->calculateCascadingPrice($productId, $qty, $defaultUnitPrice);
                    $newPriceCalc['unit_price'] = $cascadedResult['price'];
                    $newPriceCalc['price'] = $cascadedResult['price'];
                    $this->priceBreakdowns[$cart_item->id] = $cascadedResult['breakdown'];
                } else {
                    $this->priceBreakdowns[$cart_item->id] = ''; // Clear if not cascading
                }

                $this->unit_price[$cart_item->id] = $newPriceCalc['unit_price'];

                // Now, use calculateSubtotalAndTax() to properly calculate subtotals based on quantity,
                // discount, and tax.
                $calculated = $this->calculateSubtotalAndTax(
                    $newPriceCalc['unit_price'],
                    $cart_item->qty,
                    $cart_item->options->product_discount ?? 0,
                    $this->syncProductTaxState($cart_item)
                );

                [$updatedBundleItems, $bundleTotal] = $this->recalculateBundleItems(
                    $cart_item->options->bundle_items ?? [],
                    (int) $cart_item->qty,
                    (int) $cart_item->qty
                );

                // Calculate the overall totals by adding the parent's calculated values and the bundle total.
                $newSubTotal = $calculated['sub_total'] + $bundleTotal;
                $newSubTotalBeforeTax = $calculated['subtotal_before_tax'] + $bundleTotal;

                // Update the cart item with the new pricing and subtotal details.
                $cart->update($cart_item->rowId, [
                    'price' => $newPriceCalc['price'],
                    'unit_price' => $newPriceCalc['unit_price'],
                    'options' => array_merge($cart_item->options->toArray(), [
                        'unit_price' => $newPriceCalc['unit_price'],
                        'sub_total' => $newSubTotal,
                        'sub_total_before_tax' => $newSubTotalBeforeTax,
                        'product_tax_amount' => $calculated['tax_amount'],
                        'sale_price' => $resolvedPrices['sale_price'] ?? $cart_item->options->sale_price,
                        'tier_1_price' => $resolvedPrices['tier_1_price'] ?? $cart_item->options->tier_1_price,
                        'tier_2_price' => $resolvedPrices['tier_2_price'] ?? $cart_item->options->tier_2_price,
                        'bundle_items' => $updatedBundleItems,
                        'bundle_price' => $bundleTotal,
                    ]),
                ]);
            }

            // Recalculate overall cart totals.
            $this->recalculateCart();
        }
    }

    public function productSelected($product): void
    {
        Log::info('Product Selected:', $product);

        $bundles = ProductBundle::with('items.product')
            ->where('parent_product_id', $product['id'])
            ->get();

        if ($bundles->isNotEmpty()) {
            $this->pendingProduct = $product;
            $this->bundleOptions = $bundles;
            // Dispatch event to open the bundle selection modal.
            $this->dispatch('showBundleSelectionModal', $this->pendingProduct, $this->bundleOptions);
            return;
        }

        $this->addProduct($product);
    }

    public function addProduct($product): string {

        $cart = Cart::instance($this->cart_instance);

        $stockData = ProductStock::where('product_id', $product['id'])
            ->selectRaw('SUM(quantity_non_tax) as quantity_non_tax, SUM(quantity_tax) as quantity_tax')
            ->first();

        $calculated = $this->calculate($product);
        $resolvedPrices = $calculated['resolved_prices'] ?? $this->resolveProductPricing($product);
        $defaultTaxId = $this->resolvePreferredPkpAutoTaxId((int) ($product['id'] ?? 0), $product);
        $taxCalculation = $this->calculateSubtotalAndTax(
            $calculated['price'],
            1,
            0,
            $defaultTaxId
        );

        $cartItem = $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $product['product_name'],
            'qty' => 1,
            'price' => $calculated['price'],
            'weight' => 1,
            'options' => [
                'product_id' => $product['id'],
                'product_discount' => 0.00,
                'product_discount_type' => 'fixed',
                'sub_total' => $taxCalculation['sub_total'],
                'sub_total_before_tax' => $taxCalculation['subtotal_before_tax'],
                'product_tax_amount' => $taxCalculation['tax_amount'],
                'code' => $product['product_code'],
                'stock' => $product['product_quantity'],
                'unit' => $product['product_unit'],
                'product_tax' => $defaultTaxId,
                'unit_price' => $calculated['unit_price'],
                'sale_price' => $resolvedPrices['sale_price'] ?? 0,
                'tier_1_price' => $resolvedPrices['tier_1_price'] ?? 0,
                'tier_2_price' => $resolvedPrices['tier_2_price'] ?? 0,
                'quantity_non_tax' => $stockData->quantity_non_tax ?? 0,
                'quantity_tax' => $stockData->quantity_tax ?? 0,
            ]
        ]);

        $this->initializeCartItemAttributes($cartItem); // Initialize per-product tax
        $this->quantityBreakdowns[$cartItem->id] = $this->calculateConversionBreakdown(
            $product['id'],
            $this->quantity[$cartItem->id] ?? $cart_Item->qty
        );

        return $cartItem->rowId;
    }

    private function calculateCascadingPrice(int $productId, int $quantity, float $defaultUnitPrice): array
    {
        if ($quantity < 1) {
            return ['price' => $defaultUnitPrice, 'breakdown' => ''];
        }

        $settingId = $this->settingId;
        if (!$settingId) {
            $settingId = (int) session('setting_id');
            if ($settingId) {
                $this->settingId = $settingId;
            }
        }

        $conversions = ProductUnitConversion::query()
            ->where('product_id', $productId)
            ->with(['prices', 'unit'])
            ->orderByDesc('conversion_factor')
            ->get();

        $totalCost = 0;
        $remainingQty = $quantity;
        $usedQty = 0;
        $breakdownParts = [];

        foreach ($conversions as $conv) {
            $factor = (float) $conv->conversion_factor;

            if ($factor < 1) {
                continue;
            }

            $conversionPrice = $settingId ? $conv->priceForSetting($settingId) : null;
            $price = $conversionPrice ? (float) $conversionPrice->price : null;

            if ($price === null) {
                continue;
            }

            $unitCount = floor($remainingQty / $factor);
            if ($unitCount > 0) {
                $totalCost += $unitCount * $price;
                $usedQty += $unitCount * $factor;
                $remainingQty -= $unitCount * $factor;

                $unitLabel = optional($conv->unit)->name ?? 'unit';
                $breakdownParts[] = "{$unitCount} {$unitLabel}(s) @ " . number_format($price, 0);
            }
        }

        if ($remainingQty > 0) {
            $totalCost += $remainingQty * $defaultUnitPrice;
            $usedQty += $remainingQty;
            $breakdownParts[] = "{$remainingQty} pcs @ " . number_format($defaultUnitPrice, 0);
        }

        $avgPrice = $usedQty > 0 ? round($totalCost / $usedQty, 2) : $defaultUnitPrice;

        return [
            'price' => $avgPrice,
            'breakdown' => implode(' + ', $breakdownParts),
        ];
    }

    /**
     * Resolve a cart item by rowId, falling back to stable ID (UUID).
     * This ensures synchronization if rowId has changed due to options updates.
     */
    private function resolveCartItem(string $rowId, ?string $stableId = null)
    {
        $cart = Cart::instance($this->cart_instance);
        $item = $cart->content()->get($rowId);

        if (!$item && $stableId) {
            $item = $cart->content()->firstWhere('id', $stableId);
        }

        return $item;
    }

    public function viewBundleDetails($rowId, $id = null): void
    {
        $cartItem = $this->resolveCartItem($rowId, $id);

        if ($cartItem && $cartItem->options->bundle_items) {
            $this->selectedBundle = [
                'name' => $cartItem->options->bundle_name ?? 'Packaged Items',
                'price' => $cartItem->options->bundle_price ?? 0,
                'items' => $cartItem->options->bundle_items
            ];

            $this->dispatch('open-bundle-modal');
        }
    }

    public function confirmBundleSelection($bundleId): void
    {
        $cart = Cart::instance($this->cart_instance);
        $bundle = ProductBundle::with('items.product')->find($bundleId);

        if (!$bundle) {
            session()->flash('message', 'Invalid bundle selected.');
            return;
        }

        $selectedBundleItems = [];

        // Prefer the bundle's configured price (Harga Paket). If not set, fall back
        // to summing individual bundle item prices (legacy behavior).
        $bundleTotal = $bundle->price !== null ? (float) $bundle->price : 0.0;

        foreach ($bundle->items as $bundleItem) {
            // For create flow we do not use individual bundle item prices for
            // the cart row. We still keep item records (name, qty) but set
            // their displayed/used price to 0 so the bundle price alone
            // represents the package value.
            $initialQuantity = (int) $bundleItem->quantity;

            $selectedBundleItems[] = [
                'bundle_id'           => $bundle->id,
                'bundle_item_id'      => $bundleItem->id,
                'product_id'          => $bundleItem->product->id,
                'name'                => $bundleItem->product->product_name,
                // price intentionally 0 to avoid double-counting
                'price'               => 0.0,
                'quantity'            => $initialQuantity,
                'quantity_per_bundle' => $initialQuantity,
                'sub_total'           => 0.0,
            ];

            // If bundle has no explicit price, accumulate item prices as fallback.
            if ($bundle->price === null) {
                if ($bundleItem->price !== null) {
                    $itemPrice = (float) $bundleItem->price;
                } else {
                    $itemPricing = $this->resolveProductPricing($bundleItem->product);
                    $itemPrice = $this->determineTierPrice($itemPricing);
                }

                $bundleTotal += round($itemPrice * $initialQuantity, 2);
            }
        }

        $bundleTotal = round($bundleTotal, 2);

        if ($this->pendingProduct) {
            $stockData = ProductStock::where('product_id', $this->pendingProduct['id'])
                ->selectRaw('SUM(quantity_non_tax) as quantity_non_tax, SUM(quantity_tax) as quantity_tax')
                ->first();

            // Use the parent product's display/unit price for the cart row so
            // the Harga Jual column shows the product price (e.g., 5,500,000).
            // Keep the bundle price stored in options (bundle_price) and keep
            // the combined subtotal (parent + bundle) in options->sub_total.
            $parentCalculated = $this->calculate($this->pendingProduct);
            $parentResolved = $parentCalculated['resolved_prices'] ?? $this->resolveProductPricing($this->pendingProduct);

            $parentUnitPrice = $parentCalculated['unit_price'] ?? $parentCalculated['price'] ?? 0.0;
            $defaultTaxId = $this->resolvePreferredPkpAutoTaxId((int) ($this->pendingProduct['id'] ?? 0), $this->pendingProduct);
            $parentTaxCalculation = $this->calculateSubtotalAndTax(
                $parentUnitPrice,
                1,
                0,
                $defaultTaxId
            );
            $combinedSubTotal = $parentTaxCalculation['sub_total'] + $bundleTotal;
            $combinedSubTotalBeforeTax = $parentTaxCalculation['subtotal_before_tax'] + $bundleTotal;

            $cartItem = $cart->add([
                'id' => Str::uuid()->toString(),
                'name' => $this->pendingProduct['product_name'],
                'qty' => 1,
                'price' => $parentUnitPrice,
                'weight' => 1,
                'options' => [
                    'product_id' => $this->pendingProduct['id'],
                    'product_discount' => 0.00,
                    'product_discount_type' => 'fixed',
                    'sub_total' => $combinedSubTotal,
                    'sub_total_before_tax' => $combinedSubTotalBeforeTax,
                    'product_tax_amount' => $parentTaxCalculation['tax_amount'],
                    'code' => $this->pendingProduct['product_code'],
                    'stock' => $this->pendingProduct['product_quantity'],
                    'unit' => $this->pendingProduct['product_unit'],
                    'product_tax' => $defaultTaxId,
                    'unit_price' => $parentUnitPrice,
                    'sale_price' => $parentResolved['sale_price'] ?? 0,
                    'tier_1_price' => $parentResolved['tier_1_price'] ?? 0,
                    'tier_2_price' => $parentResolved['tier_2_price'] ?? 0,
                    'quantity_non_tax' => $stockData->quantity_non_tax ?? 0,
                    'quantity_tax' => $stockData->quantity_tax ?? 0,
                    'bundle_price' => $bundleTotal,
                    'bundle_items' => $selectedBundleItems,
                    'bundle_name' => $bundle->name,
                ]
            ]);

            $this->initializeCartItemAttributes($cartItem);

            session()->flash('message', 'Bundle items added successfully.');
            $this->pendingProduct = null;
            $this->bundleOptions = [];
        }

        Log::info('Cart content after confirmBundleSelection:', [
            'cart' => $cart->content()->toArray()
        ]);
    }

    public function proceedWithoutBundle(): void
    {
        if ($this->pendingProduct) {
            $this->addProduct($this->pendingProduct);
            $this->pendingProduct = null;
            $this->bundleOptions = [];
            session()->flash('message', 'Produk diproses tanpa bundle.');
        }
    }

    private function calculateConversionBreakdown(int $productId, int $quantity): string
    {
        if ($quantity < 1) {
            return '';
        }

        $conversions = ProductUnitConversion::with(['unit', 'baseUnit'])
            ->where('product_id', $productId)
            ->orderByDesc('conversion_factor')
            ->get();

        $parts = [];
        $remaining = $quantity;

        foreach ($conversions as $conv) {
            $factor = (int) $conv->conversion_factor;
            if ($factor < 1) {
                continue;
            }
            $count = intdiv($remaining, $factor);
            if ($count > 0) {
                $unitName = optional($conv->unit)->name ?? "unit";
                $parts[] = "{$count} {$unitName}";
                $remaining -= $count * $factor;
            }
        }

        if ($remaining > 0) {
            $baseUnitId = $conversions->first()->base_unit_id ?? null;
            $baseName   = optional(Unit::find($baseUnitId))->name ?? "pc";
            $parts[]    = "{$remaining} {$baseName}";
        }

        return implode(', ', $parts);
    }

    public function removeItem($row_id, $id = null)
    {
        $cart_item = $this->resolveCartItem($row_id, $id);
        if (!$cart_item) return;

        Cart::instance($this->cart_instance)->remove($cart_item->rowId);
        unset($this->quantity[$cart_item->id]);
        unset($this->unit_price[$cart_item->id]);
        unset($this->item_discount[$cart_item->id]);
        unset($this->product_tax[$cart_item->id]);
    }

    public function updatedGlobalTax()
    {
        // Recalculate when global tax changes
        // Ensure that the new global tax is applied
        $this->render();
    }

    public function updatedGlobalDiscount()
    {
        $this->render();
    }

    public function updateQuantity($row_id, $id)
    {
        Log::info('called', [
            'row_id' => $row_id,
            'id' => $id
        ]);

        if (($this->quantity[$id] ?? 0) <= 0) {
            $this->quantity[$id] = 1;
            session()->flash('message', 'Jumlah barang dipesan minimal 1!');
            return;
        }

        $cart_item = $this->resolveCartItem($row_id, $id);
        if (!$cart_item) return;

        $row_id = $cart_item->rowId; // Sync to current rowId

        if (!in_array($this->customer['tier'] ?? '', ['WHOLESALER', 'RESELLER'])) {
            $productId = $cart_item->options->product_id;
            $qty = $this->quantity[$id] ?? $cart_item->qty;

            // 🚨 Get the true base price, not from $this->unit_price!
            $defaultUnitPrice = $cart_item->options->sale_price
                ?? $cart_item->options->unit_price
                ?? $cart_item->price
                ?? 0;

            $cascadedResult = $this->calculateCascadingPrice($productId, $qty, $defaultUnitPrice);
            $this->unit_price[$cart_item->id] = $cascadedResult['price'];
            $this->priceBreakdowns[$cart_item->id] = $cascadedResult['breakdown'];
            $cart_item->price = $this->unit_price[$id];
        } else {
            $this->priceBreakdowns[$cart_item->id] = ''; // Clear if not cascading
        }

        // Recalculate subtotal and tax
        $calculated = $this->calculateSubtotalAndTax(
            $this->unit_price[$id] ?? $cart_item->price,
            $this->quantity[$id] ?? 0,
            $cart_item->options->product_discount ?? 0,
            $this->syncProductTaxState($cart_item)
        );

        [$updatedBundleItems, $bundleTotal] = $this->recalculateBundleItems(
            $cart_item->options->bundle_items ?? [],
            (int) ($this->quantity[$id] ?? 0),
            (int) $cart_item->qty
        );

        // Update cart item totals
        $newSubTotal = $calculated['sub_total'] + $bundleTotal;
        $newSubTotalBeforeTax = $calculated['subtotal_before_tax'] + $bundleTotal;

        Cart::instance($this->cart_instance)->update($row_id, [
            'qty' => $this->quantity[$id],
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $newSubTotal,
                'sub_total_before_tax' => $newSubTotalBeforeTax,
                'product_tax_amount' => $calculated['tax_amount'],
                'bundle_items' => $updatedBundleItems,
                'bundle_price' => $bundleTotal,
            ]),
        ]);

        $this->quantityBreakdowns[$id] = $this->calculateConversionBreakdown(
            $cart_item->options->product_id,
            $this->quantity[$id]
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
    private function calculateSubtotalAndTax($price, $qty, $discount = 0, $tax_id = null)
    {
        // Validate inputs
        $price = max(0, (float) $price);
        $qty = max(1, (int) $qty);
        $discount = max(0.0, (float) $discount);
        $tax_id = ($this->cart_instance === 'sale' && ! $this->isPkp) ? null : $tax_id;

        $effective_price = max(0.0, $price - $discount);

        // Initialize variables
        $tax_amount = 0;

        if ($this->is_tax_included) {
            // Case: Tax is included in the price
            if ($tax_id) {
                $tax = Tax::find($tax_id);
                if ($tax) {
                    // Calculate price excluding tax
                    $price_ex_tax = $effective_price / (1 + $tax->value / 100);
                    $tax_amount_per_unit = $effective_price - $price_ex_tax;
                    $tax_amount = $tax_amount_per_unit * $qty;
                    $subtotal_before_tax = $price_ex_tax * $qty;
                    Log::info('Tax included - Price ex tax and tax amount per unit calculated', [
                        'price_ex_tax' => $price_ex_tax,
                        'tax_amount_per_unit' => $tax_amount_per_unit,
                    ]);
                } else {
                    Log::warning("Invalid tax ID provided", ['tax_id' => $tax_id]);
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
                $tax = Tax::find($tax_id);
                if ($tax) {
                    // Calculate tax on subtotal before tax
                    $tax_amount = $subtotal_before_tax * ($tax->value / 100);
                } else {
                    Log::warning("Invalid tax ID provided", ['tax_id' => $tax_id]);
                }
            }
        }

        // Return recalculated values
        return [
            'sub_total' => $subtotal_before_tax + $tax_amount, // Total with tax
            'tax_amount' => $tax_amount,                      // Tax amount
            'subtotal_before_tax' => $subtotal_before_tax,    // Total without tax
        ];
    }

    /**
     * Recalculate bundle item quantities and totals based on the parent quantity.
     *
     * @param  iterable<int, array<string, mixed>>|array<string, mixed>|null  $bundleItems
     * @param  int  $newParentQuantity
     * @param  int|null  $previousParentQuantity
     * @return array{0: array<int, array<string, mixed>>, 1: float}
     */
    private function recalculateBundleItems($bundleItems, int $newParentQuantity, ?int $previousParentQuantity = null): array
    {
        if ($bundleItems instanceof \Illuminate\Support\Collection) {
            $bundleItems = $bundleItems->toArray();
        } elseif ($bundleItems === null) {
            $bundleItems = [];
        } elseif (! is_array($bundleItems)) {
            $bundleItems = (array) $bundleItems;
        }

        $updatedItems = [];
        $bundleTotal = 0.0;
        $newParentQuantity = max(0, $newParentQuantity);
        $previousParentQuantity = max(1, $previousParentQuantity ?? $newParentQuantity ?: 1);

        foreach ($bundleItems as $bundleItem) {
            $bundleItem = is_array($bundleItem) ? $bundleItem : (array) $bundleItem;

            $baseQuantity = $bundleItem['quantity_per_bundle'] ?? null;
            if ($baseQuantity === null) {
                $existingQuantity = isset($bundleItem['quantity']) ? (float) $bundleItem['quantity'] : 0.0;
                $baseQuantity = $previousParentQuantity > 0
                    ? $existingQuantity / $previousParentQuantity
                    : $existingQuantity;
            }

            $baseQuantity = max(0.0, (float) $baseQuantity);
            $price = isset($bundleItem['price']) ? (float) $bundleItem['price'] : 0.0;

            $computedQuantity = $baseQuantity * $newParentQuantity;
            $computedQuantity = (int) round($computedQuantity);
            $subTotal = round($price * $computedQuantity, 2);

            $bundleItem['price'] = $price;
            $bundleItem['quantity_per_bundle'] = round($baseQuantity, 4);
            $bundleItem['quantity'] = $computedQuantity;
            $bundleItem['sub_total'] = $subTotal;

            $updatedItems[] = $bundleItem;
            $bundleTotal += $subTotal;
        }

        return [$updatedItems, round($bundleTotal, 2)];
    }

    public function updatedDiscountType($value, $name)
    {
        $this->item_discount[$name] = 0;
    }

    public function discountModalRefresh($id, $row_id)
    {
        $this->updateQuantity($row_id, $id);
    }

    public function setDiscountType($row_id, $id, $discount_type): void
    {
        $this->discount_type[$id] = $discount_type;
        $this->setProductDiscount($row_id, $id);
    }

    public function setProductDiscount($row_id, $product_id): void
    {
        $cart_item = $this->resolveCartItem($row_id, $product_id);
        if (!$cart_item) return;

        $row_id = $cart_item->rowId; // Sync to current rowId

        $unit_price = $this->unit_price[$product_id] ?? $cart_item->price;
        $quantity = $cart_item->qty;

        $raw_input = $this->item_discount[$product_id] ?? 0;
        $input = is_numeric($raw_input) ? (float) $raw_input : 0;

        $discount_amount = 0;

        if ($this->discount_type[$product_id] === 'percentage') {
            if ($input > 100) {
                $input = 100;
                $this->item_discount[$product_id] = 100;
                session()->flash('message', 'Diskon tidak boleh lebih dari 100%');
            } elseif ($input < 0) {
                $input = 0;
                $this->item_discount[$product_id] = 0;
                session()->flash('message', 'Diskon tidak boleh kurang dari 0%');
            }

            $discount_amount = $unit_price * ($input / 100);
        } else { // fixed
            if ($input > $unit_price) {
                $input = $unit_price;
                $this->item_discount[$product_id] = $unit_price;
                session()->flash('message', 'Diskon tidak boleh lebih besar dari harga satuan!');
            } elseif ($input < 0) {
                $input = 0;
                $this->item_discount[$product_id] = 0;
                session()->flash('message', 'Diskon tidak boleh kurang dari 0!');
            }

            $discount_amount = $input;
        }

        $calculated = $this->calculateSubtotalAndTax(
            $unit_price,
            $quantity,
            $discount_amount,
            $this->syncProductTaxState($cart_item)
        );

        [$updatedBundleItems, $bundleTotal] = $this->recalculateBundleItems(
            $cart_item->options->bundle_items ?? [],
            (int) $quantity,
            (int) $quantity
        );

        Cart::instance($this->cart_instance)->update($row_id, [
            'unit_price' => $unit_price,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $calculated['sub_total'] + $bundleTotal,
                'sub_total_before_tax' => $calculated['subtotal_before_tax'] + $bundleTotal,
                'product_tax_amount' => $calculated['tax_amount'],
                'product_discount' => $discount_amount,
                'product_discount_type' => $this->discount_type[$product_id],
                'bundle_items' => $updatedBundleItems,
                'bundle_price' => $bundleTotal,
            ]),
        ]);

        $this->recalculateCart();
        session()->flash('discount_message' . $product_id, 'Diskon berhasil diterapkan!');
    }

    public function updatePrice($row_id, $id)
    {
        $cart_item = $this->resolveCartItem($row_id, $id);
        if (!$cart_item) return;

        $row_id = $cart_item->rowId; // Sync to current rowId

        // Get the new price (unit price)
        $new_price = $this->unit_price[$id] ?? $cart_item->price;

        // Determine discount amount
        $discount_amount = $cart_item->options->product_discount;
        $raw_discount_input = $this->item_discount[$id] ?? 0;
        $sanitized_discount_input = is_numeric($raw_discount_input) ? (float)$raw_discount_input : 0;

        if ($this->discount_type[$id] == 'percentage') {
            $discount_amount = $new_price * ($sanitized_discount_input / 100);
        }

        // Recalculate totals
        $calculated = $this->calculateSubtotalAndTax(
            $new_price,
            $cart_item->qty,
            $discount_amount,
            $this->syncProductTaxState($cart_item)
        );

        [$updatedBundleItems, $bundleTotal] = $this->recalculateBundleItems(
            $cart_item->options->bundle_items ?? [],
            (int) $cart_item->qty,
            (int) $cart_item->qty
        );

        $newSubTotal = $calculated['sub_total'] + $bundleTotal;
        $newSubTotalBeforeTax = $calculated['subtotal_before_tax'] + $bundleTotal;

        Cart::instance($this->cart_instance)->update($row_id, [
            'price' => $new_price,
            'unit_price' => $new_price,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $newSubTotal,
                'sub_total_before_tax' => $newSubTotalBeforeTax,
                'product_tax_amount' => $calculated['tax_amount'],
                'product_discount' => $discount_amount,
                'bundle_items' => $updatedBundleItems,
                'bundle_price' => $bundleTotal,
            ]),
        ]);

        // Update breakdown display if applicable
        if (!empty($this->priceBreakdowns[$id])) {
            $qty = $cart_item->qty;
            $unitName = $cart_item->options->unit ?? 'unit';

            $this->priceBreakdowns[$id] = "{$qty} {$unitName} @ " . number_format($new_price, 0);
        }

        $this->recalculateCart();
    }

    private function resolveProductPricing($product): array
    {
        $productId = 0;

        if ($product instanceof Product) {
            $productId = (int) $product->getKey();
        } else {
            $productId = (int) data_get($product, 'id', data_get($product, 'product_id', 0));
        }

        $saleFallback = (float) data_get($product, 'sale_price', data_get($product, 'product_price', 0)) ?: 0.0;
        $tier1Fallback = (float) data_get($product, 'tier_1_price', $saleFallback);
        $tier2Fallback = (float) data_get($product, 'tier_2_price', $saleFallback);

        $priceRow = null;
        $settingId = $this->settingId ?: (int) session('setting_id');

        if (!$this->settingId && $settingId) {
            $this->settingId = $settingId;
        }

        if ($productId > 0 && $settingId) {
            $priceRow = ProductPrice::query()
                ->forProduct($productId)
                ->forSetting((int) $settingId)
                ->first();
        }

        return [
            'product_id' => $productId,
            'sale_price' => (float) ($priceRow?->sale_price ?? $saleFallback ?? 0),
            'tier_1_price' => (float) ($priceRow?->tier_1_price ?? $tier1Fallback ?? $saleFallback ?? 0),
            'tier_2_price' => (float) ($priceRow?->tier_2_price ?? $tier2Fallback ?? $saleFallback ?? 0),
        ];
    }

    private function determineTierPrice(array $pricing, ?string $tier = null): float
    {
        $baseSalePrice = (float) ($pricing['sale_price'] ?? 0);
        $tier = $tier ?? ($this->customer['tier'] ?? null);

        if ($tier === 'WHOLESALER') {
            $tierPrice = (float) ($pricing['tier_1_price'] ?? 0);
            return $tierPrice > 0 ? $tierPrice : $baseSalePrice;
        }

        if ($tier === 'RESELLER') {
            $tierPrice = (float) ($pricing['tier_2_price'] ?? 0);
            return $tierPrice > 0 ? $tierPrice : $baseSalePrice;
        }

        return $baseSalePrice;
    }

    public function calculate($product, $new_price = null)
    {
        // Determine the base price
        Log::info('from calculate:', [
            'customer' => $this->customer,
            'product' => $product,
        ]);

        $pricing = $this->resolveProductPricing($product);
        $product_price = $new_price ?? $this->determineTierPrice($pricing);

        $product_tax = 0;
        $sub_total = (float) $product_price; // Start with base price as subtotal

        return [
            'price' => (float) $product_price,
            'unit_price' => (float) $product_price,
            'product_tax' => $product_tax,
            'sub_total' => $sub_total,
            'resolved_prices' => $pricing,
        ];
    }

    public function updateCartOptions($row_id, $id, $cart_item, $discount_amount)
    {
        [$updatedBundleItems, $bundleTotal] = $this->recalculateBundleItems(
            $cart_item->options->bundle_items ?? [],
            (int) $cart_item->qty,
            (int) $cart_item->qty
        );

        $parentSubTotal = $cart_item->price * $cart_item->qty;
        $parentSubTotalBeforeTax = $cart_item->options->sub_total_before_tax
            ?? $parentSubTotal;

        Cart::instance($this->cart_instance)->update($row_id, ['options' => [
            'sub_total' => $parentSubTotal + $bundleTotal,
            'sub_total_before_tax' => $parentSubTotalBeforeTax + $bundleTotal,
            'code' => $cart_item->options->code,
            'stock' => $cart_item->options->stock,
            'unit' => $cart_item->options->unit,
            'product_tax' => $cart_item->options->product_tax,
            'unit_price' => $cart_item->options->unit_price,
            'product_discount' => $discount_amount,
            'product_discount_type' => $this->discount_type[$id],
            'bundle_items' => $updatedBundleItems,
            'bundle_price' => $bundleTotal,
        ]]);
    }

    public function recalculateCart()
    {
        // Trigger a re-render to update totals
        $this->render();
    }

    public function updateTax($row_id, $id, $selectedTaxId = null)
    {
        $cart_item = $this->resolveCartItem($row_id, $id);
        if (! $cart_item) {
            return;
        }

        $row_id = $cart_item->rowId; // Sync to current rowId

        $tax_id = $this->normalizeTaxId($selectedTaxId);
        $this->product_tax[$id] = $tax_id;
        $tax_amount = 0;

        if ($tax_id) {
            $tax = Tax::find($tax_id);
            if ($tax) {
                Log::info('Tax applied', [
                    'id' => $id,
                    'tax_id' => $tax_id,
                    'tax_value' => $tax->value,
                ]);

                $updated_cart_data = $this->calculateSubtotalAndTax(
                    $cart_item->price,
                    $cart_item->qty,
                    $cart_item->options->product_discount ?? 0,
                    $tax_id
                );

                [$updatedBundleItems, $bundleTotal] = $this->recalculateBundleItems(
                    $cart_item->options->bundle_items ?? [],
                    (int) $cart_item->qty,
                    (int) $cart_item->qty
                );

                $newSubTotal = $updated_cart_data['sub_total'] + $bundleTotal;
                $newSubTotalBeforeTax = $updated_cart_data['subtotal_before_tax'] + $bundleTotal;

                Cart::instance($this->cart_instance)->update($row_id, [
                    'options' => array_merge($cart_item->options->toArray(), [
                        'product_tax' => $tax_id,
                        'sub_total' => $newSubTotal,
                        'sub_total_before_tax' => $newSubTotalBeforeTax,
                        'product_tax_amount' => $updated_cart_data['tax_amount'],
                        'bundle_items' => $updatedBundleItems,
                        'bundle_price' => $bundleTotal,
                    ]),
                ]);

                $this->recalculateCart();
                Log::info('Tax updated successfully', [
                    'row_id' => $row_id,
                    'updated_cart_data' => $updated_cart_data,
                ]);
            } else {
                Log::warning('Invalid tax ID provided', ['tax_id' => $tax_id]);
                session()->flash('message', 'Invalid tax selected.');
            }
        } else {
            $updated_cart_data = $this->calculateSubtotalAndTax(
                $cart_item->price,
                $cart_item->qty,
                $cart_item->options->product_discount ?? 0,
                $tax_id
            );

            [$updatedBundleItems, $bundleTotal] = $this->recalculateBundleItems(
                $cart_item->options->bundle_items ?? [],
                (int) $cart_item->qty,
                (int) $cart_item->qty
            );

            $newSubTotal = $updated_cart_data['sub_total'] + $bundleTotal;
            $newSubTotalBeforeTax = $updated_cart_data['subtotal_before_tax'] + $bundleTotal;

            Cart::instance($this->cart_instance)->update($row_id, [
                'options' => array_merge($cart_item->options->toArray(), [
                    'product_tax' => $tax_id,
                    'sub_total' => $newSubTotal,
                    'sub_total_before_tax' => $newSubTotalBeforeTax,
                    'product_tax_amount' => $updated_cart_data['tax_amount'],
                    'bundle_items' => $updatedBundleItems,
                    'bundle_price' => $bundleTotal,
                ]),
            ]);

            $this->recalculateCart();
            Log::warning('No tax ID provided for product', ['id' => $id]);
        }
    }

    public function handleTaxIncluded()
    {
        $cart_items = Cart::instance($this->cart_instance)->content();

        $this->dispatch('taxIncludedUpdated', (bool) $this->is_tax_included);

        foreach ($cart_items as $cart_item) {
            $row_id = $cart_item->rowId;

            // Retrieve required data for calculations
            $price = $cart_item->price;
            $quantity = $cart_item->qty;
            $discount = $cart_item->options->product_discount ?? 0;
            $tax_id = $this->syncProductTaxState($cart_item);

            // Calculate subtotal and tax for the parent product
            $calculated = $this->calculateSubtotalAndTax($price, $quantity, $discount, $tax_id);

            [$updatedBundleItems, $bundleTotal] = $this->recalculateBundleItems(
                $cart_item->options->bundle_items ?? [],
                (int) $quantity,
                (int) $quantity
            );

            $newSubTotal = $calculated['sub_total'] + $bundleTotal;
            $newSubTotalBeforeTax = $calculated['subtotal_before_tax'] + $bundleTotal;

            // Update the cart item with the new totals and bundle details
            Cart::instance($this->cart_instance)->update($row_id, [
                'options' => array_merge($cart_item->options->toArray(), [
                    'product_tax' => $tax_id,
                    'sub_total' => $newSubTotal,
                    'sub_total_before_tax' => $newSubTotalBeforeTax,
                    'product_tax_amount' => $calculated['tax_amount'],
                    'bundle_items' => $updatedBundleItems,
                    'bundle_price' => $bundleTotal,
                ]),
            ]);

            Log::info('Updated cart item for tax inclusion', [
                'row_id' => $row_id,
                'sub_total' => $newSubTotal,
                'sub_total_before_tax' => $newSubTotalBeforeTax,
                'tax_amount' => $calculated['tax_amount'],
            ]);
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

        $cart_items = Cart::instance($this->cart_instance)->content();
        $total_sub_total = 0;

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
        } else {
            if ($this->global_discount > $total_sub_total) {
                $this->global_discount = $total_sub_total;
                session()->flash('message', 'Diskon global tidak boleh melebihi total!');
            } elseif ($this->global_discount < 0) {
                $this->global_discount = 0;
                session()->flash('message', 'Diskon global tidak boleh kurang dari 0!');
            }
        }

        $this->dispatch('globalDiscountUpdated', (float) $this->global_discount);
        $this->recalculateCart();
    }

    public function updatedShipping(): void
    {
        $raw = $this->shipping;
        $this->shipping = is_numeric($raw) ? (float) $raw : 0;
        $this->dispatch('shippingUpdated', (float) $this->shipping);
    }

    public function handleTaxCreated($id, $name, $value, $product_id = null): void
    {
        $this->taxes = $this->loadTaxes(); // Refresh the taxes list

        if ($product_id) {
            $cartItem = $this->resolveCartItem('', $product_id);
            if ($cartItem) {
                $this->updateTax($cartItem->rowId, $cartItem->id, $id);
            }
        }
    }
}
