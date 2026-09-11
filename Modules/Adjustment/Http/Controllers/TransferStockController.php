<?php

namespace Modules\Adjustment\Http\Controllers;

use App\Services\IdempotencyService;
use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Adjustment\DataTables\StockTransfersDataTable;
use Modules\Adjustment\DTOs\TransferFormLineState;
use Modules\Adjustment\DTOs\TransferFormState;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Http\Requests\StockTransferRequest;
use Modules\Adjustment\Http\Requests\UpdateStockTransferRequest;
use Modules\Adjustment\Services\TransferDraftService;
use Modules\Adjustment\Services\TransferFormStateMapper;
use Modules\Adjustment\Services\TransferLifecycleService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use App\Services\SerialNumberHistoryService;
use Modules\Product\Entities\SerialNumberHistory;
use Throwable;

class TransferStockController extends Controller
{
    public function __construct()
    {
        $this->middleware('idempotency')->only('store');
    }
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
    public function create(Request $request): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('stockTransfers.create'), 403);

        $currentSettingId = (int) session('setting_id');
        $currentSetting   = Setting::find($currentSettingId);
        $settings         = Setting::all();
        $locations        = Location::where('setting_id', $currentSettingId)->active()->get();
        $destinationLocations = Location::active()->get();

        $idempotencyToken = IdempotencyService::tokenFromRequest($request);

        return view('adjustment::transfers.create', compact('currentSetting', 'settings', 'locations', 'destinationLocations', 'idempotencyToken'));
    }

    /**
     * Store a newly created resource in storage.
     * Uses TransferDraftService::createAndSubmitForApproval() to create a
     * DRAFT and immediately submit it for approval as one atomic operation:
     * this legacy HTTP contract always requires a destination and never
     * supports broken-mode or serialized rows, and if submission fails no
     * draft is left behind.
     */
    public function store(StockTransferRequest $request): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.create'), 403);

        $validated = $request->validated();
        $currentSettingId = (int) session('setting_id');
        $user = auth()->user();

        try {
            $draftService = app(TransferDraftService::class);

            $formState = new TransferFormState(
                (int) $validated['origin_location'],
                (int) $validated['destination_location'],
                Transfer::CONDITION_GOOD
            );

            // Add lines for each product with quantities allocated as non-tax (HTTP legacy behavior)
            foreach ($validated['product_ids'] as $index => $productId) {
                $quantity = (int) ($validated['quantities'][$index] ?? 0);
                if ($quantity <= 0) continue;

                $product = Product::find($productId);
                if (!$product) {
                    throw new InvalidArgumentException("Product {$productId} not found.");
                }

                // HTTP contract does not support serialized products or broken mode
                if ($product->serial_number_required) {
                    throw new InvalidArgumentException(
                        "Product '{$product->product_name}' requires serial number selection. " .
                        "Use the web interface to allocate serials for this product."
                    );
                }

                $line = new TransferFormLineState(
                    $productId,
                    $product->product_name,
                    $product->product_code,
                    $product->barcode,
                    (bool) $product->serial_number_required,
                    false, // normal mode
                    $quantity
                );

                $formState->addLine($line);
            }

            if (empty($formState->lines)) {
                throw new InvalidArgumentException("At least one product with quantity > 0 is required.");
            }

            // Create and submit for approval atomically: this legacy
            // contract always supplies a destination and expects an atomic
            // create-and-submit outcome, so if submission fails no draft is
            // left behind.
            $transfer = $draftService->createAndSubmitForApproval($formState, $user, $currentSettingId);

            toast('Transfer Stok Dibuat! No. Dokumen: ' . $transfer->document_number, 'success');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            Log::error('Failed to create transfer', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
            ]);
            toast($e->getMessage(), 'error');
            return redirect()->back()->withInput();
        }
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
    public function approve(Transfer $transfer, \Modules\Adjustment\Services\TransferLifecycleService $service): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.approval'), 403);

        $currentSettingId = (int) session('setting_id');

        try {
            $service->approve($transfer, auth()->id(), $currentSettingId);
            toast('Transfer Stok Disetujui! No. Dokumen: ' . $transfer->document_number, 'success');
        } catch (Throwable $e) {
            Log::error('Failed to approve transfer', [
                'transfer_id' => $transfer->id,
                'error' => $e->getMessage(),
            ]);
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Reject the stock transfer.
     */
    public function reject(Request $request, Transfer $transfer, \Modules\Adjustment\Services\TransferLifecycleService $service): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.approval'), 403);

        $reason = $request->input('reason', '');
        if (empty(trim($reason))) {
            toast('Alasan penolakan harus diisi.', 'error');
            return redirect()->route('transfers.show', $transfer->id);
        }

        $currentSettingId = (int) session('setting_id');

        try {
            $service->reject($transfer, auth()->id(), $currentSettingId, $reason);
            toast('Transfer Stok Ditolak! No. Dokumen: ' . $transfer->document_number, 'warning');
        } catch (Throwable $e) {
            Log::error('Failed to reject transfer', [
                'transfer_id' => $transfer->id,
                'error' => $e->getMessage(),
            ]);
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Acknowledge rejection and transition to DRAFT for resubmission.
     */
    public function acknowledgeRejection(Transfer $transfer, \Modules\Adjustment\Services\TransferLifecycleService $service): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.edit'), 403);

        $currentSettingId = (int) session('setting_id');

        try {
            $service->acknowledgeRejection($transfer, auth()->id(), $currentSettingId);
            toast('Penolakan diakui. Transfer sekarang siap untuk diajukan kembali.', 'info');
        } catch (Throwable $e) {
            Log::error('Failed to acknowledge rejection', [
                'transfer_id' => $transfer->id,
                'error' => $e->getMessage(),
            ]);
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Resubmit a rejected transfer with a new PENDING revision.
     */
    public function resubmit(Transfer $transfer, \Modules\Adjustment\Services\TransferLifecycleService $service): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.edit'), 403);

        $currentSettingId = (int) session('setting_id');

        try {
            $service->resubmit($transfer, auth()->id(), $currentSettingId);
            toast('Transfer berhasil diajukan kembali dengan revisi baru.', 'success');
        } catch (Throwable $e) {
            Log::error('Failed to resubmit transfer', [
                'transfer_id' => $transfer->id,
                'error' => $e->getMessage(),
            ]);
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Dispatch the transfer shipment.
     */
    public function dispatchShipment(Transfer $transfer, Request $request, \Modules\Adjustment\Services\TransferLifecycleService $service): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.dispatch'), 403);

        $currentSettingId = (int) session('setting_id');
        $acknowledgedHash = $request->input('acknowledged_hash');

        try {
            $service->dispatch($transfer, auth()->id(), $currentSettingId, $acknowledgedHash);
            toast('Transfer Stok Dikirim! No. Dokumen: ' . $transfer->document_number, 'info');
        } catch (\Modules\Adjustment\Exceptions\AllocationDriftException $e) {
            session()->flash('drift_exception', [
                'message' => $e->getMessage(),
                'hash' => $e->hash,
                'allocations' => $e->allocations,
            ]);
            toast('Alokasi stok berubah. Harap tinjau dan konfirmasi.', 'warning');
        } catch (Throwable $e) {
            Log::error('Failed to dispatch transfer', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Receive the stock transfer.
     */
    public function receive(Transfer $transfer, Request $request, \Modules\Adjustment\Services\TransferLifecycleService $service): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.receive'), 403);

        $currentSettingId = (int) session('setting_id');

        try {
            $service->receive($transfer, auth()->id(), $currentSettingId);
            toast('Transfer Stok Diterima! No. Dokumen: ' . $transfer->document_number, 'info');
        } catch (Throwable $e) {
            Log::error('Failed to receive transfer', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Dispatch back the stock for cross-tenant transfers.
     */
    public function dispatchReturn(Transfer $transfer, \Modules\Adjustment\Services\TransferLifecycleService $service): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.dispatch'), 403);

        $currentSettingId = (int) session('setting_id');

        try {
            $service->dispatchReturn($transfer, auth()->id(), $currentSettingId);
            toast('Retur Stok Dikirim! No. Dokumen: ' . $transfer->document_number, 'info');
        } catch (Throwable $e) {
            Log::error('Failed to dispatch return for transfer', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Receive back the returned stock at the origin location.
     */
    public function receiveReturn(Transfer $transfer, \Modules\Adjustment\Services\TransferLifecycleService $service): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.receive'), 403);

        $currentSettingId = (int) session('setting_id');

        try {
            $service->receiveReturn($transfer, auth()->id(), $currentSettingId);
            toast('Retur Stok Diterima! No. Dokumen: ' . $transfer->document_number, 'info');
        } catch (Throwable $e) {
            Log::error('Failed to receive return for transfer', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }
    
    /**
     * Archive the stock transfer.
     */
    public function archive(Request $request, Transfer $transfer, \Modules\Adjustment\Services\TransferLifecycleService $service): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.archive'), 403);

        $currentSettingId = (int) session('setting_id');
        $reason = $request->input('reason');

        try {
            $service->archive($transfer, auth()->id(), $currentSettingId, $reason);
            toast('Transfer Stok Diarsipkan! No. Dokumen: ' . $transfer->document_number, 'info');
        } catch (Throwable $e) {
            Log::error('Failed to archive transfer', [
                'transfer_id' => $transfer->id,
                'error'       => $e->getMessage(),
            ]);
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('transfers.show', $transfer->id);
    }

    /**
     * Show the form for editing the specified transfer.
     */
    public function edit(Transfer $transfer): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('stockTransfers.edit'), 403);

        $transfer->loadMissing('originLocation.setting');

        // Verify active tenant owns the origin location
        $currentSettingId = (int) session('setting_id');
        $originSettingId = $transfer->originLocation?->setting?->id;
        
        if ($originSettingId === null || $currentSettingId !== (int) $originSettingId) {
            abort(403, 'You can only edit transfers from your origin location.');
        }

        // Only PENDING and acknowledged DRAFT transfers can be edited
        if (!in_array($transfer->status, [Transfer::STATUS_PENDING, Transfer::STATUS_DRAFT])) {
            abort(403, 'This transfer cannot be edited in its current status.');
        }

        // Historical transfers whose lines mix good and broken stock (no
        // explicit persisted condition) predate the single-condition
        // constraint and cannot be safely rewritten through ordinary
        // editing: their contents are preserved as view-only.
        if (app(TransferFormStateMapper::class)->isMixedConditionHistory($transfer)) {
            abort(403, 'This transfer contains a historical mix of good and broken stock and cannot be edited.');
        }

        $currentSetting       = $transfer->originLocation->setting;
        $settings             = Setting::all();
        $locations            = Location::where('setting_id', $currentSetting->id)->get();
        $destinationLocations = Location::all();

        $transfer->load('products.product');

        return view('adjustment::transfers.edit', compact('transfer', 'currentSetting', 'settings', 'locations', 'destinationLocations'));
    }

    /**
     * Update the specified transfer in storage.
     * Routes through TransferDraftService to preserve bucket and serial data.
     * Does NOT auto-submit: editing remains DRAFT, explicit resubmit required.
     */
    public function update(UpdateStockTransferRequest $request, Transfer $transfer): RedirectResponse
    {
        abort_if(Gate::denies('stockTransfers.edit'), 403);

        $transfer->loadMissing('originLocation.setting');

        // Verify active tenant owns the origin location
        $currentSettingId = (int) session('setting_id');
        $originSettingId = $transfer->originLocation?->setting?->id;
        
        if ($originSettingId === null || $currentSettingId !== (int) $originSettingId) {
            toast('You can only edit transfers from your origin location.', 'error');
            return redirect()->route('transfers.show', $transfer->id);
        }

        // Only PENDING and acknowledged DRAFT transfers can be edited
        if (!in_array($transfer->status, [Transfer::STATUS_PENDING, Transfer::STATUS_DRAFT])) {
            toast('This transfer cannot be edited in its current status.', 'error');
            return redirect()->route('transfers.show', $transfer->id);
        }

        // Historical mixed-condition transfers remain view-only; ordinary
        // editing must never rewrite their contents or assign a condition.
        if (app(TransferFormStateMapper::class)->isMixedConditionHistory($transfer)) {
            toast('This transfer contains a historical mix of good and broken stock and cannot be edited.', 'error');
            return redirect()->route('transfers.show', $transfer->id);
        }

        $validated = $request->validated();
        $user = auth()->user();

        try {
            // Build TransferFormState from request data
            $draftService = app(TransferDraftService::class);

            $formState = new TransferFormState(
                (int) $transfer->origin_location_id,
                (int) $transfer->destination_location_id,
                $transfer->stock_condition ?? Transfer::CONDITION_GOOD
            );

            // Add lines for each product with quantities allocated as non-tax (HTTP legacy behavior)
            foreach ($validated['product_ids'] as $index => $productId) {
                $quantity = (int) ($validated['quantities'][$index] ?? 0);
                if ($quantity <= 0) continue;

                $product = Product::find($productId);
                if (!$product) {
                    throw new InvalidArgumentException("Product {$productId} not found.");
                }

                // HTTP contract does not support serialized products or broken mode
                if ($product->serial_number_required) {
                    throw new InvalidArgumentException(
                        "Product '{$product->product_name}' requires serial number selection. " .
                        "Use the web interface to allocate serials for this product."
                    );
                }

                $line = new TransferFormLineState(
                    $productId,
                    $product->product_name,
                    $product->product_code,
                    $product->barcode,
                    (bool) $product->serial_number_required,
                    false, // normal mode
                    $quantity
                );

                $formState->addLine($line);
            }

            // Use the same draft-save command the Livewire form uses; editing
            // never auto-submits, matching the explicit save/submit split.
            $wasPending = $transfer->status === Transfer::STATUS_PENDING;

            $transfer = $draftService->saveDraft($formState, $user, $currentSettingId, $transfer);

            if ($wasPending && $transfer->status === Transfer::STATUS_DRAFT) {
                toast('Transfer diperbarui dan dikembalikan ke Draf karena perubahan material. Ajukan kembali untuk persetujuan. No. Dokumen: ' . $transfer->document_number, 'info');
            } else {
                toast('Transfer Stok Diperbarui! No. Dokumen: ' . $transfer->document_number, 'success');
            }

            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            Log::error('Failed to update transfer', [
                'transfer_id' => $transfer->id,
                'error' => $e->getMessage(),
            ]);
            toast($e->getMessage(), 'error');
            return redirect()->back()->withInput();
        }
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

        toast('Transfer Stok Dihapus! No. Dokumen: ' . $transfer->document_number, 'warning');

        return redirect()->route('transfers.index');
    }

}
