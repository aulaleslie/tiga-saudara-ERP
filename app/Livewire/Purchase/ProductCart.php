<?php

namespace App\Livewire\Purchase;

use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Tax;

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

    public $taxes; // Collection of taxes filtered by setting_id
    public $setting_id; // Current setting ID
    public $product_tax = []; // Array to store selected tax IDs for each product

    private $product;

    protected $rules = [
        'unit_price.*' => 'required|numeric|min:0',
        'quantity.*' => 'required|integer|min:1',
        'item_discount.*' => 'nullable|numeric|min:0',
        'global_discount' => 'nullable|numeric|min:0|max:100',
        'shipping' => 'required|numeric|min:0',
        'global_tax_id' => 'nullable|integer|exists:taxes,id',
        'product_tax.*' => 'nullable|integer|exists:taxes,id',
    ];

    public function mount($cartInstance, $data = null)
    {
        $this->cart_instance = $cartInstance;

        // Initialize setting_id from user's current setting
        $this->setting_id = session('setting_id');

        // Fetch taxes filtered by setting_id
        $this->taxes = Tax::where('setting_id', $this->setting_id)->get();

        if ($data) {
            $this->data = $data;

            // Assuming $data now contains 'global_tax_id' instead of 'tax_percentage'
            $this->global_tax_id = $data->global_tax_id;
            $this->global_discount = $data->discount_percentage;
            $this->shipping = $data->shipping_amount;

            $this->updatedGlobalTax();
            $this->updatedGlobalDiscount();

            $cart_items = Cart::instance($this->cart_instance)->content();

            foreach ($cart_items as $cart_item) {
                $this->check_quantity[$cart_item->id] = [$cart_item->options->stock];
                $this->quantity[$cart_item->id] = $cart_item->qty;
                $this->unit_price[$cart_item->id] = $cart_item->price;
                $this->discount_type[$cart_item->id] = $cart_item->options->product_discount_type;
                if ($cart_item->options->product_discount_type == 'fixed') {
                    $this->item_discount[$cart_item->id] = $cart_item->options->product_discount;
                } elseif ($cart_item->options->product_discount_type == 'percentage') {
                    $this->item_discount[$cart_item->id] = round(100 * ($cart_item->options->product_discount / $cart_item->price));
                }
                // Initialize per-product tax
                $this->product_tax[$cart_item->id] = $cart_item->options->product_tax ?? null;
            }
        } else {
            $this->global_discount = 0;
            $this->global_tax_id = null;
            $this->shipping = 0.00;
            $this->check_quantity = [];
            $this->quantity = [];
            $this->unit_price = [];
            $this->discount_type = [];
            $this->item_discount = [];
            $this->product_tax = [];
        }
    }

    public function render()
    {
        $cart_items = Cart::instance($this->cart_instance)->content();

        // Calculate cart total
        $cart_total = 0;
        foreach ($cart_items as $item) {
            $cart_total += $item->options->sub_total;
        }

        // Calculate global tax
        $global_tax_amount = 0;
        if ($this->global_tax_id) {
            $selected_tax = Tax::find($this->global_tax_id);
            if ($selected_tax) {
                $global_tax_amount = ($cart_total * ($selected_tax->value / 100));
            }
        }

        // Calculate per-product tax
        $product_tax_amount = 0;
        foreach ($this->product_tax as $id => $tax_id) {
            if ($tax_id) {
                $tax = Tax::find($tax_id);
                if ($tax) {
                    $product = Cart::instance($this->cart_instance)->search(function ($cartItem, $rowId) use ($id) {
                        return $cartItem->id == $id;
                    })->first();

                    if ($product) {
                        $product_tax_amount += ($product->price * ($tax->value / 100)) * $product->qty;
                    }
                }
            }
        }

        // Calculate grand total
        $grand_total = $cart_total + $global_tax_amount + $product_tax_amount + (float) $this->shipping;

        return view('livewire.purchase.product-cart', [
            'cart_items' => $cart_items,
            'cart_total' => $cart_total,
            'grand_total' => $grand_total,
            'taxes' => $this->taxes, // Pass taxes to the view
        ]);
    }

    public function productSelected($product)
    {
        Log::info('Product Selected:', $product);
        $cart = Cart::instance($this->cart_instance);

        $exists = $cart->search(function ($cartItem, $rowId) use ($product) {
            return $cartItem->id == $product['id'];
        });

        if ($exists->isNotEmpty()) {
            session()->flash('message', 'Product exists in the cart!');
            return;
        }

        $this->product = $product;

        $cart->add([
            'id'      => $product['id'],
            'name'    => $product['product_name'],
            'qty'     => 1,
            'last_purchase_price' => $product['last_purchase_price'],
            'average_purchase_price' => $product['average_purchase_price'],
            'price'   => $this->calculate($product)['price'],
            'weight'  => 1,
            'options' => [
                'product_discount'      => 0.00,
                'product_discount_type' => 'fixed',
                'sub_total'             => $this->calculate($product)['sub_total'],
                'code'                  => $product['product_code'],
                'stock'                 => $product['product_quantity'],
                'unit'                  => $product['product_unit'],
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
        // Recalculate when global discount changes
        // Ensure that the new discount is applied
        $this->render();
    }

    public function updateQuantity($row_id, $product_id)
    {
        if ($this->cart_instance == 'sale' || $this->cart_instance == 'purchase_return') {
            if ($this->check_quantity[$product_id] < $this->quantity[$product_id]) {
                session()->flash('message', 'The requested quantity is not available in stock.');
                $this->quantity[$product_id] = $this->check_quantity[$product_id];
                return;
            }
        }

        Cart::instance($this->cart_instance)->update($row_id, $this->quantity[$product_id]);

        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

        // Recalculate sub_total including tax
        $tax_amount = 0;
        if ($this->product_tax[$product_id]) {
            $tax = Tax::find($this->product_tax[$product_id]);
            if ($tax) {
                $tax_amount = ($cart_item->price * ($tax->value / 100)) * $cart_item->qty;
            }
        }

        $sub_total = ($cart_item->price * $cart_item->qty) - $cart_item->options->product_discount + $tax_amount;

        Cart::instance($this->cart_instance)->update($row_id, [
            'options' => [
                'sub_total' => $sub_total,
                // Retain other options
                'code'                  => $cart_item->options->code,
                'stock'                 => $cart_item->options->stock,
                'unit'                  => $cart_item->options->unit,
                'product_tax'           => $this->product_tax[$product_id],
                'unit_price'            => $cart_item->options->unit_price,
                'product_discount'      => $cart_item->options->product_discount,
                'product_discount_type' => $cart_item->options->product_discount_type,
            ]
        ]);

        $this->recalculateCart();
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

        if ($this->discount_type[$product_id] == 'fixed') {
            Cart::instance($this->cart_instance)
                ->update($row_id, [
                    'price' => ($cart_item->price + $cart_item->options->product_discount) - $this->item_discount[$product_id]
                ]);

            $discount_amount = $this->item_discount[$product_id];

            $this->updateCartOptions($row_id, $product_id, $cart_item, $discount_amount);
        } elseif ($this->discount_type[$product_id] == 'percentage') {
            $discount_amount = ($cart_item->price + $cart_item->options->product_discount) * ($this->item_discount[$product_id] / 100);

            Cart::instance($this->cart_instance)
                ->update($row_id, [
                    'price' => ($cart_item->price + $cart_item->options->product_discount) - $discount_amount
                ]);

            $this->updateCartOptions($row_id, $product_id, $cart_item, $discount_amount);
        }

        session()->flash('discount_message' . $product_id, 'Discount added to the product!');
    }

    public function updatePrice($row_id, $product_id)
    {
        $product = Product::findOrFail($product_id);

        $cart_item = Cart::instance($this->cart_instance)->get($row_id);

        Cart::instance($this->cart_instance)->update($row_id, ['price' => $this->unit_price[$product['id']]]);

        Cart::instance($this->cart_instance)->update($row_id, [
            'options' => [
                'sub_total'             => $this->calculate($product, $this->unit_price[$product['id']])['sub_total'],
                'code'                  => $cart_item->options->code,
                'stock'                 => $cart_item->options->stock,
                'unit'                  => $cart_item->options->unit,
                'product_tax'           => $this->calculate($product, $this->unit_price[$product['id']])['product_tax'],
                'unit_price'            => $this->calculate($product, $this->unit_price[$product['id']])['unit_price'],
                'product_discount'      => $cart_item->options->product_discount,
                'product_discount_type' => $cart_item->options->product_discount_type,
                'last_purchase_price'   => $cart_item->options->last_purchase_price, // Preserve
                'average_purchase_price'=> $cart_item->options->average_purchase_price, // Preserve
            ]
        ]);

        $this->recalculateCart();
    }

    public function calculate($product, $new_price = null) {
        if ($new_price) {
            $product_price = $new_price;
        } else {
            $this->unit_price[$product['id']] = $product['product_price'];
            if ($this->cart_instance == 'purchase' || $this->cart_instance == 'purchase_return') {
                $this->unit_price[$product['id']] = $product['product_cost'];
            }
            $product_price = $this->unit_price[$product['id']];
        }
        $price = 0;
        $unit_price = 0;
        $product_tax = 0;
        $sub_total = 0;

        if ($product['product_tax_type'] == 1) {
            $price = $product_price + ($product_price * ($product['product_order_tax'] / 100));
            $unit_price = $product_price;
            $product_tax = $product_price * ($product['product_order_tax'] / 100);
            $sub_total = $product_price + ($product_price * ($product['product_order_tax'] / 100));
        } elseif ($product['product_tax_type'] == 2) {
            $price = $product_price;
            $unit_price = $product_price - ($product_price * ($product['product_order_tax'] / 100));
            $product_tax = $product_price * ($product['product_order_tax'] / 100);
            $sub_total = $product_price;
        } else {
            $price = $product_price;
            $unit_price = $product_price;
            $product_tax = 0.00;
            $sub_total = $product_price;
        }

        return ['price' => $price, 'unit_price' => $unit_price, 'product_tax' => $product_tax, 'sub_total' => $sub_total];
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
}
