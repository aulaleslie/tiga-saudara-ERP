<?php

namespace Modules\Sale\Http\Controllers;

use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\DataTables\SalesDataTable;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Http\Requests\StoreSaleRequest;
use Modules\Sale\Http\Requests\UpdateSaleRequest;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;

class SaleController extends Controller
{

    public function index(SalesDataTable $dataTable)
    {
        abort_if(Gate::denies('sale.access'), 403);

        return $dataTable->render('sale::index');
    }


    public function create(Request $request): Factory|\Illuminate\Foundation\Application|View|Application
    {
        abort_if(Gate::denies('sale.create'), 403);

        if (! $request->session()->hasOldInput()) {
            Cart::instance('sale')->destroy();
        }

        // Retrieve the current setting_id from the session
        $setting_id = session('setting_id');

        // Filter PaymentTerms by the setting_id
        $paymentTerms = PaymentTerm::where('setting_id', $setting_id)->get();
        $customers = Customer::where('setting_id', $setting_id)->get();

        return view('sale::create', compact('paymentTerms', 'customers'));
    }


    public function store(StoreSaleRequest $request): RedirectResponse
    {
        Log::info('REQUEST', [
            'request' => $request->all(),
            'cart' => Cart::instance('sale')->content()->toArray()
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
            if (is_array($cart_item->options->bundle_items)) {
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
            }
        }

        // Validate bundled products stock.
        foreach ($bundleQuantities as $productId => $requestedQty) {
            $product = Product::find($productId);
            if (!$product) {
                $errors[] = "Bundle Product ID {$productId} not found.";
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
                'date' => $request->date,
                'due_date' => $request->due_date,
                'customer_id' => $request->customer_id,
                'customer_name' => Customer::findOrFail($request->customer_id)->customer_name,
                'tax_id' => $request->tax_id,
                'tax_percentage' => 0, // Set as needed.
                'tax_amount' => 0, // Set as needed.
                'discount_percentage' => $request->discount_percentage ?? 0,
                'discount_amount' => $request->discount_amount ?? 0,
                'shipping_amount' => $request->shipping_amount,
                'total_amount' => $request->total_amount,
                'due_amount' => $request->total_amount,
                'status' => Sale::STATUS_DRAFTED, // Adjust as necessary (or use Sale::STATUS_DRAFTED).
                'payment_status' => 'unpaid',
                'payment_term_id' => $request->payment_term_id,
                'note' => $request->note,
                'setting_id' => $setting_id,
                'paid_amount' => 0.0,
                'is_tax_included' => $request->is_tax_included,
                'payment_method' => '',
            ]);

            // Iterate over cart items and create sale details.
            foreach (Cart::instance('sale')->content() as $cart_item) {
                // Calculate product tax amount.
                $product_tax_amount = $cart_item->options['sub_total'] -
                    ($cart_item->options['sub_total_before_tax'] ?? 0);

                $saleDetail = SaleDetails::create([
                    'sale_id' => $sale->id,
                    'product_id' => $cart_item->options->product_id,
                    'product_name' => $cart_item->name,
                    'product_code' => $cart_item->options['code'],
                    'quantity' => $cart_item->qty,
                    'unit_price' => $cart_item->options['unit_price'],
                    'price' => $cart_item->price,
                    'product_discount_type' => $cart_item->options['product_discount_type'],
                    'product_discount_amount' => $cart_item->options['product_discount'],
                    'sub_total' => $cart_item->options['sub_total'],
                    'product_tax_amount' => $product_tax_amount,
                    'tax_id' => $cart_item->options['product_tax'],
                ]);

                // If the cart item has bundle items, iterate and create SaleBundleItem records.
                if (is_array($cart_item->options->bundle_items)) {
                    foreach ($cart_item->options->bundle_items as $bundleItem) {
                        // Create a bundle record for each bundle item.
                        // Note: You might need to adjust fields if you have computed values.
                        SaleBundleItem::create([
                            'sale_detail_id' => $saleDetail->id,
                            'sale_id' => $sale->id,
                            'bundle_id' => $bundleItem['bundle_id'] ?? null,
                            'bundle_item_id' => $bundleItem['bundle_item_id'] ?? null,
                            'product_id' => $bundleItem['product_id'],
                            'name' => $bundleItem['name'],
                            'price' => $bundleItem['price'],
                            'quantity' => $bundleItem['quantity'], // base quantity; computed quantity = base * parent qty can be computed as needed.
                            'sub_total' => $bundleItem['sub_total'],
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


    public function show(Sale $sale)
    {
        abort_if(Gate::denies('show_sales'), 403);

        // Eager load saleDetails and their related bundleItems
        $sale->load('saleDetails.bundleItems');

        $customer = Customer::findOrFail($sale->customer_id);

        return view('sale::show', compact('sale', 'customer'));
    }


    public function edit(Sale $sale)
    {
        abort_if(Gate::denies('sale.edit'), 403);

        // Ensure the related bundle items are loaded for each sale detail.
        $sale->load('saleDetails.bundleItems');

        // Destroy any existing cart items
        Cart::instance('sale')->destroy();
        $cart = Cart::instance('sale');

        // Iterate over each sale detail to rebuild the cart item.
        foreach ($sale->saleDetails as $saleDetail) {
            // Build the options array from the sale detail.
            $subtotal_before_tax = $saleDetail->price * $saleDetail->quantity;
            if ($sale->is_tax_included) {
                // Case: Tax is included in the price
                if ($saleDetail->tax_id) {
                    $tax = Tax::find($saleDetail->tax_id);
                    if ($tax) {
                        // Calculate price excluding tax
                        $price_ex_tax = $saleDetail->price / (1 + $tax->value / 100);
//                        $tax_amount_per_unit = $purchase_detail->price - $price_ex_tax;
//                        $tax_amount = $tax_amount_per_unit * $purchase_detail->quantity;
                        $subtotal_before_tax = $price_ex_tax * $saleDetail->quantity;
                    } else {
                        $subtotal_before_tax = $saleDetail->price * $saleDetail->quantity;
                    }
                } else {
                    // No tax applied
                    $subtotal_before_tax = $saleDetail->price * $saleDetail->quantity;
                }
            }
            $options = [
                'product_discount' => $saleDetail->product_discount_amount,
                'product_discount_type' => $saleDetail->product_discount_type,
                'sub_total' => $saleDetail->sub_total,
                'code' => $saleDetail->product_code,
                'stock' => Product::findOrFail($saleDetail->product_id)->product_quantity,
                'unit_price' => $saleDetail->unit_price,
                'product_tax' => $saleDetail->tax_id,
                'sub_total_before_tax' => $subtotal_before_tax
            ];

            // Remap the bundle items if they exist.
            if ($saleDetail->bundleItems && $saleDetail->bundleItems->isNotEmpty()) {
                $bundleItems = [];
                foreach ($saleDetail->bundleItems as $bundleItem) {
                    // Format each bundle item similar to how it's built in ProductCart.
                    $bundleItems[] = [
                        'bundle_id' => $bundleItem->bundle_id,
                        'bundle_item_id' => $bundleItem->bundle_item_id,
                        'product_id' => $bundleItem->product_id,
                        'name' => $bundleItem->name,
                        'price' => $bundleItem->price,
                        'quantity' => $bundleItem->quantity, // this is the base quantity
                        'sub_total' => $bundleItem->sub_total,
                    ];
                }
                $options['bundle_items'] = $bundleItems;
            } else {
                $options['bundle_items'] = [];
            }

            // Re-create the cart item with the rebuilt options.
            $cart->add([
                'id' => Str::uuid()->toString(),
                'name' => $saleDetail->product_name,
                'qty' => $saleDetail->quantity,
                'price' => $saleDetail->price,
                'weight' => 1,
                'options' => $options,
            ]);
        }

        return view('sale::edit', compact('sale'));
    }


    public function update(UpdateSaleRequest $request, Sale $sale)
    {
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


    public function destroy(Sale $sale)
    {
        abort_if(Gate::denies('sale.delete'), 403);

        $sale->delete();

        toast('Penjualan Dihapus!', 'warning');

        return redirect()->route('sales.index');
    }

    public function dispatch(Sale $sale)
    {
        $currentSettingId = session('setting_id');
        $locations = Location::where('setting_id', $currentSettingId)->get();

        $aggregatedProducts = [];

        // Aggregate products from sale_details
        foreach ($sale->saleDetails as $detail) {
            $pid = $detail->product_id;
            $taxId = $detail->tax_id; // assumed to exist on sale detail
            $key = $pid . '-' . $taxId; // composite key for grouping

            if (!isset($aggregatedProducts[$key])) {
                // Retrieve product to get the product_code
                $product = Product::find($pid);
                // Retrieve tax to get tax_name (if tax_id exists)
                $tax = $taxId ? Tax::find($taxId) : null;

                $aggregatedProducts[$key] = [
                    'product_id' => $pid,
                    'tax_id' => $taxId,
                    'product_name' => $detail->product_name,
                    'product_code' => $product ? $product->product_code : null,
                    'tax_name' => $tax ? $tax->name : null,
                    'is_tax_included' => $sale->is_tax_included,
                    'total_quantity' => 0,
                    'dispatched_quantity' => 0,
                ];
            }
            $aggregatedProducts[$key]['total_quantity'] += $detail->quantity;
        }

        // Aggregate from bundle items (assumes SaleBundleItem model exists)
        $bundleItems = SaleBundleItem::where('sale_id', $sale->id)->get();
        foreach ($bundleItems as $bundleItem) {
            $pid = $bundleItem->product_id;
            // Assume bundle item has a tax_id field or follow its sale detail's tax.
            $taxId = $bundleItem->tax_id;
            $key = $pid . '-' . $taxId;

            if (!isset($aggregatedProducts[$key])) {
                $product = Product::find($pid);
                $tax = $taxId ? Tax::find($taxId) : null;

                $aggregatedProducts[$key] = [
                    'product_id' => $pid,
                    'tax_id' => $taxId,
                    'product_name' => $bundleItem->name,
                    'product_code' => $product ? $product->product_code : null,
                    'tax_name' => $tax ? $tax->name : null,
                    'is_tax_included' => $sale->is_tax_included,
                    'total_quantity' => 0,
                    'dispatched_quantity' => 0,
                ];
            }
            // Adjust quantity multiplication if needed.
            $aggregatedProducts[$key]['total_quantity'] += $bundleItem->quantity;
        }

        // Get already dispatched quantities for this sale (if any)
        $dispatchedDetails = DispatchDetail::whereHas('dispatch', function ($query) use ($sale) {
            $query->where('sale_id', $sale->id);
        })->get();

        foreach ($dispatchedDetails as $d) {
            $key = $d->product_id . '-' . $d->tax_id;
            if (isset($aggregatedProducts[$key])) {
                $aggregatedProducts[$key]['dispatched_quantity'] += $d->dispatched_quantity;
            }
        }

        return view('sale::dispatch', compact('sale', 'locations', 'aggregatedProducts'));
    }

    public function storeDispatch(Request $request, Sale $sale): RedirectResponse
    {
        Log::info('Store dispatch request', [
            'request' => $request->all()
        ]);

        $validator = Validator::make($request->all(), [
            'dispatch_date' => 'required|date',
            'dispatchedQuantities' => 'required|array',
            'selectedLocations' => 'required|array',
            'selectedSerialNumbers' => 'nullable|array',
            'stockAtLocations' => 'required|array',
        ]);

        $validator->after(function ($validator) use ($request, $sale) {
            $dispatchedQuantities = $request->input('dispatchedQuantities', []);
            $selectedLocations = $request->input('selectedLocations', []);
            $selectedSerialNumbers = $request->input('selectedSerialNumbers', []);
            $stockAtLocations = $request->input('stockAtLocations', []);

            $aggregated = [];
            foreach ($sale->saleDetails as $detail) {
                $pid = $detail->product_id;
                $taxId = $detail->tax_id;
                $key = $pid . '-' . $taxId;
                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'total_quantity' => 0,
                        'dispatched_quantity' => 0,
                    ];
                }
                $aggregated[$key]['total_quantity'] += $detail->quantity;
            }

            $dispatchedDetails = DispatchDetail::whereHas('dispatch', function ($query) use ($sale) {
                $query->where('sale_id', $sale->id);
            })->get();
            foreach ($dispatchedDetails as $d) {
                $key = $d->product_id . '-' . $d->tax_id;
                if (isset($aggregated[$key])) {
                    $aggregated[$key]['dispatched_quantity'] += $d->dispatched_quantity;
                }
            }

            foreach ($dispatchedQuantities as $compositeKey => $qty) {
                if (isset($aggregated[$compositeKey])) {
                    $remaining = $aggregated[$compositeKey]['total_quantity'] - $aggregated[$compositeKey]['dispatched_quantity'];
                    if ($remaining > 0 && (int)$qty === 0) {
                        $validator->errors()->add("dispatchedQuantities.$compositeKey", "Dispatched quantity cannot be 0 as there is remaining quantity of {$remaining}.");
                    }
                }
                if (isset($stockAtLocations[$compositeKey]) && (int)$qty > (int)$stockAtLocations[$compositeKey]) {
                    $validator->errors()->add("dispatchedQuantities.$compositeKey", "Dispatched quantity ({$qty}) cannot exceed available stock ({$stockAtLocations[$compositeKey]}).");
                }
                if (empty($selectedLocations[$compositeKey])) {
                    $validator->errors()->add("selectedLocations.$compositeKey", "Location is required for this product.");
                }

                list($productId, $taxId) = explode('-', $compositeKey);
                $product = Product::find($productId);
                if ($product && $product->serial_number_required) {
                    $serials = $selectedSerialNumbers[$compositeKey] ?? [];
                    if (count($serials) != (int)$qty) {
                        $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Number of serial numbers must equal the dispatched quantity ({$qty}).");
                    }
                    if (count($serials) !== count(array_unique($serials))) {
                        $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Duplicate serial numbers are not allowed.");
                    }
                }
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        DB::beginTransaction();
        try {
            $dispatch = Dispatch::create([
                'sale_id' => $sale->id,
                'dispatch_date' => $request->input('dispatch_date'),
            ]);

            $dispatchedQuantities = $request->input('dispatchedQuantities', []);
            $selectedLocations = $request->input('selectedLocations', []);
            $selectedSerialNumbers = $request->input('selectedSerialNumbers', []);

            foreach ($dispatchedQuantities as $compositeKey => $qty) {
                list($productId, $taxId) = explode('-', $compositeKey);
                $locationId = $selectedLocations[$compositeKey] ?? null;
                $serialNumbers = $selectedSerialNumbers[$compositeKey] ?? [];

                $product = Product::where('id', $productId)->lockForUpdate()->first();
                if (!$product) {
                    throw new Exception("Product ID {$productId} not found.");
                }

                $productStock = ProductStock::where('product_id', $productId)
                    ->where('location_id', $locationId)
                    ->lockForUpdate()
                    ->first();

                if (!$productStock) {
                    $productStock = ProductStock::create([
                        'product_id' => $productId,
                        'location_id' => $locationId,
                        'quantity' => 0,
                        'quantity_tax' => 0,
                        'quantity_non_tax' => 0,
                        'broken_quantity_non_tax' => 0,
                        'broken_quantity_tax' => 0,
                        'broken_quantity' => 0,
                    ]);
                }

                if ($productStock->quantity < $qty) {
                    throw new Exception("Not enough stock for product ID {$productId} at location ID {$locationId}.");
                }

                $previousQuantity = $product->product_quantity;
                $previousQuantityAtLocation = $productStock->quantity;

                $productStock->decrement('quantity', $qty);
                if ($taxId) {
                    $productStock->decrement('quantity_tax', $qty);
                } else {
                    $productStock->decrement('quantity_non_tax', $qty);
                }

                if ($product->product_quantity < $qty) {
                    throw new Exception("Not enough overall stock for product ID {$productId}.");
                }

                $product->decrement('product_quantity', $qty);

                $afterQuantity = $product->product_quantity;
                $afterQuantityAtLocation = $productStock->quantity;

                Transaction::create([
                    'product_id' => $productId,
                    'setting_id' => session('setting_id'),
                    'quantity' => -$qty,
                    'current_quantity' => $afterQuantity,
                    'broken_quantity' => 0,
                    'location_id' => $locationId,
                    'user_id' => auth()->id(),
                    'reason' => 'Dispatched for Sale Order #' . $sale->reference,
                    'type' => 'DISPATCH',
                    'previous_quantity' => $previousQuantity,
                    'after_quantity' => $afterQuantity,
                    'previous_quantity_at_location' => $previousQuantityAtLocation,
                    'after_quantity_at_location' => $afterQuantityAtLocation,
                    'quantity_non_tax' => $taxId ? 0 : $qty,
                    'quantity_tax' => $taxId ? $qty : 0,
                    'broken_quantity_non_tax' => 0,
                    'broken_quantity_tax' => 0,
                ]);

                $dispatchDetail = DispatchDetail::create([
                    'dispatch_id' => $dispatch->id,
                    'sale_id' => $sale->id,
                    'tax_id' => !empty($taxId) ? $taxId : null,
                    'product_id' => $productId,
                    'dispatched_quantity' => $qty,
                    'location_id' => $locationId,
                    'serial_numbers' => json_encode($serialNumbers),
                ]);

                if ($product->serial_number_required) {
                    foreach ($serialNumbers as $serial) {
                        ProductSerialNumber::where('product_id', $productId)
                            ->where('serial_number', $serial)
                            ->update(['dispatch_detail_id' => $dispatchDetail->id]);
                    }
                }
            }

            $totalSaleQty = $sale->saleDetails()->sum('quantity');
            $totalBundleQty = SaleBundleItem::where('sale_id', $sale->id)->sum('quantity');
            $totalOrderQty = $totalSaleQty + $totalBundleQty;
            $allDispatchedQty = DispatchDetail::where('sale_id', $sale->id)->sum('dispatched_quantity');

            if ($allDispatchedQty < $totalOrderQty) {
                $sale->status = Sale::STATUS_DISPATCHED_PARTIALLY;
            } else {
                $sale->status = Sale::STATUS_DISPATCHED;
            }
            $sale->save();

            DB::commit();
            toast('Pengeluaran berhasil dibuat!', 'success');
            return redirect()->route('sales.show', $sale->id);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error storing dispatch', ['error' => $e->getMessage()]);
            toast('Gagal menyimpan pengeluaran', 'error');
            return redirect()->back()->withInput();
        }
    }
}
