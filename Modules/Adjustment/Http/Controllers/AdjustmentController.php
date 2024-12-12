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
        abort_if(Gate::denies('create_adjustments'), 403);

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
        abort_if(Gate::denies('create_adjustments'), 403);

        $request->validate([
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'note' => 'nullable|string|max:1000',
            'product_ids' => 'required',
            'quantities' => 'required',
            'location_id' => 'required|exists:locations,id', // Ensure location_id is provided and valid
        ]);

        DB::transaction(function () use ($request) {
            $adjustment = Adjustment::create([
                'date' => $request->date,
                'note' => $request->note,
                'type' => 'breakage',  // Save the adjustment type
                'status' => 'pending',  // Set status to pending
                'location_id' => $request->location_id,  // Record the location_id
            ]);

            foreach ($request->product_ids as $key => $id) {
                AdjustedProduct::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $id,
                    'quantity' => $request->quantities[$key],
                    'type' => 'sub'
                ]);
            }
        });

        toast('Penyesuaian Barang Rusak Dibuat!', 'success');

        return redirect()->route('adjustments.index');
    }


    public function show(Adjustment $adjustment): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('show_adjustments'), 403);

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
                AdjustedProduct::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $id,
                    'quantity' => $request->quantities[$key],
                    'type' => $request->types[$key]
                ]);
            }
        });

        toast('Penyesuaian Diperbaharui!', 'info');

        return redirect()->route('adjustments.index');
    }

    public function editBreakage(Adjustment $adjustment): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('edit_adjustments'), 403);

        return view('adjustment::edit-breakage', compact('adjustment'));
    }


    public function updateBreakage(Request $request, Adjustment $adjustment): RedirectResponse
    {
        abort_if(Gate::denies('edit_adjustments'), 403);

        $request->validate([
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'note' => 'nullable|string|max:1000',
            'product_ids' => 'required',
            'quantities' => 'required'
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
                AdjustedProduct::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $id,
                    'quantity' => $request->quantities[$key],
                    'type' => 'sub'
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
            // Iterate through the adjusted products
            foreach ($adjustment->adjustedProducts as $adjustedProduct) {
                $product = Product::findOrFail($adjustedProduct->product_id);

                if ($adjustment->type === 'normal') {
                    // Determine the quantity to update and store in the transaction
                    $quantity = $adjustedProduct->quantity;
                    if ($adjustedProduct->type == 'sub') {
                        $quantity = -$quantity; // Make quantity negative for subtraction
                    }

                    // Update product quantity for normal adjustment
                    $product->update([
                        'product_quantity' => $product->product_quantity + $quantity
                    ]);

                    // Create a transaction for the adjustment
                    Transaction::create([
                        'product_id' => $product->id,
                        'setting_id' => session('setting_id'),
                        'type' => 'ADJ',
                        'quantity' => $quantity,
                        'current_quantity' => $product->product_quantity,
                        'broken_quantity' => 0, // No change in broken quantity for normal adjustments
                        'location_id' => $adjustment->location_id,
                        'user_id' => auth()->id(),
                        'reason' => 'Adjustment approved',
                    ]);

                } elseif ($adjustment->type === 'breakage') {
                    // Update broken quantity for breakage adjustment
                    $product->update([
                        'broken_quantity' => $product->broken_quantity + $adjustedProduct->quantity
                    ]);

                    // Create a transaction for the breakage adjustment
                    Transaction::create([
                        'product_id' => $product->id,
                        'setting_id' => session('setting_id'),
                        'type' => 'ADJ',
                        'quantity' => 0,
                        'current_quantity' => $product->product_quantity, // No change in current quantity for breakage
                        'broken_quantity' => $product->broken_quantity, // Update broken quantity
                        'location_id' => $adjustment->location_id,
                        'user_id' => auth()->id(),
                        'reason' => 'Breakage adjustment approved',
                    ]);
                }
            }

            // Update adjustment status to approved
            $adjustment->update(['status' => 'approved']);

            DB::commit();
            toast('Penyesuain Disetujui!', 'warning');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Adjustment approval failed', ['error' => $e->getMessage()]);
            session()->flash('error', 'Gagal menyetujui penyesuaian. Silakan coba lagi.');
            toast('Kesalahan saat Menyetujui Penyesuaian!', 'error');
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
