<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Pos\Services\PosApprovalRequestService;

class PosSupervisorApprovalQueueController extends Controller
{
    public function __construct(
        private readonly PosApprovalRequestService $requestService
    ) {
    }

    public function index()
    {
        return view('pos::approval-queue.index');
    }

    public function data(Request $request): JsonResponse
    {
        $settingId = $this->currentSettingId();
        
        $filters = [
            'status' => $request->input('status', 'PENDING'),
        ];
        
        $paginator = $this->requestService->listPending($settingId, $filters);
        
        return response()->json([
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function approve(int $id, Request $request): JsonResponse
    {
        try {
            $note = $request->input('note');
            $result = $this->requestService->approveRequest($id, $request->user(), $note);
            return response()->json($result);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reject(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        try {
            $result = $this->requestService->rejectRequest($id, $request->user(), $request->input('reason'));
            return response()->json($result);
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
}
