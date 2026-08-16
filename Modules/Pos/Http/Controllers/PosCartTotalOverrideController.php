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
     * Retired: Cart-wide total override is no longer supported.
     */
    public function store(PosCartTotalOverrideRequest $request): JsonResponse
    {
        return response()->json([
            'status' => 'retired',
            'message' => 'Ubah total keranjang telah dipensiunkan. Gunakan ubah total baris (LINE_TOTAL_OVERRIDE).',
            'code' => 'FEATURE_RETIRED',
        ], 422);
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
