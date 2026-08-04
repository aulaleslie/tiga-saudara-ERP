<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Http\Requests\PosCartTotalOverrideRequest;
use Modules\Pos\Services\PosCartService;

class PosCartTotalOverrideController extends Controller
{
    public function __construct(
        private readonly PosCartService $cartService
    ) {
    }

    /**
     * Apply or request a total-price override for the current cart.
     */
    public function store(PosCartTotalOverrideRequest $request): JsonResponse
    {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);
        $user = $request->user();

        try {
            $approvalToken = $request->string('approval_token')->trim()->value() ?: null;

            // For token execution, do not depend on client-provided target_total.
            // Pass a placeholder value; the service will use the approved request payload.
            $targetTotalMinorUnits = 0;
            if ($approvalToken === null) {
                // Direct override: target_total is required and was validated
                $targetTotalMinorUnits = (int) round($request->float('target_total') * 100);
            }

            $reason = $request->string('reason')->trim()->value() ?: null;

            $result = $this->cartService->overrideTotalPrice(
                $settingId,
                $sessionId,
                (int) $user->id,
                $targetTotalMinorUnits,
                $reason,
                $approvalToken,
                $user
            );

            // Distinguish between direct application and request creation
            if (isset($result['status']) && $result['status'] === 'request_created') {
                // Return as-is for request creation (without cart_snapshot)
                return response()->json($result, 201);
            }

            // For direct application, include the cart snapshot
            return response()->json(['cart_snapshot' => $result], 200);
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
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
