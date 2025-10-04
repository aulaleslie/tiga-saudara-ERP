<?php

namespace Modules\Sale\Http\Controllers;

use Exception;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Http\Requests\StorePosSaleRequest;

class PosController extends Controller
{

    public function index() {
        abort_if(Gate::denies('pos.access'), 403);
        Cart::instance('sale')->destroy();

        $customers = Customer::all();
        $product_categories = Category::all();

        return view('sale::pos.index', compact('product_categories', 'customers'));
    }


    public function store(StorePosSaleRequest $request) {
        abort_if(Gate::denies('pos.access'), 403);
        DB::transaction(function () use ($request) {
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

            $sale = Sale::create([
                'date' => now()->format('Y-m-d'),
                'reference' => 'PSL',
                'customer_id' => $request->customer_id,
                'customer_name' => Customer::findOrFail($request->customer_id)->customer_name,
                'tax_percentage' => $request->tax_percentage,
                'discount_percentage' => $request->discount_percentage,
                'shipping_amount' => round((float) $request->shipping_amount, 2),
                'paid_amount' => round((float) $request->paid_amount, 2),
                'total_amount' => $total_amount,
                'due_amount' => $due_amount,
                'status' => 'Completed',
                'payment_status' => $payment_status,
                'payment_method' => $request->payment_method,
                'note' => $request->note,
                'tax_amount' => round((float) Cart::instance('sale')->tax(), 2),
                'discount_amount' => round((float) Cart::instance('sale')->discount(), 2),
            ]);

            foreach (Cart::instance('sale')->content() as $cart_item) {
                SaleDetails::create([
                    'sale_id' => $sale->id,
                    'product_id' => $cart_item->id,
                    'product_name' => $cart_item->name,
                    'product_code' => $cart_item->options->code,
                    'quantity' => $cart_item->qty,
                    'price' => round((float) $cart_item->price, 2),
                    'unit_price' => round((float) $cart_item->options->unit_price, 2),
                    'sub_total' => round((float) $cart_item->options->sub_total, 2),
                    'product_discount_amount' => round((float) $cart_item->options->product_discount, 2),
                    'product_discount_type' => $cart_item->options->product_discount_type,
                    'product_tax_amount' => round((float) $cart_item->options->product_tax, 2),
                ]);

                $product = Product::findOrFail($cart_item->id);
                $product->update([
                    'product_quantity' => $product->product_quantity - $cart_item->qty
                ]);
            }

            Cart::instance('sale')->destroy();

            if ($sale->paid_amount > 0) {
                SalePayment::create([
                    'date' => now()->format('Y-m-d'),
                    'reference' => 'INV/'.$sale->reference,
                    'amount' => $sale->paid_amount,
                    'sale_id' => $sale->id,
                    'payment_method' => $request->payment_method
                ]);
            }
        });

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
                'shipping_amount' => $request->shipping_amount ?? 0,
                'total_amount' => $request->total_amount,
                'paid_amount' => 0,
                'due_amount' => $request->total_amount,
                'status' => Sale::STATUS_DRAFTED,
                'payment_status' => 'Unpaid',
                'payment_method' => '', // leave blank if unknown
                'note' => $request->note ?? '',
                'setting_id' => session('setting_id'),
                'is_tax_included' => false,
            ]);

            foreach ($cart->content() as $item) {
                $saleDetail = SaleDetails::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item->id,
                    'product_name' => $item->name,
                    'product_code' => $item->options['code'],
                    'quantity' => $item->qty,
                    'price' => $item->price,
                    'unit_price' => $item->options['unit_price'],
                    'sub_total' => $item->options['sub_total'],
                    'product_discount_amount' => $item->options['product_discount'],
                    'product_discount_type' => $item->options['product_discount_type'],
                    'product_tax_amount' => 0,
                    'tax_id' => $item->options['product_tax'],
                ]);

                if (!empty($item->options['bundle_items'])) {
                    foreach ($item->options['bundle_items'] as $bundleItem) {
                        SaleBundleItem::create([
                            'sale_detail_id' => $saleDetail->id,
                            'sale_id' => $sale->id,
                            'bundle_id' => $bundleItem['bundle_id'] ?? null,
                            'bundle_item_id' => $bundleItem['bundle_item_id'] ?? null,
                            'product_id' => $bundleItem['product_id'],
                            'name' => $bundleItem['name'],
                            'price' => $bundleItem['price'] ?? 0,
                            'quantity' => $bundleItem['quantity'],
                            'sub_total' => $bundleItem['sub_total'] ?? 0,
                        ]);
                    }
                }
            }

            DB::commit();
            $cart->destroy();

            return redirect()->route('sales.index')->with('message', 'Quotation created successfully!');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create quotation from POS', ['error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to save quotation.'])->withInput();
        }
    }
}
