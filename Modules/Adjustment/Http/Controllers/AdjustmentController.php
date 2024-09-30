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
            'types' => 'nullable|string',
            'location_id' => 'required|exists:locations,id', // Ensure location_id is provided and valid
        ]);

        DB::transaction(function () use ($request) {
            $adjustment = Adjustment::create([
                'date' => $request->date ?? '',
                'note' => $request->note ?? '',
                'type' => 'normal',  // normal atau breakage
                'status' => 'pending',
                'location_id' => $request->location_id ?? '',
            ]);

            foreach ($request->product_ids as $key => $id) {
                $product = Product::findOrFail($id);
                // Simpan quantity aktual dari produk sebagai bagian dari penyesuaian
                AdjustedProduct::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $id ?? '',
                    'quantity' => $request->quantities[$key], // Ini adalah quantity yang disesuaikan
                    'type' => $request->types[$key] ?? ''
                ]);
            }
        });

        toast('Adjustment Created!', 'success');

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

        toast('Adjustment Created!', 'success');

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
            'types' => 'nullable|string'
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

        toast('Adjustment Updated!', 'info');

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

        toast('Adjustment Updated!', 'info');

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
            // Iterasi melalui produk yang disesuaikan
            foreach ($adjustment->adjustedProducts as $adjustedProduct) {
                $product = Product::findOrFail($adjustedProduct->product_id);

                if ($adjustment->type === 'normal') {
                    // Pastikan stok sesuai dengan quantity yang disesuaikan
                    $quantity = $adjustedProduct->quantity;

                    // Update stok produk agar sama dengan quantity yang disesuaikan
                    $product->update([
                        'product_quantity' => $quantity
                    ]);

                    // Buat transaksi untuk penyesuaian
                    Transaction::create([
                        'product_id' => $product->id,
                        'setting_id' => session('setting_id'),
                        'type' => 'ADJ',
                        'quantity' => $quantity,
                        'current_quantity' => $product->product_quantity, // Nilai stok yang diperbarui
                        'broken_quantity' => 0, // Tidak ada perubahan dalam jumlah rusak untuk penyesuaian normal
                        'location_id' => $adjustment->location_id,
                        'user_id' => auth()->id(),
                        'reason' => 'Adjustment approved',
                    ]);

                } elseif ($adjustment->type === 'breakage') {
                    // Tangani penyesuaian tipe breakage, tetap sinkron dengan jumlah rusak
                    $product->update([
                        'broken_quantity' => $product->broken_quantity + $adjustedProduct->quantity
                    ]);

                    // Buat transaksi untuk penyesuaian breakage
                    Transaction::create([
                        'product_id' => $product->id,
                        'setting_id' => session('setting_id'),
                        'type' => 'ADJ',
                        'quantity' => 0,
                        'current_quantity' => $product->product_quantity, // Tidak ada perubahan dalam jumlah saat ini untuk breakage
                        'broken_quantity' => $product->broken_quantity, // Perbarui jumlah rusak
                        'location_id' => $adjustment->location_id,
                        'user_id' => auth()->id(),
                        'reason' => 'Breakage adjustment approved',
                    ]);
                }
            }

            // Update status penyesuaian menjadi disetujui
            $adjustment->update(['status' => 'approved']);

            DB::commit();
            toast('Adjustment Approved!', 'success');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal menyetujui penyesuaian', ['error' => $e->getMessage()]);
            session()->flash('error', 'Gagal menyetujui penyesuaian. Silakan coba lagi.');
            toast('Error to Approve Adjustment!', 'error');
        }

        return redirect()->route('adjustments.index');
    }


    public function reject(Adjustment $adjustment): RedirectResponse
    {
        // Update the status of the adjustment to 'rejected'
        $adjustment->update(['status' => 'rejected']);

        // Optionally, you can add a success message to be displayed after the redirect
        toast('Adjustment Rejected!', 'info');

        // Redirect back to the adjustments index
        return redirect()->route('adjustments.index');
    }
}
