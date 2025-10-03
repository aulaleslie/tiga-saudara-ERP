<?php

namespace Modules\Adjustment\Http\Controllers;

use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Adjustment\DataTables\StockTransfersDataTable;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Http\Requests\StockTransferRequest;
use Modules\Adjustment\Http\Requests\UpdateStockTransferRequest;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Throwable;

class TransferStockController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(StockTransfersDataTable $dataTable)
    {
        abort_if(Gate::denies('stockTransfers.access'), 403);

        return $dataTable->render('adjustment::transfers.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('stockTransfers.create'), 403);

        $currentSettingId = (int) session('setting_id');
        $currentSetting   = Setting::find($currentSettingId);
        $settings         = Setting::all();
        $locations        = Location::where('setting_id', $currentSettingId)->get();
        $destinationLocations = Location::all();

        return view('adjustment::transfers.create', compact('currentSetting', 'settings', 'locations', 'destinationLocations'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StockTransferRequest $request): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.create'), 403);

        $validated = $request->validated();

        $transfer = Transfer::create([
            'origin_location_id'      => $validated['origin_location'],
            'destination_location_id' => $validated['destination_location'],
            'created_by'              => auth()->id(),
            'status'                  => Transfer::STATUS_PENDING,
        ]);

        foreach ($validated['product_ids'] as $index => $productId) {
            $quantity = (int) ($validated['quantities'][$index] ?? 0);

            TransferProduct::create([
                'transfer_id' => $transfer->id,
                'product_id'  => $productId,
                'quantity'    => $quantity,
            ]);
        }

        toast('Transfer Stok Dibuat!', 'success');

        return redirect()->route('transfers.index');
    }

    /**
     * Show the specified resource.
     */
    public function show(Transfer $transfer): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('stockTransfers.show'), 403);

        $transfer->load([
            'originLocation.setting',
            'destinationLocation.setting',
            'products.product',
            'createdBy',
            'approvedBy',
            'rejectedBy',
            'dispatchedBy',
            'receivedBy',
            'returnDispatchedBy',
            'returnReceivedBy',
        ]);

        $currentSettingId = (int) session('setting_id');

        $originSettingId      = $transfer->originLocation?->setting?->id;
        $destinationSettingId = $transfer->destinationLocation?->setting?->id;

        $isOrigin      = $originSettingId !== null && $currentSettingId === (int) $originSettingId;
        $isDestination = $destinationSettingId !== null && $currentSettingId === (int) $destinationSettingId;
        $requiresReturn = $transfer->requiresReturn();

        return view('adjustment::transfers.show', compact(
            'transfer',
            'isOrigin',
            'isDestination',
            'requiresReturn'
        ));
    }

    /**
     * Approve the stock transfer.
     */
    public function approve(Transfer $transfer): RedirectResponse
    {
        abort_unless(Gate::any(['stockTransfers.edit', 'stockTransfers.approval']), 403);

        $transfer->loadMissing('originLocation.setting');

        $currentSettingId = (int) session('setting_id');
        $originSettingId  = $transfer->originLocation?->setting?->id;

        if ($originSettingId === null || $currentSettingId !== (int) $originSettingId) {
            toast('Transfer hanya dapat disetujui oleh tenant asal.', 'error');

            return redirect()->route('transfers.show', $transfer->id);
        }

        if ($transfer->status !== Transfer::STATUS_PENDING) {
            toast('Transfer tidak dapat disetujui pada status saat ini.', 'error');

            return redirect()->route('transfers.show', $transfer->id);
        }

        $transfer->update([
            'status'      => Transfer::STATUS_APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => Carbon::now(),
        ]);

        toast('Transfer Stok Disetujui!', 'success');

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Reject the stock transfer.
     */
    public function reject(Transfer $transfer): RedirectResponse
    {
        abort_unless(Gate::any(['stockTransfers.edit', 'stockTransfers.approval']), 403);

        $transfer->loadMissing('originLocation.setting');

        $currentSettingId = (int) session('setting_id');
        $originSettingId  = $transfer->originLocation?->setting?->id;

        if ($originSettingId === null || $currentSettingId !== (int) $originSettingId) {
            toast('Transfer hanya dapat ditolak oleh tenant asal.', 'error');

            return redirect()->route('transfers.show', $transfer->id);
        }

        if ($transfer->status !== Transfer::STATUS_PENDING) {
            toast('Transfer tidak dapat ditolak pada status saat ini.', 'error');

            return redirect()->route('transfers.show', $transfer->id);
        }

        $transfer->update([
            'status'      => Transfer::STATUS_REJECTED,
            'rejected_by' => auth()->id(),
            'rejected_at' => Carbon::now(),
        ]);

        toast('Transfer Stok Ditolak!', 'warning');

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Dispatch the stock transfer.
     */
    public function dispatchShipment(Transfer $transfer): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.dispatch'), 403);

        if ($transfer->status !== Transfer::STATUS_APPROVED) {
            toast('Transfer harus disetujui sebelum dapat dikirim.', 'error');

            return redirect()->route('transfers.show', $transfer->id);
        }

        $transfer->loadMissing(['products.product', 'originLocation.setting', 'destinationLocation.setting']);

        try {
            DB::transaction(function () use ($transfer) {
                foreach ($transfer->products as $transferProduct) {
                    $serialValidation = $this->validateSerialNumbersForLocation(
                        $transferProduct,
                        $transfer->origin_location_id,
                        preferDispatchedPayload: false
                    );

                    $serialsToMove    = $serialValidation['serials'];
                    $normalizedSerials = $serialValidation['payload'];

                    if ($serialsToMove->isNotEmpty()) {
                        ProductSerialNumber::whereIn('id', $serialsToMove->pluck('id')->all())
                            ->update(['location_id' => $transfer->destination_location_id]);
                    }

                    $stock = $this->ensureStock($transferProduct->product_id, $transfer->origin_location_id, false);

                    $snapshot = $this->applyInventoryChange($transferProduct, $stock, increase: false);

                    $transferProduct->update([
                        'dispatched_at'                      => now(),
                        'dispatched_by'                      => auth()->id(),
                        'dispatched_quantity'                => $snapshot['total'],
                        'dispatched_quantity_tax'            => $snapshot['quantities']['tax'],
                        'dispatched_quantity_non_tax'        => $snapshot['quantities']['non_tax'],
                        'dispatched_quantity_broken_tax'     => $snapshot['quantities']['broken_tax'],
                        'dispatched_quantity_broken_non_tax' => $snapshot['quantities']['broken_non_tax'],
                        'dispatched_serial_numbers'          => ! empty($normalizedSerials) ? $normalizedSerials : null,
                    ]);

                    $reason = sprintf(
                        'Transfer stock to %s - %s (#%d)',
                        $transfer->destinationLocation->setting->company_name ?? '-',
                        $transfer->destinationLocation->name ?? '-',
                        $transfer->id
                    );

                    $this->recordTransaction(
                        $transfer,
                        $transferProduct,
                        $snapshot,
                        $transfer->origin_location_id,
                        $transfer->originLocation->setting_id,
                        $reason,
                        increase: false
                    );
                }

                $transfer->update([
                    'status'        => Transfer::STATUS_DISPATCHED,
                    'dispatched_by' => auth()->id(),
                    'dispatched_at' => now(),
                ]);
            });

            toast('Transfer Stok Dikirim!', 'info');
        } catch (Throwable $e) {
            Log::error('Failed to dispatch transfer', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
            session()->flash('error', 'Gagal mengirim transfer stok. Silakan coba lagi.');
            toast('Gagal mengirim Transfer Stok!', 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Receive the stock transfer.
     */
    public function receive(Transfer $transfer): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.receive'), 403);

        if ($transfer->status !== Transfer::STATUS_DISPATCHED) {
            toast('Transfer belum dalam status terkirim.', 'error');

            return redirect()->route('transfers.show', $transfer->id);
        }

        $transfer->loadMissing(['products.product', 'originLocation.setting', 'destinationLocation.setting']);

        try {
            DB::transaction(function () use ($transfer) {
                foreach ($transfer->products as $transferProduct) {
                    $this->validateSerialNumbersForLocation(
                        $transferProduct,
                        $transfer->destination_location_id
                    );

                    $stock = $this->ensureStock($transferProduct->product_id, $transfer->destination_location_id, true);

                    $snapshot = $this->applyInventoryChange($transferProduct, $stock, increase: true);

                    $reason = sprintf(
                        'Receive stock from %s - %s (#%d)',
                        $transfer->originLocation->setting->company_name ?? '-',
                        $transfer->originLocation->name ?? '-',
                        $transfer->id
                    );

                    $this->recordTransaction(
                        $transfer,
                        $transferProduct,
                        $snapshot,
                        $transfer->destination_location_id,
                        $transfer->destinationLocation->setting_id,
                        $reason,
                        increase: true
                    );
                }

                $transfer->update([
                    'status'      => Transfer::STATUS_RECEIVED,
                    'received_by' => auth()->id(),
                    'received_at' => now(),
                ]);
            });

            toast('Transfer Stok Diterima!', 'info');
        } catch (Throwable $e) {
            Log::error('Failed to receive transfer', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
            session()->flash('error', 'Gagal menerima transfer stok. Silakan coba lagi.');
            toast('Gagal menerima Transfer Stok!', 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Dispatch back the stock for cross-tenant transfers.
     */
    public function dispatchReturn(Transfer $transfer): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.dispatch'), 403);

        if ($transfer->status !== Transfer::STATUS_RECEIVED || ! $transfer->requiresReturn()) {
            toast('Transfer tidak tersedia untuk pengiriman kembali.', 'error');

            return redirect()->route('transfers.show', $transfer->id);
        }

        $transfer->loadMissing(['products.product', 'originLocation.setting', 'destinationLocation.setting']);

        try {
            DB::transaction(function () use ($transfer) {
                foreach ($transfer->products as $transferProduct) {
                    $serialValidation = $this->validateSerialNumbersForLocation(
                        $transferProduct,
                        $transfer->destination_location_id
                    );

                    $serialsToMove    = $serialValidation['serials'];
                    $normalizedSerials = $serialValidation['payload'];

                    if ($serialsToMove->isNotEmpty()) {
                        ProductSerialNumber::whereIn('id', $serialsToMove->pluck('id')->all())
                            ->update(['location_id' => $transfer->origin_location_id]);
                    }

                    $stock = $this->ensureStock($transferProduct->product_id, $transfer->destination_location_id, false);

                    $snapshot = $this->applyInventoryChange($transferProduct, $stock, increase: false);

                    $reason = sprintf(
                        'Return stock to %s - %s (#%d)',
                        $transfer->originLocation->setting->company_name ?? '-',
                        $transfer->originLocation->name ?? '-',
                        $transfer->id
                    );

                    $this->recordTransaction(
                        $transfer,
                        $transferProduct,
                        $snapshot,
                        $transfer->destination_location_id,
                        $transfer->destinationLocation->setting_id,
                        $reason,
                        increase: false
                    );

                    if (! empty($normalizedSerials)) {
                        $transferProduct->update([
                            'dispatched_serial_numbers' => $normalizedSerials,
                        ]);
                    }
                }

                $transfer->update([
                    'status'                => Transfer::STATUS_RETURN_DISPATCHED,
                    'return_dispatched_by'  => auth()->id(),
                    'return_dispatched_at'  => now(),
                ]);
            });

            toast('Barang dikirim kembali ke lokasi asal!', 'info');
        } catch (Throwable $e) {
            Log::error('Failed to dispatch transfer return', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
            session()->flash('error', 'Gagal mengirim kembali transfer stok. Silakan coba lagi.');
            toast('Gagal mengirim kembali Transfer Stok!', 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Receive back the stock for cross-tenant transfers.
     */
    public function receiveReturn(Transfer $transfer): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.receive'), 403);

        if ($transfer->status !== Transfer::STATUS_RETURN_DISPATCHED || ! $transfer->requiresReturn()) {
            toast('Transfer belum dalam status pengiriman kembali.', 'error');

            return redirect()->route('transfers.show', $transfer->id);
        }

        $transfer->loadMissing(['products.product', 'originLocation.setting', 'destinationLocation.setting']);

        try {
            DB::transaction(function () use ($transfer) {
                foreach ($transfer->products as $transferProduct) {
                    $this->validateSerialNumbersForLocation(
                        $transferProduct,
                        $transfer->origin_location_id
                    );

                    $stock = $this->ensureStock($transferProduct->product_id, $transfer->origin_location_id, true);

                    $snapshot = $this->applyInventoryChange($transferProduct, $stock, increase: true);

                    $reason = sprintf(
                        'Receive returned stock from %s - %s (#%d)',
                        $transfer->destinationLocation->setting->company_name ?? '-',
                        $transfer->destinationLocation->name ?? '-',
                        $transfer->id
                    );

                    $this->recordTransaction(
                        $transfer,
                        $transferProduct,
                        $snapshot,
                        $transfer->origin_location_id,
                        $transfer->originLocation->setting_id,
                        $reason,
                        increase: true
                    );
                }

                $transfer->update([
                    'status'               => Transfer::STATUS_RETURN_RECEIVED,
                    'return_received_by'   => auth()->id(),
                    'return_received_at'   => now(),
                ]);
            });

            toast('Barang kembali diterima di lokasi asal!', 'success');
        } catch (Throwable $e) {
            Log::error('Failed to receive transfer return', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
            session()->flash('error', 'Gagal menerima kembali transfer stok. Silakan coba lagi.');
            toast('Gagal menerima kembali Transfer Stok!', 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Show the form for editing the specified transfer.
     */
    public function edit(Transfer $transfer): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('stockTransfers.edit'), 403);

        $currentSetting       = $transfer->originLocation->setting;
        $settings             = Setting::all();
        $locations            = Location::where('setting_id', $currentSetting->id)->get();
        $destinationLocations = Location::all();

        $transfer->load('products.product');

        return view('adjustment::transfers.edit', compact('transfer', 'currentSetting', 'settings', 'locations', 'destinationLocations'));
    }

    /**
     * Update the specified transfer in storage.
     */
    public function update(UpdateStockTransferRequest $request, Transfer $transfer): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.edit'), 403);

        $validated = $request->validated();

        $transfer->products()->delete();

        foreach ($validated['product_ids'] as $index => $productId) {
            $quantity = (int) ($validated['quantities'][$index] ?? 0);

            TransferProduct::create([
                'transfer_id' => $transfer->id,
                'product_id'  => $productId,
                'quantity'    => $quantity,
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
        abort_if(Gate::denies('stockTransfers.delete'), 403);

        if ($transfer->status !== Transfer::STATUS_PENDING) {
            return redirect()->route('transfers.index')->with('error', 'Only pending transfers can be deleted.');
        }

        $transfer->delete();

        toast('Transfer Stok Dihapus!', 'warning');

        return redirect()->route('transfers.index');
    }

    private function ensureStock(int $productId, int $locationId, bool $createIfMissing): ProductStock
    {
        $query = ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->lockForUpdate();

        $stock = $query->first();

        if (! $stock && ! $createIfMissing) {
            throw new Exception('Data stok tidak ditemukan untuk produk di lokasi yang dipilih.');
        }

        if (! $stock) {
            $stock = ProductStock::create([
                'product_id'              => $productId,
                'location_id'             => $locationId,
                'quantity'                => 0,
                'quantity_non_tax'        => 0,
                'quantity_tax'            => 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax'     => 0,
                'broken_quantity'         => 0,
            ]);

            $stock = ProductStock::whereKey($stock->id)->lockForUpdate()->first();
        }

        return $stock;
    }

    private function applyInventoryChange(TransferProduct $transferProduct, ProductStock $stock, bool $increase): array
    {
        $product = $transferProduct->product;

        $quantities = $this->getQuantities($transferProduct);
        $total      = $quantities['total'];
        $brokenTotal = $quantities['broken_tax'] + $quantities['broken_non_tax'];

        $previousStock = [
            'quantity_tax'       => (int) ($stock->quantity_tax ?? 0),
            'quantity_non_tax'   => (int) ($stock->quantity_non_tax ?? 0),
            'broken_tax'         => (int) ($stock->broken_quantity_tax ?? 0),
            'broken_non_tax'     => (int) ($stock->broken_quantity_non_tax ?? 0),
        ];

        $previousStockQuantity = (int) ($stock->quantity ?? array_sum($previousStock));
        $previousBrokenQuantity = (int) ($stock->broken_quantity ?? ($previousStock['broken_tax'] + $previousStock['broken_non_tax']));
        $previousProductQuantity = (int) ($product->product_quantity ?? 0);
        $previousProductBroken   = (int) ($product->broken_quantity ?? 0);

        if (! $increase) {
            if ($previousStock['quantity_tax'] < $quantities['tax']
                || $previousStock['quantity_non_tax'] < $quantities['non_tax']
                || $previousStock['broken_tax'] < $quantities['broken_tax']
                || $previousStock['broken_non_tax'] < $quantities['broken_non_tax']) {
                throw new Exception('Stok tidak mencukupi untuk melakukan perpindahan.');
            }

            $stock->quantity_tax            = max(0, $previousStock['quantity_tax'] - $quantities['tax']);
            $stock->quantity_non_tax        = max(0, $previousStock['quantity_non_tax'] - $quantities['non_tax']);
            $stock->broken_quantity_tax     = max(0, $previousStock['broken_tax'] - $quantities['broken_tax']);
            $stock->broken_quantity_non_tax = max(0, $previousStock['broken_non_tax'] - $quantities['broken_non_tax']);

            $product->product_quantity = max(0, $previousProductQuantity - $total);
            $product->broken_quantity  = max(0, $previousProductBroken - $brokenTotal);
        } else {
            $stock->quantity_tax            = $previousStock['quantity_tax'] + $quantities['tax'];
            $stock->quantity_non_tax        = $previousStock['quantity_non_tax'] + $quantities['non_tax'];
            $stock->broken_quantity_tax     = $previousStock['broken_tax'] + $quantities['broken_tax'];
            $stock->broken_quantity_non_tax = $previousStock['broken_non_tax'] + $quantities['broken_non_tax'];

            $product->product_quantity = $previousProductQuantity + $total;
            $product->broken_quantity  = $previousProductBroken + $brokenTotal;
        }

        $stock->quantity        = max(0, $stock->quantity_tax + $stock->quantity_non_tax + $stock->broken_quantity_tax + $stock->broken_quantity_non_tax);
        $stock->broken_quantity = max(0, $stock->broken_quantity_tax + $stock->broken_quantity_non_tax);

        $stock->save();
        $product->save();

        return [
            'total'            => $total,
            'quantities'       => $quantities,
            'previous_stock'   => [
                'quantity'      => $previousStockQuantity,
                'broken'        => $previousBrokenQuantity,
                'quantity_tax'  => $previousStock['quantity_tax'],
                'quantity_non_tax' => $previousStock['quantity_non_tax'],
                'broken_tax'    => $previousStock['broken_tax'],
                'broken_non_tax'=> $previousStock['broken_non_tax'],
            ],
            'current_stock'    => [
                'quantity'      => (int) $stock->quantity,
                'broken'        => (int) $stock->broken_quantity,
                'quantity_tax'  => (int) $stock->quantity_tax,
                'quantity_non_tax' => (int) $stock->quantity_non_tax,
                'broken_tax'    => (int) $stock->broken_quantity_tax,
                'broken_non_tax'=> (int) $stock->broken_quantity_non_tax,
            ],
            'previous_product' => [
                'quantity' => $previousProductQuantity,
                'broken'   => $previousProductBroken,
            ],
            'current_product'  => [
                'quantity' => (int) $product->product_quantity,
                'broken'   => (int) $product->broken_quantity,
            ],
        ];
    }

    private function recordTransaction(
        Transfer $transfer,
        TransferProduct $transferProduct,
        array $snapshot,
        int $locationId,
        int $settingId,
        string $reason,
        bool $increase
    ): void {
        Transaction::create([
            'product_id'                   => $transferProduct->product_id,
            'setting_id'                   => $settingId,
            'type'                         => 'TRF',
            'quantity'                     => $increase ? $snapshot['total'] : -$snapshot['total'],
            'current_quantity'             => $snapshot['current_stock']['quantity'],
            'broken_quantity'              => $snapshot['current_stock']['broken'],
            'previous_quantity'            => $snapshot['previous_product']['quantity'],
            'previous_quantity_at_location'=> $snapshot['previous_stock']['quantity'],
            'after_quantity'               => $snapshot['current_product']['quantity'],
            'after_quantity_at_location'   => $snapshot['current_stock']['quantity'],
            'quantity_tax'                 => $snapshot['current_stock']['quantity_tax'],
            'quantity_non_tax'             => $snapshot['current_stock']['quantity_non_tax'],
            'broken_quantity_tax'          => $snapshot['current_stock']['broken_tax'],
            'broken_quantity_non_tax'      => $snapshot['current_stock']['broken_non_tax'],
            'location_id'                  => $locationId,
            'user_id'                      => auth()->id(),
            'reason'                       => $reason,
        ]);
    }

    private function getSerialPayload(TransferProduct $transferProduct, bool $preferDispatchedPayload = true): Collection
    {
        $payload = collect();

        if ($preferDispatchedPayload) {
            $payload = collect($transferProduct->dispatched_serial_numbers ?? []);
        }

        if ($payload->isEmpty()) {
            $payload = collect($transferProduct->serial_numbers ?? []);
        }

        return $payload->values();
    }

    private function validateSerialNumbersForLocation(
        TransferProduct $transferProduct,
        int $expectedLocationId,
        bool $preferDispatchedPayload = true
    ): array {
        $payload = $this->getSerialPayload($transferProduct, $preferDispatchedPayload);

        $product = $transferProduct->relationLoaded('product')
            ? $transferProduct->product
            : $transferProduct->product()->first();

        $productName = $product?->product_name ?? ('ID #' . $transferProduct->product_id);

        if ($payload->isEmpty()) {
            if ($product && $product->serial_number_required) {
                throw new Exception("Produk {$productName} memerlukan nomor seri untuk diproses.");
            }

            return [
                'serials' => collect(),
                'payload' => [],
            ];
        }

        $serialIds = $payload->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values();

        if ($serialIds->isEmpty()) {
            throw new Exception("Data nomor seri untuk produk {$productName} tidak valid.");
        }

        if ($serialIds->unique()->count() !== $serialIds->count()) {
            throw new Exception("Nomor seri duplikat terdeteksi pada produk {$productName}.");
        }

        $serials = ProductSerialNumber::whereIn('id', $serialIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($serials->count() !== $serialIds->count()) {
            throw new Exception("Beberapa nomor seri tidak ditemukan atau sudah digunakan untuk produk {$productName}.");
        }

        $orderedSerials = $serialIds->map(fn ($id) => $serials->get($id))->values();

        foreach ($orderedSerials as $serial) {
            if (! $serial instanceof ProductSerialNumber) {
                throw new Exception("Nomor seri tidak valid untuk produk {$productName}.");
            }

            if ((int) $serial->product_id !== (int) $transferProduct->product_id) {
                throw new Exception("Nomor seri {$serial->serial_number} tidak sesuai dengan produk {$productName}.");
            }

            if ((int) $serial->location_id !== $expectedLocationId) {
                throw new Exception("Nomor seri {$serial->serial_number} tidak berada di lokasi yang sesuai untuk produk {$productName}.");
            }

            if ($serial->dispatch_detail_id !== null) {
                throw new Exception("Nomor seri {$serial->serial_number} sedang digunakan dalam pengiriman lain.");
            }
        }

        $breakdown = $this->calculateSerialQuantitiesFromModels($orderedSerials);

        $expectedQuantities = [
            'quantity_tax'            => (int) ($transferProduct->quantity_tax ?? 0),
            'quantity_non_tax'        => (int) ($transferProduct->quantity_non_tax ?? 0),
            'quantity_broken_tax'     => (int) ($transferProduct->quantity_broken_tax ?? 0),
            'quantity_broken_non_tax' => (int) ($transferProduct->quantity_broken_non_tax ?? 0),
        ];

        if (
            $breakdown['quantity_tax'] !== $expectedQuantities['quantity_tax']
            || $breakdown['quantity_non_tax'] !== $expectedQuantities['quantity_non_tax']
            || $breakdown['quantity_broken_tax'] !== $expectedQuantities['quantity_broken_tax']
            || $breakdown['quantity_broken_non_tax'] !== $expectedQuantities['quantity_broken_non_tax']
        ) {
            throw new Exception("Jumlah nomor seri tidak sesuai dengan rincian kuantitas untuk produk {$productName}.");
        }

        if ($breakdown['total'] !== $orderedSerials->count()) {
            throw new Exception("Jumlah nomor seri tidak konsisten untuk produk {$productName}.");
        }

        $normalizedPayload = $orderedSerials->map(function (ProductSerialNumber $serial) {
            return [
                'id'            => $serial->id,
                'serial_number' => $serial->serial_number,
                'tax_id'        => $serial->tax_id,
                'taxable'       => $serial->tax_id !== null,
                'is_broken'     => (bool) $serial->is_broken,
            ];
        })->toArray();

        return [
            'serials' => $orderedSerials,
            'payload' => $normalizedPayload,
        ];
    }

    private function calculateSerialQuantitiesFromModels(Collection $serials): array
    {
        $quantityTax          = 0;
        $quantityNonTax       = 0;
        $brokenQuantityTax    = 0;
        $brokenQuantityNonTax = 0;

        foreach ($serials as $serial) {
            if (! $serial instanceof ProductSerialNumber) {
                continue;
            }

            $isBroken = (bool) ($serial->is_broken ?? false);
            $isTaxed  = $serial->tax_id !== null;

            if ($isBroken && $isTaxed) {
                $brokenQuantityTax++;
            } elseif ($isBroken && ! $isTaxed) {
                $brokenQuantityNonTax++;
            } elseif ($isTaxed) {
                $quantityTax++;
            } else {
                $quantityNonTax++;
            }
        }

        return [
            'quantity_tax'            => $quantityTax,
            'quantity_non_tax'        => $quantityNonTax,
            'quantity_broken_tax'     => $brokenQuantityTax,
            'quantity_broken_non_tax' => $brokenQuantityNonTax,
            'total'                   => $quantityTax + $quantityNonTax + $brokenQuantityTax + $brokenQuantityNonTax,
        ];
    }

    private function getQuantities(TransferProduct $transferProduct): array
    {
        $tax        = max(0, (int) ($transferProduct->quantity_tax ?? 0));
        $nonTax     = max(0, (int) ($transferProduct->quantity_non_tax ?? 0));
        $brokenTax  = max(0, (int) ($transferProduct->quantity_broken_tax ?? 0));
        $brokenNon  = max(0, (int) ($transferProduct->quantity_broken_non_tax ?? 0));

        $total = $tax + $nonTax + $brokenTax + $brokenNon;

        $product = $transferProduct->relationLoaded('product')
            ? $transferProduct->product
            : $transferProduct->product()->first();

        if ($product && $product->serial_number_required) {
            $payload = $this->getSerialPayload($transferProduct);
            $serialCount = $payload->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->count();

            $productName = $product->product_name ?? ('ID #' . $transferProduct->product_id);

            if ($serialCount === 0) {
                throw new Exception("Produk {$productName} memerlukan nomor seri tetapi tidak ditemukan data serial.");
            }

            if ($total === 0) {
                throw new Exception("Jumlah kuantitas untuk produk {$productName} tidak boleh kosong ketika nomor seri tersedia.");
            }

            if ($serialCount !== $total) {
                throw new Exception("Jumlah nomor seri ({$serialCount}) tidak sesuai dengan total kuantitas ({$total}) untuk produk {$productName}.");
            }
        }

        if ($total === 0) {
            $fallback = max(0, (int) ($transferProduct->quantity ?? 0));
            $total    = $fallback;
            $nonTax   = $fallback;
        }

        return [
            'tax'          => $tax,
            'non_tax'      => $nonTax,
            'broken_tax'   => $brokenTax,
            'broken_non_tax' => $brokenNon,
            'total'        => $total,
        ];
    }
}
