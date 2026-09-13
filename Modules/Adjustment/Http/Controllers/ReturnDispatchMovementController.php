<?php

namespace Modules\Adjustment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Services\ReturnDispatchApprovalExecutor;
use Modules\Adjustment\Services\ReturnDispatchPreparationService;
use Modules\Adjustment\Services\ReturnDispatchProjectionService;
use Modules\Adjustment\Services\TransferMovementDocumentService;
use Modules\Adjustment\Services\TransferStockVisibility;
use Throwable;

class ReturnDispatchMovementController extends Controller
{
    public function __construct(
        private ReturnDispatchPreparationService $preparationService,
        private ReturnDispatchProjectionService $projectionService,
        private ReturnDispatchApprovalExecutor $approvalExecutor,
        private TransferMovementDocumentService $documentService,
    ) {
    }

    /**
     * Check feature activation, destination-side ownership (the return leg originates at the
     * transfer's original destination), and scoped movement hierarchy.
     */
    private function authorizeDestinationAction(Transfer $transfer, string $permission, ?TransferMovement $movement = null): void
    {
        if (!config('stock_transfers.v2_dispatch_enabled', false)) {
            abort(404, 'Return dispatch workflow version 2 is not enabled.');
        }

        abort_if(Gate::denies($permission), 403);

        $currentSettingId = (int) session('setting_id');
        $transfer->loadMissing('destinationLocation.setting');

        if ($transfer->destinationLocation?->setting_id !== $currentSettingId) {
            abort(403, 'Unauthorized return-dispatch origin location.');
        }

        if ((int) $transfer->workflow_version !== 2) {
            abort(400, 'This action is only supported for workflow version 2 transfers.');
        }

        if (!app(\Modules\Adjustment\Services\TransferWorkflowEligibilityService::class)->isEligibleForV2($transfer)) {
            abort(400, 'This transfer route is not eligible for workflow version 2.');
        }

        if ($movement !== null) {
            if ((int) $movement->transfer_id !== (int) $transfer->id) {
                abort(404, 'Movement does not belong to the specified transfer.');
            }
            if ($movement->type !== TransferMovement::TYPE_RETURN_DISPATCH) {
                abort(400, 'Movement type is not RETURN_DISPATCH.');
            }
        }
    }

    private function neutralizedErrorMessage(Throwable $e): string
    {
        if (Gate::allows(TransferStockVisibility::PERMISSION)) {
            return $e->getMessage();
        }

        return 'Terjadi kesalahan saat memproses batch retur transfer stok.';
    }

    /**
     * Create a new independent batch lineage.
     */
    public function create(Transfer $transfer, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.dispatch.create');

        try {
            $movement = $this->preparationService->getOrCreateBatch($transfer, auth()->id());
            $canViewSystemStock = TransferStockVisibility::canView();

            if ($request->wantsJson()) {
                return response()->json(
                    $this->projectionService->getPreparationProjection($transfer, $movement, $canViewSystemStock)
                );
            }

            return redirect()->route('transfers.movements.return.prepare', [
                'transfer' => $transfer->id,
                'batch'    => $movement->return_batch_id,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to create return dispatch batch', ['transfer_id' => $transfer->id, 'error' => $e->getMessage()]);
            if ($request->wantsJson()) {
                return response()->json(['error' => $this->neutralizedErrorMessage($e)], 400);
            }
            toast($this->neutralizedErrorMessage($e), 'error');
            return redirect()->route('transfers.show', $transfer->id);
        }
    }

    /**
     * Resume/view an existing open batch lineage.
     */
    public function prepare(Transfer $transfer, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.dispatch.create');

        $batchId = (string) $request->query('batch', '');

        try {
            $movement = $this->preparationService->getOrCreateBatch($transfer, auth()->id(), $batchId ?: null);
            $canViewSystemStock = TransferStockVisibility::canView();

            if ($request->wantsJson()) {
                return response()->json(
                    $this->projectionService->getPreparationProjection($transfer, $movement, $canViewSystemStock)
                );
            }

            return view('adjustment::transfers.return_dispatch_prepare', compact('transfer', 'movement', 'canViewSystemStock'));
        } catch (Throwable $e) {
            Log::error('Failed to prepare return dispatch batch', ['transfer_id' => $transfer->id, 'error' => $e->getMessage()]);
            if ($request->wantsJson()) {
                return response()->json(['error' => $this->neutralizedErrorMessage($e)], 400);
            }
            toast($this->neutralizedErrorMessage($e), 'error');
            return redirect()->route('transfers.show', $transfer->id);
        }
    }

    public function scan(Transfer $transfer, TransferMovement $movement, Request $request): JsonResponse
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.dispatch.create', $movement);

        $query = (string) $request->input('query', '');
        $expectedLockVersion = (int) $request->input('lock_version', $movement->lock_version);
        $settingId = (int) session('setting_id');
        $canViewSystemStock = TransferStockVisibility::canView();

        try {
            $result = $this->preparationService->applyScan(
                $movement,
                $query,
                $settingId,
                $expectedLockVersion,
                auth()->id(),
                $canViewSystemStock
            );

            return response()->json($result);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $this->neutralizedErrorMessage($e)], 400);
        }
    }

    public function setQuantity(Transfer $transfer, TransferMovement $movement, Request $request): JsonResponse
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.dispatch.create', $movement);

        $productId = (int) $request->input('product_id');
        $quantity = $request->input('quantity');
        $confirmed = (bool) $request->input('count_confirmed', true);
        $expectedLockVersion = (int) $request->input('lock_version', $movement->lock_version);

        try {
            $updatedMovement = $this->preparationService->setLineQuantity(
                $movement,
                $productId,
                $quantity,
                $confirmed,
                $expectedLockVersion,
                auth()->id()
            );

            $canViewSystemStock = TransferStockVisibility::canView();

            return response()->json([
                'status'     => 'success',
                'projection' => $this->projectionService->getPreparationProjection($transfer, $updatedMovement, $canViewSystemStock),
            ]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $this->neutralizedErrorMessage($e)], 400);
        }
    }

    public function submit(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.dispatch.create', $movement);

        $expectedLockVersion = (int) $request->input('lock_version', $movement->lock_version);

        try {
            $pendingMovement = $this->preparationService->submit($movement, $expectedLockVersion, auth()->id());

            if ($request->wantsJson()) {
                return response()->json(['status' => 'success', 'movement_id' => $pendingMovement->id]);
            }

            toast('Batch retur berhasil diajukan untuk ditinjau.', 'success');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['status' => 'error', 'message' => $this->neutralizedErrorMessage($e)], 400);
            }
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }

    public function cancel(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.dispatch.create', $movement);

        $reason = (string) $request->input('reason', 'Draft batch retur dibatalkan.');

        try {
            $this->documentService->cancel($movement, $reason, auth()->id());
            toast('Draft batch retur dibatalkan.', 'info');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }

    public function correct(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.dispatch.create', $movement);

        try {
            $newDraft = $this->documentService->startCorrection($movement, auth()->id());
            toast('Draft koreksi batch retur berhasil dibuat.', 'info');
            return redirect()->route('transfers.movements.return.prepare', [
                'transfer' => $transfer->id,
                'batch'    => $newDraft->return_batch_id,
            ]);
        } catch (Throwable $e) {
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }

    public function review(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.dispatch.approval', $movement);

        $canViewSystemStock = TransferStockVisibility::canView();
        $projection = $this->projectionService->getApprovalProjection($transfer, $movement, $canViewSystemStock);

        if ($request->wantsJson()) {
            return response()->json($projection);
        }

        return view('adjustment::transfers.return_dispatch_review', compact('transfer', 'movement', 'projection', 'canViewSystemStock'));
    }

    public function approve(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.dispatch.approval', $movement);

        $idempotencyKey = $request->input('idempotency_key');

        try {
            $this->approvalExecutor->approve($transfer, $movement, auth()->id(), $idempotencyKey);
            toast('Batch retur transfer stok berhasil disetujui.', 'success');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }

    public function reject(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.dispatch.approval', $movement);

        $reason = (string) $request->input('reason', '');
        $idempotencyKey = $request->input('idempotency_key');

        try {
            $this->documentService->reject($movement, $reason, auth()->id(), $idempotencyKey);
            toast('Batch retur transfer stok ditolak.', 'warning');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }
}
