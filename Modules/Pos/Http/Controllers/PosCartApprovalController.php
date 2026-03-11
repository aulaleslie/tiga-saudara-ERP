<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Services\PosApprovalRequestService;

class PosCartApprovalController extends Controller
{
    public function __construct(
        private readonly PosApprovalRequestService $requestService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'action_type' => 'required|string',
            'target_type' => 'required|string',
            'target_id'   => 'required|integer',
            'payload'     => 'nullable|array',
        ]);

        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);

        $approvalRequest = $this->requestService->createRequest(
            $settingId,
            $sessionId,
            $request->user(),
            $request->input('action_type'),
            $request->input('target_type'),
            $request->input('target_id'),
            $request->input('payload', [])
        );

        return response()->json([
            'request_id' => $approvalRequest->id,
            'status'     => $approvalRequest->status,
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
