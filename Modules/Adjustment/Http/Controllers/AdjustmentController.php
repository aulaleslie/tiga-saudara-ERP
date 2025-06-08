<?php

namespace Modules\Adjustment\Http\Controllers;

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

class AdjustmentController extends Controller
{

    public function index(AdjustmentsDataTable $dataTable)
    {
        abort_if(Gate::denies('access_adjustments'), 403);

        return $dataTable->render('adjustment::index');
    }


    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('create_adjustments'), 403);

        $currentSettingId = session('setting_id');

        // Fetch locations based on the current setting_id
        $locations = Location::where('setting_id', $currentSettingId)->get();

        return view('adjustment::create', compact('locations'));
    }

    public function createBreakage(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('break.create'), 403);

        $currentSettingId = session('setting_id');

        // Fetch locations based on the current setting_id
        $locations = Location::where('setting_id', $currentSettingId)->get();

        return view('adjustment::create-breakage', compact('locations'));
    }


    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('create_adjustments'), 403);

        $request->validate([
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'note' => 'nullable|string|max:1000',
            'product_ids' => 'required',
            'quantities' => 'required',
            'types' => 'required',
            'location_id' => 'required|exists:locations,id', // Ensure location_id is provided and valid
        ]);

        DB::transaction(function () use ($request) {
            $adjustment = Adjustment::create([
                'date' => $request->date,
                'note' => $request->note,
                'type' => 'normal',  // Save the adjustment type
                'status' => 'pending',  // Set status to pending
                'location_id' => $request->location_id,  // Record the location_id
            ]);

            foreach ($request->product_ids as $key => $id) {
                AdjustedProduct::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $id,
                    'quantity' => $request->quantities[$key],
                    'type' => $request->types[$key]
                ]);
            }
        });

        toast('Penyesuaian Dibuat!', 'success');

        return redirect()->route('adjustments.index');
    }

    public function storeBreakage(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('break.create'), 403);

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
        abort_if(Gate::denies('show_adjustments'), 403);

        $adjustment->load(['adjustedProducts.product']);

        foreach ($adjustment->adjustedProducts as $adjustedProduct) {
            // Process Serial Numbers
            if (!empty($adjustedProduct->serial_numbers)) {
                $serialNumberIds = json_decode($adjustedProduct->serial_numbers, true);

                if (is_array($serialNumberIds)) {
                    $adjustedProduct->serialNumbers = ProductSerialNumber::whereIn('id', $serialNumberIds)
                        ->pluck('serial_number')
                        ->toArray();
                } else {
                    $adjustedProduct->serialNumbers = [];
                }
            } else {
                $adjustedProduct->serialNumbers = [];
            }
        }

        return view('adjustment::show', compact('adjustment'));
    }


    public function edit(Adjustment $adjustment): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('edit_adjustments'), 403);

        return view('adjustment::edit', compact('adjustment'));
    }


    public function update(Request $request, Adjustment $adjustment): RedirectResponse
    {
        abort_if(Gate::denies('edit_adjustments'), 403);

        $request->validate([
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'note' => 'nullable|string|max:1000',
            'product_ids' => 'required',
            'quantities' => 'required',
            'types' => 'required'
        ]);


        DB::transaction(function () use ($request, $adjustment) {
            $adjustment->update([
                'reference' => $request->reference,
                'date' => $request->date,
                'note' => $request->note
            ]);

            foreach ($adjustment->adjustedProducts as $adjustedProduct) {
                $adjustedProduct->delete();
            }

            foreach ($request->product_ids as $key => $id) {
                // Convert serial_numbers array to JSON (ensuring it's an array)
                $serialNumbersJson = isset($request->serial_numbers[$key])
                    ? json_encode($request->serial_numbers[$key])
                    : json_encode([]);

                AdjustedProduct::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $id,
                    'quantity' => $request->quantities[$key],
                    'type' => 'sub',
                    'serial_numbers' => $serialNumbersJson, // Store as JSON array
                ]);
            }
        });

        toast('Penyesuaian Diperbaharui!', 'info');

        return redirect()->route('adjustments.index');
    }

    public function editBreakage(Adjustment $adjustment): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('break.edit'), 403);

        $adjustment->load(['adjustedProducts.product']);

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
        abort_if(Gate::denies('break.edit'), 403);

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
        abort_if(Gate::denies('delete_adjustments'), 403);

        $adjustment->delete();

        toast('Adjustment Deleted!', 'warning');

        return redirect()->route('adjustments.index');
    }

    public function approve(Adjustment $adjustment): RedirectResponse
    {
        DB::beginTransaction();

        try {
            foreach ($adjustment->adjustedProducts as $adjustedProduct) {
                $product = Product::findOrFail($adjustedProduct->product_id);
                $locationId = $adjustment->location_id;
                $quantityToAdjust = $adjustedProduct->quantity;

                // Lock product stock at the specific location
                $productStock = ProductStock::where('product_id', $product->id)
                    ->where('location_id', $locationId)
                    ->lockForUpdate()
                    ->first();

                // Ensure product stock exists
                if (!$productStock) {
                    $productStock = ProductStock::create([
                        'product_id' => $product->id,
                        'location_id' => $locationId,
                        'quantity' => 0,
                        'quantity_tax' => 0,
                        'quantity_non_tax' => 0,
                        'broken_quantity' => 0,
                        'broken_quantity_tax' => 0,
                        'broken_quantity_non_tax' => 0,
                    ]);
                }

                if ($adjustment->type === 'normal') {
                    // Handle Normal Adjustment
                    $quantityChange = $adjustedProduct->type == 'sub' ? -$quantityToAdjust : $quantityToAdjust;

                    // Update product quantity
                    $product->increment('product_quantity', $quantityChange);

                    // Log transaction for normal adjustment
                    Transaction::create([
                        'product_id' => $product->id,
                        'setting_id' => session('setting_id'),
                        'type' => 'ADJ',
                        'quantity' => $quantityChange,
                        'previous_quantity' => 0,
                        'previous_quantity_at_location' => 0,
                        'after_quantity' => $product->product_quantity,
                        'after_quantity_at_location' => $product->product_quantity,
                        'current_quantity' => $product->product_quantity,
                        'quantity_tax' => 0,
                        'quantity_non_tax' => 0,
                        'broken_quantity' => 0,
                        'broken_quantity_tax' => 0,
                        'broken_quantity_non_tax' => 0,
                        'location_id' => $locationId,
                        'user_id' => auth()->id(),
                        'reason' => 'Adjustment approved',
                    ]);

                    $productStock->increment('quantity', $quantityChange);

                } elseif ($adjustment->type === 'breakage') {
                    // Handle Breakage Adjustment

                    // Capture previous values for logging
                    $previous_quantity_tax = $productStock->quantity_tax;
                    $previous_quantity_non_tax = $productStock->quantity_non_tax;
                    $previous_broken_quantity_tax = $productStock->broken_quantity_tax;
                    $previous_broken_quantity_non_tax = $productStock->broken_quantity_non_tax;

                    if ($product->serial_number_required) {
                        // Handle Serial Numbered Product
                        $serialNumberIds = json_decode($adjustedProduct->serial_numbers, true) ?? [];
                        $taxableSerialCount = ProductSerialNumber::whereIn('id', $serialNumberIds)
                            ->whereNotNull('tax_id') // Count taxable serials
                            ->count();

                        $nonTaxableSerialCount = count($serialNumberIds) - $taxableSerialCount;

                        // Mark serial numbers as broken
                        ProductSerialNumber::whereIn('id', $serialNumberIds)
                            ->update(['is_broken' => true]);

                        // Reduce stock accordingly
                        $productStock->decrement('quantity_tax', $taxableSerialCount);
                        $productStock->increment('broken_quantity_tax', $taxableSerialCount);

                        $productStock->decrement('quantity_non_tax', $nonTaxableSerialCount);
                        $productStock->increment('broken_quantity_non_tax', $nonTaxableSerialCount);
                    } else {
                        // Handle Non-Serial Numbered Product using is_taxable flag
                        if ($adjustedProduct->is_taxable) {
                            // Deduct from tax-tracked stock
                            if ($quantityToAdjust <= $productStock->quantity_tax) {
                                $productStock->decrement('quantity_tax', $quantityToAdjust);
                                $productStock->increment('broken_quantity_tax', $quantityToAdjust);
                            } else {
                                // Deduct from tax-tracked stock first, then non-tax
                                $remainingBreakage = $quantityToAdjust - $productStock->quantity_tax;
                                $productStock->increment('broken_quantity_tax', $productStock->quantity_tax);
                                $productStock->decrement('quantity_tax', $productStock->quantity_tax);

                                $productStock->increment('broken_quantity_non_tax', $remainingBreakage);
                                $productStock->decrement('quantity_non_tax', $remainingBreakage);
                            }
                        } else {
                            // Deduct only from non-taxable stock
                            if ($quantityToAdjust <= $productStock->quantity_non_tax) {
                                $productStock->decrement('quantity_non_tax', $quantityToAdjust);
                                $productStock->increment('broken_quantity_non_tax', $quantityToAdjust);
                            } else {
                                throw new \Exception("Insufficient non-taxable stock for product {$product->id} at location {$locationId}");
                            }
                        }
                    }

                    // Capture after values for logging
                    $after_quantity_tax = $productStock->quantity_tax;
                    $after_quantity_non_tax = $productStock->quantity_non_tax;
                    $after_broken_quantity_tax = $productStock->broken_quantity_tax;
                    $after_broken_quantity_non_tax = $productStock->broken_quantity_non_tax;

                    // Log breakage transaction
                    Transaction::create([
                        'product_id' => $product->id,
                        'setting_id' => session('setting_id'),
                        'type' => 'ADJ',
                        'quantity' => $quantityToAdjust,

                        // 📌 Capture previous values
                        'previous_quantity' => $previous_quantity_tax + $previous_quantity_non_tax, // ✅ Total quantity before
                        'after_quantity' => $after_quantity_tax + $after_quantity_non_tax, // ✅ Total quantity after
                        'previous_quantity_at_location' => $productStock->quantity, // ✅ Stock before at location
                        'after_quantity_at_location' => $productStock->quantity, // ✅ Stock after at location (unchanged)

                        // 📌 Stock quantities after the breakage update
                        'current_quantity' => $productStock->quantity, // ✅ Unchanged global stock
                        'broken_quantity' => $productStock->broken_quantity_tax + $productStock->broken_quantity_non_tax, // ✅ Total broken

                        // 📌 New breakage adjustments
                        'broken_quantity_tax' => $productStock->broken_quantity_tax, // ✅ Broken taxable quantity
                        'broken_quantity_non_tax' => $productStock->broken_quantity_non_tax, // ✅ Broken non-taxable quantity
                        'quantity_non_tax' => $after_quantity_non_tax, // ✅ Remaining non-tax stock
                        'quantity_tax' => $after_quantity_tax, // ✅ Remaining tax stock

                        'location_id' => $locationId,
                        'user_id' => auth()->id(),
                        'reason' => 'Breakage adjustment approved',

                        // 📌 Capture previous and after values for integrity checking
                        'previous_quantity_tax' => $previous_quantity_tax,
                        'after_quantity_tax' => $after_quantity_tax,
                        'previous_quantity_non_tax' => $previous_quantity_non_tax,
                        'after_quantity_non_tax' => $after_quantity_non_tax,
                        'previous_broken_quantity_tax' => $previous_broken_quantity_tax,
                        'after_broken_quantity_tax' => $after_broken_quantity_tax,
                        'previous_broken_quantity_non_tax' => $previous_broken_quantity_non_tax,
                        'after_broken_quantity_non_tax' => $after_broken_quantity_non_tax,
                    ]);

                    $product->increment('broken_quantity', $quantityToAdjust);
                }
            }

            // Update adjustment status to approved
            $adjustment->update(['status' => 'approved']);

            DB::commit();
            toast('Adjustment Approved!', 'success');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Adjustment approval failed', ['error' => $e->getMessage()]);
            session()->flash('error', 'Failed to approve adjustment. Please try again.');
            toast('Error Approving Adjustment!', 'error');
        }

        return redirect()->route('adjustments.index');
    }

    public function reject(Adjustment $adjustment): RedirectResponse
    {
        // Update the status of the adjustment to 'rejected'
        $adjustment->update(['status' => 'rejected']);

        // Optionally, you can add a success message to be displayed after the redirect
        toast('Penyesuaian Ditolak!', 'info');

        // Redirect back to the adjustments index
        return redirect()->route('adjustments.index');
    }
}
