<?php

namespace Modules\Adjustment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Services\ReturnReceiptApprovalExecutor;
use Modules\Adjustment\Services\ReturnReceiptPreparationService;
use Modules\Adjustment\Services\ReturnReceiptProjectionService;
use Modules\Adjustment\Services\TransferMovementDocumentService;
use Modules\Adjustment\Services\TransferStockVisibility;
use Throwable;

class ReturnReceiptMovementController extends Controller
{
    public function __construct(
        private ReturnReceiptPreparationService $preparationService,
        private ReturnReceiptProjectionService $projectionService,
        private ReturnReceiptApprovalExecutor $approvalExecutor,
        private TransferMovementDocumentService $documentService,
    ) {
    }

    /**
     * Check feature activation, origin-side ownership (the return receipt takes place at the transfer's original location),
     * and scoped movement hierarchy.
     */
    private function authorizeOriginAction(Transfer $transfer, string $permission, ?TransferMovement $movement = null): void
    {
        if (!config('stock_transfers.v2_dispatch_enabled', false)) {
            abort(404, 'Return receipt workflow version 2 is not enabled.');
        }

        abort_if(Gate::denies($permission), 403);

        $currentSettingId = (int) session('setting_id');
        $transfer->loadMissing('originLocation.setting');

        if ($transfer->originLocation?->setting_id !== $currentSettingId) {
            abort(403, 'Unauthorized return-receipt location.');
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
            if ($movement->type !== TransferMovement::TYPE_RETURN_RECEIPT) {
                abort(400, 'Movement type is not RETURN_RECEIPT.');
            }
        }
    }

    /**
     * Strictly blind error message for all preparation actions (identical for regular users, stock-visible users, and Super Admin).
     */
    private function neutralizedPreparationError(Throwable $e): string
    {
        return 'Terjadi kesalahan saat memproses perhitungan penerimaan retur.';
    }

    /**
     * Error message for review and approval actions where stock visibility permissions apply.
     */
    private function neutralizedApprovalError(Throwable $e): string
    {
        if (Gate::allows(TransferStockVisibility::PERMISSION)) {
            return $e->getMessage();
        }

        return 'Terjadi kesalahan saat memproses persetujuan penerimaan retur.';
    }

    /**
     * Show return receipt preparation view / JSON projection (universally blind).
     */
    public function prepare(Transfer $transfer, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.receive.create');

        $batchId = $request->input('batch');
        if (!$batchId && $request->route('batch')) {
            $batchId = $request->route('batch');
        }

        if (!$batchId) {
            abort(400, 'Parameter batch diperlukan untuk penerimaan retur.');
        }

        // Find the source return dispatch batch
        $sourceDispatch = TransferMovement::where('transfer_id', $transfer->id)
            ->where('type', TransferMovement::TYPE_RETURN_DISPATCH)
            ->where('return_batch_id', $batchId)
            ->where('status', TransferMovement::STATUS_APPROVED)
            ->first();

        if (!$sourceDispatch) {
            abort(404, 'Batch pengiriman retur yang disetujui tidak ditemukan.');
        }

        try {
            $movement = $this->preparationService->getOrCreateDraft($transfer, $sourceDispatch, auth()->id());
            $canViewSystemStock = TransferStockVisibility::canView();

            if ($request->wantsJson()) {
                return response()->json(
                    $this->projectionService->getPreparationProjection($transfer, $movement, false)
                );
            }

            return view('adjustment::transfers.return_receipt_prepare', compact('transfer', 'movement', 'sourceDispatch', 'canViewSystemStock'));
        } catch (Throwable $e) {
            Log::error('Failed to prepare return receipt', ['transfer_id' => $transfer->id, 'batch' => $batchId, 'error' => $e->getMessage()]);
            if ($request->wantsJson()) {
                return response()->json(['error' => $this->neutralizedPreparationError($e)], 400);
            }
            toast($this->neutralizedPreparationError($e), 'error');
            return redirect()->route('transfers.show', $transfer->id);
        }
    }

    /**
     * Explicitly confirm empty count (nothing physically arrived for this return batch).
     */
    public function confirmEmpty(Transfer $transfer, TransferMovement $movement, Request $request): JsonResponse
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.receive.create', $movement);

        $expectedLockVersion = (int) $request->input('lock_version', $movement->lock_version);

        try {
            $updatedMovement = $this->preparationService->confirmEmpty($movement, $expectedLockVersion, auth()->id());

            return response()->json([
                'status'     => 'success',
                'projection' => $this->projectionService->getPreparationProjection($transfer, $updatedMovement, false),
            ]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $this->neutralizedPreparationError($e)], 400);
        }
    }

    /**
     * Apply barcode/serial scan for return receipt.
     */
    public function scan(Transfer $transfer, TransferMovement $movement, Request $request): JsonResponse
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.receive.create', $movement);

        $query = (string) $request->input('query', '');
        $expectedLockVersion = (int) $request->input('lock_version', $movement->lock_version);
        $settingId = (int) session('setting_id');

        try {
            $result = $this->preparationService->applyScan(
                $movement,
                $query,
                $settingId,
                $expectedLockVersion,
                auth()->id(),
                false // always blind preparation
            );

            return response()->json($result);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $this->neutralizedPreparationError($e)], 400);
        }
    }

    /**
     * Set explicit line quantity and confirmation.
     */
    public function setQuantity(Transfer $transfer, TransferMovement $movement, Request $request): JsonResponse
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.receive.create', $movement);

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

            return response()->json([
                'status'     => 'success',
                'projection' => $this->projectionService->getPreparationProjection($transfer, $updatedMovement, false),
            ]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $this->neutralizedPreparationError($e)], 400);
        }
    }

    /**
     * Submit draft return receipt for approval.
     */
    public function submit(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.receive.create', $movement);

        $expectedLockVersion = (int) $request->input('lock_version', $movement->lock_version);

        try {
            $pendingMovement = $this->preparationService->submit(
                $movement,
                $expectedLockVersion,
                auth()->id()
            );

            if ($request->wantsJson()) {
                return response()->json(['status' => 'success', 'movement_id' => $pendingMovement->id]);
            }

            toast('Perhitungan penerimaan retur berhasil diajukan untuk ditinjau.', 'success');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['status' => 'error', 'message' => $this->neutralizedPreparationError($e)], 400);
            }
            toast($this->neutralizedPreparationError($e), 'error');
            return back();
        }
    }

    /**
     * Cancel a draft return receipt.
     */
    public function cancel(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.receive.create', $movement);

        $reason = (string) $request->input('reason', 'Draft penerimaan retur dibatalkan.');

        try {
            $this->documentService->cancel($movement, $reason, auth()->id());
            toast('Draft perhitungan penerimaan retur dibatalkan.', 'info');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            toast($this->neutralizedPreparationError($e), 'error');
            return back();
        }
    }

    /**
     * Start superseding correction draft for a REJECTED return receipt attempt.
     */
    public function correct(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.receive.create', $movement);

        try {
            $newDraft = $this->preparationService->startCorrection($movement, auth()->id());
            toast('Draft koreksi penerimaan retur berhasil dibuat.', 'info');
            return redirect()->route('transfers.movements.return-receipt.prepare', [
                'transfer' => $transfer->id,
                'batch'    => $newDraft->return_batch_id,
            ]);
        } catch (Throwable $e) {
            toast($this->neutralizedPreparationError($e), 'error');
            return back();
        }
    }

    /**
     * Show approval review projection / modal data for a pending return receipt movement.
     */
    public function review(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.receive.approval', $movement);

        $canViewSystemStock = TransferStockVisibility::canView();
        $projection = $this->projectionService->getApprovalProjection($transfer, $movement, $canViewSystemStock);

        if ($request->wantsJson()) {
            return response()->json($projection);
        }

        return view('adjustment::transfers.return_receipt_review', compact('transfer', 'movement', 'projection', 'canViewSystemStock'));
    }

    /**
     * Approve pending return receipt movement.
     */
    public function approve(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.receive.approval', $movement);

        $idempotencyKey = $request->input('idempotency_key');

        try {
            $this->approvalExecutor->approve($transfer, $movement, auth()->id(), $idempotencyKey);
            toast('Penerimaan retur transfer stok berhasil disetujui dan stok asal telah dipulihkan.', 'success');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            toast($this->neutralizedApprovalError($e), 'error');
            return back();
        }
    }

    /**
     * Reject pending return receipt movement.
     */
    public function reject(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.receive.approval', $movement);

        $reason = (string) $request->input('reason', '');
        $idempotencyKey = $request->input('idempotency_key');

        try {
            $this->documentService->reject($movement, $reason, auth()->id(), $idempotencyKey);
            toast('Penerimaan retur transfer stok ditolak.', 'warning');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            toast($this->neutralizedApprovalError($e), 'error');
            return back();
        }
    }
}
