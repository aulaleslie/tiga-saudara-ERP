<?php

namespace App\Livewire\Pos;

use App\Support\ProductBundleResolver;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Collection;
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

        'serialScanned' => 'onSerialScanned',            // one-by-one scan flow
        'serialNumbersSelected' => 'onSerialsSelected',  // (optional) bulk choose flow
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
    public Collection $bundleOptions;
    public $pendingSerials = null;
    public $serialSelectionContext = null;
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
        $this->paymentMethods = PaymentMethod::all();

        $this->bundleOptions = collect();
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
        [$product, $pendingSerials] = $this->normalizeProductInput($product);

        if (!$product) {
            return;
        }

        if (($product['product_quantity'] ?? 0) <= 0) {
            session()->flash('message', 'Product is out of stock!');
            return;
        }

        [$hydratedProduct, $bundles] = $this->resolveProductContext($product);

        if (!$hydratedProduct) {
            session()->flash('message', 'Product could not be loaded.');
            return;
        }

        if ($this->promptBundleSelection($hydratedProduct, $bundles, $pendingSerials)) {
            return;
        }

        $this->continueProductAdd($hydratedProduct, null, $pendingSerials);
    }

    public function addProduct($product, $bundle = null)
    {
        $bundleMeta   = $this->formatBundleForCart($bundle);
        $bundleId     = $bundleMeta['id'];
        $cartKey      = $this->generateCartItemKey($product['id'], $bundleId);
        $convertedQty = max(1, (int) ($product['conversion_factor'] ?? 1));

        // Centralized calc (handles conversion-pack costs via ProductUnitConversion)
        $calculated = $this->calculate($product, $convertedQty);
        $final_sub_total = $calculated['sub_total'] + $bundleMeta['bundle_price'];

        Cart::instance($this->cart_instance)->add([
            'id'      => $cartKey,
            'name'    => $product['product_name'],
            'qty'     => $convertedQty,
            'price'   => $calculated['unit_price'],   // <<< always unit price
            'weight'  => 1,
            'options' => array_merge([
                'product_discount'      => 0.00,
                'product_discount_type' => 'fixed',
                'sub_total'             => $final_sub_total, // <<< line total
                'code'                  => $product['product_code'],
                'stock'                 => (int) ($product['product_quantity'] ?? PHP_INT_MAX),
                'product_tax'           => 0,
                'unit_price'            => $calculated['unit_price'],
                'serial_number_required'=> $this->productRequiresSerial($product),
            ], [
                'bundle_items'          => $bundleMeta['bundle_items'],
                'bundle_price'          => $bundleMeta['bundle_price'],
                'bundle_name'           => $bundleMeta['bundle_name'],
            ]),
        ]);

        $this->check_quantity[$cartKey] = (int) ($product['product_quantity'] ?? PHP_INT_MAX);
        $this->quantity[$cartKey]       = $convertedQty;
        $this->discount_type[$cartKey]  = 'fixed';
        $this->item_discount[$cartKey]  = 0;
        $this->conversion_breakdowns[$cartKey] = $this->calculateConversionBreakdown((int)$product['id'], $convertedQty);

        $this->total_amount = $this->calculateTotal();
    }

    public function confirmBundleSelection($bundleId)
    {
        if (!$this->pendingProduct) {
            session()->flash('message', 'Invalid bundle selected.');
            return;
        }

        $bundle = ProductBundle::with('items.product')->find($bundleId);
        if (!$bundle) {
            session()->flash('message', 'Invalid bundle selected.');
            return;
        }

        $formatted = $this->formatBundle($bundle);

        $this->continueProductAdd($this->pendingProduct, $formatted, $this->pendingSerials ?? []);
    }

    public function proceedWithoutBundle(): void
    {
        if (!$this->pendingProduct) {
            $this->resetBundleState();
            return;
        }

        $this->continueProductAdd($this->pendingProduct, null, $this->pendingSerials ?? []);
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

    private function normalizeProductInput($product): array
    {
        if ($product === null) {
            return [null, []];
        }

        $data = is_array($product) ? $product : (array) $product;
        if (empty($data['id'])) {
            return [null, []];
        }

        $serials = [];
        if (isset($data['pending_serials']) && is_array($data['pending_serials'])) {
            foreach ($data['pending_serials'] as $serial) {
                $normalized = $this->normalizeSerial($serial);
                if ($normalized) {
                    $serials[] = $normalized;
                }
            }
            unset($data['pending_serials']);
        }

        $data['id'] = (int) $data['id'];
        $data['conversion_factor'] = max(1, (int) ($data['conversion_factor'] ?? 1));

        return [$data, $serials];
    }

    private function resolveProductContext(array $product): array
    {
        $productId = (int) ($product['id'] ?? 0);
        if ($productId <= 0) {
            return [null, collect()];
        }

        $model = Product::find($productId);
        if (!$model) {
            return [null, collect()];
        }

        $modelArray = $model->toArray();
        $hydrated = array_merge($modelArray, $product);
        $hydrated['id'] = $productId;

        if (!isset($hydrated['serial_number_required'])) {
            $hydrated['serial_number_required'] = (bool) $model->serial_number_required;
        }

        $bundles = ProductBundleResolver::forProduct($productId);

        return [$hydrated, $bundles];
    }

    private function promptBundleSelection(array $product, Collection $bundles, array $serials = []): bool
    {
        if ($bundles->isEmpty()) {
            return false;
        }

        $this->pendingProduct = $product;
        $this->bundleOptions = $bundles;
        $this->pendingSerials = $serials;

        $this->dispatch('showBundleSelectionModal');

        return true;
    }

    private function continueProductAdd(array $product, ?array $bundle = null, array $serials = []): void
    {
        if (!empty($serials)) {
            foreach ($serials as $serial) {
                $this->addSerialEntry($product, $serial, $bundle);
            }

            $this->resetBundleState();
            $this->total_amount = $this->calculateTotal();
            return;
        }

        if ($this->productRequiresSerial($product)) {
            $this->serialSelectionContext = [
                'product' => $product,
                'bundle'  => $bundle,
            ];

            $expectedCount = max(1, (int) ($product['conversion_factor'] ?? 1));
            $this->dispatch('openSerialPicker', [
                'product_id'     => (int) $product['id'],
                'expected_count' => $expectedCount,
                'bundle'         => $bundle,
            ]);

            $this->resetBundleState();
            return;
        }

        $this->handleStandardAddition($product, $bundle);
        $this->resetBundleState();
    }

    private function productRequiresSerial(array $product): bool
    {
        return (bool) ($product['serial_number_required'] ?? false);
    }

    private function handleStandardAddition(array $product, ?array $bundle = null): void
    {
        $bundleId = $bundle['id'] ?? null;
        $cartKey  = $this->generateCartItemKey($product['id'], $bundleId);
        $qtyToAdd = max(1, (int) ($product['conversion_factor'] ?? 1));

        $cart = Cart::instance($this->cart_instance);
        $exists = $cart->search(fn($item) => $item->id === $cartKey);

        if ($exists->isNotEmpty()) {
            $rowId  = $exists->first()->rowId;
            $newQty = $exists->first()->qty + $qtyToAdd;
            $this->quantity[$cartKey]       = $newQty;
            $this->check_quantity[$cartKey] = (int) ($product['product_quantity'] ?? PHP_INT_MAX);
            $this->updateQuantity($rowId, $cartKey);
            return;
        }

        $this->addProduct($product, $bundle);
    }

    private function formatBundle(ProductBundle $bundle): array
    {
        $bundle->loadMissing('items.product');

        return [
            'id'    => $bundle->id,
            'name'  => $bundle->name,
            'price' => (float) ($bundle->price ?? 0),
            'items' => $bundle->items->map(function ($item) {
                return [
                    'id'         => $item->id,
                    'bundle_id'  => $item->bundle_id,
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'price'      => isset($item->price) ? (float) $item->price : null,
                    'product'    => $item->product ? $item->product->toArray() : null,
                ];
            })->all(),
        ];
    }

    private function formatBundleForCart(?array $bundle): array
    {
        if (!$bundle) {
            return [
                'id'           => null,
                'bundle_items' => [],
                'bundle_price' => 0.0,
                'bundle_name'  => null,
            ];
        }

        $items = [];
        foreach ($bundle['items'] ?? [] as $item) {
            $items[] = [
                'bundle_id'      => $bundle['id'] ?? null,
                'bundle_item_id' => $item['id'] ?? null,
                'product_id'     => $item['product_id'] ?? null,
                'name'           => $item['product']['product_name'] ?? ($item['name'] ?? null),
                'quantity'       => $item['quantity'] ?? 0,
                'price'          => isset($item['price']) ? (float) $item['price'] : null,
            ];
        }

        return [
            'id'           => $bundle['id'] ?? null,
            'bundle_items' => $items,
            'bundle_price' => isset($bundle['price']) ? (float) $bundle['price'] : 0.0,
            'bundle_name'  => $bundle['name'] ?? null,
        ];
    }

    private function addSerialEntry(array $product, array $serial, ?array $bundle = null): void
    {
        if (!isset($serial['id'], $serial['serial_number'])) {
            return;
        }

        $bundleMeta = $this->formatBundleForCart($bundle);
        $cartKey    = $this->generateCartItemKey($product['id'], $bundleMeta['id']);

        $cart = Cart::instance($this->cart_instance);
        $existing = $cart->search(fn($item) => $item->id === $cartKey)->first();

        $serialPayload = [
            'id'            => (int) $serial['id'],
            'serial_number' => (string) $serial['serial_number'],
        ];

        if ($existing) {
            $options = $existing->options->toArray();
            $list = $options['serial_numbers'] ?? [];
            $already = array_filter($list, fn($row) => (int) ($row['id'] ?? 0) === $serialPayload['id']);
            if (!empty($already)) {
                return;
            }

            $list[] = $serialPayload;
            $options['serial_numbers'] = array_values($list);

            $quantity = count($options['serial_numbers']);

            $calculated = $this->calculate($product, $quantity);
            $finalSubTotal = $calculated['sub_total'] + ($options['bundle_price'] ?? $bundleMeta['bundle_price']);

            Cart::instance($this->cart_instance)->update($existing->rowId, [
                'qty'     => $quantity,
                'price'   => $calculated['unit_price'],
                'options' => array_merge($options, [
                    'sub_total' => $finalSubTotal,
                    'unit_price'=> $calculated['unit_price'],
                ]),
            ]);

            $this->quantity[$cartKey]       = $quantity;
            $this->check_quantity[$cartKey] = $options['stock'] ?? ($existing->options->stock ?? PHP_INT_MAX);
            $this->conversion_breakdowns[$cartKey] = $this->calculateConversionBreakdown((int)$product['id'], $quantity);
            return;
        }

        $calculated = $this->calculate($product, 1);
        $finalSubTotal = $calculated['sub_total'] + $bundleMeta['bundle_price'];

        Cart::instance($this->cart_instance)->add([
            'id'     => $cartKey,
            'name'   => $product['product_name'],
            'qty'    => 1,
            'price'  => $calculated['unit_price'],   // unit price
            'weight' => 1,
            'options' => array_merge([
                'product_discount'        => 0.00,
                'product_discount_type'   => 'fixed',
                'sub_total'               => $finalSubTotal,
                'code'                    => $product['product_code'] ?? null,
                'stock'                   => $product['product_quantity'] ?? PHP_INT_MAX,
                'product_tax'             => 0,
                'unit_price'              => $calculated['unit_price'],
                'serial_number_required'  => true,
                'serial_numbers'          => [$serialPayload],
            ], [
                'bundle_items'            => $bundleMeta['bundle_items'],
                'bundle_price'            => $bundleMeta['bundle_price'],
                'bundle_name'             => $bundleMeta['bundle_name'],
            ]),
        ]);

        $this->check_quantity[$cartKey] = $product['product_quantity'] ?? PHP_INT_MAX;
        $this->quantity[$cartKey]       = 1;
        $this->discount_type[$cartKey]  = 'fixed';
        $this->item_discount[$cartKey]  = 0;
        $this->conversion_breakdowns[$cartKey] = $this->calculateConversionBreakdown((int)$product['id'], 1);
    }

    private function normalizeSerial($serial): ?array
    {
        if (!$serial) {
            return null;
        }

        $data = is_array($serial) ? $serial : (array) $serial;
        if (!isset($data['id'], $data['serial_number'])) {
            return null;
        }

        return [
            'id'            => (int) $data['id'],
            'serial_number' => (string) $data['serial_number'],
        ];
    }

    private function resetBundleState(): void
    {
        $this->pendingProduct = null;
        $this->bundleOptions = collect();
        $this->pendingSerials = null;
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

    /**
     * A single serial was scanned in the modal.
     * payload: ['product_id' => int, 'serial' => ['id'=>int, 'serial_number'=>string]]
     */
    public function onSerialScanned(array $payload): void
    {
        $productId = (int)($payload['product_id'] ?? 0);
        $serial    = $this->normalizeSerial($payload['serial'] ?? null);

        if (!$productId || !$serial) {
            return;
        }

        $bundleFromPayload = $payload['bundle'] ?? null;

        $product = null;
        $bundle  = null;
        $bundles = collect();

        if ($this->serialSelectionContext && (int)($this->serialSelectionContext['product']['id'] ?? 0) === $productId) {
            $product = $this->serialSelectionContext['product'];
            $bundle  = $this->serialSelectionContext['bundle'] ?? null;
        }

        if ($bundleFromPayload) {
            $bundle = $bundleFromPayload;
        }

        if (!$product) {
            [$product, $bundles] = $this->resolveProductContext(['id' => $productId]);
        } else {
            $bundles = $bundle ? collect() : ProductBundleResolver::forProduct($productId);
        }

        if (!$product) {
            return;
        }

        if (!$bundle && $this->promptBundleSelection($product, $bundles, [$serial])) {
            return;
        }

        $this->addSerialEntry($product, $serial, $bundle);
        $this->total_amount = $this->calculateTotal();
    }

    /**
     * Optional bulk confirm (if you allow multi-select before closing)
     * payload: ['product_id'=>..., 'serials'=>[['id','serial_number'],...]]
     */
    public function onSerialsSelected(array $payload): void
    {
        $productId = (int)($payload['product_id'] ?? 0);
        $serials = $payload['serials'] ?? [];
        if (!$productId || empty($serials)) return;

        foreach ($serials as $serial) {
            $this->onSerialScanned(['product_id' => $productId, 'serial' => $serial]);
        }
    }

    public function removeSerial(string $rowId, string $serial): void
    {
        $item = Cart::instance($this->cart_instance)->get($rowId);
        if (!$item) return;

        $options = $item->options->toArray();
        $list = $options['serial_numbers'] ?? [];

        // remove the target serial by serial_number string match
        $list = array_values(array_filter($list, fn ($s) => (string)($s['serial_number'] ?? $s) !== (string)$serial));
        $options['serial_numbers'] = $list;

        // adjust qty = number of serials
        $newQty = count($list);
        $product = Product::find((int)explode('-', $item->id)[0]);
        if ($product) {
            $calculated = $this->calculate($product->toArray(), $newQty);
            Cart::instance($this->cart_instance)->update($rowId, [
                'qty' => $newQty,
                'price' => $calculated['unit_price'],
                'options' => array_merge($options, [
                    'sub_total' => $calculated['sub_total'],
                    'unit_price' => $calculated['unit_price'],
                ]),
            ]);
        } else {
            Cart::instance($this->cart_instance)->update($rowId, ['options' => $options, 'qty' => $newQty]);
        }

        $cart_key = $item->id;
        $this->quantity[$cart_key] = $newQty;
        $this->total_amount = $this->calculateTotal();
    }
}
