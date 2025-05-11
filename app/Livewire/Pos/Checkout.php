<?php

namespace App\Livewire\Pos;

use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\PaymentMethod;
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
    public $unit_price;
    public $discount_type;
    public $item_discount;
    public $data;
    public $customer_id;
    public $customer_tier;
    public $total_amount;
    public $conversion_breakdowns = [];
    public $pendingProduct = null;
    public $bundleOptions = [];
    public $paymentMethods = [];
    public $selected_payment_method_id = null;

    public function mount($cartInstance, $customers)
    {
        $this->cart_instance = $cartInstance;
        $this->customers = $customers;
        $this->global_discount = 0;
        $this->global_tax = 0;
        $this->shipping = 0.00;
        $this->check_quantity = [];
        $this->quantity = [];
        $this->unit_price = [];
        $this->discount_type = [];
        $this->item_discount = [];
        $this->total_amount = 0;

        session('setting_id');
        $this->paymentMethods = PaymentMethod::where('setting_id', session('setting_id'))->get();
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

        // Check for bundles
        $bundles = ProductBundle::with('items.product')
            ->where('parent_product_id', $product['id'])
            ->get();

        if ($bundles->isNotEmpty()) {
            $this->pendingProduct = $product;
            $this->bundleOptions = $bundles;
            $this->dispatch('showBundleSelectionModal', $this->pendingProduct, $this->bundleOptions);
            return;
        }

        $cartKey = $this->generateCartItemKey($product['id'], null);
        $cart = Cart::instance($this->cart_instance);
        $exists = $cart->search(fn($item) => $item->id === $cartKey);

        if ($exists->isNotEmpty()) {
            $rowId = $exists->first()->rowId;
            $newQty = $exists->first()->qty + (int) $product['conversion_factor'];
            $this->quantity[$cartKey] = $newQty;
            $this->check_quantity[$cartKey] = $product['product_quantity'];
            $this->updateQuantity($rowId, $cartKey);
            return;
        }

        $this->addProduct($product, null);
    }

    public function addProduct($product, $bundle = null)
    {
        $cartKey = $this->generateCartItemKey($product['id'], $bundle['id'] ?? null);
        $convertedQty = (int) $product['conversion_factor'];
        $calculated = $this->calculate($product, $convertedQty);

        $final_sub_total = $calculated['sub_total'];
        $bundle_items = [];
        $bundle_price = 0;
        $bundle_name = null;

        if ($bundle) {
            $bundle_price = $bundle['price'];
            $bundle_name = $bundle['name'];
            $final_sub_total += $bundle_price;

            foreach ($bundle['items'] as $item) {
                $bundle_items[] = [
                    'bundle_id' => $bundle['id'],
                    'bundle_item_id' => $item['id'],
                    'product_id' => $item['product_id'],
                    'name' => $item['product']['product_name'],
                    'quantity' => $item['quantity'],
                ];
            }
        }

        Cart::instance($this->cart_instance)->add([
            'id' => $cartKey,
            'name' => $product['product_name'],
            'qty' => $convertedQty,
            'price' => $final_sub_total,
            'weight' => 1,
            'options' => [
                'product_discount' => 0.00,
                'product_discount_type' => 'fixed',
                'sub_total' => $final_sub_total,
                'code' => $product['product_code'],
                'stock' => $product['product_quantity'],
                'product_tax' => 0,
                'unit_price' => $calculated['unit_price'],
                'bundle_items' => $bundle_items,
                'bundle_price' => $bundle_price,
                'bundle_name' => $bundle_name,
            ],
        ]);

        $this->check_quantity[$cartKey] = $product['product_quantity'];
        $this->quantity[$cartKey] = $convertedQty;
        $this->discount_type[$cartKey] = 'fixed';
        $this->item_discount[$cartKey] = 0;
        $this->total_amount = $this->calculateTotal();
    }

    public function confirmBundleSelection($bundleId)
    {
        $bundle = ProductBundle::with('items.product')->find($bundleId);
        if (!$bundle || !$this->pendingProduct) {
            session()->flash('message', 'Invalid bundle selected.');
            return;
        }

        $cartKey = $this->generateCartItemKey($this->pendingProduct['id'], $bundle->id);
        $cart = Cart::instance($this->cart_instance);
        $exists = $cart->search(fn($item) => $item->id === $cartKey);

        if ($exists->isNotEmpty()) {
            $rowId = $exists->first()->rowId;
            $newQty = $exists->first()->qty + (int) $this->pendingProduct['conversion_factor'];
            $this->quantity[$cartKey] = $newQty;
            $this->check_quantity[$cartKey] = $this->pendingProduct['product_quantity'];
            $this->updateQuantity($rowId, $cartKey);
            $this->pendingProduct = null;
            $this->bundleOptions = [];
            return;
        }

        $this->addProduct($this->pendingProduct, $bundle->toArray());
        $this->pendingProduct = null;
        $this->bundleOptions = [];
    }

    public function removeItem($row_id)
    {
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);
        $cart_key = $cart_item->id;

        Cart::instance($this->cart_instance)->remove($row_id);

        unset($this->quantity[$cart_key]);
        unset($this->check_quantity[$cart_key]);
        unset($this->unit_price[$cart_key]);
        unset($this->discount_type[$cart_key]);
        unset($this->item_discount[$cart_key]);
    }

    public function updatedGlobalTax()
    {
        Cart::instance($this->cart_instance)->setGlobalTax((int) $this->global_tax);
    }

    public function updatedGlobalDiscount()
    {
        Cart::instance($this->cart_instance)->setGlobalDiscount((int) $this->global_discount);
    }

    public function updateQuantity($row_id, $cart_key)
    {
        if ($this->check_quantity[$cart_key] < $this->quantity[$cart_key]) {
            session()->flash('message', 'The requested quantity is not available in stock.');
            return;
        }

        $newQty = $this->quantity[$cart_key];
        $product_id = explode('-', $cart_key)[0]; // extract product ID
        $product = Product::find($product_id);
        if (!$product) return;

        $calculated = $this->calculate($product->toArray(), $newQty);

        Cart::instance($this->cart_instance)->update($row_id, [
            'qty' => $newQty,
            'price' => $calculated['unit_price'],
            'options' => array_merge(
                Cart::instance($this->cart_instance)->get($row_id)->options->toArray(), [
                    'unit_price' => $calculated['unit_price'],
                    'sub_total' => $calculated['sub_total'],
                ]
            ),
        ]);

        $this->conversion_breakdowns[$cart_key] = $this->calculateConversionBreakdown($product_id, $newQty);
        $this->total_amount = $this->calculateTotal();
    }

    public function updatedDiscountType($value, $name)
    {
        $this->item_discount[$name] = 0;
    }

    public function discountModalRefresh($cart_key, $row_id)
    {
        $this->updateQuantity($row_id, $cart_key);
    }

    public function setProductDiscount($row_id, $cart_key)
    {
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

        $discount = $this->discount_type[$cart_key] === 'fixed'
            ? $this->item_discount[$cart_key]
            : ($cart_item->price + $cart_item->options->product_discount) * ($this->item_discount[$cart_key] / 100);

        Cart::instance($this->cart_instance)->update($row_id, [
            'price' => ($cart_item->price + $cart_item->options->product_discount) - $discount,
        ]);

        $this->updateCartOptions($row_id, $cart_key, $cart_item, $discount);

        session()->flash('discount_message' . $cart_key, 'Discount added to the product!');
    }

    private function generateCartItemKey($productId, $bundleId = null): string
    {
        return $bundleId ? "{$productId}-bundle-{$bundleId}" : (string) $productId;
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

    public function updateCartOptions($row_id, $cart_key, $cart_item, $discount_amount)
    {
        Cart::instance($this->cart_instance)->update($row_id, [
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $cart_item->price * $cart_item->qty,
                'product_discount' => $discount_amount,
                'product_discount_type' => $this->discount_type[$cart_key],
            ]),
        ]);
    }
}
