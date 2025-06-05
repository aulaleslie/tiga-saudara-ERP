<?php

namespace App\Livewire\Sale;

use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;

class ProductCart extends Component
{
    public $listeners = ['productSelected', 'discountModalRefresh', 'customerSelected'];

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

    public $taxes; // Collection of taxes filtered by setting_id
    public $setting_id; // Current setting ID
    public $product_tax = []; // Array to store selected tax IDs for each product

    public $is_tax_included = false;
    public $customer;

    public $global_discount_type = 'percentage';

    public $pendingProduct = null;
    public $bundleOptions = [];

    public $quantityBreakdowns = [];
    public $priceBreakdowns = [];

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
        $this->setting_id = session('setting_id');
        $this->taxes = Tax::where('setting_id', $this->setting_id)->get();

        if ($data) {
            $this->data = $data;

            if ($data->discount_percentage > 0) {
                $this->global_discount_type = 'percentage';
            } else if ($data->discount_amount > 0) {
                $this->global_discount_type = 'fixed';
            }

            $this->global_discount = $data->discount_percentage ?? 0;
            $this->shipping = $data->shipping_amount;
            $this->is_tax_included = $data->is_tax_included;

            $cart_items = Cart::instance($this->cart_instance)->content();

            foreach ($cart_items as $cart_item) {
                $this->initializeCartItemAttributes($cart_item);

                $this->quantityBreakdowns[$cart_item->id] = $this->calculateConversionBreakdown(
                    $cart_item->options->product_id,
                    $this->quantity[$cart_item->id] ?? $cart_item->qty
                );
            }
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
        $this->customer = $customer;
        $this->customerId = $customer['id'];

        $cart = Cart::instance($this->cart_instance);
        $cart_items = $cart->content();

        if ($cart_items->isNotEmpty()) {
            foreach ($cart_items as $cart_item) {
                // Reconstruct the parent product array for recalculation.
                $product = [
                    'id' => $cart_item->product_id,
                    'sale_price' => $cart_item->options->sale_price,
                    'tier_1_price' => $cart_item->options->tier_1_price ?? $cart_item->price,
                    'tier_2_price' => $cart_item->options->tier_2_price ?? $cart_item->price,
                ];

                // First, get the new unit price using the calculate() method.
                // This returns the price for a single unit based on the customer's tier.
                $newPriceCalc = $this->calculate($product);

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
                    $this->product_tax[$cart_item->id] ?? null
                );

                // Initialize bundle totals.
                $bundleTotal = 0;
                $updatedBundleItems = [];
                if (is_array($cart_item->options->bundle_items)) {
                    foreach ($cart_item->options->bundle_items as $bundleItem) {
                        // Retrieve the bundle item details (for example, to get its base quantity).
                        $bundleItemDb = ProductBundleItem::find($bundleItem['bundle_item_id']);
                        if (!$bundleItemDb) {
                            Log::warning('Bundle product not found', ['bundle_item_id' => $bundleItem['bundle_item_id']]);
                            continue;
                        }
                        // Recalculate bundle quantity based on the parent's quantity.
                        $newBundleQuantity = $bundleItemDb->quantity * $cart_item->qty;
                        $newBundleSubTotal = $bundleItem['price'] * $newBundleQuantity;
                        $bundleTotal += $newBundleSubTotal;

                        // Update bundle item data.
                        $bundleItem['quantity'] = $newBundleQuantity;
                        $bundleItem['sub_total'] = $newBundleSubTotal;
                        $updatedBundleItems[] = $bundleItem;
                    }
                }

                // Calculate the overall totals by adding the parent's calculated values and the bundle total.
                $newSubTotal = $calculated['sub_total'] + $bundleTotal;
                $newSubTotalBeforeTax = $calculated['subtotal_before_tax'] + $bundleTotal;

                // Update the cart item with the new pricing and subtotal details.
                $cart->update($cart_item->rowId, [
                    'price' => $newPriceCalc['price'],
                    'unit_price' => $newPriceCalc['unit_price'],
                    'options' => array_merge($cart_item->options->toArray(), [
                        'sub_total' => $newSubTotal,
                        'sub_total_before_tax' => $newSubTotalBeforeTax,
                        'bundle_items' => $updatedBundleItems,
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

        $cartItem = $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $product['product_name'],
            'qty' => 1,
            'price' => $this->calculate($product)['price'],
            'weight' => 1,
            'options' => [
                'product_id' => $product['id'],
                'product_discount' => 0.00,
                'product_discount_type' => 'fixed',
                'sub_total' => $this->calculate($product)['sub_total'],
                'sub_total_before_tax' => $this->calculate($product)['sub_total'],
                'code' => $product['product_code'],
                'stock' => $product['product_quantity'],
                'unit' => $product['product_unit'],
                'product_tax' => null, // Initialize as null
                'unit_price' => $this->calculate($product)['unit_price'],
                'sale_price' => $product['sale_price'] ?? 0,
                'tier_1_price' => $product['tier_1_price'] ?? 0,
                'tier_2_price' => $product['tier_2_price'] ?? 0,
                'quantity_non_tax' => $stockData->quantity_non_tax ?? 0,
                'quantity_tax' => $stockData->quantity_tax ?? 0,
            ]
        ]);

        $this->initializeCartItemAttributes($cartItem); // Initialize per-product tax
        $this->quantityBreakdowns[$cartItem->id] = $this->calculateConversionBreakdown(
            $product['id'],
            $this->quantity[$cartItem->id] ?? $cartItem->qty
        );

        return $cartItem->rowId;
    }

    private function calculateCascadingPrice(int $productId, int $quantity, float $defaultUnitPrice): array
    {
        if ($quantity < 1) {
            return ['price' => $defaultUnitPrice, 'breakdown' => ''];
        }

        $conversions = ProductUnitConversion::where('product_id', $productId)
            ->orderByDesc('conversion_factor')
            ->get();

        $totalCost = 0;
        $remainingQty = $quantity;
        $usedQty = 0;
        $breakdownParts = [];

        foreach ($conversions as $conv) {
            $factor = (float) $conv->conversion_factor;
            $price = $conv->price;

            if ($factor < 1 || $price === null) {
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

    public function confirmBundleSelection($bundleId): void
    {
        $cart = Cart::instance($this->cart_instance);
        $bundle = ProductBundle::with('items.product')->find($bundleId);

        if (!$bundle) {
            session()->flash('message', 'Invalid bundle selected.');
            return;
        }

        // Build simplified item list (no price/sub_total per item)
        $selectedBundleItems = [];
        foreach ($bundle->items as $bundleItem) {
            $selectedBundleItems[] = [
                'bundle_id'       => $bundle->id,
                'bundle_item_id'  => $bundleItem->id,
                'product_id'      => $bundleItem->product->id,
                'name'            => $bundleItem->product->product_name,
                'quantity'        => $bundleItem->quantity,
            ];
        }

        if ($this->pendingProduct) {
            $stockData = ProductStock::where('product_id', $this->pendingProduct['id'])
                ->selectRaw('SUM(quantity_non_tax) as quantity_non_tax, SUM(quantity_tax) as quantity_tax')
                ->first();

            $parentCalculated = $this->calculate($this->pendingProduct);
            $final_sub_total = $parentCalculated['sub_total'] + $bundle->price;

            $cartItem = $cart->add([
                'id' => Str::uuid()->toString(),
                'name' => $this->pendingProduct['product_name'],
                'qty' => 1,
                'price' => $final_sub_total, // base product price only
                'weight' => 1,
                'options' => [
                    'product_id' => $this->pendingProduct['id'],
                    'product_discount' => 0.00,
                    'product_discount_type' => 'fixed',
                    'sub_total' => $final_sub_total,
                    'sub_total_before_tax' => $final_sub_total,
                    'code' => $this->pendingProduct['product_code'],
                    'stock' => $this->pendingProduct['product_quantity'],
                    'unit' => $this->pendingProduct['product_unit'],
                    'product_tax' => null,
                    'unit_price' => $final_sub_total,
                    'sale_price' => $this->pendingProduct['sale_price'] ?? 0,
                    'tier_1_price' => $this->pendingProduct['tier_1_price'] ?? 0,
                    'tier_2_price' => $this->pendingProduct['tier_2_price'] ?? 0,
                    'quantity_non_tax' => $stockData->quantity_non_tax ?? 0,
                    'quantity_tax' => $stockData->quantity_tax ?? 0,
                    'bundle_price' => $bundle->price, // 🟢 NEW
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
                $parts[] = "{$count} {$unitName}(s)";
                $remaining -= $count * $factor;
            }
        }

        if ($remaining > 0) {
            $baseUnitId = $conversions->first()->base_unit_id ?? null;
            $baseName   = optional(Unit::find($baseUnitId))->name ?? "pc";
            $parts[]    = "{$remaining} {$baseName}(s)";
        }

        return implode(', ', $parts);
    }

    public function removeItem($row_id)
    {
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);
        Cart::instance($this->cart_instance)->remove($row_id);
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

        if ($this->quantity[$id] <= 0) {
            $this->quantity[$id] = 1;
            session()->flash('message', 'Jumlah barang dipesan minimal 1!');
            return;
        }

        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

        // Handle cascading price if customer tier is null
        if (!in_array($this->customer['tier'] ?? '', ['WHOLESALER', 'RESELLER'])) {
            $productId = $cart_item->options->product_id;
            $qty = $this->quantity[$id] ?? $cart_item->qty;
            $defaultUnitPrice = $this->unit_price[$id] ?? $cart_item->price;

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
            $this->product_tax[$id] ?? null
        );

        // Use the fixed bundle header price instead of calculating from bundle items
//        $bundleTotal = $cart_item->options['bundle_price'] ?? 0;

        // Update cart item totals
        $newSubTotal = $calculated['sub_total'];
        $newSubTotalBeforeTax = $calculated['subtotal_before_tax'];

        Cart::instance($this->cart_instance)->update($row_id, [
            'qty' => $this->quantity[$id],
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $newSubTotal,
                'sub_total_before_tax' => $newSubTotalBeforeTax,
                'tax_amount' => $calculated['tax_amount'],
                // leave 'bundle_items' unchanged (quantity display logic only)
            ]),
        ]);

        $this->quantityBreakdowns[$id] = $this->calculateConversionBreakdown($cart_item->options->product_id, $this->quantity[$id]);

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
        $price = max(0, (float)$price); // Ensure price is non-negative
        $qty = max(1, (int)$qty);
        $discount = max(0, (int)$discount);

        $price = $price - $discount;// Ensure quantity is at least 1
        // Ensure discount is non-negative

        // Initialize variables
        $tax_amount = 0;

        if ($this->is_tax_included) {
            // Case: Tax is included in the price
            if ($tax_id) {
                $tax = Tax::find($tax_id);
                if ($tax) {
                    // Calculate price excluding tax
                    $price_ex_tax = $price / (1 + $tax->value / 100);
                    $tax_amount_per_unit = $price - $price_ex_tax;
                    $tax_amount = $tax_amount_per_unit * $qty;
                    $subtotal_before_tax = $price_ex_tax * $qty;
                    Log::info('Tax included - Price ex tax and tax amount per unit calculated', [
                        'price_ex_tax' => $price_ex_tax,
                        'tax_amount_per_unit' => $tax_amount_per_unit,
                    ]);
                } else {
                    Log::warning("Invalid tax ID provided", ['tax_id' => $tax_id]);
                    // No tax applied, discount only
                    $subtotal_before_tax = $price * $qty;
                }
            } else {
                // No tax applied
                $subtotal_before_tax = $price * $qty;
            }
        } else {
            // Case: Tax is not included in the price
            $subtotal_before_tax = $price * $qty;

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
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

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
            $this->product_tax[$product_id] ?? null
        );

        Cart::instance($this->cart_instance)->update($row_id, [
            'unit_price' => $unit_price,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $calculated['sub_total'],
                'sub_total_before_tax' => $calculated['subtotal_before_tax'],
                'product_discount' => $discount_amount,
                'product_discount_type' => $this->discount_type[$product_id],
            ]),
        ]);

        $this->recalculateCart();
        session()->flash('discount_message' . $product_id, 'Diskon berhasil diterapkan!');
    }

    public function updatePrice($row_id, $id)
    {
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

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
            $this->product_tax[$id] ?? null
        );

        // ✅ Use header bundle price, not item prices
        $bundleTotal = $cart_item->options['bundle_price'] ?? 0;

        $newSubTotal = $calculated['sub_total'] + $bundleTotal;
        $newSubTotalBeforeTax = $calculated['subtotal_before_tax'] + $bundleTotal;

        Cart::instance($this->cart_instance)->update($row_id, [
            'price' => $new_price,
            'unit_price' => $new_price,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $newSubTotal,
                'sub_total_before_tax' => $newSubTotalBeforeTax,
                'tax_amount' => $calculated['tax_amount'],
                'product_discount' => $discount_amount,
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

    public function calculate($product, $new_price = null)
    {
        // Determine the base price
        Log::info('from calculate:', [
            'customer' => $this->customer,
            'product' => $product,
        ]);

        if ($this->customer && $new_price === null) {
            $product_price = match ($this->customer['tier']) {
                "WHOLESALER" => $product['tier_1_price'] ?? 0,
                "RESELLER" => $product['tier_2_price'] ?? 0,
                default => $product['sale_price'] ?? 0,
            };
        } else {
            $product_price = $product['sale_price'] ?? 0;
        }

        $product_tax = 0;
        $sub_total = $product_price; // Start with base price as subtotal

        return [
            'price' => $product_price,
            'unit_price' => $product_price,
            'product_tax' => $product_tax,
            'sub_total' => $sub_total,
        ];
    }

    public function updateCartOptions($row_id, $id, $cart_item, $discount_amount)
    {
        Cart::instance($this->cart_instance)->update($row_id, ['options' => [
            'sub_total' => $cart_item->price * $cart_item->qty,
            'code' => $cart_item->options->code,
            'stock' => $cart_item->options->stock,
            'unit' => $cart_item->options->unit,
            'product_tax' => $cart_item->options->product_tax,
            'unit_price' => $cart_item->options->unit_price,
            'product_discount' => $discount_amount,
            'product_discount_type' => $this->discount_type[$id],
        ]]);
    }

    public function recalculateCart()
    {
        // Trigger a re-render to update totals
        $this->render();
    }

    public function updateTax($row_id, $id)
    {
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);
        $tax_id = !empty($this->product_tax[$id]) ? $this->product_tax[$id] : null;
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

                $newSubTotal = $updated_cart_data['sub_total'];
                $newSubTotalBeforeTax = $updated_cart_data['subtotal_before_tax'];

                Cart::instance($this->cart_instance)->update($row_id, [
                    'options' => array_merge($cart_item->options->toArray(), [
                        'product_tax' => $tax_id,
                        'sub_total' => $newSubTotal,
                        'sub_total_before_tax' => $newSubTotalBeforeTax,
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

            $newSubTotal = $updated_cart_data['sub_total'];
            $newSubTotalBeforeTax = $updated_cart_data['subtotal_before_tax'];

            Cart::instance($this->cart_instance)->update($row_id, [
                'options' => array_merge($cart_item->options->toArray(), [
                    'product_tax' => $tax_id,
                    'sub_total' => $newSubTotal,
                    'sub_total_before_tax' => $newSubTotalBeforeTax,
                ]),
            ]);

            $this->recalculateCart();
            Log::warning('No tax ID provided for product', ['id' => $id]);
        }
    }

    public function handleTaxIncluded()
    {
        $cart_items = Cart::instance($this->cart_instance)->content();

        foreach ($cart_items as $cart_item) {
            $row_id = $cart_item->rowId;

            // Retrieve required data for calculations
            $price = $cart_item->price;
            $quantity = $cart_item->qty;
            $discount = $cart_item->options->product_discount ?? 0;
            $tax_id = $this->product_tax[$cart_item->id] ?? null;

            // Calculate subtotal and tax for the parent product
            $calculated = $this->calculateSubtotalAndTax($price, $quantity, $discount, $tax_id);

            $newSubTotal = $calculated['sub_total'];
            $newSubTotalBeforeTax = $calculated['subtotal_before_tax'];

            // Update the cart item with the new totals and bundle details
            Cart::instance($this->cart_instance)->update($row_id, [
                'options' => array_merge($cart_item->options->toArray(), [
                    'product_tax' => $tax_id,
                    'sub_total' => $newSubTotal,
                    'sub_total_before_tax' => $newSubTotalBeforeTax,
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
        } else { // fixed
            if ($this->global_discount > $total_sub_total) {
                $this->global_discount = $total_sub_total;
                session()->flash('message', 'Diskon global tidak boleh melebihi total!');
            } elseif ($this->global_discount < 0) {
                $this->global_discount = 0;
                session()->flash('message', 'Diskon global tidak boleh kurang dari 0!');
            }
        }

        $this->recalculateCart();
    }
}
