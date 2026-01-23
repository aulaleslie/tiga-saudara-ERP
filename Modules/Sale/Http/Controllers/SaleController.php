<?php

namespace Modules\Sale\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\DataTables\SalePaymentsDataTable;
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
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Sale\Services\SaleCartAggregator;

class SaleController extends Controller
{

    public function index(SalesDataTable $dataTable)
    {
        abort_if(Gate::denies('sales.access'), 403);

        return $dataTable->render('sale::index');
    }


    public function create(Request $request): Factory|\Illuminate\Foundation\Application|View|Application
    {
        abort_if(Gate::denies('sales.create'), 403);

        if (! $request->session()->hasOldInput()) {
            Cart::instance('sale')->destroy();
        }

        $paymentTerms = PaymentTerm::all();
        $customers = Customer::all();

        $idempotencyToken = (string) Str::uuid();

        return view('sale::create', compact('paymentTerms', 'customers', 'idempotencyToken'));
    }


    public function store(StoreSaleRequest $request): RedirectResponse
    {
        abort_if(Gate::denies('sales.create'), 403);
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

        $cartItems = Cart::instance('sale')->content();

        // Loop through each cart item.
        foreach ($cartItems as $cart_item) {
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
                'payment_status' => 'Unpaid',
                'payment_term_id' => $request->payment_term_id,
                'note' => $request->note,
                'setting_id' => $setting_id,
                'paid_amount' => 0.0,
                'is_tax_included' => $request->is_tax_included,
                'payment_method' => '',
            ]);

            $aggregatedItems = SaleCartAggregator::aggregate($cartItems);

            // Iterate over aggregated cart items and create sale details.
            foreach ($aggregatedItems as $cart_item) {
                $saleDetail = SaleDetails::create([
                    'sale_id' => $sale->id,
                    'product_id' => $cart_item['product_id'],
                    'product_name' => $cart_item['product_name'],
                    'product_code' => $cart_item['product_code'],
                    'quantity' => $cart_item['quantity'],
                    'unit_price' => round((float) $cart_item['unit_price'], 2),
                    'price' => round((float) $cart_item['price'], 2),
                    'product_discount_type' => $cart_item['product_discount_type'],
                    'product_discount_amount' => round((float) $cart_item['product_discount_amount'], 2),
                    'sub_total' => round((float) $cart_item['sub_total'], 2),
                    'product_tax_amount' => round((float) $cart_item['product_tax_amount'], 2),
                    'tax_id' => $cart_item['tax_id'],
                ]);

                // If the cart item has bundle items, iterate and create SaleBundleItem records.
                if (! empty($cart_item['bundle_items'])) {
                    foreach ($cart_item['bundle_items'] as $bundleItem) {
                        // Create a bundle record for each bundle item.
                        // Note: You might need to adjust fields if you have computed values.
                        SaleBundleItem::create([
                            'sale_detail_id' => $saleDetail->id,
                            'sale_id' => $sale->id,
                            'bundle_id' => $bundleItem['bundle_id'] ?? null,
                            'bundle_item_id' => $bundleItem['bundle_item_id'] ?? null,
                            'product_id' => $bundleItem['product_id'],
                            'name' => $bundleItem['name'],
                            'price' => round((float) ($bundleItem['price'] ?? 0), 2),
                            'quantity' => $bundleItem['quantity'], // base quantity; computed quantity = base * parent qty can be computed as needed.
                            'sub_total' => round((float) ($bundleItem['sub_total'] ?? 0), 2),
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


    public function show(Sale $sale, SalePaymentsDataTable $dataTable)
    {
        Log::info('SaleController::show reached', ['id' => $sale->id]);
        abort_if(Gate::denies('sales.show'), 403);

        $this->ensureSaleBelongsToCurrentSetting($sale);

        $sale->load([
            'saleDetails.bundleItems',
            'saleDetails.tax',
            'saleDispatches.details',
            'saleDispatches.details.product',
            'saleDispatches.details.location',
            'salePayments.paymentMethod',
        ]);

        $customer = Customer::findOrFail($sale->customer_id);

        // optional: if you want a clean var for the view
        $dispatches = $sale->saleDispatches;

        return $dataTable
            ->with(['sale_id' => $sale->id])
            ->render('sale::show', compact('sale', 'customer', 'dispatches'));
    }


    public function edit(Sale $sale)
    {
        abort_if(Gate::denies('sales.edit'), 403);

        $this->ensureSaleBelongsToCurrentSetting($sale);

        // Rule: Partially or Fully Dispatched -> Hard Block
        if (in_array($sale->status, [Sale::STATUS_DISPATCHED, Sale::STATUS_DISPATCHED_PARTIALLY])) {
            abort(403, 'Tidak dapat mengubah penjualan yang sudah dikirim barangnya.');
        }

        // Rule: Approved -> Require explicit permission
        if ($sale->status === Sale::STATUS_APPROVED) {
            if (!auth()->user()->can('sales.approved.edit')) {
                abort(403, 'Anda tidak memiliki akses untuk mengubah penjualan yang sudah disetujui.');
            }
        }

        // Ensure the related bundle items are loaded for each sale detail.
        $sale->load('saleDetails.bundleItems');

        // Destroy any existing cart items
        Cart::instance('sale')->destroy();
        $cart = Cart::instance('sale');

        // Iterate over each sale detail to rebuild the cart item.
        foreach ($sale->saleDetails as $saleDetail) {
            $product = $saleDetail->product ?? Product::findOrFail($saleDetail->product_id);
            $resolvedPricing = $this->resolveSaleDetailPricing($saleDetail, $product);

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
                'stock' => $product?->product_quantity ?? 0,
                'unit_price' => $saleDetail->unit_price,
                'product_tax' => $saleDetail->tax_id,
                'sub_total_before_tax' => $subtotal_before_tax,
                'product_id' => $resolvedPricing['product_id'],
                'sale_price' => $resolvedPricing['sale_price'],
                'tier_1_price' => $resolvedPricing['tier_1_price'],
                'tier_2_price' => $resolvedPricing['tier_2_price'],
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
        abort_if(Gate::denies('sales.edit'), 403);

        $this->ensureSaleBelongsToCurrentSetting($sale);

        // Rule: Partially or Fully Dispatched -> Hard Block
        if (in_array($sale->status, [Sale::STATUS_DISPATCHED, Sale::STATUS_DISPATCHED_PARTIALLY])) {
            abort(403, 'Tidak dapat memperbarui penjualan yang sudah dikirim barangnya.');
        }

        // Rule: Approved -> Require explicit permission
        if ($sale->status === Sale::STATUS_APPROVED) {
            if (!auth()->user()->can('sales.approved.edit')) {
                abort(403, 'Anda tidak memiliki akses untuk memperbarui penjualan yang sudah disetujui.');
            }
        }
        DB::transaction(function () use ($request, $sale) {

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
                'shipping_amount' => round((float) $request->shipping_amount, 2),
                'paid_amount' => round((float) $request->paid_amount, 2),
                'total_amount' => $total_amount,
                'due_amount' => $due_amount,
                'status' => $request->status,
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
        abort_unless(Gate::any(['sales.edit', 'sales.approval']), 403);

        $this->ensureSaleBelongsToCurrentSetting($sale);

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


    public function archive(Sale $sale): RedirectResponse
    {
        abort_if(Gate::denies('sales.archive'), 403);

        $this->ensureSaleBelongsToCurrentSetting($sale);

        // Block if processed
        if (in_array($sale->status, [Sale::STATUS_DISPATCHED, Sale::STATUS_DISPATCHED_PARTIALLY])) {
            abort(403, 'Tidak dapat mengarsipkan penjualan yang sudah dikirim barangnya.');
        }

        $sale->update([
            'archived_at' => now(),
            'archived_by' => auth()->id(),
        ]);

        toast('Penjualan Diarsipkan!', 'info');

        return redirect()->route('sales.index');
    }

    public function destroy(Sale $sale)
    {
        abort_if(Gate::denies('sales.delete'), 403);

        $this->ensureSaleBelongsToCurrentSetting($sale);

        // Rule: Partially or Fully Dispatched -> Hard Block
        if (in_array($sale->status, [Sale::STATUS_DISPATCHED, Sale::STATUS_DISPATCHED_PARTIALLY])) {
            abort(403, 'Tidak dapat menghapus penjualan yang sudah dikirim barangnya.');
        }

        // Rule: Approved -> Require explicit archive permission
        if ($sale->status === Sale::STATUS_APPROVED) {
            if (!auth()->user()->can('sales.archive')) {
                abort(403, 'Anda tidak memiliki akses untuk mengarsipkan penjualan yang sudah disetujui.');
            }
        }

        $sale->delete();

        toast('Penjualan Dihapus!', 'warning');

        return redirect()->route('sales.index');
    }

    public function dispatch(Sale $sale)
    {
        abort_if(Gate::denies('sales.dispatch'), 403);

        $this->ensureSaleBelongsToCurrentSetting($sale);

        $currentSettingId = (int) session('setting_id');
        $locations = Location::where('setting_id', $currentSettingId)
            ->orderBy('name')
            ->get();

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
                    'serial_number_required' => $product ? $product->serial_number_required : false,
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
                    'serial_number_required' => $product ? $product->serial_number_required : false,
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
        abort_if(Gate::denies('sales.dispatch'), 403);

        $this->ensureSaleBelongsToCurrentSetting($sale);

        Log::info('Store dispatch request', [
            'request' => $request->all()
        ]);

        $currentSettingId = (int) session('setting_id');
        $allowedLocationIds = Location::where('setting_id', $currentSettingId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $validator = Validator::make($request->all(), [
            'dispatch_date' => 'required|date',
            'dispatchedQuantities' => 'required|array',
            'selectedLocations' => 'nullable|array',
            'selectedSerialNumbers' => 'nullable|array',
            'serialNumberLocations' => 'nullable|array',
            'stockAtLocations' => 'nullable|array',
        ]);

        $validator->after(function ($validator) use ($request, $sale, $allowedLocationIds) {
            $dispatchedQuantities = $request->input('dispatchedQuantities', []);
            $selectedLocations = $request->input('selectedLocations', []);
            $selectedSerialNumbers = $request->input('selectedSerialNumbers', []);
            $serialNumberLocations = $request->input('serialNumberLocations', []);

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
                $aggregated[$key]['total_quantity'] += (int) $detail->quantity;
            }

            $currentDispatches = DispatchDetail::whereHas('dispatch', function ($query) use ($sale) {
                $query->where('sale_id', $sale->id);
            })->get();

            foreach ($currentDispatches as $d) {
                $key = $d->product_id . '-' . $d->tax_id;
                if (isset($aggregated[$key])) {
                    $aggregated[$key]['dispatched_quantity'] += (int) $d->dispatched_quantity;
                }
            }

            foreach ($dispatchedQuantities as $compositeKey => $qty) {
                if ((int)$qty <= 0) continue;

                list($productId, $taxId) = explode('-', $compositeKey);
                $product = Product::find($productId);
                
                if (!$product) {
                    $validator->errors()->add("dispatchedQuantities.$compositeKey", "Produk tidak ditemukan.");
                    continue;
                }

                // Check remaining quantity
                if (isset($aggregated[$compositeKey])) {
                    $remaining = $aggregated[$compositeKey]['total_quantity'] - $aggregated[$compositeKey]['dispatched_quantity'];
                    if ((int)$qty > $remaining) {
                        $validator->errors()->add("dispatchedQuantities.$compositeKey", "Jumlah kirim ({$qty}) melebihi sisa pesanan ({$remaining}).");
                    }
                }

                if ($product->serial_number_required) {
                    // SERIAL NUMBER PRODUCT VALIDATION
                    $serials = $selectedSerialNumbers[$compositeKey] ?? [];
                    $locations = $serialNumberLocations[$compositeKey] ?? [];
                    
                    if (count($serials) != (int)$qty) {
                        $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Jumlah serial number harus sama dengan jumlah yang dikirim ({$qty}).");
                    }

                    if (count($serials) !== count(array_unique($serials))) {
                        $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Terdapat duplikat serial number.");
                    }

                    foreach ($serials as $serialNumber) {
                        if (!isset($locations[$serialNumber])) {
                            $validator->errors()->add("serialNumberLocations.$compositeKey", "Lokasi tidak ditemukan untuk serial: {$serialNumber}");
                            continue;
                        }

                        $locationId = (int) $locations[$serialNumber];
                        if (!in_array($locationId, $allowedLocationIds, true)) {
                            $validator->errors()->add("serialNumberLocations.$compositeKey", "Lokasi serial {$serialNumber} tidak valid untuk bisnis ini.");
                        }

                        // Verify serial status and tax
                        $snRecord = ProductSerialNumber::where('product_id', $productId)
                            ->where('serial_number', $serialNumber)
                            ->first();
                        
                        if (!$snRecord) {
                            $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Serial number {$serialNumber} tidak ditemukan di sistem.");
                        } else {
                            if ($snRecord->dispatch_detail_id) {
                                $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Serial number {$serialNumber} sudah terpakai.");
                            }
                            if ($snRecord->status !== 'active') {
                                $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Serial number {$serialNumber} tidak aktif.");
                            }
                            
                            // Tax validation
                            $expectedTaxId = !empty($taxId) ? (int)$taxId : null;
                            $actualTaxId = $snRecord->tax_id ? (int)$snRecord->tax_id : null;
                            if ($expectedTaxId !== $actualTaxId) {
                                $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Status pajak serial {$serialNumber} tidak sesuai.");
                            }
                        }
                    }
                } else {
                    // NON-SERIAL PRODUCT VALIDATION
                    if (empty($selectedLocations[$compositeKey])) {
                        $validator->errors()->add("selectedLocations.$compositeKey", "Lokasi harus dipilih.");
                        continue;
                    }

                    $locationId = (int) $selectedLocations[$compositeKey];
                    if (!in_array($locationId, $allowedLocationIds, true)) {
                        $validator->errors()->add("selectedLocations.$compositeKey", "Lokasi tidak valid untuk bisnis ini.");
                    }

                    $stock = $this->getStockAtLocation($productId, $taxId, $locationId);
                    if ((int)$qty > $stock) {
                        $validator->errors()->add("dispatchedQuantities.$compositeKey", "Stok tidak mencukupi di lokasi terpilih (Tersedia: {$stock}).");
                    }
                }
            }
        });

        if ($validator->fails()) {
            Log::debug('Dispatch validation failed', [
                'errors' => $validator->errors()->toArray(),
                'request' => $request->all()
            ]);
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
            $serialNumberLocations = $request->input('serialNumberLocations', []);

            foreach ($dispatchedQuantities as $compositeKey => $qty) {
                if ((int)$qty <= 0) continue;

                list($productId, $taxId) = explode('-', $compositeKey);
                $product = Product::where('id', $productId)->lockForUpdate()->first();
                
                if ($product->serial_number_required) {
                    // Group serials by location to create separate dispatch details
                    $serials = $selectedSerialNumbers[$compositeKey] ?? [];
                    $locations = $serialNumberLocations[$compositeKey] ?? [];
                    $serialsByLocation = [];

                    foreach ($serials as $sn) {
                        $locId = (int) $locations[$sn];
                        if (!isset($serialsByLocation[$locId])) {
                            $serialsByLocation[$locId] = [];
                        }
                        $serialsByLocation[$locId][] = $sn;
                    }

                    foreach ($serialsByLocation as $locId => $snsAtLocation) {
                        $qtyAtLoc = count($snsAtLocation);
                        $this->createDispatchDetailAndAdjustStock($dispatch, $sale, $product, $taxId, $locId, $qtyAtLoc, $snsAtLocation);
                    }
                } else {
                    $locId = (int) $selectedLocations[$compositeKey];
                    $this->createDispatchDetailAndAdjustStock($dispatch, $sale, $product, $taxId, $locId, (int)$qty, []);
                }
            }

            // Update Sale status
            $this->updateSaleStatus($sale);

            DB::commit();
            return redirect()->route('sales.index')->with('success', 'Dispatch berhasil disimpan.');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Dispatch error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage())->withInput();
        }
    }

    private function createDispatchDetailAndAdjustStock($dispatch, $sale, $product, $taxId, $locationId, $qty, $serials)
    {
        $productId = $product->id;
        $productStock = ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (!$productStock) {
            throw new Exception("Stok tidak ditemukan untuk produk {$product->product_name} di lokasi selected.");
        }

        $previousQuantity = $product->product_quantity;
        $previousQuantityAtLocation = $productStock->quantity;

        // Decrement stock
        $productStock->decrement('quantity', $qty);
        if ($taxId) {
            $productStock->decrement('quantity_tax', $qty);
        } else {
            $productStock->decrement('quantity_non_tax', $qty);
        }

        $product->decrement('product_quantity', $qty);

        $afterQuantity = $product->product_quantity;
        $afterQuantityAtLocation = $productStock->quantity;

        // Transaction record
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

        // Dispatch detail
        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'tax_id' => !empty($taxId) ? $taxId : null,
            'product_id' => $productId,
            'dispatched_quantity' => $qty,
            'location_id' => $locationId,
            'serial_numbers' => !empty($serials) ? json_encode($serials) : null,
        ]);

        // Update serial numbers
        if (!empty($serials)) {
            foreach ($serials as $serial) {
                ProductSerialNumber::where('product_id', $productId)
                    ->where('serial_number', $serial)
                    ->update(['dispatch_detail_id' => $dispatchDetail->id]);
            }
        }
    }

    private function updateSaleStatus(Sale $sale)
    {
        $totalOrderQty = $sale->saleDetails()->sum('quantity');
        // Add bundle items if any (per existing code pattern)
        if (class_exists('\Modules\Sale\Entities\SaleBundleItem')) {
            $totalBundleQty = \Modules\Sale\Entities\SaleBundleItem::where('sale_id', $sale->id)->sum('quantity');
            $totalOrderQty += $totalBundleQty;
        }

        $allDispatchedQty = DispatchDetail::where('sale_id', $sale->id)->sum('dispatched_quantity');

        if ($allDispatchedQty <= 0) {
            $sale->status = Sale::STATUS_APPROVED;
        } elseif ($allDispatchedQty < $totalOrderQty) {
            $sale->status = Sale::STATUS_DISPATCHED_PARTIALLY;
        } else {
            $sale->status = Sale::STATUS_DISPATCHED;
        }
        $sale->save();
    }

    private function getStockAtLocation($productId, $taxId, $locationId)
    {
        $stockRecord = ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        if (!$stockRecord) {
            return 0;
        }

        if ((int) $taxId > 0) {
            return max(0, $stockRecord->quantity_tax);
        } else {
            return max(0, $stockRecord->quantity_non_tax);
        }
    }


    public function deliverySlip(Sale $sale)
    {
        abort_if(Gate::denies('sales.show'), 403);

        $this->ensureSaleBelongsToCurrentSetting($sale);

        $dispatch = $sale->saleDispatches()
            ->with(['details.product.baseUnit', 'details.location'])
            ->latest('id')
            ->first();

        if (!$dispatch) {
            abort(404, 'Tidak ada pengeluaran / dispatch untuk pesanan ini.');
        }

        $customer = Customer::findOrFail($sale->customer_id);

        // Group items by product_id ONLY (ignore tax_id)
        $grouped = $dispatch->details
            ->groupBy(fn($d) => $d->product_id)
            ->map(function ($items) {
                $first = $items->first();

                // Merge serial numbers from all rows of the same product
                $serials = $items->flatMap(function ($d) {
                    $arr = $d->serial_numbers ? json_decode($d->serial_numbers, true) : [];
                    return is_array($arr) ? $arr : [];
                })->filter()->unique()->values();

                return (object) [
                    'product'        => $first->product,
                    'product_code'   => $first->product->product_code ?? null,
                    'unit_name'      => $first->product->baseUnit->name ?? '-',
                    'quantity'       => $items->sum('dispatched_quantity'),
                    'serial_numbers' => $serials,
                ];
            })->values();

        // Use sale reference as slip number
        $slipNumber = $sale->reference;

        $tanggal    = Carbon::parse($dispatch->dispatch_date);
        $jatuhTempo = $sale->due_date ? Carbon::parse($sale->due_date) : $tanggal;

        $pdf = Pdf::loadView('sale::print.delivery-slip', [
            'sale'       => $sale,
            'customer'   => $customer,
            'dispatch'   => $dispatch,
            'grouped'    => $grouped,
            'slipNumber' => $slipNumber,
            'tanggal'    => $tanggal,
            'jatuhTempo' => $jatuhTempo,
        ]);

        return $pdf->stream("Surat_Jalan_{$slipNumber}.pdf");
    }

    public function invoicePdf(Sale $sale)
    {
        abort_if(Gate::denies('sales.show'), 403);

        $this->ensureSaleBelongsToCurrentSetting($sale);

        $sale->load([
            'saleDetails.product.baseUnit',
            'salePayments.paymentMethod',
        ]);

        $customer = Customer::findOrFail($sale->customer_id);

        // number / dates used in the PDF
        $invoiceNumber = $sale->reference; // e.g. JL.2025.16198 (adjust if you have a different numbering rule)
        $tanggal      = Carbon::parse($sale->date);
        $jatuhTempo   = $sale->due_date ? Carbon::parse($sale->due_date) : $tanggal;

        // money figures
        $total = (float) $sale->total_amount;
        $paid  = (float) $sale->salePayments->sum('amount');
        $due   = max($total - $paid, 0);

        $pdf = Pdf::loadView('sale::print.invoice', [
            'sale'          => $sale,
            'customer'      => $customer,
            'details'       => $sale->saleDetails,  // show as entered; no grouping for invoice
            'invoiceNumber' => $invoiceNumber,
            'tanggal'       => $tanggal,
            'jatuhTempo'    => $jatuhTempo,
            'total'         => $total,
            'paid'          => $paid,
            'due'           => $due,
        ]);

        return $pdf->stream('Sales-Invoice-'.$invoiceNumber.'.pdf');
    }

    public function posPdf(Sale $sale)
    {
        $this->ensureSaleBelongsToCurrentSetting($sale);

        $sale->load([
            'saleDetails.product.conversions.unit',
            'saleDetails.product.conversions.prices',
            'saleDetails.product.baseUnit',
            'saleDetails.product.prices',
            'customer',
            'posReceipt.sales.saleDetails.product.conversions.unit',
            'posReceipt.sales.saleDetails.product.conversions.prices',
            'posReceipt.sales.saleDetails.product.baseUnit',
            'posReceipt.sales.saleDetails.product.prices',
            'posReceipt.sales.tenantSetting',
            'posReceipt.sales.customer'
        ]);

        $receipt = $sale->posReceipt;
        $viewData = $receipt ? ['receipt' => $receipt] : ['sale' => $sale];
        $fileReference = $receipt?->receipt_number ?? $sale->reference;

        $pdf = Pdf::loadView('sale::print-pos', $viewData)->setPaper('a7')
            ->setOption('margin-top', 8)
            ->setOption('margin-bottom', 8)
            ->setOption('margin-left', 5)
            ->setOption('margin-right', 5);

        return $pdf->stream('sale-' . $fileReference . '.pdf');
    }

    private function resolveSaleDetailPricing(SaleDetails $saleDetail, ?Product $product = null): array
    {
        $product = $product ?? $saleDetail->product ?? Product::find($saleDetail->product_id);
        $productId = (int) optional($product)->getKey() ?: (int) $saleDetail->product_id;

        $saleFallback = (float) ($saleDetail->unit_price ?? $saleDetail->price ?? optional($product)->sale_price ?? 0);
        $tier1Fallback = (float) (optional($product)->tier_1_price ?? $saleFallback);
        $tier2Fallback = (float) (optional($product)->tier_2_price ?? $saleFallback);

        $priceRow = null;
        $settingId = (int) session('setting_id');

        if ($productId > 0 && $settingId > 0) {
            $priceRow = ProductPrice::query()
                ->forProduct($productId)
                ->forSetting($settingId)
                ->first();
        }

        return [
            'product_id' => $productId,
            'sale_price' => (float) ($priceRow?->sale_price ?? $saleFallback),
            'tier_1_price' => (float) ($priceRow?->tier_1_price ?? $tier1Fallback),
            'tier_2_price' => (float) ($priceRow?->tier_2_price ?? $tier2Fallback),
        ];
    }

    private function ensureSaleBelongsToCurrentSetting(Sale $sale): void
    {
        $currentSettingId = session('setting_id');

        if (! is_null($currentSettingId) && (int) $sale->setting_id !== (int) $currentSettingId) {
            Log::info('Access denied: Sale setting mismatch', [
                'sale_setting' => $sale->setting_id,
                'session_setting' => $currentSettingId,
                'sale_id' => $sale->id
            ]);
            abort(404);
        }
    }
}
