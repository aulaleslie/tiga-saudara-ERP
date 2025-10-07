<?php

namespace Modules\Sale\Http\Controllers;

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
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Http\Requests\StorePosSaleRequest;
use Modules\Setting\Entities\PaymentMethod;

class PosController extends Controller
{
    private static ?bool $salesHasPaymentMethodIdColumn = null;

    private static ?bool $saleDetailsHasSerialNumbersColumn = null;

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

        $paymentMethod = PaymentMethod::find($request->payment_method_id);

        if (! $paymentMethod) {
            return back()->withErrors(['payment_method_id' => 'Selected payment method is invalid.'])->withInput();
        }

        $customer = Customer::findOrFail($request->customer_id);

        $due_amount = round((float) $request->total_amount - (float) $request->paid_amount, 2);
        $due_amount = max($due_amount, 0);
        $total_amount = round((float) $request->total_amount, 2);

        if (round($due_amount, 2) >= $total_amount) {
            $payment_status = 'Unpaid';
        } elseif ($due_amount > 0) {
            $payment_status = 'Partial';
        } else {
            $payment_status = 'Paid';
        }

        DB::beginTransaction();

        try {
            $saleData = [
                'date' => now()->format('Y-m-d'),
                'reference' => 'PSL',
                'customer_id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'tax_percentage' => $request->tax_percentage,
                'discount_percentage' => $request->discount_percentage,
                'shipping_amount' => round((float) $request->shipping_amount, 2),
                'paid_amount' => round((float) $request->paid_amount, 2),
                'total_amount' => $total_amount,
                'due_amount' => $due_amount,
                'status' => 'Completed',
                'payment_status' => $payment_status,
                'payment_method' => $paymentMethod->name,
                'note' => $request->note,
                'tax_amount' => round((float) $cart->tax(), 2),
                'discount_amount' => round((float) $cart->discount(), 2),
            ];

            if ($this->salesHasPaymentMethodIdColumn()) {
                $saleData['payment_method_id'] = $paymentMethod->id;
            }

            $sale = Sale::create($saleData);

            $this->persistSaleDetailsFromCart($sale, $cart->content(), true);

            if ($sale->paid_amount > 0) {
                SalePayment::create([
                    'date' => now()->format('Y-m-d'),
                    'reference' => 'INV/' . $sale->reference,
                    'amount' => $sale->paid_amount,
                    'sale_id' => $sale->id,
                    'payment_method_id' => $paymentMethod->id,
                    'payment_method' => $paymentMethod->name,
                ]);
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

        $cart->destroy();

        toast('POS Sale Created!', 'success');

        return redirect()->route('sales.index');
    }

    public function storeAsQuotation(Request $request)
    {
        abort_if(Gate::denies('pos.access'), 403);
        $cart = Cart::instance('sale');

        if ($cart->count() == 0) {
            return back()->withErrors(['cart' => 'Cart is empty'])->withInput();
        }

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
                'shipping_amount' => round((float) ($request->shipping_amount ?? 0), 2),
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

            $this->persistSaleDetailsFromCart($sale, $cart->content(), false);

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

        return redirect()->route('sales.index')->with('message', 'Quotation created successfully!');
    }

    private function persistSaleDetailsFromCart(Sale $sale, $cartItems, bool $adjustInventory): void
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

                $product = Product::find($productId);

                if (! $product) {
                    Log::warning('Product not found when adjusting POS sale inventory', [
                        'product_id' => $productId,
                        'sale_id' => $sale->id,
                    ]);
                    continue;
                }

                $newQuantity = max(0, $product->product_quantity - (int) $cartItem->qty);
                $product->update(['product_quantity' => $newQuantity]);
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
        $taxId = $options['product_tax'] ?? null;

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

            SaleBundleItem::create([
                'sale_detail_id' => $saleDetail->id,
                'sale_id' => $sale->id,
                'bundle_id' => $bundleId,
                'bundle_item_id' => $bundleItemId,
                'product_id' => $bundleItem['product_id'] ?? null,
                'name' => $bundleItem['name'] ?? '',
                'price' => round((float) ($bundleItem['price'] ?? 0), 2),
                'quantity' => (int) ($bundleItem['quantity'] ?? 0),
                'sub_total' => round((float) ($bundleItem['sub_total'] ?? 0), 2),
            ]);
        }
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

            $normalized[] = $serial;
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
}
