<?php

namespace Modules\Purchase\Http\Controllers;

use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\DataTables\PurchaseDataTable;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Http\Requests\StorePurchaseRequest;
use Modules\Purchase\Http\Requests\UpdatePurchaseRequest;
use Modules\Setting\Entities\Location;

class PurchaseController extends Controller
{

    public function index(PurchaseDataTable $dataTable)
    {
        abort_if(Gate::denies('purchase.access'), 403);

        return $dataTable->render('purchase::index');
    }


    public function create()
    {
        abort_if(Gate::denies('purchase.create'), 403);

        // Clear the purchase cart
        Cart::instance('purchase')->destroy();

        // Retrieve the current setting_id from the session
        $setting_id = session('setting_id');

        // Filter PaymentTerms by the setting_id
        $paymentTerms = PaymentTerm::where('setting_id', $setting_id)->get();

        // Pass the filtered terms to the view
        return view('purchase::create', compact('paymentTerms'));
    }


    public function store(StorePurchaseRequest $request): RedirectResponse
    {
        $setting_id = session('setting_id');
        DB::beginTransaction(); // Start the transaction manually
        try {
            // Create the purchase record (example shown earlier)
            $purchase = Purchase::create([
                'date' => $request->date,
                'due_date' => $request->due_date,
                'supplier_id' => $request->supplier_id,
                'tax_id' => $request->tax_id,
                'tax_percentage' => 0, // Example
                'tax_amount' => 0, // Example
                'discount_percentage' => $request->discount_percentage,
                'discount_amount' => $request->discount_amount,
                'shipping_amount' => $request->shipping_amount,
                'total_amount' => $request->total_amount,
                'due_amount' => $request->total_amount,
                'status' => Purchase::STATUS_DRAFTED,
                'payment_status' => 'unpaid',
                'payment_term_id' => $request->payment_term,
                'note' => $request->note,
                'setting_id' => $setting_id,
                'paid_amount' => 0.0,
                'is_tax_included' => $request->is_tax_included,
                'payment_method' => '',
            ]);

            // Iterate over cart items
            foreach (Cart::instance('purchase')->content() as $cart_item) {
                // Map cart item to purchase details
                $product_tax_amount = $cart_item->options['sub_total'] - $cart_item->options['sub_total_before_tax'];

                PurchaseDetail::create([
                    'purchase_id' => $purchase->id, // FK reference
                    'product_id' => $cart_item->id,
                    'product_name' => $cart_item->name,
                    'product_code' => $cart_item->options['code'],
                    'quantity' => $cart_item->qty,
                    'unit_price' => $cart_item->options['unit_price'],
                    'price' => $cart_item->price,
                    'product_discount_type' => $cart_item->options['product_discount_type'],
                    'product_discount_amount' => $cart_item->options['product_discount'],
                    'sub_total' => $cart_item->options['sub_total'],
                    'product_tax_amount' => $product_tax_amount, // Calculated
                    'tax_id' => $cart_item->options['product_tax'], // Tax ID
                ]);
            }

            // Commit transaction
            DB::commit();

            toast('Pembelian Ditambahkan!', 'success');
            return redirect()->route('purchases.index');
        } catch (Exception $e) {
            // Rollback on error
            DB::rollBack();

            // Log the error for debugging
            Log::error('Purchase Creation Failed:', ['error' => $e->getMessage()]);

            // Return an error message to the user
            toast('An error occurred while creating the purchase. Please try again.', 'error');
            return redirect()->back()->withInput();
        }
    }


    public function show(Purchase $purchase)
    {
        abort_if(Gate::denies('show_purchases'), 403);

        $supplier = Supplier::findOrFail($purchase->supplier_id);

        return view('purchase::show', compact('purchase', 'supplier'));
    }


    public function edit(Purchase $purchase)
    {
        abort_if(Gate::denies('purchase.edit'), 403);

        // Retrieve the current setting_id from the session
        $setting_id = session('setting_id');

        // Filter PaymentTerms by the setting_id
        $paymentTerms = PaymentTerm::where('setting_id', $setting_id)->get();

        // Retrieve purchase details
        $purchase_details = $purchase->purchaseDetails;

        // Clear and re-add items to the cart
        Cart::instance('purchase')->destroy();
        $cart = Cart::instance('purchase');
        foreach ($purchase_details as $purchase_detail) {
            $cart->add([
                'id' => $purchase_detail->product_id,
                'name' => $purchase_detail->product_name,
                'qty' => $purchase_detail->quantity,
                'price' => $purchase_detail->price,
                'weight' => 1,
                'options' => [
                    'product_discount' => $purchase_detail->product_discount_amount,
                    'product_discount_type' => $purchase_detail->product_discount_type,
                    'sub_total' => $purchase_detail->sub_total,
                    'code' => $purchase_detail->product_code,
                    'stock' => Product::findOrFail($purchase_detail->product_id)->product_quantity,
                    'product_tax' => $purchase_detail->tax_id,
                    'unit_price' => $purchase_detail->unit_price
                ]
            ]);
        }

        // Pass $paymentTerms to the view
        return view('purchase::edit', compact('purchase', 'paymentTerms'));
    }


    public function update(UpdatePurchaseRequest $request, Purchase $purchase)
    {
        DB::transaction(function () use ($request, $purchase) {
            $due_amount = $request->total_amount - $request->paid_amount;
            if ($due_amount == $request->total_amount) {
                $payment_status = 'Unpaid';
            } elseif ($due_amount > 0) {
                $payment_status = 'Partial';
            } else {
                $payment_status = 'Paid';
            }

            foreach ($purchase->purchaseDetails as $purchase_detail) {
                if ($purchase->status == 'Completed') {
                    $product = Product::findOrFail($purchase_detail->product_id);
                    $product->update([
                        'product_quantity' => $product->product_quantity - $purchase_detail->quantity
                    ]);
                }
                $purchase_detail->delete();
            }

            $purchase->update([
                'date' => $request->date,
                'reference' => $request->reference,
                'supplier_id' => $request->supplier_id,
                'supplier_name' => Supplier::findOrFail($request->supplier_id)->supplier_name,
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
                'tax_amount' => Cart::instance('purchase')->tax() * 100,
                'discount_amount' => Cart::instance('purchase')->discount() * 100,
            ]);

            foreach (Cart::instance('purchase')->content() as $cart_item) {
                PurchaseDetail::create([
                    'purchase_id' => $purchase->id,
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

                if ($request->status == 'Completed') {
                    $product = Product::findOrFail($cart_item->id);
                    $product->update([
                        'product_quantity' => $product->product_quantity + $cart_item->qty
                    ]);
                }
            }

            Cart::instance('purchase')->destroy();
        });

        toast('Pembelian Diperbaharui!', 'info');

        return redirect()->route('purchases.index');
    }


    public function destroy(Purchase $purchase)
    {
        abort_if(Gate::denies('purchase.delete'), 403);

        $purchase->delete();

        toast('Pembelian Dihapus!', 'warning');

        return redirect()->route('purchases.index');
    }

    public function updateStatus(Request $request, Purchase $purchase)
    {
        abort_if(Gate::denies('update_purchase_status'), 403);

        $validated = $request->validate([
            'status' => 'required|string|in:' . implode(',', [
                    Purchase::STATUS_WAITING_APPROVAL,
                    Purchase::STATUS_APPROVED,
                    Purchase::STATUS_REJECTED
                ]),
        ]);

        try {
            $purchase->update(['status' => $validated['status']]);
            toast("Purchase status updated to {$validated['status']}!", 'success');
        } catch (Exception $e) {
            Log::error('Failed to update purchase status', ['error' => $e->getMessage()]);
            toast('Failed to update purchase status.', 'error');
        }

        return redirect()->route('purchases.show', $purchase->id);
    }

    public function datatable(PurchaseDataTable $dataTable, Request $request)
    {
        return $dataTable->with('supplier_id', $request->get('supplier_id'))->render('purchase::index');
    }

    public function receive(Purchase $purchase): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $currentSettingId = session('setting_id');
        $locations = Location::where('setting_id', $currentSettingId)->get();

        // Calculate quantity_received for each purchase detail
        foreach ($purchase->purchaseDetails as $detail) {
            $detail->quantity_received = ReceivedNoteDetail::where('po_detail_id', $detail->id)
                ->sum('quantity_received');
        }

        return view('purchase::receive', compact('purchase', 'locations'));
    }

    public function storeReceive(Request $request, Purchase $purchase): RedirectResponse
    {
        $data = $request->validate([
            'received.*' => 'nullable|integer|min:0',
            'notes.*' => 'nullable|string|max:255',
            'serial_numbers.*.*' => 'nullable|string|max:255',
            'external_delivery_number' => 'nullable|string|max:255',
            'location_id' => 'required|integer|exists:locations,id',
        ]);

        DB::transaction(function () use ($data, $purchase) {
            // Create a ReceivedNote
            $receivedNote = ReceivedNote::create([
                'po_id' => $purchase->id,
                'external_delivery_number' => $data['external_delivery_number'] ?? '',
                'date' => now(),
            ]);

            // Track the newly received quantities for this transaction
            $newReceivedQuantities = [];

            foreach ($purchase->purchaseDetails as $detail) {
                $receivedQuantity = $data['received'][$detail->id] ?? 0;

                if ($receivedQuantity > 0) {
                    // Track the received quantity for this transaction
                    $newReceivedQuantities[$detail->id] = ($newReceivedQuantities[$detail->id] ?? 0) + $receivedQuantity;

                    // Create ReceivedNoteDetail
                    ReceivedNoteDetail::create([
                        'received_note_id' => $receivedNote->id,
                        'quantity_received' => $receivedQuantity,
                        'po_detail_id' => $detail->id,
                    ]);

                    // If serial numbers are required, save them
                    if ($detail->product->serial_number_required && isset($data['serial_numbers'][$detail->id])) {
                        foreach ($data['serial_numbers'][$detail->id] as $serialNumber) {
                            ProductSerialNumber::create([
                                'product_id' => $detail->product_id,
                                'location_id' => $data['location_id'], // Use selected location ID
                                'serial_number' => $serialNumber,
                                'tax_id' => $detail->tax_id,
                            ]);
                        }
                    }

                    // Update product stock
                    $productStock = ProductStock::firstOrCreate([
                        'product_id' => $detail->product_id,
                        'location_id' => $data['location_id'], // Use selected location ID
                    ], [
                        'quantity' => 0,
                        'quantity_tax' => 0,
                        'quantity_non_tax' => 0,
                        'broken_quantity_non_tax' => 0,
                        'broken_quantity_tax' => 0,
                        'broken_quantity' => 0,
                    ]);

                    // Increment stock quantity
                    $productStock->increment('quantity', $receivedQuantity);

                    if ($detail->tax_id) {
                        $productStock->increment('quantity_tax', $receivedQuantity);
                    } else {
                        $productStock->increment('quantity_non_tax', $receivedQuantity);
                    }
                }
            }

            // Calculate status based on stored and new received quantities
            $allFullyReceived = true;

            foreach ($purchase->purchaseDetails as $detail) {
                // Sum of stored and new received quantities
                $storedReceived = ReceivedNoteDetail::where('po_detail_id', $detail->id)->sum('quantity_received');
                $newReceived = $newReceivedQuantities[$detail->id] ?? 0;
                $totalReceived = $storedReceived + $newReceived;

                Log::info('numbers', [
                    '$storedReceived' => $storedReceived,
                    'newReceived' => $newReceived,
                    'detailQuantity' => $detail->quantity
                ]);
                if ($totalReceived < $detail->quantity) {
                    $allFullyReceived = false;
                    break;
                }
            }

            $status = $allFullyReceived ? Purchase::STATUS_RECEIVED : Purchase::STATUS_RECEIVED_PARTIALLY;

            // Update purchase status
            $purchase->update(['status' => $status]);
        });

        return redirect()->route('purchases.show', $purchase->id)->with('message', 'Items successfully received.');
    }
}
