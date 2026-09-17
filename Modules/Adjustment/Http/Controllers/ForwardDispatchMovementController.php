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
use Modules\Adjustment\Services\ForwardDispatchApprovalExecutor;
use Modules\Adjustment\Services\ForwardDispatchPreparationService;
use Modules\Adjustment\Services\ForwardDispatchProjectionService;
use Modules\Adjustment\Services\TransferMovementDocumentService;
use Modules\Adjustment\Services\TransferStockVisibility;
use Throwable;

class ForwardDispatchMovementController extends Controller
{
    public function __construct(
        private ForwardDispatchPreparationService $preparationService,
        private ForwardDispatchProjectionService $projectionService,
        private ForwardDispatchApprovalExecutor $approvalExecutor,
        private TransferMovementDocumentService $documentService,
    ) {
    }

    /**
     * Check feature activation, origin ownership, and scoped movement hierarchy.
     */
    private function authorizeOriginAction(Transfer $transfer, string $permission, ?TransferMovement $movement = null): void
    {
        if (!config('stock_transfers.v2_dispatch_enabled', false)) {
            abort(404, 'Forward dispatch workflow version 2 is not enabled.');
        }

        abort_if(Gate::denies($permission), 403);

        $currentSettingId = (int) session('setting_id');
        $transfer->loadMissing('originLocation.setting');

        if ($transfer->originLocation?->setting_id !== $currentSettingId) {
            abort(403, 'Unauthorized origin location.');
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
            if ($movement->type !== TransferMovement::TYPE_FORWARD_DISPATCH) {
                abort(400, 'Movement type is not FORWARD_DISPATCH.');
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
     * Show preparation view / JSON projection.
     */
    public function prepare(Transfer $transfer, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.dispatch.create');

        try {
            $movement = $this->preparationService->getOrCreateDraft($transfer, auth()->id());
            $canViewSystemStock = TransferStockVisibility::canView();

            if ($request->wantsJson()) {
                return response()->json(
                    $this->projectionService->getPreparationProjection($transfer, $movement, $canViewSystemStock)
                );
            }

            $movement->loadMissing([
                'lines.product',
                'lines.serials',
            ]);

            if ($canViewSystemStock) {
                $transfer->loadMissing('products');
            }

            return view('adjustment::transfers.forward_dispatch_prepare', compact('transfer', 'movement', 'canViewSystemStock'));
        } catch (Throwable $e) {
            Log::error('Failed to prepare forward dispatch', ['transfer_id' => $transfer->id, 'error' => $e->getMessage()]);
            if ($request->wantsJson()) {
                return response()->json(['error' => $this->neutralizedErrorMessage($e)], 400);
            }
            toast($this->neutralizedErrorMessage($e), 'error');
            return redirect()->route('transfers.show', $transfer->id);
        }
    }

    /**
     * Apply barcode/serial scan.
     */
    public function scan(Transfer $transfer, TransferMovement $movement, Request $request): JsonResponse
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.dispatch.create', $movement);

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

    /**
     * Set explicit line quantity and confirmation.
     */
    public function setQuantity(Transfer $transfer, TransferMovement $movement, Request $request): JsonResponse
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.dispatch.create', $movement);

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

    /**
     * Submit draft count for approval.
     */
    public function submit(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.dispatch.create', $movement);

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

            toast('Perhitungan pengiriman berhasil diajukan untuk ditinjau.', 'success');
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
     * Cancel a draft count.
     */
    public function cancel(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.dispatch.create', $movement);

        $reason = (string) $request->input('reason', 'Draft pergerakan dibatalkan.');

        try {
            $this->documentService->cancel($movement, $reason, auth()->id());
            toast('Draft perhitungan pengiriman dibatalkan.', 'info');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }

    /**
     * Start superseding correction draft for a REJECTED movement.
     */
    public function correct(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.dispatch.create', $movement);

        try {
            $newDraft = $this->documentService->startCorrection($movement, auth()->id());
            toast('Draft koreksi berhasil dibuat.', 'info');
            return redirect()->route('transfers.movements.prepare', ['transfer' => $transfer->id]);
        } catch (Throwable $e) {
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }

    /**
     * Show approval review projection / modal data for a pending forward dispatch movement.
     */
    public function review(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.dispatch.approval', $movement);

        $canViewSystemStock = TransferStockVisibility::canView();
        $projection = $this->projectionService->getApprovalProjection($transfer, $movement, $canViewSystemStock);

        if ($request->wantsJson()) {
            return response()->json($projection);
        }

        return view('adjustment::transfers.forward_dispatch_review', compact('transfer', 'movement', 'projection', 'canViewSystemStock'));
    }

    /**
     * Approve pending dispatch movement.
     */
    public function approve(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.dispatch.approval', $movement);

        $idempotencyKey = $request->input('idempotency_key');

        try {
            $this->approvalExecutor->approve($transfer, $movement, auth()->id(), $idempotencyKey);
            toast('Pengiriman transfer stok berhasil disetujui.', 'success');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }

    /**
     * Reject pending dispatch movement.
     */
    public function reject(Transfer $transfer, TransferMovement $movement, Request $request)
    {
        $this->authorizeOriginAction($transfer, 'stockTransfers.dispatch.approval', $movement);

        $reason = (string) $request->input('reason', '');
        $idempotencyKey = $request->input('idempotency_key');

        try {
            $this->documentService->reject($movement, $reason, auth()->id(), $idempotencyKey);
            toast('Pengiriman transfer stok ditolak.', 'warning');
            return redirect()->route('transfers.show', $transfer->id);
        } catch (Throwable $e) {
            toast($this->neutralizedErrorMessage($e), 'error');
            return back();
        }
    }
}
