<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Services\PosApprovalRequestService;
use Modules\Pos\Services\PosCartService;

class PosCartApprovalController extends Controller
{
    public function __construct(
        private readonly PosApprovalRequestService $requestService,
        private readonly PosCartService $cartService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'action_type' => 'required|string',
            'target_type' => 'required|string',
            'target_id'   => 'required|integer',
            'payload'     => 'nullable|array',
            'reason'      => 'nullable|string|max:255',
        ]);

        $actionType = (string) $request->input('action_type');
        if (
            $actionType === \Modules\Pos\Entities\PosActionApprovalRequest::ACTION_TOTAL_PRICE_OVERRIDE
            || $actionType === \Modules\Pos\Entities\PosActionApprovalRequest::ACTION_PRICE_OVERRIDE
        ) {
            return response()->json([
                'message' => 'Tindakan penyesuaian harga ini telah digantikan oleh total baris.',
            ], 422);
        }

        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);

        $payload = (array) $request->input('payload', []);
        $reason = $request->filled('reason') ? $request->string('reason')->value() : ($payload['reason'] ?? null);

        if (\Modules\Pos\Entities\PosActionApprovalRequest::isRowOverrideAction($actionType)) {
            $lineId = (int) $request->input('target_id');
            $cart = app(\Modules\Pos\Services\PosCartSessionStore::class)->getCart($settingId, $sessionId);
            $line = $cart['lines'][$lineId] ?? null;

            if ($line === null) {
                return response()->json([
                    'message' => 'Baris keranjang tidak ditemukan.',
                ], 422);
            }

            // Only the requested value and reason are accepted from the client.
            // Source values, customer context, and the fingerprint are all
            // derived server-side from the authoritative cart.
            $requestedValue = $payload['requested_value']
                ?? $payload['requested_unit_price']
                ?? $payload['requested_total']
                ?? null;

            if ($requestedValue === null || ! is_numeric($requestedValue)) {
                return response()->json([
                    'message' => 'Nilai yang diminta wajib diisi dan berupa angka.',
                ], 422);
            }

            if ((float) $requestedValue < 0) {
                return response()->json([
                    'message' => 'Nilai yang diminta tidak boleh negatif.',
                ], 422);
            }

            try {
                $payload = app(\Modules\Pos\Services\PosRowOverrideApprovalPayloadBuilder::class)->build(
                    $actionType,
                    $settingId,
                    $sessionId,
                    $lineId,
                    $cart,
                    $line,
                    (int) round(((float) $requestedValue) * 100),
                    (int) $request->user()->id,
                    $reason
                );
            } catch (DomainException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        } else {
            if ($reason !== null) {
                $payload['reason'] = $reason;
            }
        }

        try {
            $approvalRequest = $this->requestService->createRequest(
                $settingId,
                $sessionId,
                $request->user(),
                $actionType,
                $request->input('target_type'),
                $request->input('target_id'),
                $payload
            );
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $cartSnapshot = $this->cartService->getSnapshot($settingId, $sessionId);

        return response()->json([
            'request_id' => $approvalRequest->id,
            'status'     => $approvalRequest->status,
            'cart_snapshot' => $cartSnapshot,
        ], 201);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        try {
            $statusData = $this->requestService->checkStatus($id, $request->user());
            return response()->json($statusData);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function cancel(int $id, Request $request): JsonResponse
    {
        try {
            $this->requestService->cancelRequest($id, $request->user());
            return response()->json(['message' => 'Layanan telah dibatalkan']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function currentSettingId(): int
    {
        $settingId = (int) session('setting_id');
        abort_if($settingId <= 0, 403, 'Setting context is required.');
        return $settingId;
    }

    private function activeSessionId(Request $request): int
    {
        $sessionId = (int) $request->attributes->get('pos_session_id');

        if ($sessionId > 0) {
            return $sessionId;
        }

        $activeSession = $request->attributes->get('pos_active_session');

        if ($activeSession instanceof PosSession) {
            return (int) $activeSession->id;
        }

        abort(403, 'Active POS session context is required.');
    }
}
