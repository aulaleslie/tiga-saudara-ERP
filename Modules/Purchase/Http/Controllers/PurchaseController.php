<?php

namespace Modules\Purchase\Http\Controllers;

use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
use App\Services\SerialNumberHistoryService;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Purchase\DataTables\PurchasePaymentsDataTable;
use Modules\Purchase\DataTables\PurchaseReceivingsDataTable;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Setting\Entities\Setting;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Http\Requests\StorePurchaseRequest;
use Modules\Purchase\Http\Requests\UpdatePurchaseRequest;
use Modules\Purchase\Services\PurchaseNormalizer;
use Modules\Purchase\Services\PurchaseReceivingCompletionService;
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
        abort_unless(Gate::any(['purchases.receive.access', 'purchases.receive']), 403);

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
        abort_unless(Gate::any(['purchases.receive.access', 'purchases.receive']), 403);

        return view('purchase::receiving.list');
    }



    public function createAlpine(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('purchases.create'), 403);

        // Get data for Alpine.js form
        $paymentTerms = PaymentTerm::all();
        $suppliers = Supplier::all();
        $isPkp = (bool) (Setting::query()->whereKey((int) session('setting_id'))->value('is_pkp') ?? false);
        $taxes = \Modules\Setting\Entities\Tax::all();
        $categories = \Modules\Product\Entities\Category::all();
        $brands = \Modules\Product\Entities\Brand::all();
        $units = \Modules\Setting\Entities\Unit::all();
        $isPkp = (bool) (Setting::query()->whereKey((int) session('setting_id'))->value('is_pkp') ?? false);
        $idempotencyToken = (string) Str::uuid();

        return view('purchase::create-alpine', compact(
            'paymentTerms',
            'suppliers',
            'taxes',
            'categories',
            'brands',
            'units',
            'isPkp',
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
        $isPkp = (bool) (Setting::query()->whereKey((int) $setting_id)->value('is_pkp') ?? false);
        if ($request->has('cart')) {
            $cartItems = array_map(static function (array $cartItem): array {
                $product = Product::find($cartItem['product_id'] ?? null);

                return array_merge($cartItem, [
                    'product_name' => $product?->product_name ?? '',
                    'product_code' => $product?->product_code ?? '',
                    'price' => $cartItem['unit_price'] ?? 0,
                ]);
            }, (array) $cartItems);
        }

        if ($isPkp) {
            if ($request->has('cart')) {
                foreach ((array) $request->cart as $index => $cartItem) {
                    if (empty($cartItem['tax_id'])) {
                        return redirect()->back()
                            ->withErrors(['cart' => 'Semua produk wajib memilih pajak karena bisnis PKP.'])
                            ->withInput();
                    }
                }
            } else {
                foreach ($cartItems as $cartItem) {
                    if (empty($cartItem->options['product_tax'])) {
                        return redirect()->back()
                            ->withErrors(['cart' => 'Semua produk wajib memilih pajak karena bisnis PKP.'])
                            ->withInput();
                    }
                }
            }
        }

        DB::beginTransaction(); // Start the transaction manually
        try {
            $normalizedPurchase = app(PurchaseNormalizer::class)->normalize([
                'tax_id' => $request->tax_id,
                'tax_percentage' => 0,
                'discount_percentage' => $request->discount_percentage ?? 0,
                'discount_amount' => $request->discount_amount ?? 0,
                'shipping_amount' => $request->shipping_amount ?? $request->shipping ?? 0,
                'paid_amount' => 0,
            ], $cartItems, $isPkp);
            $header = $normalizedPurchase['header'];

            // Create the purchase record
            $purchase = Purchase::create([
                'date' => $request->date,
                'due_date' => $request->due_date,
                'supplier_id' => $request->supplier_id,
                'supplier_purchase_number' => $request->supplier_purchase_number,
                'tax_ref_no' => $request->tax_ref_no,
                'tax_id' => $header['tax_id'],
                'tax_percentage' => $header['tax_percentage'],
                'tax_amount' => $header['tax_amount'],
                'discount_percentage' => $header['discount_percentage'],
                'discount_amount' => $header['discount_amount'],
                'shipping_amount' => $header['shipping_amount'],
                'total_amount' => $header['total_amount'],
                'due_amount' => $header['due_amount'],
                'status' => Purchase::STATUS_DRAFTED,
                'payment_status' => 'Unpaid',
                'payment_term_id' => $request->payment_term ?: PaymentTerm::defaultCodTermId(),
                'note' => $request->note,
                'setting_id' => $setting_id,
                'paid_amount' => 0.0,
                'is_tax_included' => $request->is_tax_included ?? true,
                'payment_method' => '',
            ]);

            foreach ($normalizedPurchase['details'] as $detail) {
                PurchaseDetail::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $detail['product_id'],
                    'product_name' => $detail['product_name'],
                    'product_code' => $detail['product_code'],
                    'quantity' => $detail['quantity'],
                    'unit_price' => $detail['unit_price'],
                    'price' => $detail['price'],
                    'product_discount_type' => $detail['product_discount_type'],
                    'product_discount_amount' => $detail['product_discount_amount'],
                    'sub_total' => $detail['sub_total'],
                    'product_tax_amount' => $detail['product_tax_amount'],
                    'tax_id' => $detail['tax_id'],
                ]);
            }

            if (! $request->has('cart')) {
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

        $purchase->load([
            'reportingDateAudits.actor',
            'purchaseDetails.uomNormalizationLines.batch.oldBaseUnit',
            'purchaseDetails.uomNormalizationLines.batch.newBaseUnit',
            'purchaseDetails.uomNormalizationLines.batch.legacyBaseUnit',
        ]);

        $supplier = Supplier::findOrFail($purchase->supplier_id);

        $receivedNotes = ReceivedNote::where('po_id', $purchase->id)
            ->with([
                'purchase',
                'location',
                'receivedNoteDetails.purchaseDetail',
                'receivedNoteDetails.productSerialNumbers',
                'receivedNoteDetails.uomNormalizationLines.batch.oldBaseUnit',
                'receivedNoteDetails.uomNormalizationLines.batch.newBaseUnit',
                'receivedNoteDetails.uomNormalizationLines.batch.legacyBaseUnit',
            ])
            ->get();

        // Fetch all received detail IDs for this purchase to find related returned serials
        $receivedDetailIds = $receivedNotes->flatMap->receivedNoteDetails->pluck('id');

        $resolver = new \Modules\Purchase\Services\ReturnedSerialNumberResolver();
        $returnedSerials = $resolver->resolveForPurchase($purchase->id, $receivedDetailIds);
        
        $allDetails = $receivedNotes->flatMap->receivedNoteDetails;
        $resolver->mapToDetails($allDetails, $returnedSerials);

        $normBatches = app(\Modules\Purchase\Services\PurchaseNormalizationHistoryQueryService::class)
            ->getExecutedBatchesForPurchase($purchase);

        return $dataTable->with(['purchase_id' => $purchase->id])
            ->render('purchase::show', compact('purchase', 'supplier', 'receivedNotes', 'normBatches'));
    }

    public function storeAttachments(Request $request, Purchase $purchase): RedirectResponse
    {
        abort_if(Gate::denies('purchases.update'), 403);
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
        abort_if(Gate::denies('purchases.update'), 403);
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
        abort_if(Gate::denies('purchases.update'), 403);

        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $editMode = $purchase->resolveEditMode();
        if ($editMode === Purchase::EDIT_MODE_NONE) {
            abort(403, 'Anda tidak memiliki akses untuk mengubah pembelian ini pada status saat ini.');
        }


        // Filter PaymentTerms by the setting_id
        $paymentTerms = PaymentTerm::all();
        $suppliers = Supplier::all();
        $isPkp = (bool) (Setting::query()->whereKey((int) session('setting_id'))->value('is_pkp') ?? false);

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

            $normalizedSubTotal = $isPkp ? (float) $purchase_detail->sub_total : (float) $subtotal_before_tax;
            $normalizedTaxAmount = $isPkp ? (float) $purchase_detail->product_tax_amount : 0.0;
            $normalizedTaxId = $isPkp ? $purchase_detail->tax_id : null;

            $cart->add([
                'id' => $purchase_detail->product_id,
                'name' => $purchase_detail->product_name,
                'qty' => $purchase_detail->quantity,
                'price' => $purchase_detail->price,
                'weight' => 1,
                'options' => [
                    'product_discount' => $purchase_detail->product_discount_amount,
                    'product_discount_type' => $purchase_detail->product_discount_type,
                    'sub_total' => $normalizedSubTotal,
                    'code' => $purchase_detail->product_code,
                    'stock' => Product::findOrFail($purchase_detail->product_id)->product_quantity,
                    'product_tax' => $normalizedTaxId,
                    'unit_price' => $purchase_detail->unit_price,
                    'sub_total_before_tax' => $subtotal_before_tax,
                    'product_tax_amount' => $normalizedTaxAmount,
                ]
            ]);
        }

        // Pass $paymentTerms to the view
        return view('purchase::edit', compact('purchase', 'paymentTerms', 'suppliers', 'editMode'));
    }

    public function update(UpdatePurchaseRequest $request, Purchase $purchase)
    {
        abort_if(Gate::denies('purchases.update'), 403);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $editMode = $purchase->resolveEditMode();
        if ($editMode === Purchase::EDIT_MODE_NONE) {
            abort(403, 'Anda tidak memiliki akses untuk memperbarui pembelian ini pada status saat ini.');
        }

        // This endpoint's persistence deletes and recreates purchase_details,
        // which would cascade-delete received_note_details. It cannot serve a
        // post-receipt edit safely, so monetary-only documents are refused here
        // and must go through the restricted Livewire path.
        if ($editMode === Purchase::EDIT_MODE_MONETARY_ONLY) {
            abort(422, 'Pembelian yang sudah diterima hanya dapat diubah melalui mode edit moneter.');
        }

        Log::info('Cart count at start of update:', ['count' => Cart::instance('purchase')->count()]);
        if (Cart::instance('purchase')->count() == 0) {
            return redirect()->back()->withErrors(['cart' => 'Daftar Produk tidak boleh kosong.'])->withInput();
        }

        DB::transaction(function () use ($request, $purchase) {
            $isPkp = (bool) (Setting::query()->whereKey((int) session('setting_id'))->value('is_pkp') ?? false);
            $cartItems = Cart::instance('purchase')->content();
            $normalizedPurchase = app(PurchaseNormalizer::class)->normalize([
                'tax_id' => $request->tax_id ?? $purchase->tax_id,
                'tax_percentage' => $request->tax_percentage ?? $purchase->tax_percentage,
                'discount_percentage' => $request->discount_percentage ?? $purchase->discount_percentage,
                'discount_amount' => $request->discount_amount ?? $purchase->discount_amount,
                'shipping_amount' => $request->shipping_amount ?? $purchase->shipping_amount,
                'paid_amount' => $request->paid_amount ?? $purchase->paid_amount,
            ], $cartItems, $isPkp);
            $header = $normalizedPurchase['header'];

            // Fields to update, only if new values are passed in the request
            $updateData = array_filter([
                'date' => $request->filled('date') && $request->date !== $purchase->date ? $request->date : null,
                'due_date' => $request->filled('due_date') && $request->due_date !== $purchase->due_date ? $request->due_date : null,
                'supplier_id' => $request->filled('supplier_id') && $request->supplier_id !== $purchase->supplier_id ? $request->supplier_id : null,
                'tax_id' => $header['tax_id'] !== $purchase->tax_id ? $header['tax_id'] : null,
                'tax_percentage' => $header['tax_percentage'] != $purchase->tax_percentage ? $header['tax_percentage'] : null,
                'tax_amount' => $header['tax_amount'] != $purchase->tax_amount ? $header['tax_amount'] : null,
                'discount_percentage' => $header['discount_percentage'] != $purchase->discount_percentage ? $header['discount_percentage'] : null,
                'discount_amount' => $header['discount_amount'] != $purchase->discount_amount ? $header['discount_amount'] : null,
                'shipping_amount' => $header['shipping_amount'] != $purchase->shipping_amount ? $header['shipping_amount'] : null,
                'paid_amount' => $request->filled('paid_amount') && $request->paid_amount != $purchase->paid_amount ? $request->paid_amount : null,
                'total_amount' => $header['total_amount'] != $purchase->total_amount ? $header['total_amount'] : null,
                'due_amount' => $header['due_amount'] != $purchase->due_amount ? $header['due_amount'] : null,
                'status' => $request->filled('status') && $request->status !== $purchase->status ? $request->status : null,
                'payment_method' => $request->filled('payment_method') && $request->payment_method !== $purchase->payment_method ? $request->payment_method : null,
                'note' => $request->filled('note') && $request->note !== $purchase->note ? $request->note : null,
                'payment_term_id' => $request->filled('payment_term') && $request->payment_term != $purchase->payment_term_id ? $request->payment_term : null,
            ], function ($value) {
                return $value !== null;
            });

            if ($request->has('supplier_purchase_number') && $request->supplier_purchase_number !== $purchase->supplier_purchase_number) {
                $updateData['supplier_purchase_number'] = $request->supplier_purchase_number;
            }

            if ($request->has('tax_ref_no') && $request->tax_ref_no !== $purchase->tax_ref_no) {
                $updateData['tax_ref_no'] = $request->tax_ref_no;
            }

            if ($header['tax_id'] === null && $purchase->tax_id !== null) {
                $updateData['tax_id'] = null;
            }

            if (!empty($updateData)) {
                // Update the purchase record
                $purchase->update($updateData);
            }

            // Clear existing purchase details
            $purchase->purchaseDetails()->delete();

            // Re-add updated cart items
            foreach ($normalizedPurchase['details'] as $detail) {
                PurchaseDetail::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $detail['product_id'],
                    'product_name' => $detail['product_name'],
                    'product_code' => $detail['product_code'],
                    'quantity' => $detail['quantity'],
                    'unit_price' => $detail['unit_price'],
                    'price' => $detail['price'],
                    'product_discount_type' => $detail['product_discount_type'],
                    'product_discount_amount' => $detail['product_discount_amount'],
                    'sub_total' => $detail['sub_total'],
                    'product_tax_amount' => $detail['product_tax_amount'],
                    'tax_id' => $detail['tax_id'],
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
        abort_unless(Gate::any(['purchases.update', 'purchases.approval']), 403);
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

            $notificationService = app(\App\Services\Notification\DocumentNotificationService::class);
            if ($validated['status'] === Purchase::STATUS_WAITING_APPROVAL) {
                $notificationService->notifyApprovalNeeded($purchase, $purchase->reference, $purchase->setting_id);
                $notificationService->resolveRevision($purchase);
            } elseif ($validated['status'] === Purchase::STATUS_REJECTED) {
                $notificationService->notifyRevisionNeeded($purchase, $purchase->reference, $purchase->setting_id, $validated['rejection_note'] ?? '');
                $notificationService->resolveApproval($purchase);
            } else {
                $notificationService->resolveApproval($purchase);
                $notificationService->resolveRevision($purchase);
            }

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
            'location_id' => [
                'required',
                'integer',
                'exists:locations,id',
                function ($attribute, $value, $fail) use ($purchase) {
                    $loc = Location::find($value);
                    if ($loc && ($loc->setting_id !== $purchase->setting_id || $loc->is_consignment)) {
                        $fail('Lokasi yang dipilih tidak valid atau merupakan lokasi konsinyasi.');
                    }
                }
            ],
        ], [
            'location_id.required' => 'Lokasi wajib dipilih.',
        ], [
            'location_id' => 'Lokasi',
            'external_delivery_number' => 'Nomor Surat Jalan Supplier'
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        // Prepare to check duplicates
        $duplicateErrors = [];
        $checkedSerials = []; // To track duplicates within the request itself

        // Get relevant purchase details to map detail_id -> product_id
        $inputtedDetailIds = array_keys($data['serial_numbers'] ?? []);
        $details = PurchaseDetail::whereIn('id', $inputtedDetailIds)->get()->keyBy('id');

        foreach ($data['serial_numbers'] ?? [] as $detailId => $serials) {
            $detail = $details->get($detailId);
            if (!$detail) continue;

            $productId = $detail->product_id;

            foreach ($serials as $serial) {
                if (!$serial) continue;

                // 1. Check for duplicates within the current request (same product)
                $key = $productId . '|' . $serial;
                if (isset($checkedSerials[$key])) {
                    $duplicateErrors[] = "Serial number '$serial' digandakan dalam input untuk produk yang sama.";
                    continue;
                }
                $checkedSerials[$key] = true;

                // 2. Check against committed serials for this product
                // Allow reuse if status is RETURNED
                $existing = ProductSerialNumber::where('product_id', $productId)
                    ->where('serial_number', $serial)
                    ->first();

                if ($existing) {
                    if (!in_array($existing->status, [ProductSerialNumber::STATUS_RETURNED, ProductSerialNumber::STATUS_SOLD], true)) {
                        $duplicateErrors[] = "Serial number '$serial' sudah ada untuk produk ini (Status: {$existing->status}).";
                    }
                }
            }
        }

        if (!empty($duplicateErrors)) {
            return redirect()->back()->withErrors([
                'serial_numbers' => implode(' ', array_unique($duplicateErrors)),
            ])->withInput();
        }

        try {
            DB::transaction(function () use ($data, $purchase) {
                $purchase = Purchase::where('id', $purchase->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($purchase->status === Purchase::STATUS_RECEIVED) {
                    throw new \Exception('Pembelian ini sudah ditutup. Tidak dapat menerima pengiriman lebih lanjut.');
                }

                // Create a ReceivedNote with PENDING status
                $receivedNote = ReceivedNote::create([
                    'po_id' => $purchase->id,
                    'external_delivery_number' => $data['external_delivery_number'] ?? null,
                    'date' => now(),
                    'location_id' => $data['location_id'],
                    'status' => ReceivedNote::STATUS_PENDING,
                ]);

                app(\App\Services\Notification\DocumentNotificationService::class)
                    ->notifyApprovalNeeded($receivedNote, 'Penerimaan ' . $purchase->reference, $purchase->setting_id, $data['location_id']);

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
                            'note' => $data['notes'][$detail->id] ?? null,
                        ]);

                        // Serial numbers will be committed to product_serial_numbers table on approval
                    }
                }

                // Stock increment and purchase status update are now done on approval
            });

            toast('Penerimaan berhasil disimpan dan menunggu persetujuan.', 'success');
            return redirect()->route('purchases.receiving.index')->with('message', 'Penerimaan berhasil disimpan. Menunggu persetujuan.');
        } catch (\Exception $e) {
            toast($e->getMessage(), 'error');
            return redirect()->back()->withInput();
        }
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
        abort_if(Gate::denies('purchases.receive.access'), 403);

        $purchase = Purchase::withArchived()->findOrFail($purchase_id);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $receivedNotes = ReceivedNote::where('po_id', $purchase->id)
            ->with([
                'purchase',
                'location',
                'receivedNoteDetails.purchaseDetail',
                'receivedNoteDetails.productSerialNumbers',
                'receivedNoteDetails.uomNormalizationLines.batch.oldBaseUnit',
                'receivedNoteDetails.uomNormalizationLines.batch.newBaseUnit',
                'receivedNoteDetails.uomNormalizationLines.batch.legacyBaseUnit',
            ])
            ->get();

        return $dataTable->render('purchase::receivings.index', compact('purchase', 'receivedNotes'));
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
    public function approveReceiving(ReceivedNote $receivedNote): RedirectResponse|\Illuminate\Http\JsonResponse|\Illuminate\Http\Response
    {
        abort_if(Gate::denies('purchases.receive.approval'), 403);

        $purchase = $receivedNote->purchase;

        if ($purchase->status === Purchase::STATUS_RECEIVED) {
            if (request()->ajax() || request()->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'purchase_closed',
                    'message' => 'Pembelian ini sudah ditutup. Tidak dapat menyetujui penerimaan lebih lanjut.',
                ], 422);
            }
            toast('Pembelian ini sudah ditutup. Tidak dapat menyetujui penerimaan lebih lanjut.', 'error');
            return redirect()->back();
        }

        // Initial check
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

        $purchase = $receivedNote->purchase;
        $lock = Cache::lock('purchase_approval_' . $purchase->id, 10);

        try {
            $callback = function () use ($receivedNote, $purchase) {
                // Re-check status inside lock to handle race conditions
                if (!$receivedNote->fresh()->isPending()) {
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

                    // Revalidate purchase status under lock
                    $purchase = Purchase::query()
                        ->where('id', $purchase->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($purchase->status === Purchase::STATUS_RECEIVED) {
                        throw new \Exception('Pembelian ini sudah ditutup. Tidak dapat menyetujui penerimaan lebih lanjut.');
                    }

                    $receivingLocation = Location::query()->find($receivedNote->location_id);
                    if (!$receivingLocation || $receivingLocation->setting_id !== $purchase->setting_id || $receivingLocation->is_consignment) {
                        throw new \Exception('Lokasi penerimaan tidak valid atau merupakan lokasi konsinyasi.');
                    }

                    $settingLocationIds = Location::where('setting_id', $purchase->setting_id)->pluck('id');
                    
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
                            $previous_quantity_for_setting = ProductStock::where('product_id', $product->id)
                                ->whereIn('location_id', $settingLocationIds)
                                ->sum('quantity');

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
                            $after_quantity_for_setting = $previous_quantity_for_setting + $receivedQuantity;

                            app(\App\Services\Notification\StockNotificationService::class)
                                ->checkGlobalStock($product, $previous_quantity, $after_quantity);
                            app(\App\Services\Notification\StockNotificationService::class)
                                ->checkLocationStock($productStock, $previous_quantity_at_location, $after_quantity_at_location);

                            // Update Last Purchase Price
                            $product->update(['last_purchase_price' => $purchaseDetail->price]);

                            // Calculate unit DPP cost for average price calculation
                            $dppUnitCost = \Modules\Purchase\Services\PurchaseCostHelper::calculateUnitCost(
                                $purchaseDetail->sub_total,
                                $purchaseDetail->product_tax_amount,
                                $purchaseDetail->product_discount_amount,
                                $purchaseDetail->quantity
                            );

                            // Update legacy product price
                            $this->updateAveragePurchasePrice($product, $dppUnitCost, $receivedQuantity);

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
                                $newTotalValue = $dppUnitCost * $receivedQuantity;
                                $newTotalQuantity = $previousQty + $receivedQuantity;

                                $newAveragePrice = $newTotalQuantity > 0
                                    ? ($currentTotalValue + $newTotalValue) / $newTotalQuantity
                                    : $dppUnitCost;

                                $productPrice->update([
                                    'last_purchase_price' => $purchaseDetail->price,
                                    'average_purchase_price' => $newAveragePrice,
                                ]);

                                app(\Modules\Product\Services\ProductAveragePriceSynchronizer::class)
                                    ->syncAveragePurchasePrice($product->id, $newAveragePrice);
                            }

                            // Insert Transaction Log
                            Transaction::create([
                                'product_id' => $purchaseDetail->product_id,
                                'setting_id' => session('setting_id'),
                                'quantity' => $receivedQuantity,
                                'current_quantity' => $after_quantity_for_setting,
                                'broken_quantity' => 0,
                                'location_id' => $receivedNote->location_id,
                                'user_id' => auth()->id(),
                                'reason' => 'Diterima dari Pembelian #' . $purchase->reference . ' (Disetujui)',
                                'type' => 'BUY',
                                'previous_quantity' => $previous_quantity_for_setting,
                                'after_quantity' => $after_quantity_for_setting,
                                'previous_quantity_at_location' => $previous_quantity_at_location,
                                'after_quantity_at_location' => $after_quantity_at_location,
                                'quantity_non_tax' => $purchaseDetail->tax_id ? 0 : $receivedQuantity,
                                'quantity_tax' => $purchaseDetail->tax_id ? $receivedQuantity : 0,
                                'broken_quantity_non_tax' => 0,
                                'broken_quantity_tax' => 0,
                                'received_note_detail_id' => $detail->id,
                            ]);

                            // Commit pending serial numbers to product_serial_numbers table
                            if (!empty($detail->pending_serial_numbers) && is_array($detail->pending_serial_numbers)) {
                                foreach ($detail->pending_serial_numbers as $serialNumber) {
                                    
                                    $existingSerial = ProductSerialNumber::where('product_id', $purchaseDetail->product_id)
                                        ->where('serial_number', $serialNumber)
                                        ->first();
                                        
                                    if ($existingSerial) {
                                        if (in_array($existingSerial->status, [ProductSerialNumber::STATUS_RETURNED, ProductSerialNumber::STATUS_SOLD], true)) {
                                            // Reactivate existing serial
                                            $existingSerial->update([
                                                'status' => ProductSerialNumber::STATUS_ACTIVE,
                                                'location_id' => $receivedNote->location_id,
                                                'tax_id' => $purchaseDetail->tax_id,
                                                // 'received_note_detail_id' => $detail->id, // Legacy: Stop updating to preserve history
                                                'purchase_return_id' => null,
                                                'is_in_return_process' => false,
                                            ]);
                                            $existingSerial->receivedNoteDetails()->syncWithoutDetaching([$detail->id]);
                                            $serialRecord = $existingSerial;
                                        } else {
                                            throw new Exception("Serial number {$serialNumber} sudah ada dan statusnya bukan RETURNED atau SOLD.");
                                        }
                                    } else {
                                        $serialRecord = ProductSerialNumber::create([
                                            'product_id' => $purchaseDetail->product_id,
                                            'location_id' => $receivedNote->location_id,
                                            'serial_number' => $serialNumber,
                                            'tax_id' => $purchaseDetail->tax_id,
                                            // 'received_note_detail_id' => $detail->id, // Legacy: Stop updating
                                        ]);
                                        $serialRecord->receivedNoteDetails()->attach($detail->id);
                                    }

                                    // Record RECEIVED history event
                                    SerialNumberHistoryService::record(
                                        $serialRecord->id,
                                        SerialNumberHistory::EVENT_RECEIVED,
                                        $receivedNote->location_id,
                                        $detail
                                    );
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

                    app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($receivedNote);
                    app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($receivedNote);

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
            };

            $result = $lock->get($callback);

            if ($result === false) {
                if (request()->ajax() || request()->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'error' => 'conflict',
                        'message' => 'Pembelian ini sedang diproses oleh pengguna lain. Silakan coba lagi.',
                    ], 409);
                }
                return response('Pembelian ini sedang diproses oleh pengguna lain. Silakan coba lagi.', 409);
            }

            return $result;

        } catch (\Throwable $e) {
            // Handle serial number conflict
            if (Str::contains($e->getMessage(), 'sudah ada dan statusnya bukan RETURNED')) {
                $message = $e->getMessage();
                if (request()->ajax() || request()->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'error' => 'conflict',
                        'message' => $message,
                    ], 409);
                }
                return response($message, 409);
            }

            // Ensure lock is released if it was acquired manually, but get() helper releases it automatically.
            // But we should rethrow to not silence errors.
            throw $e;
        }
    }

    /**
     * Reject a receiving.
     */
    public function rejectReceiving(Request $request, ReceivedNote $receivedNote): RedirectResponse
    {
        abort_if(Gate::denies('purchases.receive.approval'), 403);

        if (!$receivedNote->isPending()) {
            toast('Penerimaan ini sudah diproses sebelumnya.', 'error');
            return redirect()->back();
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ], [
            'rejection_reason.required' => 'Alasan penolakan wajib diisi.',
        ]);

        $receivedNote->update([
            'status' => ReceivedNote::STATUS_REJECTED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejection_reason' => $validated['rejection_reason']
        ]);

        app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($receivedNote);
        app(\App\Services\Notification\DocumentNotificationService::class)->notifyRevisionNeeded(
            $receivedNote, 
            'Penerimaan ' . $receivedNote->purchase->reference, 
            $receivedNote->purchase->setting_id, 
            $validated['rejection_reason'], 
            $receivedNote->location_id
        );

        toast('Penerimaan ditolak.', 'warning');
        return redirect()->back();
    }

    public function previewReceivingCompletion(Purchase $purchase)
    {
        abort_if(Gate::denies('purchases.receive.complete_shortfall'), 403);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        try {
            $preview = app(PurchaseReceivingCompletionService::class)->preview($purchase);
            return response()->json(['success' => true, 'preview' => $preview]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function submitReceivingCompletion(Request $request, Purchase $purchase)
    {
        abort_if(Gate::denies('purchases.receive.complete_shortfall'), 403);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ], [
            'reason.required' => 'Alasan kekurangan pemasok wajib diisi.',
        ]);

        try {
            $completion = app(PurchaseReceivingCompletionService::class)->complete(
                $purchase,
                $validated['reason'],
                auth()->id()
            );

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Penerimaan berhasil diselesaikan.',
                    'purchase_id' => $purchase->id,
                ]);
            }

            toast('Penerimaan berhasil diselesaikan.', 'success');
            return redirect()->route('purchases.show', $purchase);
        } catch (Exception $e) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => $e->getMessage(),
                ], 422);
            }

            toast($e->getMessage(), 'error');
            return redirect()->back();
        }
    }
}
