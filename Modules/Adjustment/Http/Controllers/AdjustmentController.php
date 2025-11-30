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

    public function index(AdjustmentsDataTable $dataTable)
    {
        abort_if(Gate::denies('adjustments.access'), 403);

        return $dataTable->render('adjustment::index');
    }


    public function create(Request $request): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('adjustments.create'), 403);

        $currentSettingId = session('setting_id');

        // Fetch locations based on the current setting_id
        $locations = Location::where('setting_id', $currentSettingId)->get();

        $idempotencyToken = IdempotencyService::tokenFromRequest($request);

        return view('adjustment::create', compact('locations', 'idempotencyToken'));
    }

    public function createBreakage(Request $request): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('adjustments.breakage.create'), 403);

        $currentSettingId = session('setting_id');

        // Fetch locations based on the current setting_id
        $locations = Location::where('setting_id', $currentSettingId)->get();

        $idempotencyToken = IdempotencyService::tokenFromRequest($request);

        return view('adjustment::create-breakage', compact('locations', 'idempotencyToken'));
    }


    public function store(Request $request)
    {
        abort_if(Gate::denies('adjustments.create'), 403);
        Log::info('[Adjustment] Incoming store request:', $request->all());
        $validated = $request->validate([
            'reference' => 'required|string',
            'date' => 'required|date',
            'location_id' => 'required|exists:locations,id',
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
            'location_id' => 'required|exists:locations,id',
        ], [
            'location_id.required' => 'Lokasi wajib diisi.',
        ]);

        // Custom validation for serial numbers count matching quantity
        foreach ($request->product_ids as $key => $id) {
            // Retrieve product details
            $product = Product::find($id);

            if ($product && $product->serial_number_required) {
                // Ensure serial numbers exist
                if (empty($request->serial_numbers[$key])) {
                    return back()
                        ->withErrors([
                            "serial_numbers.$key" => "Produk {$product->product_name} memerlukan serial number."
                        ])
                        ->withInput();
                }

                // Ensure serial numbers count matches quantity
                $serialCount = count($request->serial_numbers[$key]);
                $expected = (int) ($request->quantities_tax[$key] ?? 0) + (int) ($request->quantities_non_tax[$key] ?? 0);

                if ($serialCount !== $expected) {
                    return back()
                        ->withErrors([
                            "serial_numbers.$key" => "Jumlah serial number untuk produk {$product->product_name} harus sama dengan total kuantitas ($expected)."
                        ])
                        ->withInput();
                }
            }
        }

        DB::transaction(function () use ($request) {
            $adjustment = Adjustment::create([
                'date' => $request->date,
                'note' => $request->note,
                'type' => 'breakage',
                'status' => 'pending',
                'location_id' => $request->location_id,
            ]);

            foreach ($request->product_ids as $key => $id) {
                $product = Product::findOrFail($id);
                $serialIds = $request->serial_numbers[$key] ?? [];
                $serialNumbers = [];
                $quantityTax = (int) ($request->quantities_tax[$key] ?? 0);
                $quantityNonTax = (int) ($request->quantities_non_tax[$key] ?? 0);

                if (!empty($serialIds)) {
                    $serials = ProductSerialNumber::whereIn('id', $serialIds)
                        ->where('product_id', $id)
                        ->whereNull('dispatch_detail_id')
                        ->get(['id', 'tax_id']);

                    $serialNumbers = $serials->pluck('id')->map(function ($serialId) {
                        return (int) $serialId;
                    })->toArray();

                    $quantityTax = $serials->whereNotNull('tax_id')->count();
                    $quantityNonTax = $serials->count() - $quantityTax;
                }

                $productStock = ProductStock::where('product_id', $id)
                    ->where('location_id', $request->location_id)
                    ->first();

                if (!$productStock) {
                    throw ValidationException::withMessages([
                        "product_ids.$key" => "Stok untuk {$product->product_name} tidak ditemukan di lokasi terpilih.",
                    ]);
                }

                $availableTax = (int) ($productStock->quantity_tax ?? 0);
                $availableNonTax = (int) ($productStock->quantity_non_tax ?? 0);

                if ($quantityTax > $availableTax) {
                    throw ValidationException::withMessages([
                        "quantities_tax.$key" => "Stok kena pajak untuk {$product->product_name} tidak mencukupi (tersedia {$availableTax}).",
                    ]);
                }

                if ($quantityNonTax > $availableNonTax) {
                    throw ValidationException::withMessages([
                        "quantities_non_tax.$key" => "Stok non-pajak untuk {$product->product_name} tidak mencukupi (tersedia {$availableNonTax}).",
                    ]);
                }

                if (($quantityTax + $quantityNonTax) <= 0) {
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



    public function edit(Adjustment $adjustment): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('adjustments.edit'), 403);

        // ✅ Add this
        $adjustment->load('adjustedProducts.product.baseUnit');

        return view('adjustment::edit', compact('adjustment'));
    }


    public function update(Request $request, Adjustment $adjustment): RedirectResponse
    {
        abort_if(Gate::denies('adjustments.edit'), 403);

        $validated = $request->validate([
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'location_id' => 'required|exists:locations,id',
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
            'location_id' => 'required|exists:locations,id',
        ]);

        foreach ($request->product_ids as $key => $id) {
            $product = Product::find($id);

            if ($product && $product->serial_number_required) {
                $serialCount = isset($request->serial_numbers[$key])
                    ? count($request->serial_numbers[$key])
                    : 0;
                $expected = (int) ($request->quantities_tax[$key] ?? 0) + (int) ($request->quantities_non_tax[$key] ?? 0);

                if ($serialCount !== $expected) {
                    return back()
                        ->withErrors([
                            "serial_numbers.$key" => "Jumlah serial number untuk produk {$product->product_name} harus sama dengan total kuantitas ($expected)."
                        ])
                        ->withInput();
                }
            }
        }

        DB::transaction(function () use ($request, $adjustment) {
            // Update Adjustment Header
            $adjustment->update([
                'date' => $request->date,
                'note' => $request->note,
            ]);

            // Delete previous adjusted products
            $adjustment->adjustedProducts()->delete();

            // Insert new adjusted products
            foreach ($request->product_ids as $key => $id) {
                $product = Product::findOrFail($id);
                $serialIds = $request->serial_numbers[$key] ?? [];
                $quantityTax = (int) ($request->quantities_tax[$key] ?? 0);
                $quantityNonTax = (int) ($request->quantities_non_tax[$key] ?? 0);

                if (!empty($serialIds)) {
                    $serials = ProductSerialNumber::whereIn('id', $serialIds)
                        ->where('product_id', $id)
                        ->whereNull('dispatch_detail_id')
                        ->get(['id', 'tax_id']);

                    $quantityTax = $serials->whereNotNull('tax_id')->count();
                    $quantityNonTax = $serials->count() - $quantityTax;

                    $serialIds = $serials->pluck('id')->map(fn ($serialId) => (int) $serialId)->toArray();
                }

                $productStock = ProductStock::where('product_id', $id)
                    ->where('location_id', $request->location_id)
                    ->first();

                if (!$productStock) {
                    throw ValidationException::withMessages([
                        "product_ids.$key" => "Stok untuk {$product->product_name} tidak ditemukan di lokasi terpilih.",
                    ]);
                }

                $availableTax = (int) ($productStock->quantity_tax ?? 0);
                $availableNonTax = (int) ($productStock->quantity_non_tax ?? 0);

                if ($quantityTax > $availableTax) {
                    throw ValidationException::withMessages([
                        "quantities_tax.$key" => "Stok kena pajak untuk {$product->product_name} tidak mencukupi (tersedia {$availableTax}).",
                    ]);
                }

                if ($quantityNonTax > $availableNonTax) {
                    throw ValidationException::withMessages([
                        "quantities_non_tax.$key" => "Stok non-pajak untuk {$product->product_name} tidak mencukupi (tersedia {$availableNonTax}).",
                    ]);
                }

                if (($quantityTax + $quantityNonTax) <= 0) {
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

        $adjustment->delete();

        toast('Adjustment Deleted!', 'warning');

        return redirect()->route('adjustments.index');
    }

    public function approve(Adjustment $adjustment): RedirectResponse
    {
        abort_unless(Gate::any(['adjustments.approval', 'adjustments.breakage.approval']), 403);
        Log::info('[Adjustment] Approving adjustment (full)', $adjustment->toArray());
        Log::info('[Adjustment] Approving adjustment details (full)', $adjustment->adjustedProducts->load('product')->toArray());

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

    public function approveNormal(Adjustment $adjustment): RedirectResponse
    {
        abort_if(Gate::denies('adjustments.approval'), 403);
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
                    }
                }

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
            DB::beginTransaction();

            // Eager load to avoid N+1 surprises
            $adjustment->load('adjustedProducts.product');

            foreach ($adjustment->adjustedProducts as $adjustedProduct) {
                $product    = $adjustedProduct->product;
                $locationId = $adjustment->location_id;

                $quantityTax = max(0, (int) ($adjustedProduct->quantity_tax ?? 0));
                $quantityNonTax = max(0, (int) ($adjustedProduct->quantity_non_tax ?? 0));
                $qtyToBreak = $quantityTax + $quantityNonTax;

                // Backward compatibility for legacy rows
                if ($qtyToBreak === 0 && (int) $adjustedProduct->quantity > 0) {
                    if ((bool) $adjustedProduct->is_taxable) {
                        $quantityTax = (int) $adjustedProduct->quantity;
                    } else {
                        $quantityNonTax = (int) $adjustedProduct->quantity;
                    }

                    $qtyToBreak = $quantityTax + $quantityNonTax;
                }

                // Lock stock row; must exist for breakage
                $productStock = ProductStock::where('product_id', $product->id)
                    ->where('location_id', $locationId)
                    ->lockForUpdate()
                    ->first();

                if (!$productStock) {
                    throw new Exception("Stok tidak ditemukan untuk {$product->product_name} di lokasi ini.");
                }

                // Snapshot before
                $beforeTax       = (int) ($productStock->quantity_tax ?? 0);
                $beforeNonTax    = (int) ($productStock->quantity_non_tax ?? 0);
                $beforeBrokenTax = (int) ($productStock->broken_quantity_tax ?? 0);
                $beforeBrokenNon = (int) ($productStock->broken_quantity_non_tax ?? 0);
                $prevQuantityAtLocation = (int) ($productStock->quantity ?? ($beforeTax + $beforeNonTax));

                if ($product->serial_number_required) {
                    $serialIds = json_decode($adjustedProduct->serial_numbers, true) ?? [];
                    $serialIds = array_map('intval', $serialIds);

                    // 1) strict quantity vs serials
                    if (count($serialIds) !== $qtyToBreak) {
                        throw new Exception("Jumlah serial (" . count($serialIds) . ") tidak sama dengan kuantitas breakage ($qtyToBreak) untuk {$product->product_name}.");
                    }

                    // 2) fetch serials with all safety constraints
                    $serials = ProductSerialNumber::query()
                        ->whereIn('id', $serialIds)
                        ->where('product_id', $product->id)
                        ->where('location_id', $locationId)
                        ->whereNull('dispatch_detail_id')
                        ->where(function ($q) {
                            $q->whereNull('is_broken')->orWhere('is_broken', false);
                        })
                        ->lockForUpdate()
                        ->get();

                    if ($serials->count() !== $qtyToBreak) {
                        throw new Exception("Beberapa serial tidak tersedia/valid untuk {$product->product_name}.");
                    }

                    // 3) classify by tax status based on serial.tax_id
                    $taxableCount    = $serials->whereNotNull('tax_id')->count();
                    $nonTaxableCount = $serials->count() - $taxableCount;

                    $quantityTax = $taxableCount;
                    $quantityNonTax = $nonTaxableCount;
                    $qtyToBreak = $taxableCount + $nonTaxableCount;

                    // 4) sufficiency check vs current stock
                    if ($taxableCount > $beforeTax) {
                        throw new Exception("Stok kena pajak tidak cukup untuk {$product->product_name} (butuh {$taxableCount}, ada {$beforeTax}).");
                    }
                    if ($nonTaxableCount > $beforeNonTax) {
                        throw new Exception("Stok non-pajak tidak cukup untuk {$product->product_name} (butuh {$nonTaxableCount}, ada {$beforeNonTax}).");
                    }

                    // 5) mutate stock
                    $productStock->quantity_tax            = $beforeTax - $taxableCount;
                    $productStock->quantity_non_tax        = $beforeNonTax - $nonTaxableCount;
                    $productStock->broken_quantity_tax     = $beforeBrokenTax + $taxableCount;
                    $productStock->broken_quantity_non_tax = $beforeBrokenNon + $nonTaxableCount;
                    $productStock->quantity                = max(0, ($productStock->quantity_tax ?? 0) + ($productStock->quantity_non_tax ?? 0));
                    $productStock->broken_quantity         = ($productStock->broken_quantity_tax ?? 0) + ($productStock->broken_quantity_non_tax ?? 0);
                    $productStock->save();

                    // 6) mark serials as broken
                    ProductSerialNumber::whereIn('id', $serialIds)
                        ->update([
                            'is_broken' => true,
                        ]);

                } else {
                    if ($quantityTax > $beforeTax) {
                        throw new Exception("Stok kena pajak tidak cukup untuk {$product->product_name} (butuh {$quantityTax}, ada {$beforeTax}).");
                    }

                    if ($quantityNonTax > $beforeNonTax) {
                        throw new Exception("Stok non-pajak tidak cukup untuk {$product->product_name} (butuh {$quantityNonTax}, ada {$beforeNonTax}).");
                    }

                    $productStock->quantity_tax            = $beforeTax - $quantityTax;
                    $productStock->quantity_non_tax        = $beforeNonTax - $quantityNonTax;
                    $productStock->broken_quantity_tax     = $beforeBrokenTax + $quantityTax;
                    $productStock->broken_quantity_non_tax = $beforeBrokenNon + $quantityNonTax;
                    $productStock->quantity                = max(0, ($productStock->quantity_tax ?? 0) + ($productStock->quantity_non_tax ?? 0));
                    $productStock->broken_quantity         = ($productStock->broken_quantity_tax ?? 0) + ($productStock->broken_quantity_non_tax ?? 0);
                    $productStock->save();
                }

                // Re-read persisted "after" values from the model for accurate logging
                $afterTax       = (int) ($productStock->quantity_tax ?? 0);
                $afterNonTax    = (int) ($productStock->quantity_non_tax ?? 0);
                $afterBrokenTax = (int) ($productStock->broken_quantity_tax ?? 0);
                $afterBrokenNon = (int) ($productStock->broken_quantity_non_tax ?? 0);
                $newQuantityAtLocation = (int) ($productStock->quantity ?? ($afterTax + $afterNonTax));

                Transaction::create([
                    'product_id'                     => $product->id,
                    'setting_id'                     => session('setting_id'),
                    'type'                           => 'ADJ',
                    'quantity'                       => $qtyToBreak,

                    'previous_quantity'              => $prevQuantityAtLocation,
                    'after_quantity'                 => $newQuantityAtLocation,
                    'previous_quantity_at_location'  => $prevQuantityAtLocation,
                    'after_quantity_at_location'     => $newQuantityAtLocation,

                    'quantity_tax'                   => $afterTax,
                    'quantity_non_tax'               => $afterNonTax,
                    'broken_quantity_tax'            => $afterBrokenTax,
                    'broken_quantity_non_tax'        => $afterBrokenNon,
                    'broken_quantity'                => $afterBrokenTax + $afterBrokenNon,
                    'current_quantity'               => $newQuantityAtLocation,

                    'location_id'                    => $locationId,
                    'user_id'                        => auth()->id(),
                    'reason'                         => 'Breakage adjustment approved',

                    'created_at'                     => now(),
                    'updated_at'                     => now(),
                ]);

                // Optional: keep product-level broken counter if you use it
                if ($qtyToBreak > 0) {
                    $product->increment('broken_quantity', $qtyToBreak);
                }
            }

            $adjustment->update(['status' => 'approved']);
            DB::commit();

            toast('Breakage Adjustment Approved!', 'success');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Breakage adjustment approval failed', [
                'adjustment_id' => $adjustment->id,
                'error' => $e->getMessage(),
            ]);
            session()->flash('error', 'Failed to approve breakage adjustment.');
            toast('Error Approving Breakage Adjustment!', 'error');
        }

        return redirect()->route('adjustments.index');
    }

    public function reject(Adjustment $adjustment): RedirectResponse
    {
        abort_unless(Gate::any([
            'adjustments.reject',
            'adjustments.approval',
            'adjustments.breakage.approval',
        ]), 403);
        // Update the status of the adjustment to 'rejected'
        $adjustment->update(['status' => 'rejected']);

        // Optionally, you can add a success message to be displayed after the redirect
        toast('Penyesuaian Ditolak!', 'info');

        // Redirect back to the adjustments index
        return redirect()->route('adjustments.index');
    }
}
