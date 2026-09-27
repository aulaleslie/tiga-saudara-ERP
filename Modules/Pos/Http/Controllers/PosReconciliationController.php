<?php

namespace Modules\Pos\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Pos\Services\PosReconciliationService;

class PosReconciliationController extends Controller
{
    public function index(): Renderable
    {
        $settingId = $this->currentSettingId();

        return view('pos::reconciliation.index', compact('settingId'));
    }

    public function sessions(Request $request, PosReconciliationService $reconciliationService): JsonResponse
    {
        $this->validateDateParams($request);
        
        $settingId = $this->currentSettingId();
        
        return response()->json(
            $reconciliationService->getSessionReconciliation(
                $settingId,
                $request->input('date_from'),
                $request->input('date_to')
            )
        );
    }

    private function validateDateParams(Request $request): void
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);
    }

    private function currentSettingId(): int
    {
        $settingId = (int) session('setting_id');

        abort_if($settingId <= 0, 403, 'Setting context is required.');

        return $settingId;
    }
}
