<?php

namespace Modules\Adjustment\Http\Controllers;

use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Adjustment\DataTables\AdjustmentsDataTable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

    public function index(AdjustmentsDataTable $dataTable)
    {
        abort_if(Gate::denies('adjustments.access'), 403);

        return $dataTable->render('adjustment::index');
    }


    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('adjustments.create'), 403);

        $currentSettingId = session('setting_id');

        // Fetch locations based on the current setting_id
        $locations = Location::where('setting_id', $currentSettingId)->get();

        return view('adjustment::create', compact('locations'));
    }

    public function createBreakage(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('adjustments.breakage.create'), 403);

        $currentSettingId = session('setting_id');

        // Fetch locations based on the current setting_id
        $locations = Location::where('setting_id', $currentSettingId)->get();

        return view('adjustment::create-breakage', compact('locations'));
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
            'quantities' => 'required|array',
            'quantities.*' => 'integer|min:1', // Ensure each quantity is at least 1
            'serial_numbers' => 'nullable|array',
            'serial_numbers.*' => 'array', // Ensure each product's serials are arrays
            'serial_numbers.*.*' => 'integer|exists:product_serial_numbers,id', // Validate each serial number ID
            'is_taxables' => 'nullable|array', // Ensure it's an array
            'is_taxables.*' => 'boolean', // Ensure each value is a boolean
            'location_id' => 'required|exists:locations,id', // Ensure location_id is valid
        ], [
            'location_id.required' => 'Lokasi wajib diisi.',
            'serial_numbers.*.count' => 'Jumlah serial number harus sesuai dengan kuantitas produk yang dipilih.'
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
                $quantity = (int) $request->quantities[$key];

                if ($serialCount !== $quantity) {
                    return back()
                        ->withErrors([
                            "serial_numbers.$key" => "Jumlah serial number untuk produk {$product->product_name} harus sama dengan kuantitas ($quantity)."
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
                $serialNumbersJson = isset($request->serial_numbers[$key])
                    ? json_encode($request->serial_numbers[$key])
                    : json_encode([]);

                $isTaxable = isset($request->is_taxables[$key]) && $request->is_taxables[$key];

                AdjustedProduct::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $id,
                    'quantity' => $request->quantities[$key],
                    'type' => 'sub',
                    'serial_numbers' => $serialNumbersJson,
                    'is_taxable' => $isTaxable,
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
            'quantities' => 'required|array',
            'serial_numbers' => 'nullable|array',
            'serial_numbers.*' => 'array', // Ensure each product's serials are arrays
            'serial_numbers.*.*' => 'integer|exists:product_serial_numbers,id', // Validate each serial number ID
            'is_taxables' => 'nullable|array', // Validate is_taxables as an array
            'is_taxables.*' => 'boolean', // Ensure each value is a boolean (0 or 1)
        ]);

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
                // Convert serial_numbers array to JSON (ensuring it's an array)
                $serialNumbersJson = isset($request->serial_numbers[$key])
                    ? json_encode($request->serial_numbers[$key])
                    : json_encode([]);

                // Ensure `is_taxable` is stored as `0` (false) or `1` (true)
                $isTaxable = isset($request->is_taxables[$key]) ? (int) $request->is_taxables[$key] : 0;

                AdjustedProduct::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $id,
                    'quantity' => $request->quantities[$key],
                    'type' => 'sub',
                    'serial_numbers' => $serialNumbersJson, // Store serial numbers as JSON
                    'is_taxable' => $isTaxable, // Store taxable field
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

        if ($adjustment->type === 'normal') {
            return $this->approveNormal($adjustment);
        } elseif ($adjustment->type === 'breakage') {
            return $this->approveBreakage($adjustment);
        }

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
                    $productStock->quantity_tax = $adjustedProduct->quantity_tax;
                    $productStock->quantity_non_tax = $adjustedProduct->quantity_non_tax;
                    $productStock->save();

                    $quantityTax = $adjustedProduct->quantity_tax;
                    $quantityNonTax = $adjustedProduct->quantity_non_tax;
                }

                // 🧮 After values
                $after_quantity_tax = $productStock->quantity_tax ?? 0;
                $after_quantity_non_tax = $productStock->quantity_non_tax ?? 0;

                // 🧾 Log the transaction
                Transaction::create([
                    'product_id' => $product->id,
                    'setting_id' => $settingId,
                    'type' => 'ADJ',
                    'quantity' => $adjustedProduct->quantity,

                    'previous_quantity' => $prev_quantity_tax + $prev_quantity_non_tax,
                    'after_quantity' => $after_quantity_tax + $after_quantity_non_tax,
                    'previous_quantity_at_location' => $prev_quantity_tax + $prev_quantity_non_tax,
                    'after_quantity_at_location' => $after_quantity_tax + $after_quantity_non_tax,

                    'quantity_tax' => $after_quantity_tax,
                    'quantity_non_tax' => $after_quantity_non_tax,
                    'broken_quantity_tax' => $productStock->broken_quantity_tax ?? 0,
                    'broken_quantity_non_tax' => $productStock->broken_quantity_non_tax ?? 0,
                    'broken_quantity' => ($productStock->broken_quantity_tax ?? 0) + ($productStock->broken_quantity_non_tax ?? 0),
                    'current_quantity' => $after_quantity_tax + $after_quantity_non_tax,

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
        abort_if(Gate::denies('adjustments.breakage.approval'), 403);
        try {
            DB::beginTransaction();
            foreach ($adjustment->adjustedProducts as $adjustedProduct) {
                $product = $adjustedProduct->product;
                $locationId = $adjustment->location_id;
                $quantityToAdjust = $adjustedProduct->quantity;

                $productStock = ProductStock::where('product_id', $product->id)
                    ->where('location_id', $locationId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // 🧮 Capture previous values
                $prev_quantity_tax = $productStock->quantity_tax ?? 0;
                $prev_quantity_non_tax = $productStock->quantity_non_tax ?? 0;
                $prev_broken_tax = $productStock->broken_quantity_tax ?? 0;
                $prev_broken_non_tax = $productStock->broken_quantity_non_tax ?? 0;

                $after_quantity_tax = $prev_quantity_tax;
                $after_quantity_non_tax = $prev_quantity_non_tax;
                $after_broken_tax = $prev_broken_tax;
                $after_broken_non_tax = $prev_broken_non_tax;

                if ($product->serial_number_required) {
                    $serialIds = json_decode($adjustedProduct->serial_numbers, true) ?? [];

                    $serials = ProductSerialNumber::whereIn('id', $serialIds)
                        ->where('product_id', $product->id)
                        ->where('location_id', $locationId)
                        ->get();

                    $taxableCount = $serials->whereNotNull('tax_id')->count();
                    $nonTaxableCount = count($serials) - $taxableCount;

                    // Mark serials as broken
                    ProductSerialNumber::whereIn('id', $serialIds)
                        ->update(['is_broken' => true]);

                    // Adjust stock
                    $productStock->decrement('quantity_tax', $taxableCount);
                    $productStock->decrement('quantity_non_tax', $nonTaxableCount);
                    $productStock->increment('broken_quantity_tax', $taxableCount);
                    $productStock->increment('broken_quantity_non_tax', $nonTaxableCount);

                    $after_quantity_tax -= $taxableCount;
                    $after_quantity_non_tax -= $nonTaxableCount;
                    $after_broken_tax += $taxableCount;
                    $after_broken_non_tax += $nonTaxableCount;
                } else {
                    $isTaxable = $adjustedProduct->is_taxable;

                    if ($isTaxable) {
                        if ($quantityToAdjust > $productStock->quantity_tax) {
                            throw new \Exception("Insufficient taxable stock for product {$product->product_name}");
                        }

                        $productStock->decrement('quantity_tax', $quantityToAdjust);
                        $productStock->increment('broken_quantity_tax', $quantityToAdjust);

                        $after_quantity_tax -= $quantityToAdjust;
                        $after_broken_tax += $quantityToAdjust;
                    } else {
                        if ($quantityToAdjust > $productStock->quantity_non_tax) {
                            throw new \Exception("Insufficient non-taxable stock for product {$product->product_name}");
                        }

                        $productStock->decrement('quantity_non_tax', $quantityToAdjust);
                        $productStock->increment('broken_quantity_non_tax', $quantityToAdjust);

                        $after_quantity_non_tax -= $quantityToAdjust;
                        $after_broken_non_tax += $quantityToAdjust;
                    }
                }

                // Save updated stock
                $productStock->save();

                // Log breakage as transaction
                Transaction::create([
                    'product_id' => $product->id,
                    'setting_id' => session('setting_id'),
                    'type' => 'ADJ',
                    'quantity' => $quantityToAdjust,

                    'previous_quantity' => $prev_quantity_tax + $prev_quantity_non_tax,
                    'after_quantity' => $after_quantity_tax + $after_quantity_non_tax,
                    'previous_quantity_at_location' => $prev_quantity_tax + $prev_quantity_non_tax,
                    'after_quantity_at_location' => $after_quantity_tax + $after_quantity_non_tax,

                    'quantity_tax' => $after_quantity_tax,
                    'quantity_non_tax' => $after_quantity_non_tax,
                    'broken_quantity_tax' => $after_broken_tax,
                    'broken_quantity_non_tax' => $after_broken_non_tax,
                    'broken_quantity' => $after_broken_tax + $after_broken_non_tax,
                    'current_quantity' => $after_quantity_tax + $after_quantity_non_tax,

                    'location_id' => $locationId,
                    'user_id' => auth()->id(),
                    'reason' => 'Breakage adjustment approved',

                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Optional: also increment broken_quantity in `products` table
                $product->increment('broken_quantity', $quantityToAdjust);
            }

            $adjustment->update(['status' => 'approved']);
            DB::commit();
            toast('Breakage Adjustment Approved!', 'success');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Breakage adjustment approval failed', ['error' => $e->getMessage()]);
            session()->flash('error', 'Failed to approve breakage adjustment.');
            toast('Error Approving Breakage Adjustment!', 'error');
        }

        return redirect()->route('adjustments.index');
    }

    public function reject(Adjustment $adjustment): RedirectResponse
    {
        abort_if(Gate::denies('adjustments.approval'), 403);
        // Update the status of the adjustment to 'rejected'
        $adjustment->update(['status' => 'rejected']);

        // Optionally, you can add a success message to be displayed after the redirect
        toast('Penyesuaian Ditolak!', 'info');

        // Redirect back to the adjustments index
        return redirect()->route('adjustments.index');
    }
}
