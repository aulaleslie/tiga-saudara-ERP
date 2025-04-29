<?php

namespace App\Livewire\Purchase;

use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;

class ProductCart extends Component
{
    public $listeners = ['productSelected', 'discountModalRefresh'];

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
    public $data;
    public $quantityBreakdowns = [];

    public $taxes; // Collection of taxes filtered by setting_id
    public $setting_id; // Current setting ID
    public $product_tax = []; // Array to store selected tax IDs for each product

    public $is_tax_included = false;
    private $product;

    public $global_discount_type = 'percentage';

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
        $cart_items = Cart::instance($this->cart_instance)->content();
        Log::info('mount() called at: ' . round(microtime(true) * 1000), [
            'cart_instance' => $cartInstance,
            'cart_items' => $cart_items,
        ]);
        $this->cart_instance = $cartInstance;
        $this->setting_id = session('setting_id');
        $this->taxes = Tax::where('setting_id', $this->setting_id)->get();
        Log::info('validated', [
            'data' => $data,
        ]);

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
                $qty = $this->quantity[$cart_item->id] ?? $cart_item->qty;
                $this->quantityBreakdowns[$cart_item->id] = $this->calculateConversionBreakdown($cart_item->id, $qty);
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

    private function initializeCartItemAttributes($cart_item)
    {
        $this->check_quantity[$cart_item->id] = [$cart_item->options->stock ?? 0];
        $this->quantity[$cart_item->id] = $cart_item->qty ?? 0;
        $this->unit_price[$cart_item->id] = $cart_item->price ?? 0;
        $this->discount_type[$cart_item->id] = $cart_item->options->product_discount_type ?? 'fixed';
        $this->item_discount[$cart_item->id] = $cart_item->options->product_discount ?? 0;
        $this->product_tax[$cart_item->id] = $cart_item->options->product_tax ?? null;
    }

    private function calculateConversionBreakdown(int $productId, int $quantity): string
    {
        if ($quantity < 1) {
            return '';
        }

        // 1) get all conversions for this product, biggest first
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
                // assume you have a relation to Unit for the name:
                $unitName = optional($conv->unit)->name ?? "unit";
                $parts[] = "{$count} {$unitName}(s)";
                $remaining -= $count * $factor;
            }
        }

        // 2) whatever is left is in the base unit:
        if ($remaining > 0) {
            // you can grab the base unit name however your schema defines it:
            $baseUnitId = $conversions->first()->base_unit_id ?? null;
            $baseName   = optional(Unit::find($baseUnitId))->name ?? "pc";
            $parts[]    = "{$remaining} {$baseName}(s)";
        }

        return implode(', ', $parts);
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $cart_items = Cart::instance($this->cart_instance)->content();

        Log::info('render() called at: ' . round(microtime(true) * 1000), [
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

        // Calculate global discount amount
        if ($this->global_discount_type == 'percentage') {
            $global_discount_amount = $total_sub_total * ($this->global_discount/100);
        } else {
            $global_discount_amount = $this->global_discount;
        }

        // Apply discount and shipping to calculate grand total
        $grand_total = ($total_sub_total - $global_discount_amount) + (float) $this->shipping;

        // Log the final totals for debugging
        Log::info('Final totals calculated', [
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
        Log::info('Product Selected:', $product);
        $cart = Cart::instance($this->cart_instance);

        $exists = $cart->search(function ($cartItem, $rowId) use ($product) {
            return $cartItem->id == $product['id'];
        });

        if ($exists->isNotEmpty()) {
            session()->flash('message', 'Produk sudah dimasukkan!');
            return;
        }

        $this->product = $product;

        $cart->add([
            'id'      => $product['id'],
            'name'    => $product['product_name'],
            'qty'     => 1,
            'price'   => $this->calculate($product)['price'],
            'weight'  => 1,
            'options' => [
                'product_discount'      => 0.00,
                'product_discount_type' => 'fixed',
                'sub_total'             => $this->calculate($product)['sub_total'],
                'code'                  => $product['product_code'],
                'stock'                 => $product['product_quantity'],
                'unit'                  => $product['product_unit'],
                'last_purchase_price' => $product['last_purchase_price'],
                'average_purchase_price' => $product['average_purchase_price'],
                'product_tax'           => null, // Initialize as null
                'unit_price'            => $this->calculate($product)['unit_price']
            ]
        ]);

        $this->check_quantity[$product['id']] = $product['product_quantity'];
        $this->quantity[$product['id']] = 1;
        $this->discount_type[$product['id']] = 'fixed';
        $this->item_discount[$product['id']] = 0;
        $this->product_tax[$product['id']] = null; // Initialize per-product tax
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

    public function updateQuantity($row_id, $product_id)
    {
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

        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

        // Use calculateSubtotalAndTax function to calculate
        $calculated = $this->calculateSubtotalAndTax(
            $this->unit_price[$product_id] ?? $cart_item->price,
            $this->quantity[$product_id],
            $cart_item->options->product_discount ?? 0,
            $this->product_tax[$product_id] ?? null
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
                'sub_total_before_tax' => $calculated['subtotal_before_tax'],
                'tax_amount' => $calculated['tax_amount'],
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
        $price = max(0, (float) $price); // Ensure price is non-negative
        $qty = max(1, (int) $qty);
        $discount = max(0, (int) $discount);

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
        // Fetch cart item
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

        // Retrieve the unit price (fallback to 'price' if 'unit_price' is missing)
        $unit_price = $this->unit_price[$product_id] ?? $cart_item->price;
        $quantity = $cart_item->qty;

        Log::info('SetProductDiscount - Initial Values', [
            'row_id' => $row_id,
            'product_id' => $product_id,
            'unit_price' => $unit_price,
            'quantity' => $quantity,
            'current_discount' => $cart_item->options['product_discount'] ?? 0,
        ]);

        // Sanitize and validate discount input
        $raw_discount_input = $this->item_discount[$product_id] ?? 0;
        $sanitized_discount_input = is_numeric($raw_discount_input) ? (float) $raw_discount_input : 0;

        // Calculate discount amount
        $discount_amount = 0;
        if ($this->discount_type[$product_id] == 'fixed') {
            $discount_amount = $sanitized_discount_input;
        } elseif ($this->discount_type[$product_id] == 'percentage') {
            $discount_amount = $unit_price * ($sanitized_discount_input / 100);
        }

        // Ensure discount does not exceed the unit price
        if ($discount_amount > $unit_price) {
            $discount_amount = $unit_price;
        }

        Log::info('SetProductDiscount - Calculated Discount', [
            'discount_amount' => $discount_amount,
            'discount_type' => $this->discount_type[$product_id],
        ]);

        // Adjust price and subtotal based on tax inclusion
        $adjusted_price = $unit_price;
        $updated_cart_data = $this->calculateSubtotalAndTax(
            $adjusted_price,
            $quantity,
            $discount_amount,
            $this->product_tax[$product_id] ?? null
        );

        Log::info('SetProductDiscount - Updated Cart Data', [
            'adjusted_price' => $adjusted_price,
            'updated_cart_data' => $updated_cart_data,
        ]);

        // Update the cart row with recalculated values
        Cart::instance($this->cart_instance)->update($row_id, [
            'unit_price' => $this->is_tax_included ? $adjusted_price : $unit_price,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $updated_cart_data['sub_total'],
                'sub_total_before_tax' => $updated_cart_data['subtotal_before_tax'],
                'product_discount' => $discount_amount,
                'product_discount_type' => $this->discount_type[$product_id],
            ]),
        ]);

        // Trigger cart recalculation
        $this->recalculateCart();

        // Flash success message
        session()->flash('discount_message' . $product_id, 'Discount applied to the product!');

        // Log the updated values
        Log::info('SetProductDiscount - Final Update', [
            'row_id' => $row_id,
            'product_id' => $product_id,
            'product_discount' => $discount_amount,
            'updated_cart_data' => $updated_cart_data,
        ]);
    }

    public function updatePrice($row_id, $product_id)
    {
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

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
            $this->product_tax[$product_id] ?? null
        );

        // Update cart item
        Cart::instance($this->cart_instance)->update($row_id, [
            'price' => $new_price,
            'unit_price' => $new_price,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $calculated['sub_total'],
                'sub_total_before_tax' => $calculated['subtotal_before_tax'],
                'tax_amount' => $calculated['tax_amount'],
                'product_discount' => $discount_amount,
            ]),
        ]);

        $this->recalculateCart();
    }

    public function calculate($product, $new_price = null)
    {
        // Determine the base price
        $product_price = $product['last_purchase_price'] ?? 0;

        $product_tax = 0;
        $sub_total = $product_price; // Start with base price as subtotal

        return [
            'price' => $product_price,
            'unit_price' => $product_price,
            'product_tax' => $product_tax,
            'sub_total' => $sub_total,
        ];
    }

    public function updateCartOptions($row_id, $product_id, $cart_item, $discount_amount)
    {
        Cart::instance($this->cart_instance)->update($row_id, ['options' => [
            'sub_total'             => $cart_item->price * $cart_item->qty,
            'code'                  => $cart_item->options->code,
            'stock'                 => $cart_item->options->stock,
            'unit'                  => $cart_item->options->unit,
            'product_tax'           => $cart_item->options->product_tax,
            'unit_price'            => $cart_item->options->unit_price,
            'product_discount'      => $discount_amount,
            'product_discount_type' => $this->discount_type[$product_id],
            'last_purchase_price'   => $cart_item->options->last_purchase_price, // Preserve
            'average_purchase_price'=> $cart_item->options->average_purchase_price, // Preserve
        ]]);
    }

    public function recalculateCart()
    {
        // Trigger a re-render to update totals
        $this->render();
    }

    public function updateTax($row_id, $product_id)
    {
        // Fetch the cart item
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

        // Get the selected tax ID
        $tax_id = !empty($this->product_tax[$product_id]) ? $this->product_tax[$product_id] : null;

        // Initialize tax amount and validate the tax ID
        $tax_amount = 0;
        if ($tax_id) {
            $tax = Tax::find($tax_id);
            if ($tax) {
                Log::info('Tax applied', [
                    'product_id' => $product_id,
                    'tax_id' => $tax_id,
                    'tax_value' => $tax->value,
                ]);

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
                        'sub_total_before_tax' => $updated_cart_data['subtotal_before_tax'],
                    ]),
                ]);

                // Trigger cart recalculation
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

            Cart::instance($this->cart_instance)->update($row_id, [
                'options' => array_merge($cart_item->options->toArray(), [
                    'product_tax' => $tax_id,
                    'sub_total' => $updated_cart_data['sub_total'],
                    'sub_total_before_tax' => $updated_cart_data['subtotal_before_tax'],
                ]),
            ]);

            $this->recalculateCart();
            Log::warning('No tax ID provided for product', ['product_id' => $product_id]);
        }
    }

    public function handleTaxIncluded()
    {
        $cart_items = Cart::instance($this->cart_instance)->content();

        foreach ($cart_items as $cart_item) {
            $product_id = $cart_item->id;
            $row_id = $cart_item->rowId;

            // Retrieve required data for calculations
            $price = $cart_item->price;
            $quantity = $cart_item->qty;
            $discount = $cart_item->options->product_discount ?? 0;
            $tax_id = $this->product_tax[$product_id] ?? null;

            // Calculate subtotal and tax using the helper function
            $calculated = $this->calculateSubtotalAndTax($price, $quantity, $discount, $tax_id);

            // Update the cart item with the calculated values
            Cart::instance($this->cart_instance)->update($row_id, [
                'options' => array_merge($cart_item->options->toArray(), [
                    'product_tax' => $tax_id,
                    'sub_total' => $calculated['sub_total'],
                    'sub_total_before_tax' => $calculated['subtotal_before_tax'],
                ]),
            ]);

            // Log the updated cart item for debugging purposes
            Log::info('Updated cart item for tax inclusion', [
                'row_id' => $row_id,
                'sub_total' => $calculated['sub_total'],
                'sub_total_before_tax' => $calculated['subtotal_before_tax'],
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
