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
use Modules\Adjustment\Services\ForwardReceiptApprovalExecutor;
use Modules\Adjustment\Services\ForwardReceiptPreparationService;
use Modules\Adjustment\Services\ForwardReceiptProjectionService;
use Modules\Adjustment\Services\TransferMovementDocumentService;
use Modules\Adjustment\Services\TransferStockVisibility;
use Throwable;

class ForwardReceiptMovementController extends Controller
{
    public function __construct(
        private ForwardReceiptPreparationService $preparationService,
        private ForwardReceiptProjectionService $projectionService,
        private ForwardReceiptApprovalExecutor $approvalExecutor,
        private TransferMovementDocumentService $documentService,
    ) {
    }

    /**
     * Check feature activation, destination ownership, and scoped movement hierarchy.
     */
    private function authorizeDestinationAction(Transfer $transfer, string $permission, ?TransferMovement $movement = null): void
    {
        if (!config('stock_transfers.v2_dispatch_enabled', false)) {
            abort(404, 'Forward receipt workflow version 2 is not enabled.');
        }

        abort_if(Gate::denies($permission), 403);

        $currentSettingId = (int) session('setting_id');
        $transfer->loadMissing('destinationLocation.setting');

        if ($transfer->destinationLocation?->setting_id !== $currentSettingId) {
            abort(403, 'Unauthorized destination location.');
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
            if ($movement->type !== TransferMovement::TYPE_FORWARD_RECEIPT) {
                abort(400, 'Movement type is not FORWARD_RECEIPT.');
            }
        }
    }

    private function neutralizedErrorMessage(Throwable $e): string
    {
        if (Gate::allows(TransferStockVisibility::PERMISSION)) {
            return $e->getMessage();
        }

        return 'Terjadi kesalahan saat memproses pergerakan transfer stok.';
    }

    /**
     * Show receipt preparation view / JSON projection (universally blind).
     */
    public function prepare(Transfer $transfer, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.receive.create');

        try {
            $movement = $this->preparationService->getOrCreateDraft($transfer, auth()->id());
            $canViewSystemStock = TransferStockVisibility::canView();

            if ($request->wantsJson()) {
                return response()->json(
                    $this->projectionService->getPreparationProjection($transfer, $movement, false)
                );
            }

            return view('adjustment::transfers.forward_receipt_prepare', compact('transfer', 'movement', 'canViewSystemStock'));
        } catch (Throwable $e) {
            Log::error('Failed to prepare forward receipt', ['transfer_id' => $transfer->id, 'error' => $e->getMessage()]);
            if ($request->wantsJson()) {
                return response()->json(['error' => $this->neutralizedErrorMessage($e)], 400);
            }
            toast($this->neutralizedErrorMessage($e), 'error');
            return redirect()->route('transfers.show', $transfer->id);
        }
    }

    /**
     * Explicitly confirm empty count (nothing physically arrived).
     */
    public function confirmEmpty(Transfer $transfer, TransferMovement $movement, Request $request): JsonResponse
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.receive.create', $movement);

        $expectedLockVersion = (int) $request->input('lock_version', $movement->lock_version);

        try {
            $updatedMovement = $this->preparationService->confirmEmpty($movement, $expectedLockVersion, auth()->id());

            return response()->json([
                'status'     => 'success',
                'projection' => $this->projectionService->getPreparationProjection($transfer, $updatedMovement, false),
            ]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $this->neutralizedErrorMessage($e)], 400);
        }
    }

    /**
     * Apply barcode/serial scan.
     */
    public function scan(Transfer $transfer, TransferMovement $movement, Request $request): JsonResponse
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.receive.create', $movement);

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
            return response()->json(['status' => 'error', 'message' => $this->neutralizedErrorMessage($e)], 400);
        }
    }

    /**
     * Set explicit line quantity and confirmation.
     */
    public function setQuantity(Transfer $transfer, TransferMovement $movement, Request $request): JsonResponse
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.receive.create', $movement);

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
            return response()->json(['status' => 'error', 'message' => $this->neutralizedErrorMessage($e)], 400);
        }
    }

    /**
     * Submit draft receipt for approval.
     */
    public function submit(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.receive.create', $movement);

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

            toast('Perhitungan penerimaan berhasil diajukan untuk ditinjau.', 'success');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['status' => 'error', 'message' => $this->neutralizedErrorMessage($e)], 400);
            }
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }

    /**
     * Cancel a draft receipt.
     */
    public function cancel(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.receive.create', $movement);

        $reason = (string) $request->input('reason', 'Draft penerimaan dibatalkan.');

        try {
            $this->documentService->cancel($movement, $reason, auth()->id());
            toast('Draft perhitungan penerimaan dibatalkan.', 'info');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }

    /**
     * Start superseding correction draft for a REJECTED receipt movement.
     */
    public function correct(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.receive.create', $movement);

        try {
            $newDraft = $this->documentService->startCorrection($movement, auth()->id());
            toast('Draft koreksi penerimaan berhasil dibuat.', 'info');
            return redirect()->route('transfers.movements.receipt.prepare', ['transfer' => $transfer->id]);
        } catch (Throwable $e) {
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }

    /**
     * Show approval review projection / modal data for a pending forward receipt movement.
     */
    public function review(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.receive.approval', $movement);

        $canViewSystemStock = TransferStockVisibility::canView();
        $projection = $this->projectionService->getApprovalProjection($transfer, $movement, $canViewSystemStock);

        if ($request->wantsJson()) {
            return response()->json($projection);
        }

        return view('adjustment::transfers.forward_receipt_review', compact('transfer', 'movement', 'projection', 'canViewSystemStock'));
    }

    /**
     * Approve pending receipt movement.
     */
    public function approve(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.receive.approval', $movement);

        $idempotencyKey = $request->input('idempotency_key');

        try {
            $this->approvalExecutor->approve($transfer, $movement, auth()->id(), $idempotencyKey);
            toast('Penerimaan transfer stok berhasil disetujui dan stok telah ditambahkan.', 'success');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }

    /**
     * Reject pending receipt movement.
     */
    public function reject(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeDestinationAction($transfer, 'stockTransfers.receive.approval', $movement);

        $reason = (string) $request->input('reason', '');
        $idempotencyKey = $request->input('idempotency_key');

        try {
            $this->documentService->reject($movement, $reason, auth()->id(), $idempotencyKey);
            toast('Penerimaan transfer stok ditolak.', 'warning');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }
}
