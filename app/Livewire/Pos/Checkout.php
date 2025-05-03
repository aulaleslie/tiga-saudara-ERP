<?php

namespace App\Livewire\Pos;

use Gloudemans\Shoppingcart\Facades\Cart;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Unit;

class Checkout extends Component
{
    public $listeners = [
        'productSelected',
        'discountModalRefresh',
        'customerSelected' => 'setCustomer',
    ];

    public $cart_instance;
    public $customers;
    public $global_discount;
    public $global_tax;
    public $shipping;
    public $quantity;
    public $check_quantity;
    public $discount_type;
    public $item_discount;
    public $data;
    public $customer_id;
    public $customer_tier;
    public $total_amount;
    public $conversion_breakdowns = [];

    public function mount($cartInstance, $customers)
    {
        $this->cart_instance = $cartInstance;
        $this->customers = $customers;
        $this->global_discount = 0;
        $this->global_tax = 0;
        $this->shipping = 0.00;
        $this->check_quantity = [];
        $this->quantity = [];
        $this->discount_type = [];
        $this->item_discount = [];
        $this->total_amount = 0;
    }

    public function hydrate()
    {
        $this->total_amount = $this->calculateTotal();
    }

    public function render()
    {
        $cart_items = Cart::instance($this->cart_instance)->content();

        return view('livewire.pos.checkout', [
            'cart_items' => $cart_items,
        ]);
    }

    public function proceed()
    {
        if ($this->customer_id != null) {
            $this->dispatch('showCheckoutModal');
        } else {
            session()->flash('message', 'Please Select Customer!');
        }
    }

    public function calculateTotal()
    {
        $total = 0;

        foreach (Cart::instance($this->cart_instance)->content() as $item) {
            $total += $item->options['sub_total'] ?? ($item->price * $item->qty);
        }

        return $total + $this->shipping;
    }

    public function resetCart()
    {
        Cart::instance($this->cart_instance)->destroy();
    }

    public function productSelected($product)
    {
        if ($product['product_quantity'] == 0) {
            session()->flash('message', 'Product is out of stock!');
            return;
        }

        $cart = Cart::instance($this->cart_instance);

        $exists = $cart->search(fn($cartItem, $rowId) => $cartItem->id == $product['id']);

        if ($exists->isNotEmpty()) {
            session()->flash('message', 'Product exists in the cart!');
            return;
        }

        $calculated = $this->calculate($product, 1);

        $cart->add([
            'id' => $product['id'],
            'name' => $product['product_name'],
            'qty' => 1,
            'price' => $calculated['unit_price'],
            'weight' => 1,
            'options' => [
                'product_discount' => 0.00,
                'product_discount_type' => 'fixed',
                'sub_total' => $calculated['sub_total'],
                'code' => $product['product_code'],
                'stock' => $product['product_quantity'],
                'unit' => $product['product_unit'],
                'product_tax' => 0,
                'unit_price' => $calculated['unit_price'],
            ],
        ]);

        $this->check_quantity[$product['id']] = $product['product_quantity'];
        $this->quantity[$product['id']] = 1;
        $this->discount_type[$product['id']] = 'fixed';
        $this->item_discount[$product['id']] = 0;
        $this->total_amount = $this->calculateTotal();
    }

    public function removeItem($row_id)
    {
        Cart::instance($this->cart_instance)->remove($row_id);
    }

    public function updatedGlobalTax()
    {
        Cart::instance($this->cart_instance)->setGlobalTax((int) $this->global_tax);
    }

    public function updatedGlobalDiscount()
    {
        Cart::instance($this->cart_instance)->setGlobalDiscount((int) $this->global_discount);
    }

    public function updateQuantity($row_id, $product_id)
    {
        if ($this->check_quantity[$product_id] < $this->quantity[$product_id]) {
            session()->flash('message', 'The requested quantity is not available in stock.');
            return;
        }

        $newQty = $this->quantity[$product_id];
        $product = Product::find($product_id);
        if (!$product) return;

        $calculated = $this->calculate($product->toArray(), $newQty);

        Cart::instance($this->cart_instance)->update($row_id, [
            'qty' => $newQty,
            'price' => $calculated['unit_price'],
            'options' => array_merge(Cart::instance($this->cart_instance)->get($row_id)->options->toArray(), [
                'unit_price' => $calculated['unit_price'],
                'sub_total' => $calculated['sub_total'],
            ]),
        ]);

        $this->conversion_breakdowns[$product_id] = $this->calculateConversionBreakdown($product_id, $newQty);
        $this->total_amount = $this->calculateTotal();
    }

    public function updatedDiscountType($value, $name)
    {
        $this->item_discount[$name] = 0;
    }

    public function discountModalRefresh($product_id, $row_id)
    {
        $this->updateQuantity($row_id, $product_id);
    }

    public function setProductDiscount($row_id, $product_id)
    {
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

        $discount = $this->discount_type[$product_id] === 'fixed'
            ? $this->item_discount[$product_id]
            : ($cart_item->price + $cart_item->options->product_discount) * ($this->item_discount[$product_id] / 100);

        Cart::instance($this->cart_instance)->update($row_id, [
            'price' => ($cart_item->price + $cart_item->options->product_discount) - $discount,
        ]);

        $this->updateCartOptions($row_id, $product_id, $cart_item, $discount);

        session()->flash('discount_message' . $product_id, 'Discount added to the product!');
    }

    public function calculate($product, $qty = 1)
    {
        if ($this->customer_tier === 'WHOLESALER') {
            $unit_price = $product['tier_1_price'] ?? $product['sale_price'];
            $sub_total = $unit_price * $qty;
        } elseif ($this->customer_tier === 'RESELLER') {
            $unit_price = $product['tier_2_price'] ?? $product['sale_price'];
            $sub_total = $unit_price * $qty;
        } else {
            // Calculate conversion-based total and average price
            $conversion = $this->calculateCascadingUnitPrice($product['id'], $qty, $product['sale_price']);
            $unit_price = $conversion['price'];
            $sub_total = $conversion['sub_total'];
        }

        return [
            'price' => $unit_price,        // average or fallback
            'unit_price' => $unit_price,   // same for consistency
            'product_tax' => 0,
            'sub_total' => $sub_total,     // total price of qty
        ];
    }

    private function calculateCascadingUnitPrice(int $productId, int $quantity, float $fallback): array
    {
        $conversions = ProductUnitConversion::where('product_id', $productId)
            ->orderByDesc('conversion_factor')
            ->get();

        $total_cost = 0;
        $used_qty = 0;
        $remaining = $quantity;

        foreach ($conversions as $conv) {
            if ($conv->conversion_factor < 1 || $conv->price === null) continue;

            $factor = $conv->conversion_factor;
            $price = $conv->price;

            $unit_count = floor($remaining / $factor);
            if ($unit_count > 0) {
                $total_cost += $unit_count * $price;
                $used_qty += $unit_count * $factor;
                $remaining -= $unit_count * $factor;
            }
        }

        if ($remaining > 0) {
            $total_cost += $remaining * $fallback;
            $used_qty += $remaining;
        }

        return [
            'price' => $used_qty > 0 ? round($total_cost / $used_qty, 2) : $fallback,
            'sub_total' => round($total_cost, 2),
        ];
    }

    private function calculateConversionBreakdown(int $productId, int $quantity): string
    {
        if ($quantity < 1) return '';

        $conversions = ProductUnitConversion::with(['unit', 'baseUnit'])
            ->where('product_id', $productId)
            ->orderByDesc('conversion_factor')
            ->get();

        $parts = [];
        $remaining = $quantity;

        foreach ($conversions as $conv) {
            $factor = (int) $conv->conversion_factor;
            if ($factor < 1) continue;

            $count = intdiv($remaining, $factor);
            if ($count > 0) {
                $unitName = optional($conv->unit)->name ?? "unit";
                $parts[] = "{$count} {$unitName}(s)";
                $remaining -= $count * $factor;
            }
        }

        if ($remaining > 0) {
            $baseUnitId = $conversions->first()->base_unit_id ?? null;
            $baseName = optional(Unit::find($baseUnitId))->name ?? "pc";
            $parts[] = "{$remaining} {$baseName}(s)";
        }

        return implode(', ', $parts);
    }

    public function setCustomer($customer)
    {
        $this->customer_id = $customer['id'];
        $this->customer_tier = $customer['tier'] ?? null;

        foreach (Cart::instance($this->cart_instance)->content() as $item) {
            $product = Product::find($item->id);
            if (!$product) continue;

            $productData = $product->toArray();
            $qty = $item->qty;
            $calculated = $this->calculate($productData, $qty);

            Cart::instance($this->cart_instance)->update($item->rowId, [
                'price' => $calculated['unit_price'],
                'options' => array_merge($item->options->toArray(), [
                    'unit_price' => $calculated['unit_price'],
                    'product_tax' => $calculated['product_tax'],
                    'sub_total' => $calculated['sub_total'],
                ]),
            ]);
        }

        $this->total_amount = $this->calculateTotal();
    }

    public function triggerCustomerModal()
    {
        $this->dispatch('openCustomerModal');
    }

    public function updateCartOptions($row_id, $product_id, $cart_item, $discount_amount)
    {
        Cart::instance($this->cart_instance)->update($row_id, [
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $cart_item->price * $cart_item->qty,
                'product_discount' => $discount_amount,
                'product_discount_type' => $this->discount_type[$product_id],
            ]),
        ]);
    }
}
