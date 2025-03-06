<?php

namespace App\Livewire\Sale;

use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Setting\Entities\Tax;

class ProductCart extends Component
{
    public $listeners = ['productSelected', 'discountModalRefresh', 'customerSelected' => 'handleCustomerSelected'];

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
    public $customerId;

    public $taxes; // Collection of taxes filtered by setting_id
    public $setting_id; // Current setting ID
    public $product_tax = []; // Array to store selected tax IDs for each product

    public $is_tax_included = false;
    private $product;

    public $global_discount_type = 'percentage';

    public $pendingProduct = null;
    public $bundleOptions = [];

    protected $rules = [
        'unit_price.*'      => 'required|numeric|min:0',
        'quantity.*'        => 'required|integer|min:1',
        'item_discount.*'   => 'nullable|numeric|min:0',
        'global_discount'   => 'nullable|numeric|min:0|max:100',
        'shipping'          => 'nullable|numeric|min:0',
        'product_tax_id.*'  => 'nullable|integer|exists:taxes,id',
        'is_tax_included'   => 'nullable|boolean',
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

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $cart_items = Cart::instance($this->cart_instance)->content();

        $grand_total_before_tax = 0;
        $product_tax_amount = 0;
        $total_sub_total = 0;

        foreach ($cart_items as $item) {
            $grand_total_before_tax += $item->options->sub_total_before_tax ?? 0;
            $sub_total = $item->options->sub_total ?? 0;
            $sub_total_before_tax = $item->options->sub_total_before_tax ?? 0;

            $product_tax_amount += $sub_total - $sub_total_before_tax;
            $total_sub_total += $sub_total;
        }

        if ($this->global_discount_type == 'percentage') {
            $global_discount_amount = $total_sub_total * ($this->global_discount / 100);
        } else {
            $global_discount_amount = $this->global_discount;
        }

        $grand_total = ($total_sub_total - $global_discount_amount) + (float)$this->shipping;

        Log::info('Final totals calculated', [
            'grand_total'            => $grand_total,
            'total_sub_total'        => $total_sub_total,
            'global_discount'        => $this->global_discount,
            'global_discount_amount' => $global_discount_amount,
            'shipping'               => $this->shipping,
        ]);

        return view('livewire.sale.product-cart', [
            'cart_items'              => $cart_items,
            'grand_total'             => $grand_total,
            'taxes'                   => $this->taxes,
            'product_tax_total'       => $product_tax_amount,
            'grand_total_before_tax'  => $grand_total_before_tax,
            'total_sub_total'         => $total_sub_total,
            'global_discount_amount'  => $global_discount_amount,
        ]);
    }

    private function initializeCartItemAttributes($cart_item): void
    {
        $this->check_quantity[$cart_item->id] = [$cart_item->options->stock ?? 0];
        $this->quantity[$cart_item->id] = $cart_item->qty ?? 0;
        $this->unit_price[$cart_item->id] = $cart_item->price ?? 0;
        $this->discount_type[$cart_item->id] = $cart_item->options->product_discount_type ?? 'fixed';
        $this->item_discount[$cart_item->id] = $cart_item->options->product_discount ?? 0;
        $this->product_tax[$cart_item->id] = $cart_item->options->product_tax ?? null;
    }

    // -------------------------------------------------------------------------
    // PRODUCT SELECTION METHODS
    // -------------------------------------------------------------------------
    public function productSelected($product): void
    {
        $cart = Cart::instance($this->cart_instance);

        // --- Check for associated bundles (could be more than one) ---
        $bundles = ProductBundle::with('items.product')
            ->where('parent_product_id', $product['id'])
            ->get();
        if ($bundles->isNotEmpty()) {
            $this->pendingProduct = $product;
            $this->bundleOptions = $bundles;
            // Emit event to show the bundle selection modal
            $this->dispatch('showBundleSelectionModal', $this->pendingProduct, $this->bundleOptions);
            return;
        }

        // --- Normal product selection if no bundle exists ---
        // For products that do NOT require a serial number, update quantity if exists.
        if (empty($product['serial_number_required'])) {
            $exists = $cart->search(function ($cartItem, $rowId) use ($product) {
                return $cartItem->id == $product['id'];
            });
            if ($exists->isNotEmpty()) {
                // Increment quantity instead of error.
                $existingItem = $exists->first();
                $newQty = $existingItem->qty + 1;
                Cart::instance($this->cart_instance)->update($existingItem->rowId, [
                    'qty' => $newQty,
                ]);
                $this->quantity[$product['id']] = $newQty;
                session()->flash('message', 'Jumlah produk diperbaharui.');
                return;
            }
        } else {
            // For products requiring a serial number:
            $exists = $cart->search(function ($cartItem, $rowId) use ($product) {
                return $cartItem->id == $product['id'];
            });
            if ($exists->isNotEmpty()) {
                $newSerial = $product['serial_number'] ?? null;
                if (!$newSerial) {
                    session()->flash('message', 'No serial number provided!');
                    return;
                }
                $existingItem = $exists->first();
                $currentSerials = $existingItem->options->serial_numbers ?? [];
                if (in_array($newSerial, $currentSerials)) {
                    session()->flash('message', 'Serial number sudah dimasukkan!');
                    return;
                }
                $currentSerials[] = $newSerial;
                Cart::instance($this->cart_instance)->update($existingItem->rowId, [
                    'qty'     => count($currentSerials),
                    'options' => array_merge($existingItem->options->toArray(), [
                        'serial_numbers' => $currentSerials,
                    ]),
                ]);
                session()->flash('message', 'Serial number berhasil ditambahkan.');
                return;
            }
        }

        // --- Add parent product normally ---
        $this->addParentProduct($product);
    }

    public function addParentProduct($product): void
    {
        $cart = Cart::instance($this->cart_instance);

        // Check again for duplicates; if exists, update accordingly.
        $exists = $cart->search(function ($cartItem, $rowId) use ($product) {
            return $cartItem->id == $product['id'];
        });
        if ($exists->isNotEmpty()) {
            if (empty($product['serial_number_required'])) {
                $existingItem = $exists->first();
                $newQty = $existingItem->qty + 1;
                Cart::instance($this->cart_instance)->update($existingItem->rowId, [
                    'qty' => $newQty,
                ]);
                $this->quantity[$product['id']] = $newQty;
                session()->flash('message', 'Jumlah produk diperbaharui.');
                return;
            } else {
                $newSerial = $product['serial_number'] ?? null;
                if (!$newSerial) {
                    session()->flash('message', 'No serial number provided!');
                    return;
                }
                $existingItem = $exists->first();
                $currentSerials = $existingItem->options->serial_numbers ?? [];
                if (in_array($newSerial, $currentSerials)) {
                    session()->flash('message', 'Serial number sudah dimasukkan!');
                    return;
                }
                $currentSerials[] = $newSerial;
                Cart::instance($this->cart_instance)->update($existingItem->rowId, [
                    'qty'     => count($currentSerials),
                    'options' => array_merge($existingItem->options->toArray(), [
                        'serial_numbers' => $currentSerials,
                    ]),
                ]);
                session()->flash('message', 'Serial number berhasil ditambahkan.');
                return;
            }
        }

        // No existing row; prepare options.
        $this->product = $product;
        $options = [
            'product_discount'      => 0.00,
            'product_discount_type' => 'fixed',
            'sub_total'             => $this->calculate($product)['sub_total'],
            'code'                  => $product['product_code'],
            'stock'                 => $product['product_quantity'],
            'unit'                  => $product['product_unit'],
            'last_sale_price'       => 0,
            'average_sale_price'    => 0,
            'product_tax'           => null,
            'unit_price'            => $this->calculate($product)['unit_price']
        ];

        if (!empty($product['serial_number_required']) && $product['serial_number_required']) {
            $newSerial = $product['serial_number'] ?? null;
            $options['serial_numbers'] = $newSerial ? [$newSerial] : [];
            $options['serial_number_required'] = true;
            $qty = count($options['serial_numbers']);
        } else {
            $qty = 1;
        }

        $cart->add([
            'id'      => $product['id'],
            'name'    => $product['product_name'],
            'qty'     => $qty,
            'price'   => $this->calculate($product)['price'],
            'weight'  => 1,
            'options' => $options,
        ]);

        $this->check_quantity[$product['id']] = $product['product_quantity'];
        $this->quantity[$product['id']] = $qty;
        $this->discount_type[$product['id']] = 'fixed';
        $this->item_discount[$product['id']] = 0;
        $this->product_tax[$product['id']] = null;

        Log::info('Cart:', [
            'cart' => $cart->content(),
        ]);
    }

    // -------------------------------------------------------------------------
    // BUNDLE SELECTION METHODS
    // -------------------------------------------------------------------------
    public function confirmBundleSelection($bundleId): void
    {
        $cart = Cart::instance($this->cart_instance);
        $bundle = ProductBundle::find($bundleId);
        if ($bundle) {
            $bundleItems = ProductBundleItem::where('bundle_id', $bundle->id)->get();
            foreach ($bundleItems as $bundleItem) {
                $bundleProduct = Product::find($bundleItem->product_id);
                if (!$bundleProduct) {
                    continue;
                }
                // Use calculate() to get default values then override with bundle info.
                $calc = $this->calculate($bundleProduct);
                $calc['price'] = $bundleItem->price;
                $calc['unit_price'] = $bundleItem->price;
                $calc['sub_total'] = $bundleItem->price * $bundleItem->quantity;
                $options = [
                    'product_discount'      => 0.00,
                    'product_discount_type' => 'fixed',
                    'sub_total'             => $calc['sub_total'],
                    'code'                  => $bundleProduct->product_code,
                    'stock'                 => $bundleProduct->product_quantity,
                    'unit'                  => $bundleProduct->product_unit,
                    'last_sale_price'       => 0,
                    'average_sale_price'    => 0,
                    'product_tax'           => null,
                    'unit_price'            => $calc['unit_price'],
                ];

                // Check if bundle product exists already.
                $exists = $cart->search(function ($cartItem, $rowId) use ($bundleProduct) {
                    return $cartItem->id == $bundleProduct->id;
                });
                if ($exists->isNotEmpty()) {
                    $existingItem = $exists->first();
                    $newQty = $existingItem->qty + $bundleItem->quantity;
                    Cart::instance($this->cart_instance)->update($existingItem->rowId, [
                        'qty' => $newQty,
                    ]);
                    $this->quantity[$bundleProduct->id] = $newQty;
                } else {
                    $cart->add([
                        'id'      => $bundleProduct->id,
                        'name'    => $bundleProduct->product_name,
                        'qty'     => $bundleItem->quantity,
                        'price'   => $calc['price'],
                        'weight'  => 1,
                        'options' => $options,
                    ]);
                    $this->check_quantity[$bundleProduct->id] = $bundleProduct->product_quantity;
                    $this->quantity[$bundleProduct->id] = $bundleItem->quantity;
                    $this->discount_type[$bundleProduct->id] = 'fixed';
                    $this->item_discount[$bundleProduct->id] = 0;
                    $this->product_tax[$bundleProduct->id] = null;
                }
            }
            session()->flash('message', 'Bundle items added successfully.');
        }
        if ($this->pendingProduct) {
            $this->addParentProduct($this->pendingProduct);
            $this->pendingProduct = null;
            $this->bundleOptions = [];
        }
    }

    public function proceedWithoutBundle(): void
    {
        if ($this->pendingProduct) {
            $this->addParentProduct($this->pendingProduct);
            $this->pendingProduct = null;
            $this->bundleOptions = [];
            session()->flash('message', 'Produk diproses tanpa bundle.');
        }
    }

    // -------------------------------------------------------------------------
    // CART UPDATE METHODS
    // -------------------------------------------------------------------------
    public function removeItem($row_id): void
    {
        $cart = Cart::instance($this->cart_instance);
        $cart_item = $cart->get($row_id);
        $cart->remove($row_id);
        unset($this->quantity[$cart_item->id]);
        unset($this->unit_price[$cart_item->id]);
        unset($this->item_discount[$cart_item->id]);
        unset($this->product_tax[$cart_item->id]);
    }

    public function updateQuantity($row_id, $product_id): void
    {
        if ($this->cart_instance == 'purchase' || $this->cart_instance == 'purchase_return') {
            if ($this->check_quantity[$product_id] < $this->quantity[$product_id]) {
                session()->flash('message', 'The requested quantity is not available in stock.');
                $this->quantity[$product_id] = $this->check_quantity[$product_id];
                return;
            }
        }
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);
        $calculated = $this->calculateSubtotalAndTax(
            $this->unit_price[$product_id] ?? $cart_item->price,
            $this->quantity[$product_id],
            $cart_item->options->product_discount ?? 0,
            $this->product_tax[$product_id] ?? null
        );
        Cart::instance($this->cart_instance)->update($row_id, [
            'qty' => $this->quantity[$product_id],
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total'             => $calculated['sub_total'],
                'sub_total_before_tax'  => $calculated['subtotal_before_tax'],
                'tax_amount'            => $calculated['tax_amount'],
            ]),
        ]);
        $this->recalculateCart();
    }

    private function calculateSubtotalAndTax($price, $qty, $discount = 0, $tax_id = null)
    {
        $price = max(0, (float)$price);
        $qty = max(1, (int)$qty);
        $discount = max(0, (int)$discount);
        $price = $price - $discount;
        $subtotal_before_tax = 0;
        $tax_amount = 0;
        if ($this->is_tax_included) {
            if ($tax_id) {
                $tax = Tax::find($tax_id);
                if ($tax) {
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
                    $subtotal_before_tax = $price * $qty;
                }
            } else {
                $subtotal_before_tax = $price * $qty;
            }
        } else {
            $subtotal_before_tax = $price * $qty;
            if ($tax_id) {
                $tax = Tax::find($tax_id);
                if ($tax) {
                    $tax_amount = $subtotal_before_tax * ($tax->value / 100);
                } else {
                    Log::warning("Invalid tax ID provided", ['tax_id' => $tax_id]);
                }
            }
        }
        return [
            'sub_total' => $subtotal_before_tax + $tax_amount,
            'tax_amount' => $tax_amount,
            'subtotal_before_tax' => $subtotal_before_tax,
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
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);
        $unit_price = $this->unit_price[$product_id] ?? $cart_item->price;
        $quantity = $cart_item->qty;
        Log::info('SetProductDiscount - Initial Values', [
            'row_id' => $row_id,
            'product_id' => $product_id,
            'unit_price' => $unit_price,
            'quantity' => $quantity,
            'current_discount' => $cart_item->options['product_discount'] ?? 0,
        ]);
        $raw_discount_input = $this->item_discount[$product_id] ?? 0;
        $sanitized_discount_input = is_numeric($raw_discount_input) ? (float)$raw_discount_input : 0;
        $discount_amount = 0;
        if ($this->discount_type[$product_id] == 'fixed') {
            $discount_amount = $sanitized_discount_input;
        } elseif ($this->discount_type[$product_id] == 'percentage') {
            $discount_amount = $unit_price * ($sanitized_discount_input / 100);
        }
        if ($discount_amount > $unit_price) {
            $discount_amount = $unit_price;
        }
        Log::info('SetProductDiscount - Calculated Discount', [
            'discount_amount' => $discount_amount,
            'discount_type' => $this->discount_type[$product_id],
        ]);
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
        Cart::instance($this->cart_instance)->update($row_id, [
            'unit_price' => $this->is_tax_included ? $adjusted_price : $unit_price,
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => $updated_cart_data['sub_total'],
                'sub_total_before_tax' => $updated_cart_data['subtotal_before_tax'],
                'product_discount' => $discount_amount,
                'product_discount_type' => $this->discount_type[$product_id],
            ]),
        ]);
        $this->recalculateCart();
        session()->flash('discount_message' . $product_id, 'Discount applied to the product!');
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
        $new_price = $this->unit_price[$product_id] ?? $cart_item->price;
        $discount_amount = $cart_item->options->product_discount;
        $raw_discount_input = $this->item_discount[$product_id] ?? 0;
        $sanitized_discount_input = is_numeric($raw_discount_input) ? (float)$raw_discount_input : 0;
        if ($this->discount_type[$product_id] == 'percentage') {
            $discount_amount = $new_price * ($sanitized_discount_input / 100);
        }
        $calculated = $this->calculateSubtotalAndTax(
            $new_price,
            $cart_item->qty,
            $discount_amount,
            $this->product_tax[$product_id] ?? null
        );
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
        // Initialize sale price from product.sale_price if available.
        $product_price = $product['sale_price'] ?? 0;
        $sub_total = $product_price; // For one unit.
        return [
            'price' => $product_price,
            'unit_price' => $product_price,
            'product_tax' => 0,
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
            'last_sale_price'       => $cart_item->options->last_sale_price,
            'average_sale_price'    => $cart_item->options->average_sale_price,
        ]]);
    }

    public function recalculateCart()
    {
        $this->render();
    }

    public function updateTax($row_id, $product_id)
    {
        $cart_item = Cart::instance($this->cart_instance)->get($row_id);
        $tax_id = !empty($this->product_tax[$product_id]) ? $this->product_tax[$product_id] : null;
        $tax_amount = 0;
        if ($tax_id) {
            $tax = Tax::find($tax_id);
            if ($tax) {
                Log::info('Tax applied', [
                    'product_id' => $product_id,
                    'tax_id' => $tax_id,
                    'tax_value' => $tax->value,
                ]);
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
            $price = $cart_item->price;
            $quantity = $cart_item->qty;
            $discount = $cart_item->options->product_discount ?? 0;
            $tax_id = $this->product_tax[$product_id] ?? null;
            $calculated = $this->calculateSubtotalAndTax($price, $quantity, $discount, $tax_id);
            Cart::instance($this->cart_instance)->update($row_id, [
                'options' => array_merge($cart_item->options->toArray(), [
                    'product_tax' => $tax_id,
                    'sub_total' => $calculated['sub_total'],
                    'sub_total_before_tax' => $calculated['subtotal_before_tax'],
                ]),
            ]);
            Log::info('Updated cart item for tax inclusion', [
                'row_id' => $row_id,
                'sub_total' => $calculated['sub_total'],
                'sub_total_before_tax' => $calculated['subtotal_before_tax'],
                'tax_amount' => $calculated['tax_amount'],
            ]);
        }
        $this->recalculateCart();
    }

    public function handleCustomerSelected($customer): void
    {
        $this->customerId = $customer ? $customer['id'] : null;
    }

    public function setGlobalDiscountType($type): void
    {
        $this->global_discount_type = $type;
        $this->updateGlobalDiscount();
    }

    public function updateGlobalDiscount(): void
    {
        $this->recalculateCart();
    }

    public function removeSerialNumber($rowId, $serial): void
    {
        $cart = Cart::instance($this->cart_instance);
        $item = $cart->get($rowId);
        if (!$item) {
            session()->flash('message', 'Cart item not found.');
            return;
        }
        $serialNumbers = $item->options->serial_numbers ?? [];
        if (($key = array_search($serial, $serialNumbers)) !== false) {
            unset($serialNumbers[$key]);
            $serialNumbers = array_values($serialNumbers);
        } else {
            session()->flash('message', 'Serial number not found.');
            return;
        }
        if (count($serialNumbers) === 0) {
            $cart->remove($rowId);
            session()->flash('message', 'Serial number removed successfully. Product removed as no serial numbers remain.');
        } else {
            Cart::instance($this->cart_instance)->update($rowId, [
                'qty' => count($serialNumbers),
                'options' => array_merge($item->options->toArray(), [
                    'serial_numbers' => $serialNumbers
                ])
            ]);
            session()->flash('message', 'Serial number removed successfully.');
        }
    }
}
