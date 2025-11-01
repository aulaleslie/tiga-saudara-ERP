<?php

namespace Modules\Sale\Http\Controllers;

use App\Events\PrintJobEvent;
use App\Support\PosLocationResolver;
use Exception;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Http\Requests\StorePosSaleRequest;
use Modules\Setting\Entities\PaymentMethod;
use Throwable;

class PosController extends Controller
{
    private static ?bool $salesHasPaymentMethodIdColumn = null;

    private static ?bool $saleDetailsHasSerialNumbersColumn = null;

    private static ?bool $saleBundleItemsHasTaxIdColumn = null;

    public function index() {
        abort_if(Gate::denies('pos.access'), 403);
        Cart::instance('sale')->destroy();

        $customers = Customer::all();
        $product_categories = Category::all();

        return view('sale::pos.index', compact('product_categories', 'customers'));
    }


    public function store(StorePosSaleRequest $request) {
        abort_if(Gate::denies('pos.access'), 403);

        $cart = Cart::instance('sale');

        if ($cart->count() === 0) {
            return back()->withErrors(['cart' => 'Cart is empty'])->withInput();
        }

        $customer = Customer::findOrFail($request->customer_id);

        $validated = $request->validated();
        $paymentsInput = collect(data_get($validated, 'payments', []));
        $total_amount = round((float) data_get($validated, 'total_amount', 0), 2);
        $shippingAmount = round((float) data_get($validated, 'shipping_amount', 0), 2);

        $paymentMethodIds = $paymentsInput
            ->pluck('method_id')
            ->filter()
            ->unique()
            ->values();

        $availableMethods = PaymentMethod::query()
            ->where('is_available_in_pos', true)
            ->whereIn('id', $paymentMethodIds)
            ->get()
            ->keyBy('id');

        $processedPayments = [];
        $runningBalance = $total_amount;
        $overallPaid = 0.0;

        foreach ($paymentsInput as $index => $payment) {
            $methodId = (int) data_get($payment, 'method_id');
            $amount = round((float) data_get($payment, 'amount', 0), 2);

            if ($amount <= 0) {
                continue;
            }

            /** @var PaymentMethod|null $method */
            $method = $availableMethods->get($methodId);

            if (! $method) {
                return back()->withErrors([
                    "payments.$index.method_id" => 'Selected payment method is not available for POS.',
                ])->withInput();
            }

            if (! $method->is_cash && $amount > $runningBalance + 0.00001) {
                return back()->withErrors([
                    "payments.$index.amount" => 'Non-cash payments cannot exceed the remaining balance.',
                ])->withInput();
            }

            $runningBalance = round($runningBalance - $amount, 2);

            if ($runningBalance < -0.01 && ! $method->is_cash) {
                return back()->withErrors([
                    "payments.$index.amount" => 'Overpayment is only allowed for cash payments.',
                ])->withInput();
            }

            if ($runningBalance < 0) {
                $runningBalance = 0.0;
            }

            $processedPayments[] = [
                'method' => $method,
                'amount' => $amount,
            ];

            $overallPaid += $amount;
        }

        $overallPaid = round($overallPaid, 2);
        $due_amount = max(round($total_amount - $overallPaid, 2), 0);

        $uniqueMethodIds = collect($processedPayments)
            ->pluck('method.id')
            ->filter()
            ->unique();

        $hasCashPayment = collect($processedPayments)
            ->contains(function ($payment) {
                /** @var PaymentMethod|null $method */
                $method = $payment['method'] ?? null;

                return $method?->is_cash && ($payment['amount'] ?? 0) > 0;
            });

        $changeDue = $hasCashPayment
            ? max(round($overallPaid - $total_amount, 2), 0.0)
            : 0.0;

        $hasCashOverpayment = $hasCashPayment && $changeDue > 0;

        $primaryPayment = collect($processedPayments)
            ->firstWhere('amount', '>', 0) ?? collect($processedPayments)->first();

        $primaryMethodId = $primaryPayment['method']->id ?? null;
        $primaryMethodName = $primaryPayment['method']->name ?? '';

        if ($uniqueMethodIds->count() > 1) {
            $displayMethodName = 'Multiple';
        } else {
            $displayMethodName = $primaryMethodName;
        }

        if (round($due_amount, 2) >= $total_amount) {
            $payment_status = 'Unpaid';
        } elseif ($due_amount > 0) {
            $payment_status = 'Partial';
        } else {
            $payment_status = 'Paid';
        }

        $posLocationId = PosLocationResolver::resolveId();

        DB::beginTransaction();

        /** @var Sale|null $sale */
        $sale = null;

        try {
            $saleData = [
                'date' => now()->format('Y-m-d'),
                'reference' => 'PSL',
                'customer_id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'tax_percentage' => $request->tax_percentage,
                'discount_percentage' => $request->discount_percentage,
                'shipping_amount' => $shippingAmount,
                'paid_amount' => $overallPaid,
                'total_amount' => $total_amount,
                'due_amount' => $due_amount,
                'status' => 'Completed',
                'payment_status' => $payment_status,
                'payment_method' => $displayMethodName,
                'note' => $request->note,
                'tax_amount' => round((float) $cart->tax(), 2),
                'discount_amount' => round((float) $cart->discount(), 2),
            ];

            if ($this->salesHasPaymentMethodIdColumn()) {
                $saleData['payment_method_id'] = $primaryMethodId;
            }

            $sale = Sale::create($saleData);

            $this->persistSaleDetailsFromCart($sale, $cart->content(), true, $posLocationId);

            if ($overallPaid > 0) {
                foreach ($processedPayments as $payment) {
                    if ($payment['amount'] <= 0) {
                        continue;
                    }

                    /** @var PaymentMethod $method */
                    $method = $payment['method'];

                    SalePayment::create([
                        'date' => now()->format('Y-m-d'),
                        'reference' => 'INV/' . $sale->reference,
                        'amount' => $payment['amount'],
                        'sale_id' => $sale->id,
                        'payment_method_id' => $method->id,
                        'payment_method' => $method->name,
                    ]);
                }
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to create POS sale', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withErrors(['error' => 'Failed to create POS sale.'])->withInput();
        }

        if ($sale) {
            $this->triggerReceiptPrint($sale);
        }

        $cart->destroy();

        session()->flash('pos_change_due', $changeDue);
        session()->flash('pos_cash_overpayment', $hasCashOverpayment);

        toast('POS Sale Created!', 'success');

        return redirect()->route('app.pos.index');
    }

    public function storeAsQuotation(Request $request)
    {
        abort_if(Gate::denies('pos.access'), 403);
        $cart = Cart::instance('sale');

        if ($cart->count() == 0) {
            return back()->withErrors(['cart' => 'Cart is empty'])->withInput();
        }

        $posLocationId = PosLocationResolver::resolveId();

        DB::beginTransaction();
        try {
            $customer = Customer::findOrFail($request->customer_id);
            $sale = Sale::create([
                'date' => now(),
                'due_date' => now()->addDays(7), // or null if N/A
                'customer_id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'tax_percentage' => $request->tax_percentage ?? 0,
                'discount_percentage' => $request->discount_percentage ?? 0,
                'shipping_amount' => round((float) $request->input('shipping_amount', 0), 2),
                'total_amount' => round((float) $request->total_amount, 2),
                'paid_amount' => 0,
                'due_amount' => round((float) $request->total_amount, 2),
                'status' => Sale::STATUS_DRAFTED,
                'payment_status' => 'Unpaid',
                'payment_method' => '', // leave blank if unknown
                'note' => $request->note ?? '',
                'setting_id' => session('setting_id'),
                'is_tax_included' => false,
                'tax_amount' => round((float) $cart->tax(), 2),
                'discount_amount' => round((float) $cart->discount(), 2),
            ]);

            $this->persistSaleDetailsFromCart($sale, $cart->content(), false, $posLocationId);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create quotation from POS', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Failed to save quotation.'])->withInput();
        }

        $cart->destroy();

        toast('Dokumen penjualan disimpan sebagai draft!', 'success');

        return redirect()->route('app.pos.index');
    }

    private function persistSaleDetailsFromCart(Sale $sale, $cartItems, bool $adjustInventory, ?int $posLocationId = null): void
    {
        foreach ($cartItems as $cartItem) {
            $detailData = $this->mapCartItemToSaleDetailData($sale, $cartItem);
            $saleDetail = SaleDetails::create($detailData);

            $this->createBundleItemsFromCartItem($sale, $saleDetail, $cartItem);

            if ($adjustInventory) {
                $productId = $saleDetail->product_id;
                if (! $productId) {
                    continue;
                }

                $this->applyInventoryAdjustments($sale, $saleDetail, $cartItem, $posLocationId);
            }
        }
    }

    private function applyInventoryAdjustments(Sale $sale, SaleDetails $saleDetail, $cartItem, ?int $posLocationId): void
    {
        $options = $this->normalizeCartOptions($cartItem->options ?? []);
        $productId = (int) ($options['product_id'] ?? 0);

        if ($productId && $posLocationId) {
            $this->deductProductStock($productId, $posLocationId, (int) $saleDetail->quantity, $options, $sale);
        }

        if ($productId) {
            $product = Product::query()->lockForUpdate()->find($productId);

            if ($product) {
                $newQuantity = max(0, (int) $product->product_quantity - (int) $saleDetail->quantity);
                $product->update(['product_quantity' => $newQuantity]);
            } else {
                Log::warning('Product not found when adjusting POS stock summary', [
                    'product_id' => $productId,
                    'sale_id' => $sale->id,
                ]);
            }
        }

        $this->markSerialNumbersAsSold($saleDetail, $options);
    }

    private function deductProductStock(int $productId, int $locationId, int $quantity, array $options, Sale $sale): void
    {
        if ($quantity <= 0) {
            return;
        }

        $stock = ProductStock::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            Log::warning('POS sale could not locate stock row for deduction', [
                'product_id' => $productId,
                'location_id' => $locationId,
                'sale_id' => $sale->id,
            ]);
            return;
        }

        $availableNonTax = max(0, (int) $stock->quantity_non_tax);
        $availableTax = max(0, (int) $stock->quantity_tax);

        $allocatedNonTax = min($quantity, max(0, (int) ($options['allocated_non_tax'] ?? 0)));
        $allocatedTax = min($quantity - $allocatedNonTax, max(0, (int) ($options['allocated_tax'] ?? 0)));

        $remaining = $quantity - ($allocatedNonTax + $allocatedTax);

        if ($remaining > 0 && $allocatedNonTax < $availableNonTax) {
            $additional = min($remaining, max(0, $availableNonTax - $allocatedNonTax));
            $allocatedNonTax += $additional;
            $remaining -= $additional;
        }

        if ($remaining > 0 && $allocatedTax < $availableTax) {
            $additionalTax = min($remaining, max(0, $availableTax - $allocatedTax));
            $allocatedTax += $additionalTax;
            $remaining -= $additionalTax;
        }

        if ($remaining > 0) {
            Log::warning('POS sale deducted more stock than available', [
                'product_id' => $productId,
                'location_id' => $locationId,
                'sale_id' => $sale->id,
                'quantity_requested' => $quantity,
                'non_tax_allocated' => $allocatedNonTax,
                'tax_allocated' => $allocatedTax,
                'remaining' => $remaining,
            ]);
        }

        $stock->quantity_non_tax = max(0, $availableNonTax - $allocatedNonTax);
        $stock->quantity_tax = max(0, $availableTax - $allocatedTax);
        $stock->quantity = max(0, (int) $stock->quantity_non_tax + (int) $stock->quantity_tax);
        $stock->broken_quantity = max(0, (int) $stock->broken_quantity_non_tax + (int) $stock->broken_quantity_tax);
        $stock->save();
    }

    private function markSerialNumbersAsSold(SaleDetails $saleDetail, array $options): void
    {
        $serials = $this->resolveSerialNumbers($options);
        if (! $serials) {
            return;
        }

        $serialIds = collect($serials)
            ->map(fn ($serial) => (int) ($serial['id'] ?? 0))
            ->filter()
            ->values();

        if ($serialIds->isEmpty()) {
            return;
        }

        $records = ProductSerialNumber::query()
            ->whereIn('id', $serialIds)
            ->lockForUpdate()
            ->get();

        foreach ($records as $record) {
            $record->dispatch_detail_id = $saleDetail->id;
            $record->save();
        }

        if (! $saleDetail->tax_id) {
            $taxIds = $records->pluck('tax_id')->filter()->unique()->values();
            if ($taxIds->count() === 1) {
                $saleDetail->tax_id = $taxIds->first();
                $saleDetail->save();
            }
        }
    }

    private function mapCartItemToSaleDetailData(Sale $sale, $cartItem): array
    {
        $options = $this->normalizeCartOptions($cartItem->options ?? []);

        $unitPrice = $options['unit_price'] ?? $cartItem->price;
        $subTotal = $options['sub_total'] ?? ($cartItem->price * $cartItem->qty);
        $discountAmount = $options['product_discount'] ?? 0;
        $discountType = $options['product_discount_type'] ?? 'fixed';
        $taxAmount = $options['tax_amount'] ?? ($options['product_tax_amount'] ?? 0);
        $taxId = $options['resolved_tax_id'] ?? ($options['product_tax'] ?? ($options['tax_id'] ?? null));

        if ($taxId === '' || $taxId === 0 || $taxId === '0') {
            $taxId = null;
        }

        if ($taxId === null && ! empty($options['serial_tax_ids'])) {
            $uniqueTaxIds = array_values(array_unique(array_filter(array_map('intval', (array) $options['serial_tax_ids']))));
            if (count($uniqueTaxIds) === 1) {
                $taxId = $uniqueTaxIds[0];
            }
        }

        $data = [
            'sale_id' => $sale->id,
            'product_id' => $options['product_id'] ?? null,
            'tax_id' => $taxId !== null ? (int) $taxId : null,
            'product_name' => $cartItem->name,
            'product_code' => $options['code'] ?? '',
            'quantity' => (int) $cartItem->qty,
            'price' => round((float) $cartItem->price, 2),
            'unit_price' => round((float) $unitPrice, 2),
            'sub_total' => round((float) $subTotal, 2),
            'product_discount_amount' => round((float) $discountAmount, 2),
            'product_discount_type' => $discountType,
            'product_tax_amount' => round((float) $taxAmount, 2),
        ];

        if ($this->saleDetailsHasSerialNumbersColumn()) {
            $serials = $this->resolveSerialNumbers($options);
            $data['serial_numbers'] = $serials ?: null;
        }

        return $data;
    }

    private function createBundleItemsFromCartItem(Sale $sale, SaleDetails $saleDetail, $cartItem): void
    {
        $options = $this->normalizeCartOptions($cartItem->options ?? []);
        $bundleItems = $options['bundle_items'] ?? [];

        if ($bundleItems instanceof \Illuminate\Support\Collection) {
            $bundleItems = $bundleItems->toArray();
        } elseif (is_object($bundleItems)) {
            $bundleItems = (array) $bundleItems;
        }

        if (! is_array($bundleItems) || empty($bundleItems)) {
            return;
        }

        foreach ($bundleItems as $bundleItem) {
            if ($bundleItem instanceof \Illuminate\Support\Collection) {
                $bundleItem = $bundleItem->toArray();
            } elseif (is_object($bundleItem)) {
                $bundleItem = (array) $bundleItem;
            }

            if (empty($bundleItem)) {
                continue;
            }

            $bundleId = $bundleItem['bundle_id'] ?? ($options['bundle_id'] ?? null);
            $bundleItemId = $bundleItem['bundle_item_id'] ?? null;

            if ($bundleId === null || $bundleItemId === null) {
                Log::warning('Skipping POS sale bundle item due to missing identifiers', [
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $saleDetail->id,
                    'bundle_item' => $bundleItem,
                ]);
                continue;
            }

            $bundlePayload = [
                'sale_detail_id' => $saleDetail->id,
                'sale_id' => $sale->id,
                'bundle_id' => $bundleId,
                'bundle_item_id' => $bundleItemId,
                'product_id' => $bundleItem['product_id'] ?? null,
                'name' => $bundleItem['name'] ?? '',
                'price' => round((float) ($bundleItem['price'] ?? 0), 2),
                'quantity' => (int) ($bundleItem['quantity'] ?? 0),
                'sub_total' => round((float) ($bundleItem['sub_total'] ?? 0), 2),
            ];

            if ($this->saleBundleItemsHasTaxIdColumn()) {
                $bundlePayload['tax_id'] = $saleDetail->tax_id;
            }

            SaleBundleItem::create($bundlePayload);
        }
    }

    private function triggerReceiptPrint(Sale $sale): void
    {
        $userId = auth()->id();

        if (! $userId) {
            return;
        }

        try {
            $sale->loadMissing(['saleDetails.product']);
            $htmlContent = view('sale::print-pos', [
                'sale' => $sale,
            ])->render();
        } catch (Throwable $throwable) {
            Log::error('Failed to render POS sale receipt for printing', [
                'sale_id' => $sale->id,
                'error' => $throwable->getMessage(),
            ]);

            return;
        }

        event(new PrintJobEvent($htmlContent, 'pos-sale', (int) $userId));
    }

    private function normalizeCartOptions($options): array
    {
        if ($options instanceof \Illuminate\Support\Collection) {
            return $options->toArray();
        }

        if (is_object($options) && method_exists($options, 'toArray')) {
            return $options->toArray();
        }

        if (is_array($options)) {
            return $options;
        }

        return (array) $options;
    }

    private function resolveSerialNumbers(array $options): ?array
    {
        $serials = $options['serial_numbers'] ?? null;

        if ($serials instanceof \Illuminate\Support\Collection) {
            $serials = $serials->toArray();
        } elseif (is_object($serials) && method_exists($serials, 'toArray')) {
            $serials = $serials->toArray();
        } elseif (is_string($serials)) {
            $decoded = json_decode($serials, true);
            $serials = json_last_error() === JSON_ERROR_NONE ? $decoded : [$serials];
        } elseif ($serials !== null && ! is_array($serials)) {
            $serials = (array) $serials;
        }

        if (! is_array($serials) || empty($serials)) {
            return null;
        }

        $normalized = [];
        $missingTaxLookup = [];

        foreach ($serials as $serial) {
            if ($serial instanceof \Illuminate\Support\Collection) {
                $serial = $serial->toArray();
            } elseif (is_object($serial) && method_exists($serial, 'toArray')) {
                $serial = $serial->toArray();
            } elseif (is_object($serial)) {
                $serial = (array) $serial;
            }

            if ($serial === null || $serial === []) {
                continue;
            }

            $serial['id'] = isset($serial['id']) ? (int) $serial['id'] : null;
            if (! isset($serial['tax_id']) || $serial['tax_id'] === '' || $serial['tax_id'] === null) {
                if ($serial['id']) {
                    $missingTaxLookup[] = $serial['id'];
                }
                $serial['tax_id'] = null;
            } else {
                $serial['tax_id'] = (int) $serial['tax_id'];
            }

            $normalized[] = $serial;
        }

        if (! empty($missingTaxLookup)) {
            $taxMap = ProductSerialNumber::query()
                ->whereIn('id', $missingTaxLookup)
                ->pluck('tax_id', 'id');

            foreach ($normalized as &$entry) {
                $id = $entry['id'] ?? null;
                if ($id && ($entry['tax_id'] === null || $entry['tax_id'] === '')) {
                    $taxId = $taxMap[$id] ?? null;
                    $entry['tax_id'] = $taxId !== null ? (int) $taxId : null;
                }
            }
            unset($entry);
        }

        return $normalized ?: null;
    }

    private function salesHasPaymentMethodIdColumn(): bool
    {
        if (self::$salesHasPaymentMethodIdColumn === null) {
            self::$salesHasPaymentMethodIdColumn = Schema::hasColumn('sales', 'payment_method_id');
        }

        return self::$salesHasPaymentMethodIdColumn;
    }

    private function saleDetailsHasSerialNumbersColumn(): bool
    {
        if (self::$saleDetailsHasSerialNumbersColumn === null) {
            self::$saleDetailsHasSerialNumbersColumn = Schema::hasColumn('sale_details', 'serial_numbers');
        }

        return self::$saleDetailsHasSerialNumbersColumn;
    }

    private function saleBundleItemsHasTaxIdColumn(): bool
    {
        if (self::$saleBundleItemsHasTaxIdColumn === null) {
            self::$saleBundleItemsHasTaxIdColumn = Schema::hasColumn('sale_bundle_items', 'tax_id');
        }

        return self::$saleBundleItemsHasTaxIdColumn;
    }
}
