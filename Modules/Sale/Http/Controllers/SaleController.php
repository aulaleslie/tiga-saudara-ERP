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
use App\Services\SerialNumberHistoryService;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
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
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\Sale\Http\Requests\StoreSaleRequest;
use Modules\Sale\Http\Requests\UpdateSaleRequest;
use Modules\Sale\Support\PendingDispatchSerialGuard;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Sale\Services\SaleCartAggregator;
use Modules\Sale\Services\SaleService;
use Modules\Sale\Services\SaleSerialDisplayResolver;

class SaleController extends Controller
{
    protected $saleService;

    public function __construct(SaleService $saleService)
    {
        $this->saleService = $saleService;
    }

    public function index(SalesDataTable $dataTable)
    {
        abort_if(Gate::denies('sales.access'), 403);

        return $dataTable->render('sale::index');
    }

    /**
     * Display the sales dispatch landing page.
     */
    public function dispatchIndex(Request $request): Factory|Application|View
    {
        abort_unless(Gate::any(['salesDispatches.access', 'sales.dispatch']), 403);

        $sale = null;

        if ($request->filled('sale_id')) {
            $sale = Sale::withArchived()->findOrFail($request->input('sale_id'));
            $this->ensureSaleBelongsToCurrentSetting($sale);
        }

        return view('sale::dispatch.filtered-index', compact('sale'));
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

        if (Cart::instance('sale')->count() == 0) {
            return redirect()->back()
                ->withErrors(['cart' => 'Daftar Produk tidak boleh kosong.'])
                ->withInput();
        }

        try {
            $settingId = (int) session('setting_id');
            $isPkp = (bool) (Setting::query()->whereKey($settingId)->value('is_pkp') ?? false);

            if ($isPkp) {
                foreach (Cart::instance('sale')->content() as $item) {
                    if (empty($item->options['product_tax'])) {
                        return redirect()->back()
                            ->withErrors(['cart' => 'Semua produk wajib memilih pajak karena bisnis PKP.'])
                            ->withInput();
                    }
                }
            }

            $data = $request->validated();
            $data['setting_id'] = $settingId;
            $data['status'] = Sale::STATUS_DRAFTED;
            $data['payment_status'] = 'Unpaid';
            $data['paid_amount'] = 0;
            $data['due_amount'] = $request->total_amount;
            $data['payment_term_id'] = $data['payment_term_id'] ?: PaymentTerm::defaultCodTermId();
            
            $this->saleService->createSale($data, Cart::instance('sale')->content());

            Cart::instance('sale')->destroy();
            toast('Pembelian Ditambahkan!', 'success');
            return redirect()->route('sales.index');
        } catch (Exception $e) {
            Log::error('Sale Creation Failed:', ['error' => $e->getMessage()]);
            return redirect()->back()->withErrors(['cart' => $e->getMessage()])->withInput();
        }
    }


    public function show(Sale $sale, SalePaymentsDataTable $dataTable)
    {
        Log::info('SaleController::show reached', ['id' => $sale->id, 'setting_id' => $sale->setting_id, 'session_setting_id' => session('setting_id')]);
        abort_if(Gate::denies('sales.show'), 403);

        $this->ensureSaleBelongsToCurrentSetting($sale);
        Log::info('SaleController::show: passed ensureSaleBelongsToCurrentSetting');

        $sale->load([
            'saleDetails.bundleItems',
            'bundleItems', // Standalone bundle items
            'saleDetails.tax',
            'saleDispatches.details',
            'saleDispatches.details.product',
            'saleDispatches.details.location',
            'salePayments.paymentMethod',
            'reportingDateAudits.actor',
        ]);
        Log::info('SaleController::show: relationships loaded');

        try {
            $customer = Customer::findOrFail($sale->customer_id);
            Log::info('SaleController::show: customer loaded', ['customer_id' => $customer->id]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('SaleController::show: Customer not found', [
                'customer_id' => $sale->customer_id,
                'sale_id' => $sale->id,
                'sale_setting_id' => $sale->setting_id,
                'session_setting_id' => session('setting_id')
            ]);
            throw $e;
        }

        // optional: if you want a clean var for the view
        $dispatches = $sale->saleDispatches;
        app(SaleSerialDisplayResolver::class)->annotateDispatchesForSale($sale);
        Log::info('SaleController::show: dispatches annotated');

        return $dataTable
            ->with(['sale_id' => $sale->id])
            ->render('sale::show', compact('sale', 'customer', 'dispatches'));
    }


    public function edit(Sale $sale)
    {
        abort_if(Gate::denies('sales.edit'), 403);

        $this->ensureSaleBelongsToCurrentSetting($sale);

        $editMode = $sale->resolveEditMode();
        if ($editMode === Sale::EDIT_MODE_NONE) {
            abort(403, 'Anda tidak memiliki akses untuk mengubah penjualan ini pada status saat ini.');
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
                        'quantity_per_bundle' => $saleDetail->quantity > 0 ? (float) ($bundleItem->quantity / $saleDetail->quantity) : (float) $bundleItem->quantity,
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

        $editMode = $sale->resolveEditMode();
        if ($editMode === Sale::EDIT_MODE_NONE) {
            abort(403, 'Anda tidak memiliki akses untuk memperbarui penjualan ini pada status saat ini.');
        }

        // SaleService::updateSale() deletes and recreates sale_details and
        // regenerates cost snapshots, so it cannot serve a post-dispatch edit.
        // Monetary-only documents are refused here and must go through the
        // restricted Livewire path.
        if ($editMode === Sale::EDIT_MODE_MONETARY_ONLY) {
            abort(422, 'Penjualan yang sudah dikirim hanya dapat diubah melalui mode edit moneter.');
        }

        try {
            $data = $request->validated();
            $data['tax_amount'] = round((float) Cart::instance('sale')->tax(), 2);
            $data['discount_amount'] = round((float) Cart::instance('sale')->discount(), 2);

            $this->saleService->updateSale($sale, $data, Cart::instance('sale')->content());

            Cart::instance('sale')->destroy();
            toast('Penjualan Diperbaharui!', 'info');
            return redirect()->route('sales.index');
        } catch (Exception $e) {
            Log::error('Sale Update Failed:', ['error' => $e->getMessage()]);
            return redirect()->back()->withErrors(['cart' => $e->getMessage()])->withInput();
        }
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

            $notificationService = app(\App\Services\Notification\DocumentNotificationService::class);
            if ($validated['status'] === Sale::STATUS_WAITING_APPROVAL) {
                $notificationService->notifyApprovalNeeded($sale, $sale->reference, $sale->setting_id);
                $notificationService->resolveRevision($sale);
            } elseif ($validated['status'] === Sale::STATUS_REJECTED) {
                $notificationService->notifyRevisionNeeded($sale, $sale->reference, $sale->setting_id, $request->input('rejection_reason', ''));
                $notificationService->resolveApproval($sale);
            } else {
                $notificationService->resolveApproval($sale);
                $notificationService->resolveRevision($sale);
            }

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

        // Bulk-load all products needed for dispatch aggregation
        $productIds = $sale->saleDetails->pluck('product_id')
            ->merge(SaleBundleItem::where('sale_id', $sale->id)->pluck('product_id'))
            ->unique()
            ->values()
            ->all();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        // Bulk-load bundle items with saleDetail relationship to resolve inherited_tax_id
        $bundleItems = SaleBundleItem::where('sale_id', $sale->id)->with('saleDetail')->get();

        // Bulk-load all taxes needed for dispatch aggregation
        $taxIds = $sale->saleDetails->pluck('tax_id')
            ->merge($bundleItems->map(fn($item) => $item->inherited_tax_id))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $taxes = Tax::whereIn('id', $taxIds)->get()->keyBy('id');

        // Aggregate products from sale_details (both stock-managed and non-stock products)
        foreach ($sale->saleDetails as $detail) {
            $product = $products->get($detail->product_id);
            if (!$product) {
                continue;
            }

            $pid = $detail->product_id;
            $taxId = $detail->tax_id; // assumed to exist on sale detail
            $bundleId = 0; // Standard items use 0 as bundle_id for keying
            $key = $pid . '-' . $taxId . '-' . $bundleId; // composite key for grouping

            if (!isset($aggregatedProducts[$key])) {
                // Retrieve tax to get tax_name (if tax_id exists)
                $tax = $taxId ? $taxes->get($taxId) : null;

                $aggregatedProducts[$key] = [
                    'product_id' => $pid,
                    'tax_id' => $taxId,
                    'bundle_id' => $bundleId,
                    'product_name' => $detail->product_name,
                    'product_code' => $product ? $product->product_code : null,
                    'tax_name' => $tax ? $tax->name : null,
                    'is_tax_included' => $sale->is_tax_included,
                    'total_quantity' => 0,
                    'dispatched_quantity' => 0,
                    'serial_number_required' => $product ? $product->serial_number_required : false,
                    'is_inventory_managed' => $product->stock_managed !== false,
                ];
            }
            $aggregatedProducts[$key]['total_quantity'] += $detail->quantity;
        }

        // Aggregate from bundle items (both stock-managed and non-stock components)
        foreach ($bundleItems as $bundleItem) {
            $product = $products->get($bundleItem->product_id);
            if (!$product) {
                continue;
            }

            $pid = $bundleItem->product_id;
            // Inherit tax context from parent sale detail
            $taxId = $bundleItem->inherited_tax_id;
            $bundleId = $bundleItem->bundle_id ?? 0;
            $key = $pid . '-' . $taxId . '-' . $bundleId;

            if (!isset($aggregatedProducts[$key])) {
                $tax = $taxId ? $taxes->get($taxId) : null;

                $aggregatedProducts[$key] = [
                    'product_id' => $pid,
                    'tax_id' => $taxId,
                    'bundle_id' => $bundleId,
                    'product_name' => $bundleItem->name,
                    'product_code' => $product ? $product->product_code : null,
                    'tax_name' => $tax ? $tax->name : null,
                    'is_tax_included' => $sale->is_tax_included,
                    'total_quantity' => 0,
                    'dispatched_quantity' => 0,
                    'serial_number_required' => $product ? $product->serial_number_required : false,
                    'is_inventory_managed' => $product->stock_managed !== false,
                ];
            }
            // Adjust quantity multiplication if needed.
            $aggregatedProducts[$key]['total_quantity'] += $bundleItem->quantity;
        }

        // Get already dispatched quantities for this sale (only APPROVED dispatches)
        $dispatchedDetails = DispatchDetail::whereHas('dispatch', function ($query) use ($sale) {
            $query->where('sale_id', $sale->id)
                  ->where('status', Dispatch::STATUS_APPROVED);
        })->get();

        foreach ($dispatchedDetails as $d) {
            $key = $d->product_id . '-' . $d->tax_id . '-' . ($d->bundle_id ?? 0);
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

            // Bulk-load all products needed for validation
            $productIds = $sale->saleDetails->pluck('product_id')
                ->merge(SaleBundleItem::where('sale_id', $sale->id)->pluck('product_id'))
                ->unique()
                ->values()
                ->all();
            $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

            $aggregated = [];
            foreach ($sale->saleDetails as $detail) {
                $product = $products->get($detail->product_id);
                if (!$product) {
                    continue;
                }

                $pid = $detail->product_id;
                $taxId = $detail->tax_id;
                $bundleId = 0;
                $key = $pid . '-' . $taxId . '-' . $bundleId;
                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'total_quantity' => 0,
                        'dispatched_quantity' => 0,
                        'is_inventory_managed' => $product->stock_managed !== false,
                    ];
                }
                $aggregated[$key]['total_quantity'] += (int) $detail->quantity;
            }

            // Aggregate from bundle items (both stock-managed and non-stock components)
            $bundleItems = SaleBundleItem::where('sale_id', $sale->id)->with('saleDetail')->get();
            foreach ($bundleItems as $bundleItem) {
                $product = $products->get($bundleItem->product_id);
                if (!$product) {
                    continue;
                }

                if (!$bundleItem->saleDetail) {
                    $validator->errors()->add('bundle_items', "Item bundle {$bundleItem->name} tidak memiliki referensi baris induk yang valid.");
                    continue;
                }

                $pid = $bundleItem->product_id;
                $taxId = $bundleItem->inherited_tax_id;
                $bundleId = $bundleItem->bundle_id ?? 0;
                $key = $pid . '-' . $taxId . '-' . $bundleId;
                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'total_quantity' => 0,
                        'dispatched_quantity' => 0,
                        'is_inventory_managed' => $product->stock_managed !== false,
                    ];
                }
                $aggregated[$key]['total_quantity'] += (int) $bundleItem->quantity;
            }

            // Also check for PENDING and APPROVED dispatches
            $currentDispatches = DispatchDetail::whereHas('dispatch', function ($query) use ($sale) {
                $query->where('sale_id', $sale->id)->whereIn('status', [Dispatch::STATUS_PENDING, Dispatch::STATUS_APPROVED]);
            })->get();

            foreach ($currentDispatches as $d) {
                $key = $d->product_id . '-' . $d->tax_id . '-' . ($d->bundle_id ?? 0);
                if (isset($aggregated[$key])) {
                    $aggregated[$key]['dispatched_quantity'] += (int) $d->dispatched_quantity;
                }
            }

            $hasAnyPositiveQuantity = false;
            foreach ($dispatchedQuantities as $compositeKey => $qty) {
                if ((int)$qty <= 0) continue;
                $hasAnyPositiveQuantity = true;

                $parts = explode('-', $compositeKey);
                if (count($parts) < 2) continue;

                $productId = $parts[0];
                $taxId = $parts[1];
                $bundleId = $parts[2] ?? 0;
                $product = $products->get((int)$productId);

                if (!$product) {
                    $validator->errors()->add("dispatchedQuantities.$compositeKey", "Produk tidak ditemukan.");
                    continue;
                }

                // Validate that the submitted composite key exists in authoritative demand
                if (!isset($aggregated[$compositeKey])) {
                    $validator->errors()->add("dispatchedQuantities.$compositeKey", "Kunci pengiriman tidak valid untuk penjualan ini.");
                    continue;
                }

                // Check remaining quantity (applies to both stock-managed and non-stock)
                $remaining = $aggregated[$compositeKey]['total_quantity'] - $aggregated[$compositeKey]['dispatched_quantity'];
                if ((int)$qty > $remaining) {
                    $validator->errors()->add("dispatchedQuantities.$compositeKey", "Jumlah kirim ({$qty}) melebihi sisa pesanan ({$remaining}).");
                }

                $isInventoryManaged = $aggregated[$compositeKey]['is_inventory_managed'];

                if ($isInventoryManaged) {
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

                            $submittedLocationId = (int) $locations[$serialNumber];
                            if (!in_array($submittedLocationId, $allowedLocationIds, true)) {
                                $validator->errors()->add("serialNumberLocations.$compositeKey", "Lokasi serial {$serialNumber} tidak valid untuk bisnis ini.");
                            }

                            // Verify serial status and tax
                            $snRecord = ProductSerialNumber::where('product_id', $productId)
                                ->where('serial_number', $serialNumber)
                                ->with('location')
                                ->first();

                            if (!$snRecord) {
                                $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Serial number {$serialNumber} tidak ditemukan di sistem.");
                            } else {
                                if (empty($snRecord->location_id) || !$snRecord->location) {
                                    $validator->errors()->add("serialNumberLocations.$compositeKey", "Lokasi serial {$serialNumber} tidak ditemukan di sistem.");
                                } else {
                                    $actualLocationId = (int) $snRecord->location_id;

                                    if (!in_array($actualLocationId, $allowedLocationIds, true)) {
                                        $validator->errors()->add("serialNumberLocations.$compositeKey", "Lokasi serial {$serialNumber} tidak valid untuk bisnis ini.");
                                    }

                                    if ($submittedLocationId !== $actualLocationId) {
                                        $validator->errors()->add("serialNumberLocations.$compositeKey", "Lokasi serial {$serialNumber} tidak sesuai dengan data sistem.");
                                    }
                                }

                                if ($snRecord->dispatch_detail_id) {
                                    $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Serial number {$serialNumber} sudah terpakai.");
                                }
                                if (strtoupper($snRecord->status) !== ProductSerialNumber::STATUS_ACTIVE) {
                                    $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Serial number {$serialNumber} tidak aktif.");
                                }

                                // Check for serials in PENDING dispatches
                                if (PendingDispatchSerialGuard::isReserved($serialNumber)) {
                                    $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Serial number {$serialNumber} sedang dalam proses pengiriman.");
                                }

                                // Tax validation
                                $isTaxedSaleItem = !empty($taxId) && (int)$taxId > 0;
                                if ($isTaxedSaleItem) {
                                    if (is_null($snRecord->tax_id)) {
                                        $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Serial number {$serialNumber} tidak memiliki status pajak (non-pajak), sehingga tidak dapat digunakan untuk penjualan berpajak.");
                                    }
                                } else {
                                    if (!is_null($snRecord->tax_id)) {
                                        $validator->errors()->add("selectedSerialNumbers.$compositeKey", "Serial number {$serialNumber} memiliki status pajak, sehingga tidak dapat digunakan untuk penjualan non-pajak.");
                                    }
                                }
                            }
                        }
                    } else {
                        // NON-SERIAL PRODUCT VALIDATION (stock-managed)
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
                } else {
                    // NON-STOCK PRODUCT VALIDATION: only quantity, no location/stock checks
                    // Non-stock items simply need a positive quantity, which was already validated above
                }
            }

            // Allow dispatches with just non-stock products; permit mixed and stock-only
            // but require at least one positive quantity
            if (!$hasAnyPositiveQuantity) {
                $validator->errors()->add('dispatchedQuantities', 'Pengiriman harus memiliki setidaknya satu produk dengan jumlah positif.');
            }
        });

        if ($validator->fails()) {
            Log::debug('Dispatch validation failed', [
                'errors' => $validator->errors()->toArray(),
                'request' => $request->only(['dispatchedQuantities', 'selectedLocations', 'selectedSerialNumbers', 'serialNumberLocations'])
            ]);
            return redirect()->back()->withErrors($validator)->withInput();
        }
        DB::beginTransaction();
        try {
            $dispatch = Dispatch::create([
                'sale_id' => $sale->id,
                'dispatch_date' => $request->input('dispatch_date'),
                'status' => Dispatch::STATUS_PENDING,
            ]);

            app(\App\Services\Notification\DocumentNotificationService::class)
                ->notifyApprovalNeeded($dispatch, 'Pengiriman ' . $sale->reference, $sale->setting_id);

            $dispatchedQuantities = $request->input('dispatchedQuantities', []);
            $selectedLocations = $request->input('selectedLocations', []);
            $selectedSerialNumbers = $request->input('selectedSerialNumbers', []);

            // Bulk-load all products for dispatch detail creation
            $dispatchProductIds = array_unique(array_map(fn($key) => (int) explode('-', $key)[0], array_keys($dispatchedQuantities)));
            $dispatchProducts = Product::whereIn('id', $dispatchProductIds)->get()->keyBy('id');

            // Reconstruct aggregated products for reference
            $aggregatedForDetail = [];
            foreach ($sale->saleDetails as $detail) {
                $product = $dispatchProducts->get($detail->product_id);
                if (!$product) continue;
                $key = $detail->product_id . '-' . $detail->tax_id . '-0';
                if (!isset($aggregatedForDetail[$key])) {
                    $aggregatedForDetail[$key] = ['is_inventory_managed' => $product->stock_managed !== false];
                }
            }
            $bundleItems = SaleBundleItem::where('sale_id', $sale->id)->with('saleDetail')->get();
            foreach ($bundleItems as $bundleItem) {
                $product = $dispatchProducts->get($bundleItem->product_id);
                if (!$product) continue;
                $key = $bundleItem->product_id . '-' . $bundleItem->inherited_tax_id . '-' . ($bundleItem->bundle_id ?? 0);
                if (!isset($aggregatedForDetail[$key])) {
                    $aggregatedForDetail[$key] = ['is_inventory_managed' => $product->stock_managed !== false];
                }
            }

            foreach ($dispatchedQuantities as $compositeKey => $qty) {
                if ((int)$qty <= 0) continue;

                $parts = explode('-', $compositeKey);
                $productId = $parts[0];
                $taxId = $parts[1];
                $bundleId = $parts[2] ?? 0;

                $product = $dispatchProducts->get($productId);
                if (!$product) continue;

                $isInventoryManaged = $aggregatedForDetail[$compositeKey]['is_inventory_managed'] ?? ($product->stock_managed !== false);

                if ($isInventoryManaged) {
                    // Stock-managed product: requires location and/or serials
                    if ($selectedSerialNumbers[$compositeKey] ?? null) {
                        $serials = $selectedSerialNumbers[$compositeKey];
                        $serialsByLocation = [];

                        foreach ($serials as $sn) {
                            $serialRecord = ProductSerialNumber::where('product_id', $productId)
                                ->where('serial_number', $sn)
                                ->first();

                            if (!$serialRecord || empty($serialRecord->location_id)) {
                                throw new Exception("Lokasi serial number {$sn} tidak valid.");
                            }

                            $locId = (int) $serialRecord->location_id;

                            if (!in_array($locId, $allowedLocationIds, true)) {
                                throw new Exception("Lokasi serial number {$sn} tidak valid untuk bisnis ini.");
                            }

                            if (!isset($serialsByLocation[$locId])) {
                                $serialsByLocation[$locId] = [];
                            }
                            $serialsByLocation[$locId][] = $sn;
                        }

                        foreach ($serialsByLocation as $locId => $snsAtLocation) {
                            DispatchDetail::create([
                                'dispatch_id' => $dispatch->id,
                                'sale_id' => $sale->id,
                                'tax_id' => !empty($taxId) ? $taxId : null,
                                'product_id' => $productId,
                                'bundle_id' => !empty($bundleId) ? $bundleId : null,
                                'dispatched_quantity' => count($snsAtLocation),
                                'location_id' => $locId,
                                'serial_numbers' => json_encode($snsAtLocation),
                            ]);
                        }
                    } else {
                        $locId = (int) $selectedLocations[$compositeKey];
                        DispatchDetail::create([
                            'dispatch_id' => $dispatch->id,
                            'sale_id' => $sale->id,
                            'tax_id' => !empty($taxId) ? $taxId : null,
                            'product_id' => $productId,
                            'bundle_id' => !empty($bundleId) ? $bundleId : null,
                            'dispatched_quantity' => (int)$qty,
                            'location_id' => $locId,
                            'serial_numbers' => null,
                        ]);
                    }
                } else {
                    // Non-stock product: no location or serial requirements
                    DispatchDetail::create([
                        'dispatch_id' => $dispatch->id,
                        'sale_id' => $sale->id,
                        'tax_id' => !empty($taxId) ? $taxId : null,
                        'product_id' => $productId,
                        'bundle_id' => !empty($bundleId) ? $bundleId : null,
                        'dispatched_quantity' => (int)$qty,
                        'location_id' => null,
                        'serial_numbers' => null,
                    ]);
                }
            }

            DB::commit();
            toast('Pengiriman berhasil disimpan dan menunggu persetujuan.', 'success');
            return redirect()->route('sales.dispatches.index');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Dispatch error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage())->withInput();
        }
    }

    public function approveDispatch(Dispatch $dispatch): RedirectResponse
    {
        abort_if(Gate::denies('salesDispatches.approval'), 403);
        $this->ensureSaleBelongsToCurrentSetting($dispatch->sale);

        if (!$dispatch->isPending()) {
            toast('Pengiriman ini sudah diproses sebelumnya.', 'error');
            return redirect()->back();
        }

        DB::beginTransaction();
        try {
            $dispatch->load('details.product');
            $sale = $dispatch->sale;

            foreach ($dispatch->details as $detail) {
                // Only apply stock mutations to inventory-managed products
                if ($detail->product->stock_managed !== false) {
                    $this->adjustStockForDispatchDetail($detail, $sale);
                }
            }

            $dispatch->update([
                'status' => Dispatch::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($dispatch);
            app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($dispatch);

            $this->recordSerialTrackingForApprovedDispatch($dispatch);
            $this->updateSaleStatus($sale);

            DB::commit();
            toast('Pengiriman berhasil disetujui.', 'success');
            return redirect()->back();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Dispatch approval error', ['message' => $e->getMessage()]);
            toast('Terjadi kesalahan: ' . $e->getMessage(), 'error');
            return redirect()->back();
        }
    }

    public function rejectDispatch(Request $request, Dispatch $dispatch): RedirectResponse
    {
        abort_if(Gate::denies('salesDispatches.approval'), 403);
        $this->ensureSaleBelongsToCurrentSetting($dispatch->sale);

        if (!$dispatch->isPending()) {
            toast('Pengiriman ini sudah diproses sebelumnya.', 'error');
            return redirect()->back();
        }

        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $dispatch->update([
                'status' => Dispatch::STATUS_REJECTED,
                'rejection_reason' => $request->rejection_reason,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($dispatch);
            app(\App\Services\Notification\DocumentNotificationService::class)->notifyRevisionNeeded(
                $dispatch, 
                'Pengiriman ' . $dispatch->sale->reference, 
                $dispatch->sale->setting_id, 
                $request->rejection_reason
            );

            // Keep sale status in sync after reject, mirroring receiving reject consistency.
            $this->updateSaleStatus($dispatch->sale);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Dispatch rejection error', ['message' => $e->getMessage()]);
            toast('Terjadi kesalahan: ' . $e->getMessage(), 'error');
            return redirect()->back();
        }

        toast('Pengiriman ditolak.', 'warning');
        return redirect()->back();
    }

    private function adjustStockForDispatchDetail(DispatchDetail $detail, Sale $sale)
    {
        $product = $detail->product;
        $qty = $detail->dispatched_quantity;
        $locationId = $detail->location_id;
        $taxId = $detail->tax_id;

        $productStock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (!$productStock) {
            throw new Exception("Stok tidak ditemukan untuk produk {$product->product_name} di lokasi selected.");
        }

        if ($productStock->quantity < $qty) {
            throw new Exception("Stok tidak cukup untuk produk {$product->product_name} di lokasi selected.");
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
            'product_id' => $product->id,
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

        // Update serial numbers if present
        if ($detail->serial_numbers) {
            $serials = json_decode($detail->serial_numbers, true);
            foreach ($serials as $serial) {
                $serialRecord = ProductSerialNumber::where('product_id', $product->id)
                    ->where('serial_number', $serial)
                    ->first();

                if ($serialRecord) {
                    $serialRecord->update([
                        'dispatch_detail_id' => $detail->id,
                        'status' => ProductSerialNumber::STATUS_SOLD,
                    ]);

                    // Record SOLD history event
                    SerialNumberHistoryService::record(
                        $serialRecord->id,
                        SerialNumberHistory::EVENT_SOLD,
                        $detail->location_id,
                        $detail
                    );
                }
            }
        }
    }

    private function recordSerialTrackingForApprovedDispatch(Dispatch $dispatch): void
    {
        $dispatch->loadMissing('details.product');

        $serialPairs = collect();

        foreach ($dispatch->details as $detail) {
            // Only process serial tracking for inventory-managed products
            if ($detail->product->stock_managed === false) {
                continue;
            }

            foreach ($this->normalizeDispatchSerials($detail->serial_numbers) as $serialNumber) {
                $serialPairs->push([
                    'product_id' => (int) $detail->product_id,
                    'serial_number' => $serialNumber,
                ]);
            }
        }

        if ($serialPairs->isEmpty()) {
            return;
        }

        $productIds = $serialPairs->pluck('product_id')->unique()->values();
        $serialNumbers = $serialPairs->pluck('serial_number')
            ->map(fn ($value) => $this->normalizeSerialValueForLookup($value))
            ->unique()
            ->values();

        $serialRecords = ProductSerialNumber::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('serial_number', $serialNumbers)
            ->get(['id', 'product_id', 'serial_number'])
            ->keyBy(fn (ProductSerialNumber $serial) => $this->saleSerialLookupKey((int) $serial->product_id, (string) $serial->serial_number));

        $dispatchDate = $dispatch->dispatch_date ? Carbon::parse($dispatch->dispatch_date) : now();
        $timestamp = now();
        $upsertRowsBySerialId = [];

        foreach ($serialPairs as $pair) {
            $serialRecord = $serialRecords->get(
                $this->saleSerialLookupKey((int) $pair['product_id'], (string) $pair['serial_number'])
            );

            if (! $serialRecord) {
                continue;
            }

            $upsertRowsBySerialId[(int) $serialRecord->id] = [
                'sale_id' => (int) $dispatch->sale_id,
                'product_serial_number_id' => (int) $serialRecord->id,
                'quantity_allocated' => 1,
                'dispatch_date' => $dispatchDate,
                'return_date' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if (empty($upsertRowsBySerialId)) {
            return;
        }

        SalesOrderSerialTracking::query()->upsert(
            array_values($upsertRowsBySerialId),
            ['sale_id', 'product_serial_number_id'],
            ['quantity_allocated', 'dispatch_date', 'return_date', 'updated_at']
        );
    }

    /**
     * @param mixed $serialNumbers
     * @return array<int, string>
     */
    private function normalizeDispatchSerials($serialNumbers): array
    {
        $decoded = $serialNumbers;

        if (is_string($serialNumbers)) {
            $decoded = json_decode($serialNumbers, true);
        }

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn ($value) => is_string($value) || is_numeric($value))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->values()
            ->all();
    }

    private function normalizeSerialValueForLookup(string $serialNumber): string
    {
        return mb_strtoupper(trim($serialNumber), 'UTF-8');
    }

    private function saleSerialLookupKey(int $productId, string $serialNumber): string
    {
        return $productId.'|'.$this->normalizeSerialValueForLookup($serialNumber);
    }

    private function updateSaleStatus(Sale $sale)
    {
        // Aggregate all sale detail quantities (both stock-managed and non-stock)
        $totalOrderQty = $sale->saleDetails()->sum('quantity');

        // Add bundle item quantities (both stock-managed and non-stock components)
        if (class_exists('\Modules\Sale\Entities\SaleBundleItem')) {
            $totalOrderQty += \Modules\Sale\Entities\SaleBundleItem::where('sale_id', $sale->id)->sum('quantity');
        }

        // Count all approved dispatch quantities (both stock-managed and non-stock acknowledgements)
        $allDispatchedQty = DispatchDetail::where('sale_id', $sale->id)
            ->whereHas('dispatch', function($q) {
                $q->where('status', Dispatch::STATUS_APPROVED);
            })
            ->sum('dispatched_quantity');

        if ($totalOrderQty <= 0) {
            // No demand, so sale is already fulfilled
            $sale->status = Sale::STATUS_APPROVED;
        } elseif ($allDispatchedQty <= 0) {
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
            'details'       => $sale->saleDetails->concat($sale->bundleItems->filter(fn($item) => is_null($item->sale_detail_id))),  // Concatenate standalone bundles
            'invoiceNumber' => $invoiceNumber,
            'tanggal'       => $tanggal,
            'jatuhTempo'    => $jatuhTempo,
            'total'         => $total,
            'paid'          => $paid,
            'due'           => $due,
        ]);

        return $pdf->stream('Sales-Invoice-'.$invoiceNumber.'.pdf');
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
        if (Gate::allows('globalSalesSearch.access')) {
            return;
        }

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
