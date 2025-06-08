<?php

namespace Modules\Adjustment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Modules\Adjustment\DataTables\StockTransfersDataTable;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Http\Requests\StockTransferRequest;
use Modules\Adjustment\Http\Requests\UpdateStockTransferRequest;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class TransferStockController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(StockTransfersDataTable $dataTable)
    {
        return $dataTable->render('adjustment::transfers.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        $currentSettingId = session('setting_id');
        $currentSetting = Setting::find($currentSettingId);
        $settings = Setting::all();
        $locations = Location::where('setting_id', $currentSettingId)->get();
        $destinationLocations = Location::all();

        return view('adjustment::transfers.create', compact('currentSetting', 'settings', 'locations', "destinationLocations"));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StockTransferRequest $request): RedirectResponse
    {
        // Get validated data
        $validated = $request->validated();

        // Create the transfer record
        $transfer = Transfer::create([
            'origin_location_id' => $validated['origin_location'],
            'destination_location_id' => $validated['destination_location'],
            'created_by' => auth()->id(), // Record the creator
            'status' => 'PENDING', // Initial status
        ]);

        // Loop through the products and create transfer product records
        foreach ($validated['product_ids'] as $index => $productId) {
            $quantity = $validated['quantities'][$index];

            TransferProduct::create([
                'transfer_id' => $transfer->id,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
        }

        toast('Transfer Stok Dibuat!', 'success');
        //
        return redirect()->route('transfers.index');
    }

    /**
     * Show the specified resource.
     */
    public function show(Transfer $transfer): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        // Load related data (origin, destination, products)
        $transfer->load([
            'originLocation.setting', // Load the setting for origin location
            'destinationLocation.setting', // Load the setting for destination location
            'products.product',
        ]);

        // Return the view with the transfer data
        return view('adjustment::transfers.show', compact('transfer'));
    }

    /**
     * Approve the stock transfer.
     *
     * @param  Transfer  $transfer
     * @return RedirectResponse
     */
    public function approve(Transfer $transfer): RedirectResponse
    {
        // Update the transfer status, approved, and approval time
        $transfer->update([
            'status' => 'APPROVED',
            'approved_by' => auth()->id(),
            'approved_at' => Carbon::now(),
        ]);

        toast('Transfer Stok Disetujui!', 'success');

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Reject the stock transfer.
     *
     * @param  Transfer  $transfer
     * @return RedirectResponse
     */
    public function reject(Transfer $transfer): RedirectResponse
    {
        // Update the transfer status, rejected, and rejection time
        $transfer->update([
            'status' => 'REJECTED',
            'rejected_by' => auth()->id(),
            'rejected_at' => Carbon::now(),
        ]);

        toast('Transfer Stok Ditolak!', 'warning');

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Dispatch the stock transfer.
     *
     * @param  Transfer  $transfer
     * @return RedirectResponse
     */
    public function dispatchShipment(Transfer $transfer): RedirectResponse
    {
        // Update the transfer status to DISPATCHED and set the dispatcher and timestamp
        $transfer->update([
            'status' => 'DISPATCHED',
            'dispatched_by' => auth()->id(),
            'dispatched_at' => Carbon::now(),
        ]);

        // Create transactions for each product in the transfer
        foreach ($transfer->products as $product) {
            $currentQuantity = $product->product->product_quantity - $product->quantity;

            // Create the transaction record for the dispatch
            Transaction::create([
                'product_id' => $product->product->id,
                'setting_id' => $transfer->originLocation->setting->id,
                'type' => 'TRF',
                'quantity' => -1 * $product->quantity, // Negative quantity for dispatch
                'current_quantity' => $currentQuantity,
                'broken_quantity' => 0,
                'previous_quantity' => $product->product->product_quantity,
                'previous_quantity_at_location' => $product->product->product_quantity,
                'after_quantity' => $currentQuantity,
                'after_quantity_at_location' => $currentQuantity,
                'quantity_tax' => 0,
                'quantity_non_tax' => 0,
                'broken_quantity' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'location_id' => $transfer->originLocation->id,
                'user_id' => auth()->id(),
                'reason' => 'Transfer stock to ' . $transfer->destinationLocation->setting->company_name . ' - ' . $transfer->destinationLocation->name,
            ]);

            // Update the product quantity after dispatch
            $product->product->update([
                'product_quantity' => $currentQuantity,
            ]);
        }

        toast('Transfer Stok Dikirim!', 'info');

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Receive the stock transfer.
     *
     * @param  Transfer  $transfer
     * @return RedirectResponse
     */
    public function receive(Transfer $transfer): RedirectResponse
    {
        // Update the transfer status to RECEIVED and set the received by and timestamp
        $transfer->update([
            'status' => 'RECEIVED',
            'received_by' => auth()->id(),
            'received_at' => Carbon::now(),
        ]);

        // Create transactions for each product in the transfer
        foreach ($transfer->products as $product) {
            $currentQuantity = $product->product->product_quantity + $product->quantity;

            // Create the transaction record for the receive
            Transaction::create([
                'product_id' => $product->product->id,
                'setting_id' => $transfer->destinationLocation->setting->id,
                'type' => 'TRF',
                'quantity' => $product->quantity, // Positive quantity for receiving
                'current_quantity' => $currentQuantity,
                'broken_quantity' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'previous_quantity' => $product->product->product_quantity,
                'previous_quantity_at_location' => $product->product->product_quantity,
                'after_quantity' => $currentQuantity,
                'after_quantity_at_location' => $currentQuantity,
                'quantity_tax' => 0,
                'quantity_non_tax' => 0,
                'location_id' => $transfer->destinationLocation->id,
                'user_id' => auth()->id(),
                'reason' => 'Received stock from ' . $transfer->originLocation->setting->company_name . ' - ' . $transfer->originLocation->name,
            ]);

            // Update the product quantity after receiving
            $product->product->update([
                'product_quantity' => $currentQuantity,
            ]);
        }

        toast('Transfer Stok Diterima!', 'info');

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Show the form for editing the specified transfer.
     *
     * @param  Transfer  $transfer
     * @return View|Factory|Application|\Illuminate\Contracts\Foundation\Application
     */
    public function edit(Transfer $transfer): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        // Load the necessary data for the form
        $currentSetting = $transfer->originLocation->setting;
        $settings = Setting::all();
        $locations = Location::where('setting_id', $currentSetting->id)->get();
        $destinationLocations = Location::all();

        // Load the transfer products and other details
        $transfer->load('products.product');

        return view('adjustment::transfers.edit', compact('transfer', 'currentSetting', 'settings', 'locations', 'destinationLocations'));
    }

    /**
     * Update the specified transfer in storage.
     *
     * @param  UpdateStockTransferRequest  $request
     * @param  Transfer  $transfer
     * @return RedirectResponse
     */
    public function update(UpdateStockTransferRequest $request, Transfer $transfer): RedirectResponse
    {
        // Get validated data
        $validated = $request->validated();

        // Delete existing products and add the updated ones
        $transfer->products()->delete();

        // Loop through the updated products and recreate the transfer product records
        foreach ($validated['product_ids'] as $index => $productId) {
            $quantity = $validated['quantities'][$index];

            TransferProduct::create([
                'transfer_id' => $transfer->id,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
        }

        toast('Stock Transfer Updated!', 'success');
        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Transfer $transfer): RedirectResponse
    {
        // Check if the transfer can be deleted (optional logic to check if the status is allowed for deletion)
        if ($transfer->status !== 'PENDING') {
            return redirect()->route('transfers.index')->with('error', 'Only pending transfers can be deleted.');
        }

        // Delete the transfer and its associated products
        $transfer->delete();

        toast('Transfer Stok Dihapus!', 'warning');

        // Redirect to transfers index with a success message
        return redirect()->route('transfers.index');
    }
}
