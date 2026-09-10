<?php

namespace Modules\Adjustment\Http\Controllers;

use App\Services\IdempotencyService;
use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Adjustment\DataTables\AdjustmentsDataTable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\AdjustedProduct;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\CountDraftService;
use Modules\Adjustment\Services\StockOpnameReconciliationService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;

class AdjustmentController extends Controller
{

    public function __construct()
    {
        $this->middleware('idempotency')->only(['store', 'storeBreakage']);
    }

    /**
     * Guard against cross-setting access: the document's destination location
     * must belong to the active session setting and must not be consignment.
     */
    protected function assertAdjustmentOwned(Adjustment $adjustment): void
    {
        try {
            app(\Modules\Adjustment\Services\AdjustmentOwnershipGuard::class)->assertOwned($adjustment);
        } catch (ValidationException $e) {
            abort(403, (string) collect($e->errors())->flatten()->first());
        }
    }

    /**
     * A breakage document can only be edited while still pending approval;
     * an approved (or otherwise non-pending) document must never be
     * reopened for edit through a direct request. The document must also
     * actually BE a breakage document -- a legacy 'normal' adjustment with a
     * (legacy) pending status must never pass through the breakage edit
     * routes just because its status happens to match.
     */
    protected function assertBreakageEditable(Adjustment $adjustment): void
    {
        $normalizedType = Str::of($adjustment->type)->lower()->trim()->value();
        abort_if($normalizedType !== 'breakage', 403, 'Dokumen ini bukan penyesuaian barang rusak.');

        $status = \Modules\Adjustment\Entities\AdjustmentStatus::normalize($adjustment->status);

        abort_if($status !== \Modules\Adjustment\Entities\AdjustmentStatus::Pending, 403, 'Hanya penyesuaian barang rusak yang masih menunggu persetujuan yang dapat diubah.');
    }

    /**
     * Resolve a product for breakage entry, scoped to the destination
     * location's own setting, active, and stock-managed. A product outside
     * this scope (wrong setting, inactive, or not stock-managed) is treated
     * as not found rather than silently accepted -- UI-side filtering alone
     * does not establish this boundary.
     */
    protected function resolveBreakageProduct(int $productId, \Modules\Setting\Entities\Location $location): ?Product
    {
        return Product::query()
            ->where('id', $productId)
            ->active()
            ->where('stock_managed', true)
            ->where(function ($q) use ($location) {
                $q->whereNull('setting_id')->orWhere('setting_id', $location->setting_id);
            })
            ->first();
    }

    public function index(AdjustmentsDataTable $dataTable)
    {
        abort_if(Gate::denies('adjustments.access'), 403);

        return $dataTable->render('adjustment::index');
    }


    public function create(Request $request): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('adjustments.create'), 403);

        $currentSettingId = session('setting_id');

        // Fetch locations based on the current setting_id excluding consignment locations
        $locations = Location::where('setting_id', $currentSettingId)
            ->where('is_consignment', false)
            ->get();

        $idempotencyToken = IdempotencyService::tokenFromRequest($request);

        return view('adjustment::create', compact('locations', 'idempotencyToken'));
    }

    public function createBreakage(Request $request): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('adjustments.breakage.create'), 403);

        $currentSettingId = session('setting_id');
        $locations = Location::where('setting_id', $currentSettingId)
            ->where('is_consignment', false)
            ->get();

        $idempotencyToken = IdempotencyService::tokenFromRequest($request);

        return view('adjustment::create-breakage', compact('locations', 'idempotencyToken'));
    }


    public function store(Request $request)
    {
        abort_if(Gate::denies('adjustments.create'), 403);
        Log::info('[Adjustment] Incoming store request:', $request->all());

        // Check if request is from the count-draft workflow (either count_draft is present or legacy product_ids is absent)
        if ($request->has('count_draft') || !$request->has('product_ids')) {
            $service = app(\Modules\Adjustment\Services\CountDraftService::class);
            $validatedInput = $service->validateDraftInput($request->all());

            try {
                $service->saveDraft($validatedInput);

                return redirect()->route('adjustments.index')->with('success', 'Proposal penyesuaian stok (stock opname) berhasil disimpan.');
            } catch (\Throwable $e) {
                report($e);
                return back()->withErrors(['message' => 'Gagal menyimpan proposal penyesuaian stok. Silakan coba beberapa saat lagi.'])->withInput();
            }
        }

        $validated = $request->validate([
            'reference' => 'required|string',
            'date' => 'required|date',
            'location_id' => [
                'required',
                'exists:locations,id',
                function ($attribute, $value, $fail) {
                    $location = \Modules\Setting\Entities\Location::find($value);
                    if ($location && $location->is_consignment) {
                        $fail('Penyesuaian stok tidak dapat dilakukan pada lokasi konsinyasi.');
                    }
                },
            ],
            'product_ids' => 'required|array',
            'quantities_tax' => 'required|array',
            'quantities_tax.*' => 'nullable|integer|min:0',
            'quantities_non_tax' => 'required|array',
            'quantities_non_tax.*' => 'nullable|integer|min:0',
            'serial_numbers' => 'nullable|array',
            'is_taxables' => 'nullable|array',
            'note' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $adjustment = Adjustment::create([
                'reference' => $validated['reference'],
                'date' => $validated['date'],
                'location_id' => $validated['location_id'],
                'note' => $validated['note'] ?? null,
            ]);

            foreach ($validated['product_ids'] as $index => $productId) {
                $serials = $validated['serial_numbers'][$index] ?? [];

                $serialIds = collect($serials)->pluck('id')->toArray();

                // Validate: all serials must exist and not be dispatched
                $validSerials = ProductSerialNumber::whereIn('id', $serialIds)
                    ->whereNull('dispatch_detail_id')
                    ->pluck('id')
                    ->toArray();

                if (count($validSerials) !== count($serialIds)) {
                    throw new Exception("Beberapa serial number tidak valid atau telah dikirim (product index: {$index}).");
                }

                $product = Product::findOrFail($productId);

                // Use provided quantities
                $quantityTax = (int) ($validated['quantities_tax'][$index] ?? 0);
                $quantityNonTax = (int) ($validated['quantities_non_tax'][$index] ?? 0);

                // If serial required, double-check the count based on taxable flags
                if ($product->serial_number_required) {
                    $calculatedTax = collect($serials)->filter(fn($s) => !empty($s['taxable']))->count();
                    $calculatedNonTax = count($serials) - $calculatedTax;

                    if ($calculatedTax !== $quantityTax || $calculatedNonTax !== $quantityNonTax) {
                        throw new Exception("Mismatch between input quantities and serial number breakdown for product {$product->product_name}.");
                    }
                }

                AdjustedProduct::create([
                    'adjustment_id'      => $adjustment->id,
                    'product_id'         => $productId,
                    'quantity'           => $quantityTax + $quantityNonTax,
                    'quantity_tax'       => $quantityTax,
                    'quantity_non_tax'   => $quantityNonTax,
                    'serial_numbers'     => json_encode($serials), // Store full structure (id + taxable)
                    'is_taxable'         => $validated['is_taxables'][$index] ?? 0,
                    'type'               => 'sub',
                ]);
            }

            app(\App\Services\Notification\DocumentNotificationService::class)
                ->notifyApprovalNeeded($adjustment, $adjustment->reference, session('setting_id'), $adjustment->location_id);

            DB::commit();
            return redirect()->route('adjustments.index')->with('success', 'Penyesuaian berhasil disimpan.');
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return back()->withErrors(['message' => 'Gagal menyimpan penyesuaian.'])->withInput();
        }
    }

    public function storeBreakage(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('adjustments.breakage.create'), 403);

        $activeSettingId = (int) session('setting_id');

        $request->validate([
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'note' => 'nullable|string|max:1000',
            'product_ids' => 'required|array',
            'quantities_tax' => 'required|array',
            'quantities_tax.*' => 'nullable|integer|min:0',
            'quantities_non_tax' => 'required|array',
            'quantities_non_tax.*' => 'nullable|integer|min:0',
            'serial_numbers' => 'nullable|array',
            'serial_numbers.*' => 'array',
            'serial_numbers.*.*' => 'integer|exists:product_serial_numbers,id',
            'location_id' => ['required', 'exists:locations,id'],
        ], [
            'location_id.required' => 'Lokasi wajib diisi.',
        ]);

        $location = Location::with('setting')->find($request->location_id);

        try {
            app(\Modules\Adjustment\Services\AdjustmentOwnershipGuard::class)
                ->assertLocationOwned($location, $activeSettingId);
        } catch (ValidationException $e) {
            return back()->withErrors(['location_id' => (string) collect($e->errors())->flatten()->first()])->withInput();
        }

        $isPkp = (bool) $location->setting->is_pkp;
        $serialPolicy = app(\Modules\Adjustment\Services\BreakageSerialPolicy::class);

        if (count($request->product_ids) !== count(array_unique($request->product_ids))) {
            return back()
                ->withErrors(['product_ids' => 'Setiap produk hanya boleh muncul satu kali dalam dokumen barang rusak.'])
                ->withInput();
        }

        $allSerialIds = collect($request->serial_numbers ?? [])->flatten()->map('intval');
        if ($allSerialIds->count() !== $allSerialIds->unique()->count()) {
            return back()
                ->withErrors(['serial_numbers' => 'Nomor seri yang sama tidak boleh digunakan lebih dari satu kali dalam satu dokumen.'])
                ->withInput();
        }

        // Custom validation for serial numbers count matching quantity
        foreach ($request->product_ids as $key => $id) {
            $product = $this->resolveBreakageProduct((int) $id, $location);

            if (!$product) {
                return back()
                    ->withErrors(["product_ids.$key" => "Produk tidak ditemukan, tidak aktif, bukan produk yang stoknya dikelola, atau bukan milik pengaturan aktif."])
                    ->withInput();
            }

            if ($product->serial_number_required) {
                if (empty($request->serial_numbers[$key])) {
                    return back()
                        ->withErrors([
                            "serial_numbers.$key" => "Produk {$product->product_name} memerlukan serial number."
                        ])
                        ->withInput();
                }

                $serialIds = array_map('intval', $request->serial_numbers[$key]);
                $classification = $serialPolicy->classify($product, $location, $isPkp, $serialIds);

                if (!empty($classification['serial_conflicts']) || count($classification['eligible']) !== count($serialIds)) {
                    return back()
                        ->withErrors([
                            "serial_numbers.$key" => implode(' ', $classification['conflicts']) ?: "Nomor seri untuk produk {$product->product_name} tidak valid.",
                        ])
                        ->withInput();
                }
            }
        }

        DB::transaction(function () use ($request, $location, $isPkp, $serialPolicy) {
            $adjustment = Adjustment::create([
                'reference' => $request->reference,
                'date' => $request->date,
                'note' => $request->note,
                'type' => 'breakage',
                'status' => 'pending',
                'location_id' => $request->location_id,
            ]);

            app(\App\Services\Notification\DocumentNotificationService::class)
                ->notifyApprovalNeeded($adjustment, $adjustment->reference, session('setting_id'), $adjustment->location_id);

            foreach ($request->product_ids as $key => $id) {
                $product = $this->resolveBreakageProduct((int) $id, $location);
                if (!$product) {
                    throw ValidationException::withMessages([
                        "product_ids.$key" => "Produk tidak ditemukan, tidak aktif, bukan produk yang stoknya dikelola, atau bukan milik pengaturan aktif.",
                    ]);
                }
                $serialIds = array_map('intval', $request->serial_numbers[$key] ?? []);
                $serialNumbers = [];
                $quantityTax = (int) ($request->quantities_tax[$key] ?? 0);
                $quantityNonTax = (int) ($request->quantities_non_tax[$key] ?? 0);

                if ($product->serial_number_required) {
                    $classification = $serialPolicy->classify($product, $location, $isPkp, $serialIds);

                    if (!empty($classification['serial_conflicts']) || count($classification['eligible']) !== count($serialIds)) {
                        throw ValidationException::withMessages([
                            "serial_numbers.$key" => $classification['conflicts'] ?: ["Nomor seri untuk produk {$product->product_name} tidak valid."],
                        ]);
                    }

                    $serialNumbers = collect($classification['eligible'])->pluck('serial_id')->all();
                    $quantityTax = $isPkp ? count($serialNumbers) : 0;
                    $quantityNonTax = $isPkp ? 0 : count($serialNumbers);
                } else {
                    $otherBucket = $isPkp ? $quantityNonTax : $quantityTax;
                    if ($otherBucket > 0) {
                        throw ValidationException::withMessages([
                            "quantities_tax.$key" => "Kuantitas untuk {$product->product_name} harus berada pada kelompok pajak yang sesuai dengan pengaturan PKP lokasi ini.",
                        ]);
                    }
                }

                $productStock = ProductStock::where('product_id', $id)
                    ->where('location_id', $location->id)
                    ->first();

                if (!$productStock) {
                    throw ValidationException::withMessages([
                        "product_ids.$key" => "Stok untuk {$product->product_name} tidak ditemukan di lokasi terpilih.",
                    ]);
                }

                $availableGood = $isPkp
                    ? (int) ($productStock->quantity_tax ?? 0)
                    : (int) ($productStock->quantity_non_tax ?? 0);
                $requestedMovement = $quantityTax + $quantityNonTax;

                if ($requestedMovement > $availableGood) {
                    throw ValidationException::withMessages([
                        "quantities_tax.$key" => "Stok baik untuk {$product->product_name} tidak mencukupi (tersedia {$availableGood}).",
                    ]);
                }

                if ($requestedMovement <= 0) {
                    throw ValidationException::withMessages([
                        "quantities_tax.$key" => "Jumlah kuantitas rusak untuk {$product->product_name} harus lebih dari 0.",
                    ]);
                }

                AdjustedProduct::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $id,
                    'quantity' => $quantityTax + $quantityNonTax,
                    'quantity_tax' => $quantityTax,
                    'quantity_non_tax' => $quantityNonTax,
                    'type' => 'sub',
                    'serial_numbers' => json_encode($serialNumbers),
                    'is_taxable' => $quantityTax > 0 && $quantityNonTax === 0 ? 1 : 0,
                ]);
            }
        });

        toast('Penyesuaian Barang Rusak Dibuat!', 'success');

        return redirect()->route('adjustments.index');
    }


    public function show(Adjustment $adjustment): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('adjustments.show'), 403);
        $this->assertAdjustmentOwned($adjustment);

        if ($adjustment->isNormalVersioned()) {
            $adjustment->load(['location', 'submittedBy', 'approvedBy', 'rejectedBy']);

            $viewModel = $this->buildStockOpnameViewModel($adjustment);
            $document = $this->buildStockOpnameDocument($adjustment);

            return view('adjustment::show', array_merge(
                ['adjustment' => $document, 'isNormalVersioned' => true],
                $viewModel
            ));
        }

        $adjustment->load([
            'adjustedProducts.product.baseUnit',
            'location'
        ]);

        foreach ($adjustment->adjustedProducts as $adjustedProduct) {
            $product = $adjustedProduct->product;

            $rawSerials = json_decode($adjustedProduct->serial_numbers, true) ?? [];

            // 🔎 Detect format
            $isBreakage = $adjustment->type === 'breakage';

            // 🆔 Extract IDs from either format
            $serialIds = collect($rawSerials)->map(function ($item) use ($isBreakage) {
                return $isBreakage ? $item : $item['id'];
            })->toArray();

            // 📦 Load serials from DB
            $serialMap = ProductSerialNumber::whereIn('id', $serialIds)
                ->get(['id', 'serial_number', 'tax_id'])
                ->keyBy('id');

            // 🔁 Build unified display data
            $adjustedProduct->serialNumbers = collect($serialIds)->map(function ($id, $index) use ($serialMap, $rawSerials, $isBreakage) {
                $serial = $serialMap[$id] ?? null;

                $isTaxable = false;

                if ($isBreakage) {
                    // Use tax_id from DB
                    $isTaxable = $serial?->tax_id !== null;
                } else {
                    $isTaxable = !empty($rawSerials[$index]['taxable']) && $rawSerials[$index]['taxable'] == '1';
                }

                return [
                    'serial_number' => $serial?->serial_number ?? 'N/A',
                    'tax_label' => $isTaxable ? 'Kena Pajak' : 'Tidak Kena Pajak'
                ];
            });

            // 📦 Stock info (same as before)
            $stock = ProductStock::where('product_id', $product->id)
                ->where('location_id', $adjustment->location_id)
                ->first();

            $adjustedProduct->stock_info = [
                'quantity' => $stock->quantity ?? 0,
                'quantity_tax' => $stock->quantity_tax ?? 0,
                'quantity_non_tax' => $stock->quantity_non_tax ?? 0,
                'broken_quantity_tax' => $stock->broken_quantity_tax ?? 0,
                'broken_quantity_non_tax' => $stock->broken_quantity_non_tax ?? 0,
                'unit' => $product->baseUnit->unit_name ?? '',
            ];
        }

        return view('adjustment::show', compact('adjustment'));
    }

    /**
     * Build a permission-decided view model for a normal versioned Stock
     * Opname before the view is ever rendered (design.md Decision 6): the
     * reviewer-shaped array is never constructed for a user without
     * adjustments.view-system-stock, and count_draft/approval_result/DTOs are
     * never passed to Blade directly.
     */
    protected function buildStockOpnameViewModel(Adjustment $adjustment): array
    {
        $user = auth()->user();
        $status = AdjustmentStatus::normalize($adjustment->status);

        $canViewSystemStock = (bool) $user?->can('adjustments.view-system-stock');
        $canApprove = (bool) $user?->can('adjustments.approval');
        $canEditPermission = (bool) $user?->can('adjustments.edit');
        $canEdit = $canEditPermission && app(CountDraftService::class)->canEditAdjustment($adjustment);

        $isDraft = $status === AdjustmentStatus::Draft;
        $isWaitingApproval = $status === AdjustmentStatus::WaitingApproval;
        $isRejected = $status === AdjustmentStatus::Rejected;
        $isApproved = $status === AdjustmentStatus::Approved;

        $showSubmit = $isDraft && $canEditPermission;
        $showApprove = $isWaitingApproval && $canApprove;
        $showReject = $isWaitingApproval && $canApprove;
        $showEdit = ($isDraft || $isRejected) && $canEdit;
        $showDelete = ($isDraft || $isRejected) && (bool) $user?->can('adjustments.delete');

        if ($isApproved) {
            $stockOpname = $this->buildApprovedStockOpnameProjection($adjustment, $canViewSystemStock);
        } else {
            $result = app(StockOpnameReconciliationService::class)->reconcile($adjustment, locked: false);
            $stockOpname = $canViewSystemStock ? $result->toReviewerArray() : $result->toCounterArray();
        }

        return [
            'stockOpnameViewModel' => $stockOpname,
            'canViewSystemStock' => $canViewSystemStock,
            'canApprove' => $canApprove,
            'canEdit' => $canEdit,
            'isDraft' => $isDraft,
            'isWaitingApproval' => $isWaitingApproval,
            'isRejected' => $isRejected,
            'isApproved' => $isApproved,
            'showSubmit' => $showSubmit,
            'showApprove' => $showApprove,
            'showReject' => $showReject,
            'showEdit' => $showEdit,
            'showDelete' => $showDelete,
        ];
    }

    /**
     * A safe header/lifecycle projection for the versioned Stock Opname
     * partials: only IDs and metadata needed for routes, actions, and
     * history text. The raw Adjustment model (and therefore its
     * count_draft/approval_result casts) must never reach the versioned
     * Blade partials, which only receive this array plus the already
     * permission-decided stockOpnameViewModel.
     */
    protected function buildStockOpnameDocument(Adjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'reference' => (string) $adjustment->reference,
            'date' => $adjustment->date,
            'note' => $adjustment->note,
            'status' => AdjustmentStatus::normalize($adjustment->status)->value,
            'submitted_by_name' => $adjustment->submittedBy?->name,
            'submitted_at' => optional($adjustment->submitted_at)->toIso8601String(),
            'approved_by_name' => $adjustment->approvedBy?->name,
            'approved_at' => optional($adjustment->approved_at)->toIso8601String(),
            'rejected_by_name' => $adjustment->rejectedBy?->name,
            'rejected_at' => optional($adjustment->rejected_at)->toIso8601String(),
            'rejection_reason' => $adjustment->rejection_reason,
        ];
    }

    /**
     * approval_result is the immutable ground truth for an approved document
     * (design.md Decision 6 / spec "View an approved document after stock
     * changes again"): it is read as-is, never recomputed from current stock.
     * approval_result itself is reviewer-shaped (before/system totals/tax),
     * so a counter-safe projection is built by hand from only its
     * entered/applied facts.
     */
    protected function buildApprovedStockOpnameProjection(Adjustment $adjustment, bool $canViewSystemStock): array
    {
        $result = is_array($adjustment->approval_result) ? $adjustment->approval_result : [];

        if ($canViewSystemStock) {
            return $result;
        }

        $products = collect($result['products'] ?? [])->map(function (array $product) {
            $serials = collect($product['serials'] ?? [])
                ->reject(fn (array $serial) => ($serial['action'] ?? null) === 'missing')
                ->map(fn (array $serial) => [
                    'serial_number' => $serial['serial_number'] ?? '',
                    'condition' => $serial['applied_condition'] ?? null,
                ])->values()->all();

            return [
                'product_id' => $product['product_id'] ?? null,
                'product_name' => $product['product_name'] ?? '',
                'product_code' => $product['product_code'] ?? '',
                'base_unit' => $product['base_unit'] ?? '',
                'is_serialized' => $product['is_serialized'] ?? false,
                'entered' => [
                    'good' => $product['entered']['good'] ?? null,
                    'bad' => $product['entered']['bad'] ?? null,
                    'serial_count' => $product['entered']['serial_count'] ?? null,
                ],
                'serials' => $serials,
            ];
        })->values()->all();

        return [
            'adjustment_id' => $result['adjustment_id'] ?? $adjustment->id,
            'location_id' => $result['location_id'] ?? $adjustment->location_id,
            'location_name' => $result['location_name'] ?? ($adjustment->location->name ?? ''),
            'products' => $products,
        ];
    }

    public function submit(Adjustment $adjustment): RedirectResponse
    {
        abort_if(Gate::denies('adjustments.edit'), 403);
        $this->assertAdjustmentOwned($adjustment);

        try {
            app(\Modules\Adjustment\Services\StockOpnameLifecycleService::class)->submit($adjustment, auth()->user());
        } catch (ValidationException $e) {
            return back()->withErrors(['message' => (string) collect($e->errors())->flatten()->first()]);
        }

        toast('Proposal stock opname berhasil diajukan untuk persetujuan.', 'success');

        return redirect()->route('adjustments.index');
    }

    public function edit(Adjustment $adjustment): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('adjustments.edit'), 403);
        $this->assertAdjustmentOwned($adjustment);

        $service = app(\Modules\Adjustment\Services\CountDraftService::class);
        if (!$service->canEditAdjustment($adjustment)) {
            abort(403, 'Hanya penyesuaian normal berstatus draf atau ditolak yang dapat diubah.');
        }

        $adjustment->load('adjustedProducts.product.baseUnit');

        return view('adjustment::edit', compact('adjustment'));
    }


    public function update(Request $request, Adjustment $adjustment): RedirectResponse
    {
        abort_if(Gate::denies('adjustments.edit'), 403);
        $this->assertAdjustmentOwned($adjustment);

        $service = app(\Modules\Adjustment\Services\CountDraftService::class);
        if (!$service->canEditAdjustment($adjustment)) {
            abort(403, 'Hanya penyesuaian normal berstatus draf atau ditolak yang dapat diubah.');
        }

        // Check if request is from the count-draft workflow (either count_draft is present or legacy product_ids is absent)
        if ($request->has('count_draft') || !$request->has('product_ids')) {
            $validatedInput = $service->validateDraftInput($request->all(), $adjustment);

            try {
                $service->saveDraft($validatedInput, $adjustment);

                return redirect()->route('adjustments.index')->with('success', 'Proposal penyesuaian stok berhasil diperbaharui.');
            } catch (\Throwable $e) {
                report($e);
                return back()->withErrors(['message' => 'Gagal memperbaharui proposal penyesuaian stok. Silakan coba beberapa saat lagi.'])->withInput();
            }
        }

        $validated = $request->validate([
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'location_id' => [
                'required',
                'exists:locations,id',
                function ($attribute, $value, $fail) {
                    $location = \Modules\Setting\Entities\Location::find($value);
                    if ($location && $location->is_consignment) {
                        $fail('Penyesuaian stok tidak dapat dilakukan pada lokasi konsinyasi.');
                    }
                },
            ],
            'product_ids' => 'required|array',
            'quantities_tax' => 'required|array',
            'quantities_tax.*' => 'nullable|integer|min:0',
            'quantities_non_tax' => 'required|array',
            'quantities_non_tax.*' => 'nullable|integer|min:0',
            'serial_numbers' => 'nullable|array',
            'is_taxables' => 'nullable|array',
            'note' => 'nullable|string|max:1000',
        ]);


        DB::transaction(function () use ($validated, $adjustment) {
            $adjustment->update([
                'reference' => $validated['reference'],
                'date' => $validated['date'],
                'note' => $validated['note'] ?? null
            ]);

            $adjustment->adjustedProducts()->delete();

            foreach ($validated['product_ids'] as $index => $productId) {
                $serials = $validated['serial_numbers'][$index] ?? [];

                $serialIds = collect($serials)->pluck('id')->toArray();
                $validSerials = ProductSerialNumber::whereIn('id', $serialIds)
                    ->whereNull('dispatch_detail_id')
                    ->pluck('id')
                    ->toArray();

                if (count($validSerials) !== count($serialIds)) {
                    throw new Exception("Beberapa serial number tidak valid atau telah dikirim (product index: {$index}).");
                }

                $product = Product::findOrFail($productId);

                $quantityTax = (int) ($validated['quantities_tax'][$index] ?? 0);
                $quantityNonTax = (int) ($validated['quantities_non_tax'][$index] ?? 0);

                if ($product->serial_number_required) {
                    $calculatedTax = collect($serials)->filter(fn($s) => !empty($s['taxable']))->count();
                    $calculatedNonTax = count($serials) - $calculatedTax;

                    if ($calculatedTax !== $quantityTax || $calculatedNonTax !== $quantityNonTax) {
                        throw new Exception("Mismatch between input quantities and serial number breakdown for product {$product->product_name}.");
                    }
                }

                AdjustedProduct::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $productId,
                    'quantity' => $quantityTax + $quantityNonTax,
                    'quantity_tax' => $quantityTax,
                    'quantity_non_tax' => $quantityNonTax,
                    'serial_numbers' => json_encode($serials),
                    'is_taxable' => $validated['is_taxables'][$index] ?? 0,
                    'type' => 'sub',
                ]);
            }
        });

        toast('Penyesuaian Diperbaharui!', 'info');

        return redirect()->route('adjustments.index');
    }

    public function editBreakage(Adjustment $adjustment): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('adjustments.breakage.edit'), 403);
        $this->assertAdjustmentOwned($adjustment);
        $this->assertBreakageEditable($adjustment);

        $adjustment->load(['adjustedProducts.product', 'location']);

        // Convert serial numbers from JSON to an array of IDs
        foreach ($adjustment->adjustedProducts as $adjustedProduct) {
            $adjustedProduct->serial_number_ids = !empty($adjustedProduct->serial_numbers)
                ? json_decode($adjustedProduct->serial_numbers, true)
                : [];
        }

        return view('adjustment::edit-breakage', compact('adjustment'));
    }


    public function updateBreakage(Request $request, Adjustment $adjustment): RedirectResponse
    {
        abort_if(Gate::denies('adjustments.breakage.edit'), 403);
        $this->assertAdjustmentOwned($adjustment);
        $this->assertBreakageEditable($adjustment);

        $activeSettingId = (int) session('setting_id');

        $request->validate([
            'date' => 'required|date',
            'note' => 'nullable|string|max:1000',
            'product_ids' => 'required|array',
            'quantities_tax' => 'required|array',
            'quantities_tax.*' => 'nullable|integer|min:0',
            'quantities_non_tax' => 'required|array',
            'quantities_non_tax.*' => 'nullable|integer|min:0',
            'serial_numbers' => 'nullable|array',
            'serial_numbers.*' => 'array',
            'serial_numbers.*.*' => 'integer|exists:product_serial_numbers,id',
            'location_id' => ['required', 'exists:locations,id'],
        ]);

        $location = Location::with('setting')->find($request->location_id);

        try {
            app(\Modules\Adjustment\Services\AdjustmentOwnershipGuard::class)
                ->assertLocationOwned($location, $activeSettingId);
        } catch (ValidationException $e) {
            return back()->withErrors(['location_id' => (string) collect($e->errors())->flatten()->first()])->withInput();
        }

        $isPkp = (bool) $location->setting->is_pkp;
        $serialPolicy = app(\Modules\Adjustment\Services\BreakageSerialPolicy::class);

        if (count($request->product_ids) !== count(array_unique($request->product_ids))) {
            return back()
                ->withErrors(['product_ids' => 'Setiap produk hanya boleh muncul satu kali dalam dokumen barang rusak.'])
                ->withInput();
        }

        $allSerialIds = collect($request->serial_numbers ?? [])->flatten()->map('intval');
        if ($allSerialIds->count() !== $allSerialIds->unique()->count()) {
            return back()
                ->withErrors(['serial_numbers' => 'Nomor seri yang sama tidak boleh digunakan lebih dari satu kali dalam satu dokumen.'])
                ->withInput();
        }

        foreach ($request->product_ids as $key => $id) {
            $product = $this->resolveBreakageProduct((int) $id, $location);

            if (!$product) {
                return back()
                    ->withErrors(["product_ids.$key" => "Produk tidak ditemukan, tidak aktif, bukan produk yang stoknya dikelola, atau bukan milik pengaturan aktif."])
                    ->withInput();
            }

            if ($product->serial_number_required) {
                $serialIds = array_map('intval', $request->serial_numbers[$key] ?? []);

                if (empty($serialIds)) {
                    return back()
                        ->withErrors([
                            "serial_numbers.$key" => "Produk {$product->product_name} memerlukan serial number."
                        ])
                        ->withInput();
                }

                $classification = $serialPolicy->classify($product, $location, $isPkp, $serialIds);

                if (!empty($classification['serial_conflicts']) || count($classification['eligible']) !== count($serialIds)) {
                    return back()
                        ->withErrors([
                            "serial_numbers.$key" => implode(' ', $classification['conflicts']) ?: "Nomor seri untuk produk {$product->product_name} tidak valid.",
                        ])
                        ->withInput();
                }
            }
        }

        DB::transaction(function () use ($request, $adjustment, $location, $isPkp, $serialPolicy) {
            // Update Adjustment Header
            $adjustment->update([
                'date' => $request->date,
                'note' => $request->note,
                'location_id' => $location->id,
            ]);

            // Delete previous adjusted products
            $adjustment->adjustedProducts()->delete();

            // Insert new adjusted products
            foreach ($request->product_ids as $key => $id) {
                $product = $this->resolveBreakageProduct((int) $id, $location);
                if (!$product) {
                    throw ValidationException::withMessages([
                        "product_ids.$key" => "Produk tidak ditemukan, tidak aktif, bukan produk yang stoknya dikelola, atau bukan milik pengaturan aktif.",
                    ]);
                }
                $serialIds = array_map('intval', $request->serial_numbers[$key] ?? []);
                $quantityTax = (int) ($request->quantities_tax[$key] ?? 0);
                $quantityNonTax = (int) ($request->quantities_non_tax[$key] ?? 0);

                if ($product->serial_number_required) {
                    $classification = $serialPolicy->classify($product, $location, $isPkp, $serialIds);

                    if (!empty($classification['serial_conflicts']) || count($classification['eligible']) !== count($serialIds)) {
                        throw ValidationException::withMessages([
                            "serial_numbers.$key" => $classification['conflicts'] ?: ["Nomor seri untuk produk {$product->product_name} tidak valid."],
                        ]);
                    }

                    $serialIds = collect($classification['eligible'])->pluck('serial_id')->all();
                    $quantityTax = $isPkp ? count($serialIds) : 0;
                    $quantityNonTax = $isPkp ? 0 : count($serialIds);
                } else {
                    $otherBucket = $isPkp ? $quantityNonTax : $quantityTax;
                    if ($otherBucket > 0) {
                        throw ValidationException::withMessages([
                            "quantities_tax.$key" => "Kuantitas untuk {$product->product_name} harus berada pada kelompok pajak yang sesuai dengan pengaturan PKP lokasi ini.",
                        ]);
                    }
                }

                $productStock = ProductStock::where('product_id', $id)
                    ->where('location_id', $location->id)
                    ->first();

                if (!$productStock) {
                    throw ValidationException::withMessages([
                        "product_ids.$key" => "Stok untuk {$product->product_name} tidak ditemukan di lokasi terpilih.",
                    ]);
                }

                $availableGood = $isPkp
                    ? (int) ($productStock->quantity_tax ?? 0)
                    : (int) ($productStock->quantity_non_tax ?? 0);
                $requestedMovement = $quantityTax + $quantityNonTax;

                if ($requestedMovement > $availableGood) {
                    throw ValidationException::withMessages([
                        "quantities_tax.$key" => "Stok baik untuk {$product->product_name} tidak mencukupi (tersedia {$availableGood}).",
                    ]);
                }

                if ($requestedMovement <= 0) {
                    throw ValidationException::withMessages([
                        "quantities_tax.$key" => "Jumlah kuantitas rusak untuk {$product->product_name} harus lebih dari 0.",
                    ]);
                }

                AdjustedProduct::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $id,
                    'quantity' => $quantityTax + $quantityNonTax,
                    'quantity_tax' => $quantityTax,
                    'quantity_non_tax' => $quantityNonTax,
                    'type' => 'sub',
                    'serial_numbers' => json_encode($serialIds),
                    'is_taxable' => $quantityTax > 0 && $quantityNonTax === 0 ? 1 : 0,
                ]);
            }
        });

        toast('Penyesuaian Barang Rusak Diperbaharui!', 'info');

        return redirect()->route('adjustments.index');
    }


    public function destroy(Adjustment $adjustment)
    {
        abort_if(Gate::denies('adjustments.delete'), 403);

        if ($adjustment->isNormalVersioned()) {
            $this->assertAdjustmentOwned($adjustment);

            try {
                app(\Modules\Adjustment\Services\StockOpnameLifecycleService::class)->deleteDraft($adjustment, auth()->user());
            } catch (ValidationException $e) {
                abort(403, (string) collect($e->errors())->flatten()->first());
            }

            toast('Adjustment Deleted!', 'warning');

            return redirect()->route('adjustments.index');
        }

        $adjustment->delete();

        toast('Adjustment Deleted!', 'warning');

        return redirect()->route('adjustments.index');
    }

    public function approve(Adjustment $adjustment): RedirectResponse
    {
        abort_unless(Gate::any(['adjustments.approval', 'adjustments.breakage.approval']), 403);
        Log::info('[Adjustment] Approving adjustment (full)', $adjustment->toArray());
        Log::info('[Adjustment] Approving adjustment details (full)', $adjustment->adjustedProducts->load('product')->toArray());

        if ($adjustment->isVersionedCountDraft()) {
            return $this->approveVersionedStockOpname($adjustment);
        }

        $normalizedType = Str::of($adjustment->type)->lower()->trim()->value();

        if ($normalizedType === 'normal') {
            return $this->approveNormal($adjustment);
        } elseif ($normalizedType === 'breakage') {
            return $this->approveBreakage($adjustment);
        }

        Log::warning('[Adjustment] Unknown adjustment type encountered during approval.', [
            'adjustment_id' => $adjustment->id,
            'raw_type' => $adjustment->type,
            'normalized_type' => $normalizedType,
        ]);

        session()->flash('error', 'Unknown adjustment type.');
        return redirect()->route('adjustments.index');
    }

    /**
     * Guarded approval entry point for redesigned (normal versioned) Stock
     * Opname documents. Actual reconciliation and inventory mutation is
     * implemented separately (section 4); this only verifies eligibility so
     * that the legacy approveNormal()/approveBreakage() postings can never
     * receive a redesigned document.
     */
    protected function approveVersionedStockOpname(Adjustment $adjustment): RedirectResponse
    {
        abort_if(Gate::denies('adjustments.approval'), 403);

        try {
            app(\Modules\Adjustment\Services\StockOpnameApprovalService::class)->approve($adjustment, auth()->user());
        } catch (ValidationException $e) {
            return back()->withErrors(['message' => (string) collect($e->errors())->flatten()->first()]);
        }

        toast('Stock Opname Disetujui!', 'success');

        return redirect()->route('adjustments.index');
    }

    public function approveNormal(Adjustment $adjustment): RedirectResponse
    {
        abort_if(Gate::denies('adjustments.approval'), 403);

        if ($adjustment->isVersionedCountDraft()) {
            return $this->approveVersionedStockOpname($adjustment);
        }

        try {
            DB::beginTransaction();
            $settingId = session('setting_id');
            $latestTax = Tax::orderByDesc('created_at')->first();

            foreach ($adjustment->adjustedProducts as $adjustedProduct) {
                $product = $adjustedProduct->product;
                $locationId = $adjustment->location_id;

                $productStock = ProductStock::firstOrNew([
                    'product_id' => $product->id,
                    'location_id' => $locationId,
                ]);

                // 🧮 Capture previous values before mutation
                $prev_quantity_tax = $productStock->quantity_tax ?? 0;
                $prev_quantity_non_tax = $productStock->quantity_non_tax ?? 0;
                $prev_broken_tax = $productStock->broken_quantity_tax ?? 0;
                $prev_broken_non_tax = $productStock->broken_quantity_non_tax ?? 0;
                $prev_quantity_at_location = (int) ($productStock->quantity ?? ($prev_quantity_tax + $prev_quantity_non_tax));

                $quantityTax = 0;
                $quantityNonTax = 0;

                if ($product->serial_number_required) {
                    $rawSerials = json_decode($adjustedProduct->serial_numbers, true) ?? [];
                    $serialIdsInDoc = collect($rawSerials)->pluck('id')->toArray();

                    $serialsInDb = ProductSerialNumber::whereIn('id', $serialIdsInDoc)
                        ->where('product_id', $product->id)
                        ->where('location_id', $locationId)
                        ->whereNull('dispatch_detail_id')
                        ->get()
                        ->keyBy('id');

                    foreach ($rawSerials as $serialData) {
                        $serial = $serialsInDb[$serialData['id']] ?? null;
                        if (!$serial) continue;

                        $isTaxable = !empty($serialData['taxable']) && (int)$serialData['taxable'] === 1;

                        if ($isTaxable) {
                            $quantityTax++;
                            if (!$serial->tax_id && $latestTax) {
                                $serial->tax_id = $latestTax->id;
                                $serial->save();
                            }
                        } else {
                            $quantityNonTax++;
                            if ($serial->tax_id !== null) {
                                $serial->tax_id = null;
                                $serial->save();
                            }
                        }
                    }

                    // Set recalculated stock
                    $productStock->quantity_tax = $quantityTax;
                    $productStock->quantity_non_tax = $quantityNonTax;
                    $productStock->quantity = $quantityTax + $quantityNonTax;
                    $productStock->broken_quantity = (int) ($productStock->broken_quantity_tax ?? 0) + (int) ($productStock->broken_quantity_non_tax ?? 0);
                    $productStock->save();

                    // Remove unmatched serials from DB
                    $existingSerials = ProductSerialNumber::where('product_id', $product->id)
                        ->where('location_id', $locationId)
                        ->whereNull('dispatch_detail_id')
                        ->pluck('id')
                        ->toArray();

                    $serialsToDelete = array_diff($existingSerials, $serialIdsInDoc);
                    if (!empty($serialsToDelete)) {
                        ProductSerialNumber::whereIn('id', $serialsToDelete)->delete();
                        Log::info("Deleted unmatched serials for product {$product->product_code}", [
                            'deleted_ids' => $serialsToDelete,
                        ]);
                    }

                } else {
                    // Directly assign non-serial quantities
                    $productStock->quantity_tax = (int) $adjustedProduct->quantity_tax;
                    $productStock->quantity_non_tax = (int) $adjustedProduct->quantity_non_tax;
                    $productStock->quantity = (int) $productStock->quantity_tax + (int) $productStock->quantity_non_tax;
                    $productStock->broken_quantity = (int) ($productStock->broken_quantity_tax ?? 0) + (int) ($productStock->broken_quantity_non_tax ?? 0);
                    $productStock->save();

                    $quantityTax = (int) $adjustedProduct->quantity_tax;
                    $quantityNonTax = (int) $adjustedProduct->quantity_non_tax;
                }

                // 🧮 After values
                $after_quantity_tax = $productStock->quantity_tax ?? 0;
                $after_quantity_non_tax = $productStock->quantity_non_tax ?? 0;
                $new_quantity_at_location = (int) ($productStock->quantity ?? ($after_quantity_tax + $after_quantity_non_tax));

                $quantityDiff = $new_quantity_at_location - $prev_quantity_at_location;

                if ($quantityDiff !== 0) {
                    $currentProductQuantity = (int) ($product->product_quantity ?? 0);
                    $updatedTotalQuantity = max(0, $currentProductQuantity + $quantityDiff);

                    if ($updatedTotalQuantity !== $currentProductQuantity) {
                        $product->product_quantity = $updatedTotalQuantity;
                        $product->save();
                        app(\App\Services\Notification\StockNotificationService::class)
                            ->checkGlobalStock($product, $currentProductQuantity, $updatedTotalQuantity);
                    }
                }

                app(\App\Services\Notification\StockNotificationService::class)
                    ->checkLocationStock($productStock, $prev_quantity_at_location, $new_quantity_at_location);

                // 🧾 Log the transaction
                Transaction::create([
                    'product_id' => $product->id,
                    'setting_id' => $settingId,
                    'type' => 'ADJ',
                    'quantity' => $adjustedProduct->quantity,

                    'previous_quantity' => $prev_quantity_at_location,
                    'after_quantity' => $new_quantity_at_location,
                    'previous_quantity_at_location' => $prev_quantity_at_location,
                    'after_quantity_at_location' => $new_quantity_at_location,

                    'quantity_tax' => $after_quantity_tax,
                    'quantity_non_tax' => $after_quantity_non_tax,
                    'broken_quantity_tax' => $productStock->broken_quantity_tax ?? 0,
                    'broken_quantity_non_tax' => $productStock->broken_quantity_non_tax ?? 0,
                    'broken_quantity' => ($productStock->broken_quantity_tax ?? 0) + ($productStock->broken_quantity_non_tax ?? 0),
                    'current_quantity' => $new_quantity_at_location,

                    'location_id' => $locationId,
                    'user_id' => auth()->id(),
                    'reason' => 'Normal adjustment approved',

                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $adjustment->update(['status' => 'approved']);

            app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($adjustment);
            app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($adjustment);

            DB::commit();
            toast('Adjustment Approved!', 'success');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Adjustment approval failed', ['error' => $e->getMessage()]);
            session()->flash('error', 'Failed to approve adjustment. Please try again.');
            toast('Error Approving Adjustment!', 'error');
        }

        return redirect()->route('adjustments.index');
    }

    public function approveBreakage(Adjustment $adjustment): RedirectResponse
    {
        abort_unless(Gate::any(['adjustments.breakage.approval', 'adjustments.approval']), 403);

        try {
            app(\Modules\Adjustment\Services\BreakageApprovalService::class)->approve($adjustment, auth()->user());
        } catch (ValidationException $e) {
            return back()->withErrors(['message' => (string) collect($e->errors())->flatten()->first()]);
        }

        toast('Penyesuaian Barang Rusak Disetujui!', 'success');

        return redirect()->route('adjustments.index');
    }

    public function reject(Request $request, Adjustment $adjustment): RedirectResponse
    {
        if ($adjustment->isNormalVersioned()) {
            abort_if(Gate::denies('adjustments.approval'), 403);

            $reason = trim((string) $request->input('rejection_reason', ''));
            if ($reason === '') {
                return back()->withErrors(['rejection_reason' => 'Alasan penolakan wajib diisi.']);
            }

            try {
                app(\Modules\Adjustment\Services\StockOpnameLifecycleService::class)
                    ->reject($adjustment, auth()->user(), $reason);
            } catch (ValidationException $e) {
                return back()->withErrors(['message' => (string) collect($e->errors())->flatten()->first()]);
            }

            toast('Penyesuaian Ditolak!', 'info');

            return redirect()->route('adjustments.index');
        }

        abort_unless(Gate::any([
            'adjustments.reject',
            'adjustments.approval',
            'adjustments.breakage.approval',
        ]), 403);
        // Update the status of the adjustment to 'rejected'
        $adjustment->update(['status' => 'rejected']);

        app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($adjustment);
        app(\App\Services\Notification\DocumentNotificationService::class)->notifyRevisionNeeded(
            $adjustment,
            $adjustment->reference ?? 'Penyesuaian Barang',
            session('setting_id'),
            '',
            $adjustment->location_id
        );

        // Optionally, you can add a success message to be displayed after the redirect
        toast('Penyesuaian Ditolak!', 'info');

        // Redirect back to the adjustments index
        return redirect()->route('adjustments.index');
    }
}
