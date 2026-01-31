<?php

namespace Modules\Purchase\Http\Controllers;

use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\DataTables\PurchaseDataTable;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use App\Services\PurchaseAttachmentService;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\DataTables\PurchasePaymentsDataTable;
use Modules\Purchase\DataTables\PurchaseReceivingsDataTable;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Http\Requests\StorePurchaseRequest;
use Modules\Purchase\Http\Requests\UpdatePurchaseRequest;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PurchaseController extends Controller
{

    public function index(PurchaseDataTable $dataTable)
    {
        abort_if(Gate::denies('purchases.access'), 403);

        return $dataTable->render('purchase::index');
    }

    /**
     * Display the purchase receiving landing page.
     */
    public function receivingIndex(Request $request): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('purchaseReceivings.access'), 403);

        $purchase = null;

        if ($request->filled('purchase_id')) {
            $purchase = Purchase::withArchived()->findOrFail($request->input('purchase_id'));
            $this->ensurePurchaseBelongsToCurrentSetting($purchase);
        }

        return view('purchase::receiving.filtered-index', compact('purchase'));
    }

    /**
     * Display the list of all receivings with their status.
     */
    public function receivingsList(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('purchaseReceivings.access'), 403);

        return view('purchase::receiving.list');
    }



    public function createAlpine(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('purchases.create'), 403);

        // Get data for Alpine.js form
        $paymentTerms = PaymentTerm::all();
        $suppliers = Supplier::all();
        $taxes = \Modules\Setting\Entities\Tax::all();
        $categories = \Modules\Product\Entities\Category::all();
        $brands = \Modules\Product\Entities\Brand::all();
        $units = \Modules\Setting\Entities\Unit::all();
        $idempotencyToken = (string) Str::uuid();

        return view('purchase::create-alpine', compact(
            'paymentTerms',
            'suppliers',
            'taxes',
            'categories',
            'brands',
            'units',
            'idempotencyToken'
        ));
    }

    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('purchases.create'), 403);

        // Get data for new Alpine.js form
        $paymentTerms = PaymentTerm::all();
        $taxes = \Modules\Setting\Entities\Tax::all();
        $categories = \Modules\Product\Entities\Category::all();
        $brands = \Modules\Product\Entities\Brand::all();
        $units = \Modules\Setting\Entities\Unit::all();
        $idempotencyToken = (string) Str::uuid();
        $duplicateId = request()->query('duplicate');

        return view('purchase::create', compact(
            'paymentTerms',
            'taxes',
            'categories',
            'brands',
            'units',
            'idempotencyToken',
            'duplicateId'
        ));
    }


    public function store(StorePurchaseRequest $request): RedirectResponse
    {
        abort_if(Gate::denies('purchases.create'), 403);

        // Check if cart data is provided in request (Alpine.js form) or use session cart (Livewire)
        $cartItems = $request->has('cart') ? $request->cart : Cart::instance('purchase')->content();

        if (empty($cartItems) || (is_array($cartItems) && count($cartItems) == 0) || (!is_array($cartItems) && $cartItems->count() == 0)) {
            return redirect()->back()->withErrors(['cart' => 'Daftar Produk tidak boleh kosong.'])->withInput();
        }

        $setting_id = session('setting_id');
        DB::beginTransaction(); // Start the transaction manually
        try {
            // Create the purchase record
            $purchase = Purchase::create([
                'date' => $request->date,
                'due_date' => $request->due_date,
                'supplier_id' => $request->supplier_id,
                'supplier_purchase_number' => $request->supplier_purchase_number,
                'tax_ref_no' => $request->tax_ref_no,
                'tax_id' => $request->tax_id,
                'tax_percentage' => 0,
                'tax_amount' => 0,
                'discount_percentage' => $request->discount_percentage ?? 0,
                'discount_amount' => $request->discount_amount ?? 0,
                'shipping_amount' => $request->shipping_amount ?? $request->shipping ?? 0,
                'total_amount' => $request->total_amount,
                'due_amount' => $request->total_amount,
                'status' => Purchase::STATUS_DRAFTED,
                'payment_status' => 'Unpaid',
                'payment_term_id' => $request->payment_term,
                'note' => $request->note,
                'setting_id' => $setting_id,
                'paid_amount' => 0.0,
                'is_tax_included' => $request->is_tax_included ?? true,
                'payment_method' => '',
            ]);

            // Handle cart items from Alpine.js form or Livewire cart
            if ($request->has('cart')) {
                // Alpine.js form data
                foreach ($request->cart as $cartItem) {
                    $product = \Modules\Product\Entities\Product::find($cartItem['product_id']);
                    if (!$product) continue;

                    // Calculate tax amount
                    $taxAmount = 0;
                    if ($cartItem['tax_id']) {
                        $tax = \Modules\Setting\Entities\Tax::find($cartItem['tax_id']);
                        if ($tax) {
                            $subtotal = $cartItem['unit_price'] * $cartItem['quantity'];
                            $taxAmount = $subtotal * ($tax->value / 100);
                        }
                    }

                    PurchaseDetail::create([
                        'purchase_id' => $purchase->id,
                        'product_id' => $cartItem['product_id'],
                        'product_name' => $product->product_name,
                        'product_code' => $product->product_code,
                        'quantity' => $cartItem['quantity'],
                        'unit_price' => $cartItem['unit_price'],
                        'price' => $cartItem['unit_price'],
                        'product_discount_type' => $cartItem['discount_type'],
                        'product_discount_amount' => $cartItem['discount'],
                        'sub_total' => ($cartItem['unit_price'] * $cartItem['quantity']) - $cartItem['discount'],
                        'product_tax_amount' => $taxAmount,
                        'tax_id' => $cartItem['tax_id'],
                    ]);
                }
            } else {
                // Livewire cart data
                foreach ($cartItems as $cart_item) {
                    $product_tax_amount = $cart_item->options['sub_total'] -
                        ($cart_item->options['sub_total_before_tax'] ?? 0);

                    PurchaseDetail::create([
                        'purchase_id' => $purchase->id,
                        'product_id' => $cart_item->id,
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
                }

                // Clear the cart after successful creation
                Cart::instance('purchase')->destroy();
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


    public function show(Purchase $purchase, PurchasePaymentsDataTable $dataTable)
    {
        abort_if(Gate::denies('purchases.show'), 403);

        if ($purchase->isArchived()) {
            abort_if(Gate::denies('purchases.archive'), 403);
        }

        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $supplier = Supplier::findOrFail($purchase->supplier_id);

        $receivedNotes = ReceivedNote::where('po_id', $purchase->id)
            ->with([
                'purchase',
                'location',
                'receivedNoteDetails.purchaseDetail',
                'receivedNoteDetails.productSerialNumbers'
            ])
            ->get();

        return $dataTable->with(['purchase_id' => $purchase->id])
            ->render('purchase::show', compact('purchase', 'supplier', 'receivedNotes'));
    }

    public function storeAttachments(Request $request, Purchase $purchase): RedirectResponse
    {
        abort_if(Gate::denies('purchases.edit'), 403);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $service = app(PurchaseAttachmentService::class);
        $prepared = [];
        $file = $request->file('attachment');

        if (is_array($file)) {
            return redirect()->back()->withErrors(['attachment' => 'Maksimal 1 lampiran per unggah.'])->withInput();
        }

        if (!($file instanceof UploadedFile)) {
            return redirect()->back()->withErrors(['attachment' => 'Lampiran wajib diunggah.'])->withInput();
        }

        try {
            $prepared[] = $service->prepare($file);
        } catch (\RuntimeException $e) {
            $service->cleanup($prepared);
            return redirect()->back()->withErrors(['attachment' => $e->getMessage()])->withInput();
        } catch (\Throwable $e) {
            $service->cleanup($prepared);
            Log::error('Purchase attachment preparation failed', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
            return redirect()->back()->withErrors(['attachment' => 'Gagal memproses lampiran.'])->withInput();
        }

        try {
            foreach ($prepared as $item) {
                $service->attachPrepared($purchase, $item);
            }
        } catch (\Throwable $e) {
            $service->cleanup($prepared);
            Log::error('Purchase attachment upload failed', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
            return redirect()->back()->withErrors(['attachment' => 'Gagal menyimpan lampiran.'])->withInput();
        }

        toast('Lampiran pembelian diperbarui!', 'success');
        return redirect()->back();
    }

    public function destroyAttachment(Purchase $purchase, Media $media): RedirectResponse
    {
        abort_if(Gate::denies('purchases.edit'), 403);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        if ($media->collection_name !== 'attachments' || $media->model_id !== $purchase->id || $media->model_type !== Purchase::class) {
            abort(404);
        }

        $media->delete();
        toast('Lampiran dihapus.', 'success');
        return redirect()->back();
    }


    public function edit(Purchase $purchase)
    {
        abort_if(Gate::denies('purchases.edit'), 403);

        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        // Rule: Partially or Fully Received -> Hard Block
        if (in_array($purchase->status, [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY])) {
            abort(403, 'Tidak dapat mengubah pembelian yang sudah diterima barangnya.');
        }

        // Rule: Approved -> Require explicit permission
        if ($purchase->status === Purchase::STATUS_APPROVED) {
            if (!auth()->user()->can('purchases.approved.edit')) {
                abort(403, 'Anda tidak memiliki akses untuk mengubah pembelian yang sudah disetujui.');
            }
        }


        // Filter PaymentTerms by the setting_id
        $paymentTerms = PaymentTerm::all();
        $suppliers = Supplier::all();

        // Retrieve purchase details
        $purchase_details = $purchase->purchaseDetails;

        // Clear and re-add items to the cart
        Cart::instance('purchase')->destroy();
        $cart = Cart::instance('purchase');
        foreach ($purchase_details as $purchase_detail) {
            $subtotal_before_tax = $purchase_detail->price * $purchase_detail->quantity;

            if ($purchase->is_tax_included) {
                // Case: Tax is included in the price
                if ($purchase_detail->tax_id) {
                    $tax = Tax::find($purchase_detail->tax_id);
                    if ($tax) {
                        // Calculate price excluding tax
                        $price_ex_tax = $purchase_detail->price / (1 + $tax->value / 100);
//                        $tax_amount_per_unit = $purchase_detail->price - $price_ex_tax;
//                        $tax_amount = $tax_amount_per_unit * $purchase_detail->quantity;
                        $subtotal_before_tax = $price_ex_tax * $purchase_detail->quantity;
                    } else {
                        $subtotal_before_tax = $purchase_detail->price * $purchase_detail->quantity;
                    }
                } else {
                    // No tax applied
                    $subtotal_before_tax = $purchase_detail->price * $purchase_detail->quantity;
                }
            }

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
                    'unit_price' => $purchase_detail->unit_price,
                    'sub_total_before_tax' => $subtotal_before_tax
                ]
            ]);
        }

        // Pass $paymentTerms to the view
        return view('purchase::edit', compact('purchase', 'paymentTerms', 'suppliers'));
    }

    public function update(UpdatePurchaseRequest $request, Purchase $purchase)
    {
        abort_if(Gate::denies('purchases.edit'), 403);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        // Rule: Partially or Fully Received -> Hard Block
        if (in_array($purchase->status, [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY])) {
            abort(403, 'Tidak dapat memperbarui pembelian yang sudah diterima barangnya.');
        }

        // Rule: Approved -> Require explicit permission
        if ($purchase->status === Purchase::STATUS_APPROVED) {
            if (!auth()->user()->can('purchases.approved.edit')) {
                abort(403, 'Anda tidak memiliki akses untuk memperbarui pembelian yang sudah disetujui.');
            }
        }
        Log::info('Cart count at start of update:', ['count' => Cart::instance('purchase')->count()]);
        if (Cart::instance('purchase')->count() == 0) {
            return redirect()->back()->withErrors(['cart' => 'Daftar Produk tidak boleh kosong.'])->withInput();
        }

        DB::transaction(function () use ($request, $purchase) {
            // Fields to update, only if new values are passed in the request
            $updateData = array_filter([
                'date' => $request->filled('date') && $request->date !== $purchase->date ? $request->date : null,
                'due_date' => $request->filled('due_date') && $request->due_date !== $purchase->due_date ? $request->due_date : null,
                'supplier_id' => $request->filled('supplier_id') && $request->supplier_id !== $purchase->supplier_id ? $request->supplier_id : null,
                'tax_percentage' => $request->filled('tax_percentage') && $request->tax_percentage !== $purchase->tax_percentage ? $request->tax_percentage : null,
                'discount_percentage' => $request->filled('discount_percentage') && $request->discount_percentage !== $purchase->discount_percentage ? $request->discount_percentage : null,
                'shipping_amount' => $request->filled('shipping_amount') && $request->shipping_amount != $purchase->shipping_amount ? $request->shipping_amount : null,
                'paid_amount' => $request->filled('paid_amount') && $request->paid_amount != $purchase->paid_amount ? $request->paid_amount : null,
                'total_amount' => $request->filled('total_amount') && $request->total_amount != $purchase->total_amount ? $request->total_amount : null,
                'due_amount' => $request->filled('total_amount') && $request->total_amount != $purchase->total_amount ? $request->total_amount : null,
                'status' => $request->filled('status') && $request->status !== $purchase->status ? $request->status : null,
                'payment_method' => $request->filled('payment_method') && $request->payment_method !== $purchase->payment_method ? $request->payment_method : null,
                'note' => $request->filled('note') && $request->note !== $purchase->note ? $request->note : null,
            ], function ($value) {
                return $value !== null;
            });

            if ($request->has('supplier_purchase_number') && $request->supplier_purchase_number !== $purchase->supplier_purchase_number) {
                $updateData['supplier_purchase_number'] = $request->supplier_purchase_number;
            }

            if ($request->has('tax_ref_no') && $request->tax_ref_no !== $purchase->tax_ref_no) {
                $updateData['tax_ref_no'] = $request->tax_ref_no;
            }

            if (!empty($updateData)) {
                // Update the purchase record
                $purchase->update($updateData);
            }

            // Clear existing purchase details
            $purchase->purchaseDetails()->delete();

            // Re-add updated cart items
            foreach (Cart::instance('purchase')->content() as $cart_item) {
                PurchaseDetail::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $cart_item->id,
                    'product_name' => $cart_item->name,
                    'product_code' => $cart_item->options['code'],
                    'quantity' => $cart_item->qty,
                    'unit_price' => $cart_item->options['unit_price'],
                    'price' => $cart_item->price,
                    'product_discount_type' => $cart_item->options['product_discount_type'],
                    'product_discount_amount' => $cart_item->options['product_discount'],
                    'sub_total' => $cart_item->options['sub_total'],
                    'product_tax_amount' => $cart_item->options['sub_total'] -
                        ($cart_item->options['sub_total_before_tax'] ?? 0),
                    'tax_id' => $cart_item->options['product_tax'],
                ]);
            }

            Cart::instance('purchase')->destroy();
        });

        toast('Pembelian Diperbaharui!', 'info');
        return redirect()->route('purchases.index');
    }

    public function destroy(Purchase $purchase)
    {
        abort_if(Gate::denies('purchases.delete'), 403);

        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        // Rule: Partially or Fully Received -> Hard Block
        if (in_array($purchase->status, [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY])) {
            abort(403, 'Tidak dapat menghapus pembelian yang sudah diterima barangnya.');
        }

        // Rule: Approved -> Require explicit archive permission
        if ($purchase->status === Purchase::STATUS_APPROVED) {
            if (!auth()->user()->can('purchases.archive')) {
                abort(403, 'Anda tidak memiliki akses untuk mengarsipkan pembelian yang sudah disetujui.');
            }
        }

        $purchase->delete();

        toast('Pembelian Dihapus!', 'warning');

        return redirect()->route('purchases.index');
    }

    public function archive(Purchase $purchase): RedirectResponse
    {
        abort_if(Gate::denies('purchases.archive'), 403);

        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        // Rule: Partially or Fully Received -> Hard Block
        if (in_array($purchase->status, [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY])) {
            abort(403, 'Tidak dapat mengarsipkan pembelian yang sudah diterima barangnya.');
        }

        $purchase->update([
            'archived_at' => now(),
            'archived_by' => auth()->id(),
        ]);

        toast('Pembelian Diarsipkan!', 'info');

        return redirect()->route('purchases.index');
    }

    public function updateStatus(Request $request, Purchase $purchase): RedirectResponse
    {
        abort_unless(Gate::any(['purchases.edit', 'purchases.approval']), 403);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);
        $validated = $request->validate([
            'status' => 'required|string|in:' . implode(',', [
                    Purchase::STATUS_DRAFTED,
                    Purchase::STATUS_WAITING_APPROVAL,
                    Purchase::STATUS_APPROVED,
                    Purchase::STATUS_REJECTED
                ]),
            'rejection_note' => 'nullable|string|required_if:status,' . Purchase::STATUS_REJECTED,
        ]);

        try {
            $data = ['status' => $validated['status']];
            if (isset($validated['rejection_note'])) {
                $data['rejection_note'] = $validated['rejection_note'];
            }
            $purchase->update($data);
            toast("Status pembelian diperbarui menjadi {$validated['status']}!", 'success');
        } catch (Exception $e) {
            Log::error('Failed to update purchase status', ['error' => $e->getMessage()]);
            toast('Gagal memperbarui status pembelian.', 'error');
        }

        // Redirect back to the referring page
        return redirect()->to(url()->previous());
    }

    public function datatable(PurchaseDataTable $dataTable, Request $request)
    {
        return $dataTable->with('supplier_id', $request->get('supplier_id'))->render('purchase::index');
    }

    public function receive(Purchase $purchase): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('purchases.receive'), 403);

        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $currentSettingId = session('setting_id');


        // Calculate quantity_received for each purchase detail
        foreach ($purchase->purchaseDetails as $detail) {
            $detail->quantity_received = ReceivedNoteDetail::where('po_detail_id', $detail->id)
                ->sum('quantity_received');
        }

        return view('purchase::receive', compact('purchase'));
    }

    public function storeReceive(Request $request, Purchase $purchase): RedirectResponse
    {
        abort_if(Gate::denies('purchases.receive'), 403);

        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'received' => [
                'array',
                function ($attribute, $value, $fail) {
                    $total = collect($value)->sum();
                    if ($total <= 0) {
                        $fail('Minimal satu produk harus memiliki jumlah diterima lebih dari 0.');
                    }
                }
            ],
            'received.*' => 'nullable|integer|min:0',
            'notes.*' => 'nullable|string|max:255',
            'serial_numbers.*.*' => ['nullable', 'string', 'max:255'],
            'external_delivery_number' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($purchase) {
                    if (!empty($value)) {
                        $exists = ReceivedNote::where('po_id', $purchase->id)
                            ->where('external_delivery_number', $value)
                            ->exists();
                        if ($exists) {
                            $fail('Nomor pengiriman eksternal sudah digunakan untuk pembelian ini.');
                        }
                    }
                }
            ],
            'location_id' => 'required|integer|exists:locations,id',
        ], [], [
            'location_id' => 'Lokasi',
            'external_delivery_number' => 'Nomor Surat Jalan Supplier'
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        // Collect all serial numbers submitted by the user
        $inputtedSerialNumbers = collect($data['serial_numbers'] ?? [])
            ->flatten()
            ->filter() // Remove null or empty values
            ->unique(); // Avoid duplicate checks within the input itself

        // Find duplicate serial numbers in committed product_serial_numbers
        $existingSerialNumbers = ProductSerialNumber::whereIn('serial_number', $inputtedSerialNumbers)->pluck('serial_number')->toArray();

        // Also check for serial numbers pending in PENDING receivings
        $pendingSerialNumbers = [];
        if ($inputtedSerialNumbers->isNotEmpty()) {
            $pendingDetails = ReceivedNoteDetail::whereHas('receivedNote', function ($q) {
                $q->where('status', ReceivedNote::STATUS_PENDING);
            })
                ->whereNotNull('pending_serial_numbers')
                ->get();
            
            foreach ($pendingDetails as $detail) {
                $pendingSerials = $detail->pending_serial_numbers ?? [];
                foreach ($pendingSerials as $serial) {
                    if ($inputtedSerialNumbers->contains($serial)) {
                        $pendingSerialNumbers[] = $serial;
                    }
                }
            }
        }

        $allDuplicates = array_unique(array_merge($existingSerialNumbers, $pendingSerialNumbers));

        // If any duplicate serial numbers exist, return a validation error
        if (!empty($allDuplicates)) {
            return redirect()->back()->withErrors([
                'serial_numbers' => 'Serial Number berikut sudah ada atau sedang dalam proses penerimaan: ' . implode(', ', $allDuplicates),
            ])->withInput();
        }

        DB::transaction(function () use ($data, $purchase) {
            // Create a ReceivedNote with PENDING status
            $receivedNote = ReceivedNote::create([
                'po_id' => $purchase->id,
                'external_delivery_number' => $data['external_delivery_number'] ?? null,
                'date' => now(),
                'location_id' => $data['location_id'],
                'status' => ReceivedNote::STATUS_PENDING,
            ]);

            // Get purchase details for validation
            $purchaseDetails = $purchase->purchaseDetails()->get();

            foreach ($purchaseDetails as $detail) {
                $receivedQuantity = $data['received'][$detail->id] ?? 0;

                if ($receivedQuantity > 0) {
                    // Collect pending serial numbers for this detail
                    $pendingSerials = null;
                    if ($detail->product->serial_number_required && isset($data['serial_numbers'][$detail->id])) {
                        $pendingSerials = array_values(array_filter($data['serial_numbers'][$detail->id]));
                    }

                    // Create ReceivedNoteDetail with pending serial numbers (not committed yet)
                    ReceivedNoteDetail::create([
                        'received_note_id' => $receivedNote->id,
                        'quantity_received' => $receivedQuantity,
                        'po_detail_id' => $detail->id,
                        'pending_serial_numbers' => $pendingSerials,
                    ]);

                    // Serial numbers will be committed to product_serial_numbers table on approval
                }
            }

            // Stock increment and purchase status update are now done on approval
        });

        toast('Penerimaan berhasil disimpan dan menunggu persetujuan.', 'success');
        return redirect()->route('purchases.receiving.index')->with('message', 'Penerimaan berhasil disimpan. Menunggu persetujuan.');
    }

    private function updateAveragePurchasePrice(Product $product, $newPrice, $receivedQuantity)
    {
        $previousQuantity = $product->product_quantity - $receivedQuantity;

        // Sanity check to prevent negative or division-by-zero errors
        $previousQuantity = max($previousQuantity, 0);

        $currentTotalValue = $product->average_purchase_price * $previousQuantity;
        $newTotalValue = $newPrice * $receivedQuantity;

        $newTotalQuantity = $previousQuantity + $receivedQuantity;

        if ($newTotalQuantity > 0) {
            $newAveragePrice = ($currentTotalValue + $newTotalValue) / $newTotalQuantity;
        } else {
            $newAveragePrice = $newPrice;
        }

        $product->update(['average_purchase_price' => $newAveragePrice]);
    }

    public function showReceivings($purchase_id, PurchaseReceivingsDataTable $dataTable)
    {
        abort_if(Gate::denies('purchases.receive'), 403);

        $purchase = Purchase::withArchived()->findOrFail($purchase_id);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);
        return $dataTable->render('purchase::receivings.index', compact('purchase'));
    }

    private function ensurePurchaseBelongsToCurrentSetting(Purchase $purchase): void
    {
        $currentSettingId = session('setting_id');

        if (! is_null($currentSettingId) && (int) $purchase->setting_id !== (int) $currentSettingId) {
            abort(404);
        }
    }

    /**
     * Approve a receiving and increment stock.
     */
    public function approveReceiving(ReceivedNote $receivedNote): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        abort_if(Gate::denies('purchaseReceivings.approval'), 403);

        if (!$receivedNote->isPending()) {
            if (request()->ajax() || request()->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'already_processed',
                    'message' => 'Penerimaan ini sudah diproses sebelumnya.',
                ], 422);
            }
            toast('Penerimaan ini sudah diproses sebelumnya.', 'error');
            return redirect()->back();
        }

        // Validate for over-receiving before approval
        $purchase = $receivedNote->purchase;
        $receivedNote->load('receivedNoteDetails.purchaseDetail');
        
        // Get already approved quantities for this purchase
        $approvedQuantities = ReceivedNoteDetail::whereHas('receivedNote', function ($q) use ($purchase) {
            $q->where('po_id', $purchase->id)->where('status', ReceivedNote::STATUS_APPROVED);
        })->selectRaw('po_detail_id, SUM(quantity_received) as total_received')
          ->groupBy('po_detail_id')
          ->pluck('total_received', 'po_detail_id');

        $overReceivingErrors = [];
        
        foreach ($receivedNote->receivedNoteDetails as $detail) {
            $purchaseDetail = $detail->purchaseDetail;
            if (!$purchaseDetail) {
                continue;
            }
            
            $orderedQuantity = $purchaseDetail->quantity;
            $alreadyReceived = $approvedQuantities[$purchaseDetail->id] ?? 0;
            $pendingQuantity = $detail->quantity_received;
            $totalAfterApproval = $alreadyReceived + $pendingQuantity;
            
            if ($totalAfterApproval > $orderedQuantity) {
                $overReceivingErrors[] = [
                    'product_name' => $purchaseDetail->product_name,
                    'product_code' => $purchaseDetail->product_code,
                    'ordered_quantity' => $orderedQuantity,
                    'already_received' => $alreadyReceived,
                    'pending_quantity' => $pendingQuantity,
                    'excess' => $totalAfterApproval - $orderedQuantity,
                ];
            }
        }
        
        if (!empty($overReceivingErrors)) {
            if (request()->ajax() || request()->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'over_receiving',
                    'message' => 'Jumlah penerimaan melebihi jumlah pesanan',
                    'details' => $overReceivingErrors,
                    'received_note_id' => $receivedNote->id,
                ], 422);
            }
            // Fallback for non-AJAX requests
            toast('Jumlah penerimaan melebihi jumlah pesanan. Silakan tolak penerimaan ini.', 'error');
            return redirect()->back();
        }

        DB::transaction(function () use ($receivedNote) {
            $receivedNote->lockForUpdate();
            $purchase = $receivedNote->purchase;
            
            // Load received note details with purchase details
            $receivedNote->load('receivedNoteDetails.purchaseDetail.product');
            
            $productIds = $receivedNote->receivedNoteDetails->pluck('purchaseDetail.product_id')->unique();
            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get();
            $productStocks = ProductStock::whereIn('product_id', $productIds)
                ->where('location_id', $receivedNote->location_id)
                ->lockForUpdate()
                ->get();

            foreach ($receivedNote->receivedNoteDetails as $detail) {
                $purchaseDetail = $detail->purchaseDetail;
                $receivedQuantity = $detail->quantity_received;

                if ($receivedQuantity > 0) {
                    $product = $products->where('id', $purchaseDetail->product_id)->first();

                    // Update product stock
                    $productStock = $productStocks->where('product_id', $purchaseDetail->product_id)
                        ->where('location_id', $receivedNote->location_id)
                        ->first();

                    if (!$productStock) {
                        $productStock = ProductStock::create([
                            'product_id' => $purchaseDetail->product_id,
                            'location_id' => $receivedNote->location_id,
                            'quantity' => 0,
                            'quantity_tax' => 0,
                            'quantity_non_tax' => 0,
                            'broken_quantity_non_tax' => 0,
                            'broken_quantity_tax' => 0,
                            'broken_quantity' => 0,
                        ]);
                    }

                    // Capture previous stock
                    $previous_quantity = $product->product_quantity;
                    $previous_quantity_at_location = $productStock->quantity;

                    // Increment stock quantity
                    $productStock->increment('quantity', $receivedQuantity);

                    if ($purchaseDetail->tax_id) {
                        $productStock->increment('quantity_tax', $receivedQuantity);
                    } else {
                        $productStock->increment('quantity_non_tax', $receivedQuantity);
                    }

                    $product->increment('product_quantity', $receivedQuantity);

                    // Capture after stock
                    $after_quantity = $product->product_quantity;
                    $after_quantity_at_location = $productStock->quantity;

                    // Update Last Purchase Price
                    $product->update(['last_purchase_price' => $purchaseDetail->price]);

                    // Update Average Purchase Price
                    $this->updateAveragePurchasePrice($product, $purchaseDetail->price, $receivedQuantity);

                    // Update per-setting ProductPrice (last + average) on approval
                    $settingId = $purchase->setting_id ?? session('setting_id');
                    if (!is_null($settingId)) {
                        $productPrice = ProductPrice::firstOrCreate(
                            [
                                'product_id' => $product->id,
                                'setting_id' => $settingId,
                            ],
                            [
                                'sale_price' => 0,
                                'last_purchase_price' => 0,
                                'average_purchase_price' => 0,
                            ]
                        );

                        $previousQty = $previous_quantity;
                        $currentAvgPrice = $productPrice->average_purchase_price ?? 0;
                        $currentTotalValue = $currentAvgPrice * $previousQty;
                        $newTotalValue = $purchaseDetail->price * $receivedQuantity;
                        $newTotalQuantity = $previousQty + $receivedQuantity;

                        $newAveragePrice = $newTotalQuantity > 0
                            ? ($currentTotalValue + $newTotalValue) / $newTotalQuantity
                            : $purchaseDetail->price;

                        $productPrice->update([
                            'last_purchase_price' => $purchaseDetail->price,
                            'average_purchase_price' => $newAveragePrice,
                        ]);
                    }

                    // Insert Transaction Log
                    Transaction::create([
                        'product_id' => $purchaseDetail->product_id,
                        'setting_id' => session('setting_id'),
                        'quantity' => $receivedQuantity,
                        'current_quantity' => $after_quantity,
                        'broken_quantity' => 0,
                        'location_id' => $receivedNote->location_id,
                        'user_id' => auth()->id(),
                        'reason' => 'Received from Purchase Order #' . $purchase->reference . ' (Approved)',
                        'type' => 'BUY',
                        'previous_quantity' => $previous_quantity,
                        'after_quantity' => $after_quantity,
                        'previous_quantity_at_location' => $previous_quantity_at_location,
                        'after_quantity_at_location' => $after_quantity_at_location,
                        'quantity_non_tax' => $purchaseDetail->tax_id ? 0 : $receivedQuantity,
                        'quantity_tax' => $purchaseDetail->tax_id ? $receivedQuantity : 0,
                        'broken_quantity_non_tax' => 0,
                        'broken_quantity_tax' => 0,
                    ]);

                    // Commit pending serial numbers to product_serial_numbers table
                    if (!empty($detail->pending_serial_numbers) && is_array($detail->pending_serial_numbers)) {
                        foreach ($detail->pending_serial_numbers as $serialNumber) {
                            ProductSerialNumber::create([
                                'product_id' => $purchaseDetail->product_id,
                                'location_id' => $receivedNote->location_id,
                                'serial_number' => $serialNumber,
                                'tax_id' => $purchaseDetail->tax_id,
                                'received_note_detail_id' => $detail->id,
                            ]);
                        }
                        // Clear pending serial numbers after commit
                        $detail->update(['pending_serial_numbers' => null]);
                    }
                }
            }

            // Update receiving status to APPROVED
            $receivedNote->update([
                'status' => ReceivedNote::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            // Calculate and update purchase status based on all APPROVED receivings
            $purchaseDetails = $purchase->purchaseDetails;
            $approvedReceiveds = ReceivedNoteDetail::whereHas('receivedNote', function ($q) use ($purchase) {
                $q->where('po_id', $purchase->id)->where('status', ReceivedNote::STATUS_APPROVED);
            })->selectRaw('po_detail_id, SUM(quantity_received) as total_received')
              ->groupBy('po_detail_id')
              ->pluck('total_received', 'po_detail_id');

            $allFullyReceived = true;
            foreach ($purchaseDetails as $detail) {
                $totalReceived = $approvedReceiveds[$detail->id] ?? 0;
                if ($totalReceived < $detail->quantity) {
                    $allFullyReceived = false;
                    break;
                }
            }

            $status = $allFullyReceived ? Purchase::STATUS_RECEIVED : Purchase::STATUS_RECEIVED_PARTIALLY;
            $purchase->update(['status' => $status]);
        });

        if (request()->ajax() || request()->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Penerimaan berhasil disetujui dan stok telah diperbarui.',
            ]);
        }

        toast('Penerimaan berhasil disetujui dan stok telah diperbarui.', 'success');
        return redirect()->back();
    }

    /**
     * Reject a receiving.
     */
    public function rejectReceiving(Request $request, ReceivedNote $receivedNote): RedirectResponse
    {
        abort_if(Gate::denies('purchaseReceivings.approval'), 403);

        if (!$receivedNote->isPending()) {
            toast('Penerimaan ini sudah diproses sebelumnya.', 'error');
            return redirect()->back();
        }

        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ], [
            'rejection_reason.required' => 'Alasan penolakan wajib diisi.',
        ]);

        $receivedNote->update([
            'status' => ReceivedNote::STATUS_REJECTED,
            'rejection_reason' => $request->rejection_reason,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        toast('Penerimaan berhasil ditolak.', 'warning');
        return redirect()->back();
    }
}
