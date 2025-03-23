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
use Modules\Setting\Entities\Tax;

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
            'cart_items' => $cart_items,
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

                // Calculate new price for the parent product.
                $calculated = $this->calculate($product);

                // Initialize bundle totals.
                $bundleTotal = 0;

                // If bundle items exist, recalculate each.
                if (is_array($cart_item->options->bundle_items)) {
                    $updatedBundleItems = [];
                    foreach ($cart_item->options->bundle_items as $bundleItem) {
                        // Retrieve bundle product details.
                        $bundleProduct = Product::find($bundleItem['product_id']);
                        if (!$bundleProduct) {
                            Log::warning('Bundle product not found', ['product_id' => $bundleItem['product_id']]);
                            continue;
                        }
                        $bundleTotal += $bundleItem['sub_total'];
                    }
                }

                // Calculate the parent's subtotal based on its recalculated price and quantity.
                // (Assuming the calculate() function returns the base price/subtotal for a quantity of 1)
                $parentSubtotal = $calculated['sub_total'];

                // New overall subtotal = parent's subtotal + total for all bundle items.
                $newSubTotal = $parentSubtotal + $bundleTotal;

                // Update the cart item with the new parent price and updated bundle items.
                $cart->update($cart_item->rowId, [
                    'price' => $calculated['price'],
                    'unit_price' => $calculated['unit_price'],
                    'options' => array_merge($cart_item->options->toArray(), [
                        'sub_total' => $newSubTotal,
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

        return $cartItem->rowId;
    }

    public function confirmBundleSelection($bundleId): void
    {
        $cart = Cart::instance($this->cart_instance);
        $bundle = ProductBundle::find($bundleId);
        if (!$bundle) {
            session()->flash('message', 'Invalid bundle selected.');
            return;
        }

        // Build the bundle items details array from the selected bundle
        $selectedBundleItems = [];
        $bundleItems = ProductBundleItem::where('bundle_id', $bundle->id)->get();
        foreach ($bundleItems as $bundleItem) {
            $bundleProduct = Product::find($bundleItem->product_id);
            if (!$bundleProduct) {
                continue;
            }
            $selectedBundleItems[] = [
                'bundle_id'       => $bundle->id,
                'bundle_item_id'  => $bundleItem->id,
                'product_id'      => $bundleProduct->id,
                'name'            => $bundleProduct->product_name,
                'price'           => $bundleItem->price,
                'quantity'        => $bundleItem->quantity, // base quantity defined in the bundle
                'sub_total'       => $bundleItem->price * $bundleItem->quantity,
            ];
        }

        // Calculate the total price for the bundle items
        $bundleTotal = 0;
        foreach ($selectedBundleItems as $item) {
            $bundleTotal += $item['sub_total'];
        }

        // If there's a pending product, add it along with bundle details
        if ($this->pendingProduct) {
            // Get stock data for the pending product
            $stockData = ProductStock::where('product_id', $this->pendingProduct['id'])
                ->selectRaw('SUM(quantity_non_tax) as quantity_non_tax, SUM(quantity_tax) as quantity_tax')
                ->first();

            // Calculate the parent's base price and subtotal
            $parentCalculated = $this->calculate($this->pendingProduct);
            // Add the bundle total to the parent's subtotal
            $final_sub_total = $parentCalculated['sub_total'] + $bundleTotal;
            // Optionally, if you maintain a subtotal_before_tax, you could do a similar calculation:
            // $final_sub_total_before_tax = $parentCalculated['subtotal_before_tax'] + $bundleTotal;

            // Add the product to the cart with the updated subtotal that includes the bundle items
            $cartItem = $cart->add([
                'id' => Str::uuid()->toString(), // Unique id for cart row
                'product_id' => $this->pendingProduct['id'],
                'name' => $this->pendingProduct['product_name'],
                'qty' => 1,
                'price' => $parentCalculated['price'], // parent's price remains unchanged
                'weight' => 1,
                'options' => [
                    'product_discount' => 0.00,
                    'product_discount_type' => 'fixed',
                    'sub_total' => $final_sub_total, // parent's subtotal plus bundle total
                    'code' => $this->pendingProduct['product_code'],
                    'stock' => $this->pendingProduct['product_quantity'],
                    'unit' => $this->pendingProduct['product_unit'],
                    'product_tax' => null, // Initialize as null
                    'unit_price' => $parentCalculated['unit_price'],
                    'sale_price' => $this->pendingProduct['sale_price'] ?? 0,
                    'tier_1_price' => $this->pendingProduct['tier_1_price'] ?? 0,
                    'tier_2_price' => $this->pendingProduct['tier_2_price'] ?? 0,
                    'quantity_non_tax' => $stockData->quantity_non_tax ?? 0,
                    'quantity_tax' => $stockData->quantity_tax ?? 0,
                    'bundle_items' => $selectedBundleItems,
                ]
            ]);

            // Initialize component arrays for the new cart row
            $this->initializeCartItemAttributes($cartItem);

            session()->flash('message', 'Bundle items added successfully.');
            // Clear pending product and bundle options
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

        if ($this->check_quantity[$id] < $this->quantity[$id]) {
            session()->flash('message', 'The requested quantity is not available in stock.');
            $this->quantity[$id] = $this->check_quantity[$id];
            return;
        }

        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

        // First, recalculate the parent product's subtotal (excluding any bundle extras)
        $calculated = $this->calculateSubtotalAndTax(
            $this->unit_price[$id] ?? $cart_item->price,
            $this->quantity[$id] ?? 0,
            $cart_item->options->product_discount ?? 0,
            $this->product_tax[$id] ?? null
        );

        $bundleTotal = 0;
        $updatedBundleItems = $cart_item->options->bundle_items ?? null;

        // If the cart item includes bundle items, update each of them
        if (is_array($cart_item->options->bundle_items)) {

            $updatedBundleItems = [];
            foreach ($cart_item->options->bundle_items as $bundleItem) {

                Log::info('called', []);
                $bundleItemDb = ProductBundleItem::find($bundleItem['bundle_item_id']);
                // For each bundle item, multiply its defined quantity by the parent's quantity
                $newBundleQuantity = $bundleItemDb['quantity'] * $this->quantity[$id];
                $newBundleSubTotal = $bundleItem['price'] * $newBundleQuantity;
                $bundleTotal += $newBundleSubTotal;
                // Optionally, store the computed quantity (if you want to display it)
                $bundleItem['quantity'] = $newBundleQuantity;
                // Update the bundle item's sub_total to the new calculated value
                $bundleItem['sub_total'] = $newBundleSubTotal;
                $updatedBundleItems[] = $bundleItem;
            }
        }

        // Adjust the parent's subtotal to include the total from bundle items.
        // Depending on your business logic, you may also want to update the sub_total_before_tax.
        $newSubTotal = $calculated['sub_total'] + $bundleTotal;
        $newSubTotalBeforeTax = $calculated['subtotal_before_tax'] + $bundleTotal;

        // Update the cart item with new quantity and recalculated totals
        Cart::instance($this->cart_instance)->update($row_id, [
            'qty' => $this->quantity[$id],
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $newSubTotal,
                'sub_total_before_tax' => $newSubTotalBeforeTax,
                'tax_amount' => $calculated['tax_amount'], // assuming the tax calculation applies only to the parent product
                'bundle_items' => $updatedBundleItems,
            ]),
        ]);

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
        $subtotal_before_tax = 0;
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

    public function setProductDiscount($row_id, $id): void
    {
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);
        $unit_price = $this->unit_price[$id] ?? $cart_item->price;
        $quantity = $cart_item->qty;

        Log::info('SetProductDiscount - Initial Values', [
            'row_id' => $row_id,
            'id' => $id,
            'unit_price' => $unit_price,
            'quantity' => $quantity,
            'current_discount' => $cart_item->options['product_discount'] ?? 0,
        ]);

        $raw_discount_input = $this->item_discount[$id] ?? 0;
        $sanitized_discount_input = is_numeric($raw_discount_input) ? (float)$raw_discount_input : 0;

        $discount_amount = 0;
        if ($this->discount_type[$id] == 'fixed') {
            $discount_amount = $sanitized_discount_input;
        } elseif ($this->discount_type[$id] == 'percentage') {
            $discount_amount = $unit_price * ($sanitized_discount_input / 100);
        }

        if ($discount_amount > $unit_price) {
            $discount_amount = $unit_price;
        }

        Log::info('SetProductDiscount - Calculated Discount', [
            'discount_amount' => $discount_amount,
            'discount_type' => $this->discount_type[$id],
        ]);

        $adjusted_price = $unit_price;
        $updated_cart_data = $this->calculateSubtotalAndTax(
            $adjusted_price,
            $quantity,
            $discount_amount,
            $this->product_tax[$id] ?? null
        );

        Log::info('SetProductDiscount - Updated Cart Data', [
            'adjusted_price' => $adjusted_price,
            'updated_cart_data' => $updated_cart_data,
        ]);

        // Process bundle items if any
        $bundleTotal = 0;
        $updatedBundleItems = $cart_item->options->bundle_items ?? null;
        if (is_array($cart_item->options->bundle_items)) {
            $updatedBundleItems = [];
            foreach ($cart_item->options->bundle_items as $bundleItem) {
                $bundleItemDb = ProductBundleItem::find($bundleItem['bundle_item_id']);
                if (!$bundleItemDb) {
                    Log::warning('Bundle item not found', ['bundle_item_id' => $bundleItem['bundle_item_id']]);
                    continue;
                }
                $newBundleQuantity = $bundleItemDb->quantity * $quantity;
                $newBundleSubTotal = $bundleItem['price'] * $newBundleQuantity;
                $bundleTotal += $newBundleSubTotal;
                $bundleItem['quantity'] = $newBundleQuantity;
                $bundleItem['sub_total'] = $newBundleSubTotal;
                $updatedBundleItems[] = $bundleItem;
            }
        }

        $newSubTotal = $updated_cart_data['sub_total'] + $bundleTotal;
        $newSubTotalBeforeTax = $updated_cart_data['subtotal_before_tax'] + $bundleTotal;

        Cart::instance($this->cart_instance)->update($row_id, [
            'unit_price' => $this->is_tax_included ? $adjusted_price : $unit_price,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $newSubTotal,
                'sub_total_before_tax' => $newSubTotalBeforeTax,
                'product_discount' => $discount_amount,
                'product_discount_type' => $this->discount_type[$id],
                'bundle_items' => $updatedBundleItems,
            ]),
        ]);

        $this->recalculateCart();
        session()->flash('discount_message' . $id, 'Discount applied to the product!');

        Log::info('SetProductDiscount - Final Update', [
            'row_id' => $row_id,
            'id' => $id,
            'product_discount' => $discount_amount,
            'updated_cart_data' => $updated_cart_data,
        ]);
    }

    public function updatePrice($row_id, $id)
    {
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

        // Validate and set new price
        $new_price = $this->unit_price[$id] ?? $cart_item->price;

        // If percentage, recalculate discount amount
        $discount_amount = $cart_item->options->product_discount;
        $raw_discount_input = $this->item_discount[$id] ?? 0;
        $sanitized_discount_input = is_numeric($raw_discount_input) ? (float)$raw_discount_input : 0;
        if ($this->discount_type[$id] == 'percentage') {
            $discount_amount = $new_price * ($sanitized_discount_input / 100);
        }

        // Calculate parent's updated values
        $calculated = $this->calculateSubtotalAndTax(
            $new_price,
            $cart_item->qty,
            $discount_amount,
            $this->product_tax[$id] ?? null
        );

        // Initialize bundle totals
        $bundleTotal = 0;
        $updatedBundleItems = $cart_item->options->bundle_items ?? null;

        // If there are bundle items, update them
        if (is_array($cart_item->options->bundle_items)) {
            $updatedBundleItems = [];
            foreach ($cart_item->options->bundle_items as $bundleItem) {
                $bundleItemDb = ProductBundleItem::find($bundleItem['bundle_item_id']);
                if (!$bundleItemDb) {
                    Log::warning('Bundle item not found', ['bundle_item_id' => $bundleItem['bundle_item_id']]);
                    continue;
                }
                // Recalculate bundle quantity based on the parent's current quantity
                $newBundleQuantity = $bundleItemDb->quantity * $cart_item->qty;
                $newBundleSubTotal = $bundleItem['price'] * $newBundleQuantity;
                $bundleTotal += $newBundleSubTotal;
                // Update bundle item details
                $bundleItem['quantity'] = $newBundleQuantity;
                $bundleItem['sub_total'] = $newBundleSubTotal;
                $updatedBundleItems[] = $bundleItem;
            }
        }

        // Add the bundle total to the parent's calculated subtotal
        $newSubTotal = $calculated['sub_total'] + $bundleTotal;
        $newSubTotalBeforeTax = $calculated['subtotal_before_tax'] + $bundleTotal;

        // Update cart item with the new values
        Cart::instance($this->cart_instance)->update($row_id, [
            'price' => $new_price,
            'unit_price' => $new_price,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $newSubTotal,
                'sub_total_before_tax' => $newSubTotalBeforeTax,
                'tax_amount' => $calculated['tax_amount'],
                'product_discount' => $discount_amount,
                'bundle_items' => $updatedBundleItems,
            ]),
        ]);

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

                // Process bundle items if present
                $bundleTotal = 0;
                $updatedBundleItems = $cart_item->options->bundle_items ?? null;
                if (is_array($cart_item->options->bundle_items)) {
                    $updatedBundleItems = [];
                    foreach ($cart_item->options->bundle_items as $bundleItem) {
                        $bundleItemDb = ProductBundleItem::find($bundleItem['bundle_item_id']);
                        if (!$bundleItemDb) {
                            Log::warning('Bundle item not found', ['bundle_item_id' => $bundleItem['bundle_item_id']]);
                            continue;
                        }
                        $newBundleQuantity = $bundleItemDb->quantity * $cart_item->qty;
                        $newBundleSubTotal = $bundleItem['price'] * $newBundleQuantity;
                        $bundleTotal += $newBundleSubTotal;
                        $bundleItem['quantity'] = $newBundleQuantity;
                        $bundleItem['sub_total'] = $newBundleSubTotal;
                        $updatedBundleItems[] = $bundleItem;
                    }
                }

                $newSubTotal = $updated_cart_data['sub_total'] + $bundleTotal;
                $newSubTotalBeforeTax = $updated_cart_data['subtotal_before_tax'] + $bundleTotal;

                Cart::instance($this->cart_instance)->update($row_id, [
                    'options' => array_merge($cart_item->options->toArray(), [
                        'product_tax' => $tax_id,
                        'sub_total' => $newSubTotal,
                        'sub_total_before_tax' => $newSubTotalBeforeTax,
                        'bundle_items' => $updatedBundleItems,
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

            // Process bundle items if present
            $bundleTotal = 0;
            $updatedBundleItems = $cart_item->options->bundle_items ?? null;
            if (is_array($cart_item->options->bundle_items)) {
                $updatedBundleItems = [];
                foreach ($cart_item->options->bundle_items as $bundleItem) {
                    $bundleItemDb = ProductBundleItem::find($bundleItem['bundle_item_id']);
                    if (!$bundleItemDb) {
                        Log::warning('Bundle item not found', ['bundle_item_id' => $bundleItem['bundle_item_id']]);
                        continue;
                    }
                    $newBundleQuantity = $bundleItemDb->quantity * $cart_item->qty;
                    $newBundleSubTotal = $bundleItem['price'] * $newBundleQuantity;
                    $bundleTotal += $newBundleSubTotal;
                    $bundleItem['quantity'] = $newBundleQuantity;
                    $bundleItem['sub_total'] = $newBundleSubTotal;
                    $updatedBundleItems[] = $bundleItem;
                }
            }

            $newSubTotal = $updated_cart_data['sub_total'] + $bundleTotal;
            $newSubTotalBeforeTax = $updated_cart_data['subtotal_before_tax'] + $bundleTotal;

            Cart::instance($this->cart_instance)->update($row_id, [
                'options' => array_merge($cart_item->options->toArray(), [
                    'product_tax' => $tax_id,
                    'sub_total' => $newSubTotal,
                    'sub_total_before_tax' => $newSubTotalBeforeTax,
                    'bundle_items' => $updatedBundleItems,
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

            // Initialize bundle totals and updated bundle items
            $bundleTotal = 0;
            $updatedBundleItems = $cart_item->options->bundle_items ?? null;

            if (is_array($cart_item->options->bundle_items)) {
                $updatedBundleItems = [];
                foreach ($cart_item->options->bundle_items as $bundleItem) {
                    // Retrieve the original bundle item data from the DB
                    $bundleItemDb = ProductBundleItem::find($bundleItem['bundle_item_id']);
                    if (!$bundleItemDb) {
                        Log::warning('Bundle item not found', ['bundle_item_id' => $bundleItem['bundle_item_id']]);
                        continue;
                    }
                    // Calculate the new bundle quantity based on parent's quantity
                    $newBundleQuantity = $bundleItemDb->quantity * $quantity;
                    $newBundleSubTotal = $bundleItem['price'] * $newBundleQuantity;
                    $bundleTotal += $newBundleSubTotal;
                    // Update bundle item values for display and calculation
                    $bundleItem['quantity'] = $newBundleQuantity;
                    $bundleItem['sub_total'] = $newBundleSubTotal;
                    $updatedBundleItems[] = $bundleItem;
                }
            }

            // Include bundle total to the parent's calculated totals
            $newSubTotal = $calculated['sub_total'] + $bundleTotal;
            $newSubTotalBeforeTax = $calculated['subtotal_before_tax'] + $bundleTotal;

            // Update the cart item with the new totals and bundle details
            Cart::instance($this->cart_instance)->update($row_id, [
                'options' => array_merge($cart_item->options->toArray(), [
                    'product_tax' => $tax_id,
                    'sub_total' => $newSubTotal,
                    'sub_total_before_tax' => $newSubTotalBeforeTax,
                    'bundle_items' => $updatedBundleItems,
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
        $this->recalculateCart();
    }
}
