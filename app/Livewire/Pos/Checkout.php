<?php

namespace App\Livewire\Pos;

use App\Support\PosLocationResolver;
use App\Support\ProductBundleResolver;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\PaymentMethod;

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
    public $paid_amount;
    public $conversion_breakdowns = [];
    public $pendingProduct = null;
    public Collection $bundleOptions;
    public $pendingSerials = null;
    public $serialSelectionContext = null;
    public $paymentMethods = [];
    public array $payments = [];
    public ?int $posLocationId = null;
    public bool $changeModalHasPositiveChange = false;
    public ?string $changeModalAmount = null;
    #[Locked]
    public bool $paidAmountManuallyUpdated = false;
    #[Locked]
    public ?string $lastChangeModalAmount = null;
    public bool $forceShowChangeModal = false;
    public ?float $forcedChangeAmount = null;
    public bool $changeModalExplicitlyRequested = false;

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
        $this->paid_amount = 0.00;

        session('setting_id');

        $this->paymentMethods = PaymentMethod::query()
            ->where('is_available_in_pos', true)
            ->orderBy('name')
            ->get();

        $firstMethod = $this->paymentMethodsCollection()->first();

        if ($firstMethod) {
            $this->payments = [
                $this->makePaymentRow((int) data_get($firstMethod, 'id')),
            ];
        } else {
            $this->payments = [
                $this->makePaymentRow(null),
            ];
        }

        $this->bundleOptions = collect();

        $this->posLocationId = PosLocationResolver::resolveId();

        $this->initializeChangeModalFromFlash();

        $this->refreshTotals(true);
    }

    public function hydrate()
    {
        $this->refreshTotals();
        $this->dispatch('pos-mask-money-init');
    }

    public function updatedPaidAmount($value): void
    {
        $this->paid_amount = $this->sanitizeCurrencyValue($value);
        $this->paidAmountManuallyUpdated = abs($this->paid_amount - (float) $this->total_amount) > 0.00001;
        $this->syncChangeModalState();
        $this->maybeAutoShowChangeModal();
    }

    public function updatedPayments($value, $name): void
    {
        if (! is_string($name)) {
            return;
        }

        if (Str::endsWith($name, '.amount')) {
            $afterProperty = Str::after($name, '.');
            $index = (int) Str::before($afterProperty, '.');
            $this->paidAmountManuallyUpdated = true;
            $amount = $this->sanitizeCurrencyValue($value);

            if (array_key_exists($index, $this->payments)) {
                $this->payments[$index]['amount'] = $amount;
            }

            $this->syncPaidAmountFromPayments();
            $this->syncChangeModalState();
            $this->maybeAutoShowChangeModal();

            return;
        }

        if (Str::endsWith($name, '.method_id')) {
            $this->syncChangeModalState();
            $this->maybeAutoShowChangeModal();
        }
    }

    public function addPaymentRow(): void
    {
        $firstMethod = $this->paymentMethodsCollection()->first();

        $this->payments[] = $this->makePaymentRow($firstMethod ? (int) data_get($firstMethod, 'id') : null);

        $this->dispatch('pos-mask-money-init');
    }

    public function removePaymentRow(int $index): void
    {
        if (count($this->payments) <= 1) {
            return;
        }

        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);

        $this->syncPaidAmountFromPayments();
        $this->syncChangeModalState();
        $this->maybeAutoShowChangeModal();
    }

    protected function paymentMethodsCollection(): Collection
    {
        return $this->paymentMethods instanceof Collection
            ? $this->paymentMethods
            : collect($this->paymentMethods);
    }

    public function getRawChangeDueProperty(): float
    {
        return round((float) $this->paid_amount - (float) $this->total_amount, 2);
    }

    public function getOverPaidWithNonCashProperty(): bool
    {
        return $this->rawChangeDue > 0 && ! $this->hasCashPayment;
    }

    public function getChangeDueProperty(): float
    {
        $change = $this->rawChangeDue;

        if ($change > 0 && ! $this->hasCashPayment) {
            return 0.00;
        }

        return $change;
    }

    public function getHasCashPaymentProperty(): bool
    {
        return collect($this->payments)
            ->contains(function ($payment) {
                if (! $this->paymentMethodIsCash((int) data_get($payment, 'method_id'))) {
                    return false;
                }

                return (float) data_get($payment, 'amount', 0) > 0;
            });
    }

    public function openChangeModal(): void
    {
        $this->changeModalExplicitlyRequested = true;
        $this->syncChangeModalState();

        $this->dispatch('show-change-modal', amount: $this->changeModalAmount);

        $this->lastChangeModalAmount = $this->changeModalHasPositiveChange
            ? $this->changeModalAmount
            : null;
    }

    public function render()
    {
        $cart_items = Cart::instance($this->cart_instance)->content();

        return view('livewire.pos.checkout', [
            'cart_items' => $cart_items,
            'changeDue' => $this->changeDue,
            'rawChangeDue' => $this->rawChangeDue,
            'overPaidWithNonCash' => $this->overPaidWithNonCash,
            'paidAmount' => $this->paid_amount,
            'hasCashPayment' => $this->hasCashPayment,
        ]);
    }

    public function proceed()
    {
        if ($this->customer_id != null) {
            $this->dispatch('showCheckoutModal');
        } else {
            session()->flash('message', 'Silakan pilih pelanggan!');
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
        $this->refreshTotals(true);
        $this->dispatch('pos-mask-money-init');
    }

    public function productSelected($product)
    {
        [$product, $pendingSerials] = $this->normalizeProductInput($product);

        if (!$product) {
            return;
        }

        [$hydratedProduct, $bundles] = $this->resolveProductContext($product);

        if (!$hydratedProduct) {
            session()->flash('message', 'Produk tidak dapat dimuat.');
            return;
        }

        $convertedQty = max(1, (int) ($hydratedProduct['conversion_factor'] ?? 1));
        $stockContext = $this->resolveStockForProduct($hydratedProduct, $convertedQty);

        if (!($stockContext['sufficient'] ?? true)) {
            session()->flash('message', 'Jumlah yang diminta melebihi stok POS yang tersedia.');
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

        $stockContext = $this->resolveStockForProduct($product, $convertedQty);

        if (!$stockContext['sufficient']) {
            session()->flash('message', 'Jumlah yang diminta melebihi stok POS yang tersedia.');
            return;
        }

        $pricing = $this->resolveTenantPricing($product);

        // Centralized calc (handles conversion-pack costs via ProductUnitConversion)
        $calculated = $this->calculate($product, $convertedQty, $pricing);
        $bundleOptions = $this->prepareBundleOptionsForQuantity($bundleMeta, $convertedQty);
        $final_sub_total = $calculated['sub_total'] + ($bundleOptions['bundle_price'] ?? 0);

        $baseOptions = array_merge([
            'product_discount'       => 0.00,
            'product_discount_type'  => 'fixed',
            'sub_total'              => $final_sub_total, // <<< line total
            'code'                   => $product['product_code'],
            'stock'                  => (int) ($product['product_quantity'] ?? PHP_INT_MAX),
            'product_tax'            => 0,
            'unit_price'             => $calculated['unit_price'],
            'serial_number_required' => $this->productRequiresSerial($product),
            'product_id'             => (int) $product['id'],
            'conversion_context'     => $calculated['conversion_context'],
            'cart_key'               => $cartKey,
            'allocated_non_tax'      => 0,
            'allocated_tax'          => 0,
            'serial_tax_ids'         => [],
        ], $bundleOptions);
        $baseOptions = $this->injectPricingIntoOptions($baseOptions, $pricing);

        $finalOptions = $this->applyStockContextToOptions($baseOptions, $stockContext);
        $finalOptions = $this->injectPricingIntoOptions($finalOptions, $pricing);

        Cart::instance($this->cart_instance)->add([
            'id'      => $cartKey,
            'name'    => $product['product_name'],
            'qty'     => $convertedQty,
            'price'   => $calculated['unit_price'],   // <<< always unit price
            'weight'  => 1,
            'options' => $finalOptions,
        ]);

        $this->check_quantity[$cartKey] = (int) ($stockContext['available_total'] ?? ($product['product_quantity'] ?? PHP_INT_MAX));
        $this->quantity[$cartKey]       = $convertedQty;
        $this->discount_type[$cartKey]  = 'fixed';
        $this->item_discount[$cartKey]  = 0;
        $this->conversion_breakdowns[$cartKey] = $calculated['conversion_context']['breakdown'] ?? '';

        $this->refreshTotals();
    }

    public function confirmBundleSelection($bundleId)
    {
        if (!$this->pendingProduct) {
            session()->flash('message', 'Bundel yang dipilih tidak valid.');
            return;
        }

        $bundle = ProductBundle::with('items.product')->find($bundleId);
        if (!$bundle) {
            session()->flash('message', 'Bundel yang dipilih tidak valid.');
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
        $cart_key = $cart_item->options->cart_key ?? $cart_item->id;

        Cart::instance($this->cart_instance)->remove($row_id);

        unset($this->quantity[$cart_key]);
        unset($this->check_quantity[$cart_key]);
        unset($this->unit_price[$cart_key]);
        unset($this->discount_type[$cart_key]);
        unset($this->item_discount[$cart_key]);
        unset($this->conversion_breakdowns[$cart_key]);

        $this->refreshTotals();
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
        $cart = Cart::instance($this->cart_instance);
        $cart_item = $cart->get($row_id);
        if (!$cart_item) {
            return;
        }

        if (!array_key_exists($cart_key, $this->check_quantity)) {
            $this->check_quantity[$cart_key] = (int) ($cart_item->options->stock ?? PHP_INT_MAX);
        }

        if (!array_key_exists($cart_key, $this->quantity)) {
            $this->quantity[$cart_key] = $cart_item->qty;
        }

        $newQty = $this->quantity[$cart_key];
        $productId = (int) ($cart_item->options->product_id ?? 0);
        $product = $productId ? Product::find($productId) : null;
        if (!$product) {
            return;
        }

        $productData = $product->toArray();
        $pricing = $this->resolveTenantPricing($productData);
        $existingOptions = $cart_item->options->toArray();
        $forcedAllocation = null;
        if (!empty($existingOptions['serial_numbers'])) {
            $forcedAllocation = $this->buildSerialAllocation($existingOptions['serial_numbers']);
        }

        $stockContext = $this->resolveStockForProduct($productData, $newQty, $forcedAllocation);

        if (!$stockContext['sufficient']) {
            session()->flash('message', 'Jumlah yang diminta tidak tersedia di stok.');
            $this->quantity[$cart_key] = $cart_item->qty;
            return;
        }

        $calculated = $this->calculate($productData, $newQty, $pricing);
        $bundleOptions = $this->recalculateBundleOptions($existingOptions, $newQty);
        $finalSubTotal = $calculated['sub_total'] + ($bundleOptions['bundle_price'] ?? 0);

        $mergedOptions = array_merge(
            $existingOptions,
            $bundleOptions,
            [
                'unit_price' => $calculated['unit_price'],
                'sub_total' => $finalSubTotal,
                'conversion_context' => $calculated['conversion_context'],
                'cart_key' => $cart_key,
            ]
        );

        $mergedOptions = $this->applyStockContextToOptions($mergedOptions, $stockContext);
        $mergedOptions = $this->injectPricingIntoOptions($mergedOptions, $pricing);

        if ($forcedAllocation) {
            $mergedOptions['serial_tax_ids'] = $forcedAllocation['tax_ids'];
        }

        $cart->update($row_id, [
            'qty' => $newQty,
            'price' => $calculated['unit_price'],
            'options' => $mergedOptions,
        ]);

        $this->conversion_breakdowns[$cart_key] = $calculated['conversion_context']['breakdown'] ?? '';
        $this->check_quantity[$cart_key] = (int) ($stockContext['available_total'] ?? $newQty);
        $this->refreshTotals();
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

        $this->refreshTotals();

        session()->flash('discount_message' . $cart_key, 'Diskon berhasil ditambahkan pada produk!');
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
                $normalized = $this->normalizeSerial($serial, (int) ($data['id'] ?? 0));
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
            $this->refreshTotals();
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
                'bundle_price_per_bundle' => 0.0,
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
                'quantity_per_bundle' => (float) ($item['quantity'] ?? 0),
                'price'          => isset($item['price']) ? (float) $item['price'] : 0.0,
                'sub_total'      => isset($item['price']) ? round(((float) ($item['quantity'] ?? 0)) * (float) $item['price'], 2) : 0.0,
            ];
        }

        return [
            'id'           => $bundle['id'] ?? null,
            'bundle_items' => $items,
            'bundle_price' => isset($bundle['price']) ? (float) $bundle['price'] : 0.0,
            'bundle_price_per_bundle' => isset($bundle['price']) ? (float) $bundle['price'] : 0.0,
            'bundle_name'  => $bundle['name'] ?? null,
        ];
    }

    private function prepareBundleOptionsForQuantity(array $bundleMeta, int $quantity): array
    {
        if (empty($bundleMeta['id'])) {
            return [
                'bundle_items' => [],
                'bundle_price' => 0.0,
                'bundle_price_per_bundle' => 0.0,
                'bundle_name' => null,
                'bundle_id' => null,
            ];
        }

        $quantity = max(1, (int) $quantity);
        $perBundlePrice = (float) ($bundleMeta['bundle_price_per_bundle'] ?? 0.0);
        $items = [];

        foreach ($bundleMeta['bundle_items'] as $item) {
            $quantityPerBundle = (float) ($item['quantity_per_bundle'] ?? 0.0);
            $unitPrice = (float) ($item['price'] ?? 0.0);
            $lineQuantity = $quantityPerBundle * $quantity;

            $items[] = array_merge($item, [
                'quantity_per_bundle' => $quantityPerBundle,
                'line_quantity' => $lineQuantity,
                'sub_total_per_bundle' => round($quantityPerBundle * $unitPrice, 2),
                'sub_total' => round($lineQuantity * $unitPrice, 2),
            ]);
        }

        return [
            'bundle_items' => $items,
            'bundle_price' => round($perBundlePrice * $quantity, 2),
            'bundle_price_per_bundle' => $perBundlePrice,
            'bundle_name' => $bundleMeta['bundle_name'] ?? null,
            'bundle_id' => $bundleMeta['id'] ?? null,
        ];
    }

    private function recalculateBundleOptions(array $existingOptions, int $quantity): array
    {
        $hasBundle = !empty($existingOptions['bundle_id']) || !empty($existingOptions['bundle_items'] ?? []);

        if (!$hasBundle) {
            return [
                'bundle_items' => [],
                'bundle_price' => 0.0,
                'bundle_price_per_bundle' => 0.0,
                'bundle_name' => null,
                'bundle_id' => null,
            ];
        }

        $quantity = max(0, (int) $quantity);
        $perBundlePrice = (float) ($existingOptions['bundle_price_per_bundle'] ?? 0.0);
        $items = [];

        foreach ($existingOptions['bundle_items'] ?? [] as $item) {
            $quantityPerBundle = (float) ($item['quantity_per_bundle'] ?? ($item['quantity'] ?? 0));
            $unitPrice = (float) ($item['price'] ?? 0.0);
            $lineQuantity = $quantityPerBundle * $quantity;

            $items[] = array_merge($item, [
                'quantity_per_bundle' => $quantityPerBundle,
                'line_quantity' => $lineQuantity,
                'sub_total_per_bundle' => round($quantityPerBundle * $unitPrice, 2),
                'sub_total' => round($lineQuantity * $unitPrice, 2),
            ]);
        }

        return [
            'bundle_items' => $items,
            'bundle_price' => round($perBundlePrice * $quantity, 2),
            'bundle_price_per_bundle' => $perBundlePrice,
            'bundle_name' => $existingOptions['bundle_name'] ?? null,
            'bundle_id' => $existingOptions['bundle_id'] ?? null,
        ];
    }

    private function addSerialEntry(array $product, array $serial, ?array $bundle = null): void
    {
        if (!isset($serial['id'], $serial['serial_number'])) {
            return;
        }

        $pricing = $this->resolveTenantPricing($product);
        $bundleMeta = $this->formatBundleForCart($bundle);
        $cartKey    = $this->generateCartItemKey($product['id'], $bundleMeta['id']);

        $cart = Cart::instance($this->cart_instance);
        $existing = $cart->search(fn($item) => $item->id === $cartKey)->first();

        $serialPayload = [
            'id'            => (int) $serial['id'],
            'serial_number' => (string) $serial['serial_number'],
            'tax_id'        => isset($serial['tax_id']) ? ($serial['tax_id'] !== null ? (int) $serial['tax_id'] : null) : null,
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

            $calculated = $this->calculate($product, $quantity, $pricing);
            $bundleOptions = $this->recalculateBundleOptions($options, $quantity);
            $finalSubTotal = $calculated['sub_total'] + ($bundleOptions['bundle_price'] ?? 0);

            $allocation = $this->buildSerialAllocation($options['serial_numbers']);
            $stockContext = $this->resolveStockForProduct($product, $quantity, $allocation);

            if (!$stockContext['sufficient']) {
                session()->flash('message', 'Serial yang dipilih melebihi stok POS yang tersedia.');
                return;
            }

            $mergedOptions = array_merge(
                $options,
                $bundleOptions,
                [
                    'sub_total' => $finalSubTotal,
                    'unit_price'=> $calculated['unit_price'],
                    'conversion_context' => $calculated['conversion_context'],
                    'cart_key' => $cartKey,
                ]
            );

            $mergedOptions = $this->applyStockContextToOptions($mergedOptions, $stockContext);
            $mergedOptions = $this->injectPricingIntoOptions($mergedOptions, $pricing);
            $mergedOptions['serial_numbers'] = array_values($options['serial_numbers']);
            $mergedOptions['serial_tax_ids'] = $allocation['tax_ids'];

            $cart->update($existing->rowId, [
                'qty'     => $quantity,
                'price'   => $calculated['unit_price'],
                'options' => $mergedOptions,
            ]);

            $this->quantity[$cartKey]       = $quantity;
            $this->check_quantity[$cartKey] = (int) ($stockContext['available_total'] ?? ($existing->options->stock ?? PHP_INT_MAX));
            $this->conversion_breakdowns[$cartKey] = $calculated['conversion_context']['breakdown'] ?? '';
            return;
        }

        $calculated = $this->calculate($product, 1, $pricing);
        $bundleOptions = $this->prepareBundleOptionsForQuantity($bundleMeta, 1);
        $finalSubTotal = $calculated['sub_total'] + ($bundleOptions['bundle_price'] ?? 0);

        $allocation = $this->buildSerialAllocation([$serialPayload]);
        $stockContext = $this->resolveStockForProduct($product, 1, $allocation);

        if (!$stockContext['sufficient']) {
            session()->flash('message', 'Serial yang dipilih melebihi stok POS yang tersedia.');
            return;
        }

        $baseOptions = array_merge([
            'product_discount'        => 0.00,
            'product_discount_type'   => 'fixed',
            'sub_total'               => $finalSubTotal,
            'code'                    => $product['product_code'] ?? null,
            'stock'                   => $product['product_quantity'] ?? PHP_INT_MAX,
            'product_tax'             => 0,
            'unit_price'              => $calculated['unit_price'],
            'serial_number_required'  => true,
            'serial_numbers'          => [$serialPayload],
            'product_id'              => (int) $product['id'],
            'conversion_context'      => $calculated['conversion_context'],
            'cart_key'                => $cartKey,
            'allocated_non_tax'       => 0,
            'allocated_tax'           => 0,
            'serial_tax_ids'          => $allocation['tax_ids'],
        ], $bundleOptions);
        $baseOptions = $this->injectPricingIntoOptions($baseOptions, $pricing);

        $finalOptions = $this->applyStockContextToOptions($baseOptions, $stockContext);
        $finalOptions = $this->injectPricingIntoOptions($finalOptions, $pricing);
        $finalOptions['serial_numbers'] = [$serialPayload];
        $finalOptions['serial_tax_ids'] = $allocation['tax_ids'];

        Cart::instance($this->cart_instance)->add([
            'id'     => $cartKey,
            'name'   => $product['product_name'],
            'qty'    => 1,
            'price'  => $calculated['unit_price'],   // unit price
            'weight' => 1,
            'options' => $finalOptions,
        ]);

        $this->check_quantity[$cartKey] = (int) ($stockContext['available_total'] ?? ($product['product_quantity'] ?? PHP_INT_MAX));
        $this->quantity[$cartKey]       = 1;
        $this->discount_type[$cartKey]  = 'fixed';
        $this->item_discount[$cartKey]  = 0;
        $this->conversion_breakdowns[$cartKey] = $calculated['conversion_context']['breakdown'] ?? '';
    }

    private function normalizeSerial($serial, ?int $productId = null): ?array
    {
        if (!$serial) {
            return null;
        }

        $data = is_array($serial) ? $serial : (array) $serial;
        if (!isset($data['id'], $data['serial_number'])) {
            return null;
        }

        $serialId = (int) $data['id'];
        $serialNumber = (string) $data['serial_number'];

        if ($serialId <= 0) {
            return null;
        }

        if ($productId) {
            $record = ProductSerialNumber::query()
                ->where('id', $serialId)
                ->where('product_id', $productId)
                ->when($this->posLocationId, fn($query) => $query->where('location_id', $this->posLocationId))
                ->whereNull('dispatch_detail_id')
                ->first();

            if (!$record) {
                return null;
            }

            return [
                'id'            => $record->id,
                'serial_number' => $record->serial_number,
                'tax_id'        => $record->tax_id !== null ? (int) $record->tax_id : null,
            ];
        }

        return [
            'id'            => $serialId,
            'serial_number' => $serialNumber,
            'tax_id'        => isset($data['tax_id']) && $data['tax_id'] !== null ? (int) $data['tax_id'] : null,
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
        $baseKey = 'product_' . (int) $productId;

        return $bundleId ? $baseKey . '_bundle_' . (int) $bundleId : $baseKey;
    }

    private function resolveTenantPricing($product): array
    {
        $productId = (int) (data_get($product, 'id') ?? 0);

        $baseSalePrice = $this->extractPriceValue($product, 'sale_price') ?? 0.0;
        $baseTier1Price = $this->extractPriceValue($product, 'tier_1_price');
        $baseTier2Price = $this->extractPriceValue($product, 'tier_2_price');

        $pricing = [
            'sale_price' => $baseSalePrice,
            'tier_1_price' => $baseTier1Price,
            'tier_2_price' => $baseTier2Price,
        ];

        $settingId = session('setting_id');

        if ($productId > 0 && $settingId) {
            $tenantPrice = ProductPrice::query()
                ->forProduct($productId)
                ->forSetting((int) $settingId)
                ->first();

            if ($tenantPrice) {
                if ($tenantPrice->sale_price !== null) {
                    $pricing['sale_price'] = (float) $tenantPrice->sale_price;
                }

                if ($tenantPrice->tier_1_price !== null) {
                    $pricing['tier_1_price'] = (float) $tenantPrice->tier_1_price;
                }

                if ($tenantPrice->tier_2_price !== null) {
                    $pricing['tier_2_price'] = (float) $tenantPrice->tier_2_price;
                }
            }
        }

        $pricing['sale_price'] = (float) ($pricing['sale_price'] ?? 0.0);
        $pricing['tier_1_price'] = $pricing['tier_1_price'] !== null
            ? (float) $pricing['tier_1_price']
            : $pricing['sale_price'];
        $pricing['tier_2_price'] = $pricing['tier_2_price'] !== null
            ? (float) $pricing['tier_2_price']
            : $pricing['sale_price'];

        return $pricing;
    }

    private function extractPriceValue($source, string $key): ?float
    {
        $value = data_get($source, $key);

        if ($value === null) {
            return null;
        }

        return (float) $value;
    }

    private function injectPricingIntoOptions(array $options, array $pricing): array
    {
        return array_merge($options, [
            'sale_price'   => $pricing['sale_price'],
            'tier_1_price' => $pricing['tier_1_price'],
            'tier_2_price' => $pricing['tier_2_price'],
        ]);
    }

    public function calculate($product, $qty = 1, ?array $resolvedPricing = null)
    {
        $qty = max(1, (int) $qty);
        $productId = (int) (data_get($product, 'id') ?? 0);
        $resolvedPricing = $resolvedPricing ?? $this->resolveTenantPricing($product);
        $salePrice = $resolvedPricing['sale_price'];

        if ($this->customer_tier === 'WHOLESALER') {
            $unit_price = $resolvedPricing['tier_1_price'];
            $sub_total = round($unit_price * $qty, 2);
            $conversionContext = [
                'quantity' => $qty,
                'breakdown' => $this->calculateConversionBreakdown($productId, $qty),
                'segments' => [],
            ];
        } elseif ($this->customer_tier === 'RESELLER') {
            $unit_price = $resolvedPricing['tier_2_price'];
            $sub_total = round($unit_price * $qty, 2);
            $conversionContext = [
                'quantity' => $qty,
                'breakdown' => $this->calculateConversionBreakdown($productId, $qty),
                'segments' => [],
            ];
        } else {
            // Calculate conversion-based total and average price
            $conversion = $this->calculateCascadingUnitPrice($productId, $qty, $salePrice);
            $unit_price = $conversion['unit_price'];
            $sub_total = $conversion['sub_total'];
            $conversionContext = [
                'quantity' => $qty,
                'breakdown' => $conversion['breakdown'] ?? '',
                'segments' => $conversion['segments'] ?? [],
            ];
        }

        return [
            'price' => $unit_price,        // average or fallback
            'unit_price' => $unit_price,   // same for consistency
            'product_tax' => 0,
            'sub_total' => $sub_total,     // total price of qty
            'conversion_context' => $conversionContext,
        ];
    }

    private function calculateCascadingUnitPrice(int $productId, int $quantity, float $fallback): array
    {
        $settingId = session('setting_id');
        $settingId = $settingId !== null ? (int) $settingId : null;

        $relations = [
            'unit',
            'baseUnit',
        ];

        if ($settingId) {
            $relations['prices'] = fn ($query) => $query->forSetting($settingId);
        } else {
            $relations[] = 'prices';
        }

        $conversions = ProductUnitConversion::query()
            ->with($relations)
            ->where('product_id', $productId)
            ->orderByDesc('conversion_factor')
            ->get();

        $total_cost = 0.0;
        $used_qty = 0;
        $remaining = max(0, (int) $quantity);
        $segments = [];

        foreach ($conversions as $conv) {
            $factor = (int) $conv->conversion_factor;

            $price = null;

            if ($settingId) {
                $resolvedPrice = $conv->priceValueForSetting($settingId);

                if ($resolvedPrice > 0) {
                    $price = (float) $resolvedPrice;
                }
            } elseif ($conv->price !== null && (float) $conv->price > 0) {
                $price = (float) $conv->price;
            }

            if ($factor < 1 || $price === null || $remaining <= 0) {
                continue;
            }

            $unit_count = intdiv($remaining, $factor);
            if ($unit_count <= 0) {
                continue;
            }

            $segmentQuantity = $unit_count * $factor;
            $segmentCost = $unit_count * $price;

            $segments[] = [
                'unit_name' => optional($conv->unit)->name ?? 'unit',
                'count' => $unit_count,
                'quantity_per_segment' => $factor,
                'line_quantity' => $segmentQuantity,
                'price' => $price,
                'sub_total' => round($segmentCost, 2),
            ];

            $total_cost += $segmentCost;
            $used_qty += $segmentQuantity;
            $remaining -= $segmentQuantity;
        }

        if ($remaining > 0) {
            $baseUnitName = $conversions->first()?->baseUnit->name
                ?? $conversions->first()?->unit->name
                ?? 'pc';
            $segmentCost = $remaining * $fallback;

            $segments[] = [
                'unit_name' => $baseUnitName,
                'count' => $remaining,
                'quantity_per_segment' => 1,
                'line_quantity' => $remaining,
                'price' => $fallback,
                'sub_total' => round($segmentCost, 2),
            ];

            $total_cost += $segmentCost;
            $used_qty += $remaining;
            $remaining = 0;
        }

        if ($used_qty === 0) {
            $segmentCost = $quantity * $fallback;
            $segments[] = [
                'unit_name' => 'pc',
                'count' => $quantity,
                'quantity_per_segment' => 1,
                'line_quantity' => $quantity,
                'price' => $fallback,
                'sub_total' => round($segmentCost, 2),
            ];

            $total_cost += $segmentCost;
            $used_qty = max(1, $quantity);
        }

        $breakdown = collect($segments)
            ->map(function ($segment) {
                $count = (int) ($segment['count'] ?? 0);
                $unitName = $segment['unit_name'] ?? 'unit';
                $plural = $count > 1 ? 's' : '';
                return $count > 0 ? sprintf('%d %s%s', $count, $unitName, $plural) : null;
            })
            ->filter()
            ->implode(', ');

        return [
            'unit_price' => $used_qty > 0 ? round($total_cost / $used_qty, 2) : $fallback,
            'sub_total' => round($total_cost, 2),
            'segments' => $segments,
            'breakdown' => $breakdown,
        ];
    }

    private function calculateConversionBreakdown(int $productId, int $quantity): string
    {
        if ($quantity < 1) return '';

        $context = $this->calculateCascadingUnitPrice($productId, $quantity, 0.0);

        return $context['breakdown'] ?? '';
    }

    private function resolveStockForProduct(array $product, int $quantity, ?array $forcedAllocation = null): array
    {
        $quantity = max(0, (int) $quantity);
        $productId = (int) ($product['id'] ?? 0);

        $availableNonTax = null;
        $availableTax = null;
        $availableTotal = null;
        $resolvedTaxId = null;
        $stockTaxId = $product['product_tax'] ?? null;

        if ($this->posLocationId && $productId > 0) {
            $stock = ProductStock::query()
                ->select(['id', 'quantity_non_tax', 'quantity_tax', 'broken_quantity_non_tax', 'broken_quantity_tax', 'tax_id'])
                ->where('product_id', $productId)
                ->where('location_id', $this->posLocationId)
                ->first();

            if ($stock) {
                $availableNonTax = max(0, (int) $stock->quantity_non_tax - (int) $stock->broken_quantity_non_tax);
                $availableTax = max(0, (int) $stock->quantity_tax - (int) $stock->broken_quantity_tax);
                $availableTotal = $availableNonTax + $availableTax;
                $stockTaxId = $stock->tax_id !== null ? (int) $stock->tax_id : null;
            } else {
                $availableNonTax = 0;
                $availableTax = 0;
                $availableTotal = 0;
            }
        }

        if ($availableTotal === null) {
            $availableTotal = (int) ($product['product_quantity'] ?? PHP_INT_MAX);
        }

        $allocatedNonTax = 0;
        $allocatedTax = 0;

        if ($forcedAllocation) {
            $allocatedNonTax = max(0, (int) ($forcedAllocation['allocated_non_tax'] ?? 0));
            $allocatedTax = max(0, (int) ($forcedAllocation['allocated_tax'] ?? 0));
            $forcedTaxId = $forcedAllocation['resolved_tax_id'] ?? null;

            if ($availableNonTax !== null && $allocatedNonTax > $availableNonTax) {
                $allocatedNonTax = $availableNonTax;
            }

            if ($availableTax !== null && $allocatedTax > $availableTax) {
                $allocatedTax = $availableTax;
            }

            $allocatedTotal = $allocatedNonTax + $allocatedTax;
            if ($allocatedTotal < $quantity) {
                $remaining = $quantity - $allocatedTotal;

                if ($availableNonTax !== null && $allocatedNonTax < $availableNonTax) {
                    $additional = min($remaining, max(0, $availableNonTax - $allocatedNonTax));
                    $allocatedNonTax += $additional;
                    $remaining -= $additional;
                }

                if ($remaining > 0 && $availableTax !== null && $allocatedTax < $availableTax) {
                    $additionalTax = min($remaining, max(0, $availableTax - $allocatedTax));
                    $allocatedTax += $additionalTax;
                    $remaining -= $additionalTax;
                }

                if ($remaining > 0) {
                    $allocatedTax += $remaining;
                }
            }

            $resolvedTaxId = $forcedTaxId !== null ? (int) $forcedTaxId : null;
            if ($resolvedTaxId === null && $allocatedTax > 0) {
                $resolvedTaxId = $stockTaxId !== null ? (int) $stockTaxId : null;
            }
        } else {
            if ($availableNonTax !== null && $availableTax !== null) {
                $allocatedNonTax = min($quantity, $availableNonTax);
                $allocatedTax = min(max(0, $quantity - $allocatedNonTax), $availableTax);
            } else {
                $allocatedNonTax = min($quantity, $availableTotal);
                $allocatedTax = max(0, $quantity - $allocatedNonTax);
            }

            $resolvedTaxId = $allocatedTax > 0 && $stockTaxId !== null ? (int) $stockTaxId : null;
        }

        $totalAllocated = $allocatedNonTax + $allocatedTax;

        $sufficient = true;
        if ($availableTotal !== null) {
            $sufficient = $quantity <= $availableTotal;
        }
        if ($availableNonTax !== null && $allocatedNonTax > $availableNonTax) {
            $sufficient = false;
        }
        if ($availableTax !== null && $allocatedTax > $availableTax) {
            $sufficient = false;
        }
        if ($totalAllocated < $quantity) {
            $sufficient = false;
        }

        $remainingNonTax = $availableNonTax !== null ? max(0, $availableNonTax - $allocatedNonTax) : null;
        $remainingTax = $availableTax !== null ? max(0, $availableTax - $allocatedTax) : null;

        return [
            'sufficient' => $sufficient,
            'allocated_non_tax' => $allocatedNonTax,
            'allocated_tax' => $allocatedTax,
            'resolved_tax_id' => $resolvedTaxId,
            'remaining_non_tax' => $remainingNonTax,
            'remaining_tax' => $remainingTax,
            'available_non_tax' => $availableNonTax,
            'available_tax' => $availableTax,
            'available_total' => $availableTotal,
        ];
    }

    private function applyStockContextToOptions(array $options, array $stockContext): array
    {
        $resolvedTaxId = $stockContext['resolved_tax_id'] ?? null;
        if ($resolvedTaxId === 0 || $resolvedTaxId === '0' || $resolvedTaxId === '') {
            $resolvedTaxId = null;
        }

        $options['product_tax'] = $resolvedTaxId;
        $options['resolved_tax_id'] = $resolvedTaxId;
        $options['allocated_non_tax'] = (int) ($stockContext['allocated_non_tax'] ?? 0);
        $options['allocated_tax'] = (int) ($stockContext['allocated_tax'] ?? 0);
        $options['available_quantity_non_tax'] = isset($stockContext['remaining_non_tax'])
            ? max(0, (int) $stockContext['remaining_non_tax'])
            : null;
        $options['available_quantity_tax'] = isset($stockContext['remaining_tax'])
            ? max(0, (int) $stockContext['remaining_tax'])
            : null;
        $options['available_stock_non_tax'] = isset($stockContext['available_non_tax'])
            ? max(0, (int) $stockContext['available_non_tax'])
            : null;
        $options['available_stock_tax'] = isset($stockContext['available_tax'])
            ? max(0, (int) $stockContext['available_tax'])
            : null;
        if (isset($stockContext['available_total'])) {
            $options['stock'] = max(0, (int) $stockContext['available_total']);
        }
        $options['pos_location_id'] = $this->posLocationId;

        return $options;
    }

    private function buildSerialAllocation(array $serials): array
    {
        $nonTax = 0;
        $tax = 0;
        $taxIds = [];

        foreach ($serials as $serial) {
            if ($serial instanceof Collection) {
                $serial = $serial->toArray();
            } elseif (is_object($serial) && method_exists($serial, 'toArray')) {
                $serial = $serial->toArray();
            }

            if (!is_array($serial)) {
                continue;
            }

            $taxId = $serial['tax_id'] ?? null;
            if ($taxId !== null && $taxId !== '') {
                $tax++;
                $taxIds[] = (int) $taxId;
            } else {
                $nonTax++;
            }
        }

        $uniqueTaxIds = array_values(array_unique($taxIds));
        $resolvedTaxId = null;
        if ($tax > 0 && count($uniqueTaxIds) === 1) {
            $resolvedTaxId = $uniqueTaxIds[0];
        }

        return [
            'allocated_non_tax' => $nonTax,
            'allocated_tax' => $tax,
            'resolved_tax_id' => $resolvedTaxId,
            'tax_ids' => $uniqueTaxIds,
        ];
    }

    public function setCustomer($customer)
    {
        $this->customer_id = $customer['id'];
        $this->customer_tier = $customer['tier'] ?? null;

        foreach (Cart::instance($this->cart_instance)->content() as $item) {
            $productId = (int) ($item->options->product_id ?? 0);
            $product = $productId ? Product::find($productId) : null;
            if (!$product) continue;

            $productData = $product->toArray();
            $pricing = $this->resolveTenantPricing($productData);
            $qty = $item->qty;
            $calculated = $this->calculate($productData, $qty, $pricing);
            $existingOptions = $item->options->toArray();
            $bundleOptions = $this->recalculateBundleOptions($existingOptions, $qty);
            $finalSubTotal = $calculated['sub_total'] + ($bundleOptions['bundle_price'] ?? 0);

            $forcedAllocation = null;
            if (!empty($existingOptions['serial_numbers'])) {
                $forcedAllocation = $this->buildSerialAllocation($existingOptions['serial_numbers']);
            }

            $stockContext = $this->resolveStockForProduct($productData, $qty, $forcedAllocation);

            $options = array_merge($existingOptions, $bundleOptions, [
                'unit_price' => $calculated['unit_price'],
                'product_tax' => $calculated['product_tax'],
                'sub_total' => $finalSubTotal,
                'conversion_context' => $calculated['conversion_context'],
                'cart_key' => $item->options->cart_key ?? $item->id,
            ]);

            $options = $this->applyStockContextToOptions($options, $stockContext);
            $options = $this->injectPricingIntoOptions($options, $pricing);

            if ($forcedAllocation) {
                $options['serial_tax_ids'] = $forcedAllocation['tax_ids'];
            }

            Cart::instance($this->cart_instance)->update($item->rowId, [
                'price' => $calculated['unit_price'],
                'options' => $options,
            ]);

            $cartKey = $item->options->cart_key ?? $item->id;
            $this->quantity[$cartKey] = $qty;
            $this->conversion_breakdowns[$cartKey] = $calculated['conversion_context']['breakdown'] ?? '';
            $this->check_quantity[$cartKey] = (int) ($stockContext['available_total'] ?? $qty);
        }

        $this->refreshTotals();
    }

    public function triggerCustomerModal()
    {
        $this->dispatch('openCustomerModal');
    }

    public function updateCartOptions($row_id, $cart_key, $cart_item, $discount_amount)
    {
        Cart::instance($this->cart_instance)->update($row_id, [
            'options' => array_merge($cart_item->options->toArray(), [
                'sub_total' => round(($cart_item->price * $cart_item->qty) + ($cart_item->options->bundle_price ?? 0), 2),
                'product_discount' => $discount_amount,
                'product_discount_type' => $this->discount_type[$cart_key],
                'cart_key' => $cart_key,
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
        $serial    = $this->normalizeSerial($payload['serial'] ?? null, $productId);

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
        $this->refreshTotals();
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
        $newQty = max(0, count($list));
        $productId = (int) ($item->options->product_id ?? 0);
        $product = $productId ? Product::find($productId) : null;
        $productData = $product ? $product->toArray() : null;
        $pricing = $productData ? $this->resolveTenantPricing($productData) : null;

        $cart_key = $item->options->cart_key ?? $item->id;
        $allocation = $this->buildSerialAllocation($options['serial_numbers'] ?? []);
        $stockContext = null;

        if ($product) {
            $stockContext = $this->resolveStockForProduct($productData, max($newQty, 0), $allocation);
        }

        if ($product && $newQty > 0) {
            $calculated = $this->calculate($productData, $newQty, $pricing);
            $bundleOptions = $this->recalculateBundleOptions($options, $newQty);
            $finalSubTotal = $calculated['sub_total'] + ($bundleOptions['bundle_price'] ?? 0);

            $mergedOptions = array_merge($options, $bundleOptions, [
                'sub_total' => $finalSubTotal,
                'unit_price' => $calculated['unit_price'],
                'conversion_context' => $calculated['conversion_context'],
                'cart_key' => $cart_key,
            ]);

            if ($stockContext) {
                $mergedOptions = $this->applyStockContextToOptions($mergedOptions, $stockContext);
            }

            if ($pricing) {
                $mergedOptions = $this->injectPricingIntoOptions($mergedOptions, $pricing);
            }

            $mergedOptions['serial_numbers'] = $options['serial_numbers'];
            $mergedOptions['serial_tax_ids'] = $allocation['tax_ids'];

            Cart::instance($this->cart_instance)->update($rowId, [
                'qty' => $newQty,
                'price' => $calculated['unit_price'],
                'options' => $mergedOptions,
            ]);

            $this->check_quantity[$cart_key] = (int) ($stockContext['available_total'] ?? $newQty);
            $this->conversion_breakdowns[$cart_key] = $calculated['conversion_context']['breakdown'] ?? '';
        } else {
            if ($stockContext) {
                $options = $this->applyStockContextToOptions($options, $stockContext);
            }

            if ($pricing) {
                $options = $this->injectPricingIntoOptions($options, $pricing);
            }

            $options['serial_tax_ids'] = $allocation['tax_ids'];
            Cart::instance($this->cart_instance)->update($rowId, ['options' => $options, 'qty' => $newQty]);
            $this->check_quantity[$cart_key] = $stockContext['available_total'] ?? ($options['stock'] ?? PHP_INT_MAX);
            $this->conversion_breakdowns[$cart_key] = '';
        }

        $this->quantity[$cart_key] = $newQty;
        $this->refreshTotals();
    }

    protected function refreshTotals(bool $forcePaidAmountSync = false): void
    {
        $this->total_amount = $this->calculateTotal();
        $this->syncPaymentsWithTotal($forcePaidAmountSync);

        if ($this->forceShowChangeModal && $this->forcedChangeAmount !== null) {
            $this->changeModalHasPositiveChange = true;
            $this->changeModalAmount = $this->formatChangeAmount($this->forcedChangeAmount);
            $this->lastChangeModalAmount = $this->changeModalAmount;
            $this->dispatch('show-change-modal', amount: $this->changeModalAmount);
            $this->forceShowChangeModal = false;
            $this->forcedChangeAmount = null;

            return;
        }

        $this->syncChangeModalState();
        $this->maybeAutoShowChangeModal();
    }

    protected function syncPaymentsWithTotal(bool $force = false): void
    {
        if ($force) {
            $this->paidAmountManuallyUpdated = false;
        }

        if ($force || ! $this->paidAmountManuallyUpdated) {
            if (empty($this->payments)) {
                $this->payments = [
                    $this->makePaymentRow(null),
                ];
            }

            $primaryAmount = (float) $this->total_amount;

            foreach ($this->payments as $index => $payment) {
                $this->payments[$index]['amount'] = $index === 0
                    ? $primaryAmount
                    : 0.0;
            }
        }

        $this->syncPaidAmountFromPayments();
    }

    protected function syncPaidAmountFromPayments(): void
    {
        $this->paid_amount = $this->calculatePaymentsTotal();
    }

    protected function calculatePaymentsTotal(): float
    {
        return round(collect($this->payments)
            ->sum(function ($payment) {
                return (float) data_get($payment, 'amount', 0);
            }), 2);
    }

    protected function sanitizeCurrencyValue($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $raw = is_string($value) ? $value : (string) ($value ?? '');

        $settings = settings();
        $currency = $settings ? $settings->currency : null;

        $symbol = $currency->symbol ?? '';
        $decimalSeparator = $currency->decimal_separator ?? '.';
        $thousandSeparator = $currency->thousand_separator ?? ',';

        if ($symbol !== '') {
            $raw = str_replace($symbol, '', $raw);
        }

        $raw = str_replace("\xc2\xa0", '', $raw); // remove non-breaking spaces
        $raw = str_replace(' ', '', $raw);

        if ($thousandSeparator !== '') {
            $raw = str_replace($thousandSeparator, '', $raw);
        }

        if ($decimalSeparator !== '.') {
            $raw = str_replace($decimalSeparator, '.', $raw);
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', $raw);

        if ($normalized === '' || $normalized === '-' || !is_numeric($normalized)) {
            return 0.0;
        }

        return (float) $normalized;
    }

    protected function makePaymentRow(?int $methodId, float $amount = 0.0): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'method_id' => $methodId,
            'amount' => round($amount, 2),
        ];
    }

    protected function findPaymentMethod(?int $methodId): mixed
    {
        if (! $methodId) {
            return null;
        }

        return $this->paymentMethodsCollection()
            ->first(function ($method) use ($methodId) {
                return (int) data_get($method, 'id') === (int) $methodId;
            });
    }

    protected function paymentMethodIsCash(?int $methodId): bool
    {
        $method = $this->findPaymentMethod($methodId);

        return (bool) data_get($method, 'is_cash', false);
    }

    protected function initializeChangeModalFromFlash(): void
    {
        $hasCashOverpayment = session()->pull('pos_cash_overpayment', false);
        $changeDue = session()->pull('pos_change_due');

        if (! $hasCashOverpayment) {
            return;
        }

        if ($changeDue === null) {
            return;
        }

        $changeDue = round((float) $changeDue, 2);

        if ($changeDue <= 0) {
            return;
        }

        $this->forcedChangeAmount = $changeDue;
        $this->forceShowChangeModal = true;
    }

    protected function syncChangeModalState(): void
    {
        $change = $this->changeDue;
        $this->changeModalHasPositiveChange = $this->hasCashPayment && $change > 0;
        $this->changeModalAmount = $this->changeModalHasPositiveChange
            ? $this->formatChangeAmount($change)
            : null;
    }

    protected function maybeAutoShowChangeModal(): void
    {
        if ($this->changeModalHasPositiveChange) {
            if (! $this->changeModalExplicitlyRequested && ! $this->forceShowChangeModal) {
                return;
            }

            if ($this->lastChangeModalAmount !== $this->changeModalAmount) {
                $this->lastChangeModalAmount = $this->changeModalAmount;
                $this->dispatch('show-change-modal', amount: $this->changeModalAmount);
            }

            return;
        }

        if ($this->lastChangeModalAmount !== null) {
            $this->lastChangeModalAmount = null;
        }

        $this->changeModalExplicitlyRequested = false;

        $this->dispatch('hide-change-modal');
    }

    protected function formatChangeAmount(float $change): string
    {
        $settings = settings();
        $currency = $settings ? $settings->currency : null;

        $decimalSeparator = $currency->decimal_separator ?? ',';
        $thousandSeparator = $currency->thousand_separator ?? '.';

        return number_format($change, 2, $decimalSeparator, $thousandSeparator);
    }
}
