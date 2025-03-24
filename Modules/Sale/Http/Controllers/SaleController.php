<?php

namespace Modules\Sale\Http\Controllers;

use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\DataTables\SalesDataTable;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Http\Requests\StoreSaleRequest;
use Modules\Sale\Http\Requests\UpdateSaleRequest;

class SaleController extends Controller
{

    public function index(SalesDataTable $dataTable) {
        abort_if(Gate::denies('sale.access'), 403);

        return $dataTable->render('sale::index');
    }


    public function create() {
        abort_if(Gate::denies('sale.create'), 403);

        Cart::instance('sale')->destroy();

        // Retrieve the current setting_id from the session
        $setting_id = session('setting_id');

        // Filter PaymentTerms by the setting_id
        $paymentTerms = PaymentTerm::where('setting_id', $setting_id)->get();
        $customers = Customer::where('setting_id', $setting_id)->get();

        return view('sale::create', compact('paymentTerms','customers'));
    }


    public function store(StoreSaleRequest $request): RedirectResponse
    {
        Log::info('REQUEST', [
            'request' => $request->all(),
        ]);

        // Ensure cart is not empty.
        if (Cart::instance('sale')->count() == 0) {
            return redirect()->back()
                ->withErrors(['cart' => 'Daftar Produk tidak boleh kosong.'])
                ->withInput();
        }

        // Validate stock for parent products and bundled items.
        $parentQuantities = [];
        $bundleQuantities = [];

        // Loop through each cart item.
        foreach (Cart::instance('sale')->content() as $cart_item) {
            // Parent product ID is stored in options->product_id.
            $parentId = $cart_item->options->product_id;
            if (!isset($parentQuantities[$parentId])) {
                $parentQuantities[$parentId] = 0;
            }
            $parentQuantities[$parentId] += $cart_item->qty;

            // If the cart item has bundle items, validate them.
            if (isset($cart_item->options->bundle_items) && is_array($cart_item->options->bundle_items)) {
                foreach ($cart_item->options->bundle_items as $bundleItem) {
                    // Bundle product ID.
                    $bundleProductId = $bundleItem['product_id'];
                    // Assume bundleItem['quantity'] is the base quantity defined in the bundle.
                    // Multiply by the parent's quantity.
                    $bundleQty = $bundleItem['quantity'] * $cart_item->qty;
                    if (!isset($bundleQuantities[$bundleProductId])) {
                        $bundleQuantities[$bundleProductId] = 0;
                    }
                    $bundleQuantities[$bundleProductId] += $bundleQty;
                }
            }
        }

        $errors = [];

        // Validate parent products stock.
        foreach ($parentQuantities as $productId => $requestedQty) {
            $product = Product::find($productId);
            if (!$product) {
                $errors[] = "Product ID {$productId} not found.";
            } else {
                if ($product->product_quantity < $requestedQty) {
                    $errors[] = "Insufficient stock for {$product->product_name}. Requested: {$requestedQty}, Available: {$product->product_quantity}.";
                }
            }
        }

        // Validate bundled products stock.
        foreach ($bundleQuantities as $productId => $requestedQty) {
            $product = Product::find($productId);
            if (!$product) {
                $errors[] = "Bundle Product ID {$productId} not found.";
            } else {
                if ($product->product_quantity < $requestedQty) {
                    $errors[] = "Insufficient stock for bundled product {$product->product_name}. Requested: {$requestedQty}, Available: {$product->product_quantity}.";
                }
            }
        }

        // If errors exist, redirect back with error messages.
        if (!empty($errors)) {
            return redirect()->back()->withErrors($errors)->withInput();
        }

        $setting_id = session('setting_id');
        DB::beginTransaction();
        try {
            // Create the sale record.
            $sale = Sale::create([
                'date'              => $request->date,
                'due_date'          => $request->due_date,
                'customer_id'       => $request->customer_id,
                'customer_name'     => Customer::findOrFail($request->customer_id)->customer_name,
                'tax_id'            => $request->tax_id,
                'tax_percentage'    => 0, // Set as needed.
                'tax_amount'        => 0, // Set as needed.
                'discount_percentage'=> $request->discount_percentage ?? 0,
                'discount_amount'   => $request->discount_amount ?? 0,
                'shipping_amount'   => $request->shipping_amount,
                'total_amount'      => $request->total_amount,
                'due_amount'        => $request->total_amount,
                'status'            => Sale::STATUS_DRAFTED, // Adjust as necessary (or use Sale::STATUS_DRAFTED).
                'payment_status'    => 'unpaid',
                'payment_term_id'   => $request->payment_term_id,
                'note'              => $request->note,
                'setting_id'        => $setting_id,
                'paid_amount'       => 0.0,
                'is_tax_included'   => $request->is_tax_included,
                'payment_method'    => '',
            ]);

            // Iterate over cart items and create sale details.
            foreach (Cart::instance('sale')->content() as $cart_item) {
                // Calculate product tax amount.
                $product_tax_amount = $cart_item->options['sub_total'] -
                    ($cart_item->options['sub_total_before_tax'] ?? 0);

                $saleDetail = SaleDetails::create([
                    'sale_id'                   => $sale->id,
                    'product_id'                => $cart_item->options->product_id,
                    'product_name'              => $cart_item->name,
                    'product_code'              => $cart_item->options['code'],
                    'quantity'                  => $cart_item->qty,
                    'unit_price'                => $cart_item->options['unit_price'],
                    'price'                     => $cart_item->price,
                    'product_discount_type'     => $cart_item->options['product_discount_type'],
                    'product_discount_amount'   => $cart_item->options['product_discount'],
                    'sub_total'                 => $cart_item->options['sub_total'],
                    'product_tax_amount'        => $product_tax_amount,
                    'tax_id'                    => $cart_item->options['product_tax'],
                ]);

                // If the cart item has bundle items, iterate and create SaleBundleItem records.
                if (isset($cart_item->options->bundle_items) && is_array($cart_item->options->bundle_items)) {
                    foreach ($cart_item->options->bundle_items as $bundleItem) {
                        // Create a bundle record for each bundle item.
                        // Note: You might need to adjust fields if you have computed values.
                        \Modules\Sale\Entities\SaleBundleItem::create([
                            'sale_detail_id' => $saleDetail->id,
                            'sale_id'        => $sale->id,
                            'bundle_id'      => $bundleItem['bundle_id'] ?? null,
                            'bundle_item_id' => $bundleItem['bundle_item_id'] ?? null,
                            'product_id'     => $bundleItem['product_id'],
                            'name'           => $bundleItem['name'],
                            'price'          => $bundleItem['price'],
                            'quantity'       => $bundleItem['quantity'], // base quantity; computed quantity = base * parent qty can be computed as needed.
                            'sub_total'      => $bundleItem['sub_total'],
                        ]);
                    }
                }
            }

            DB::commit();
            toast('Pembelian Ditambahkan!', 'success');
            return redirect()->route('sales.index');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Sale Creation Failed:', ['error' => $e->getMessage()]);
            toast('An error occurred while creating the sale. Please try again.', 'error');
            return redirect()->back()->withInput();
        }
    }


    public function show(Sale $sale) {
        abort_if(Gate::denies('show_sales'), 403);

        $customer = Customer::findOrFail($sale->customer_id);

        return view('sale::show', compact('sale', 'customer'));
    }


    public function edit(Sale $sale) {
        abort_if(Gate::denies('sale.edit'), 403);

        $sale_details = $sale->saleDetails;

        Cart::instance('sale')->destroy();

        $cart = Cart::instance('sale');

        foreach ($sale_details as $sale_detail) {
            $cart->add([
                'id'      => $sale_detail->product_id,
                'name'    => $sale_detail->product_name,
                'qty'     => $sale_detail->quantity,
                'price'   => $sale_detail->price,
                'weight'  => 1,
                'options' => [
                    'product_discount' => $sale_detail->product_discount_amount,
                    'product_discount_type' => $sale_detail->product_discount_type,
                    'sub_total'   => $sale_detail->sub_total,
                    'code'        => $sale_detail->product_code,
                    'stock'       => Product::findOrFail($sale_detail->product_id)->product_quantity,
                    'product_tax' => $sale_detail->product_tax_amount,
                    'unit_price'  => $sale_detail->unit_price
                ]
            ]);
        }

        return view('sale::edit', compact('sale'));
    }


    public function update(UpdateSaleRequest $request, Sale $sale) {
        DB::transaction(function () use ($request, $sale) {

            $due_amount = $request->total_amount - $request->paid_amount;

            if ($due_amount == $request->total_amount) {
                $payment_status = 'Unpaid';
            } elseif ($due_amount > 0) {
                $payment_status = 'Partial';
            } else {
                $payment_status = 'Paid';
            }

            foreach ($sale->saleDetails as $sale_detail) {
                if ($sale->status == 'Shipped' || $sale->status == 'Completed') {
                    $product = Product::findOrFail($sale_detail->product_id);
                    $product->update([
                        'product_quantity' => $product->product_quantity + $sale_detail->quantity
                    ]);
                }
                $sale_detail->delete();
            }

            $sale->update([
                'date' => $request->date,
                'reference' => $request->reference,
                'customer_id' => $request->customer_id,
                'customer_name' => Customer::findOrFail($request->customer_id)->customer_name,
                'tax_percentage' => $request->tax_percentage,
                'discount_percentage' => $request->discount_percentage,
                'shipping_amount' => $request->shipping_amount * 100,
                'paid_amount' => $request->paid_amount * 100,
                'total_amount' => $request->total_amount * 100,
                'due_amount' => $due_amount * 100,
                'status' => $request->status,
                'payment_status' => $payment_status,
                'payment_method' => $request->payment_method,
                'note' => $request->note,
                'tax_amount' => Cart::instance('sale')->tax() * 100,
                'discount_amount' => Cart::instance('sale')->discount() * 100,
            ]);

            foreach (Cart::instance('sale')->content() as $cart_item) {
                SaleDetails::create([
                    'sale_id' => $sale->id,
                    'product_id' => $cart_item->id,
                    'product_name' => $cart_item->name,
                    'product_code' => $cart_item->options->code,
                    'quantity' => $cart_item->qty,
                    'price' => $cart_item->price * 100,
                    'unit_price' => $cart_item->options->unit_price * 100,
                    'sub_total' => $cart_item->options->sub_total * 100,
                    'product_discount_amount' => $cart_item->options->product_discount * 100,
                    'product_discount_type' => $cart_item->options->product_discount_type,
                    'product_tax_amount' => $cart_item->options->product_tax * 100,
                ]);

                if ($request->status == 'Shipped' || $request->status == 'Completed') {
                    $product = Product::findOrFail($cart_item->id);
                    $product->update([
                        'product_quantity' => $product->product_quantity - $cart_item->qty
                    ]);
                }
            }

            Cart::instance('sale')->destroy();
        });

        toast('Penjualan Diperbaharui!', 'info');

        return redirect()->route('sales.index');
    }

    public function updateStatus(Request $request, Sale $sale): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:' . implode(',', [
                    Sale::STATUS_WAITING_APPROVAL,
                    Sale::STATUS_APPROVED,
                    Sale::STATUS_REJECTED
                ]),
        ]);

        try {
            $sale->update(['status' => $validated['status']]);
            toast("Sale status updated to {$validated['status']}!", 'success');
        } catch (Exception $e) {
            Log::error('Failed to update sale status', ['error' => $e->getMessage()]);
            toast('Failed to update sale status.', 'error');
        }

        // Redirect back to the referring page
        return redirect()->to(url()->previous());
    }


    public function destroy(Sale $sale) {
        abort_if(Gate::denies('sale.delete'), 403);

        $sale->delete();

        toast('Penjualan Dihapus!', 'warning');

        return redirect()->route('sales.index');
    }
}
