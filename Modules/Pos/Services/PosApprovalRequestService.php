<?php

namespace Modules\Pos\Services;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Entities\PosSupervisorApproval;

class PosApprovalRequestService
{
    public function __construct(
        private readonly PosApprovalTokenService $tokenService
    ) {
    }

    public function createRequest(
        int $settingId,
        int $sessionId,
        User $requester,
        string $actionType,
        string $targetType,
        int $targetId,
        array $payload
    ): PosActionApprovalRequest {
        $this->assertSupportedAction($actionType);

        // Cancel any existing PENDING or APPROVED requests for the same action+target from this user
        // This prevents stale approvals from showing in the cart when a new request is created
        return \Illuminate\Support\Facades\DB::transaction(function () use (
            $settingId,
            $sessionId,
            $requester,
            $actionType,
            $targetType,
            $targetId,
            $payload
        ) {
            PosActionApprovalRequest::query()
                ->where('requested_by', $requester->id)
                ->where('action_type', $actionType)
                ->where('target_id', $targetId)
                ->whereIn('status', [
                    PosActionApprovalRequest::STATUS_PENDING,
                    PosActionApprovalRequest::STATUS_APPROVED,
                ])
                ->update(['status' => PosActionApprovalRequest::STATUS_CANCELLED]);

            return PosActionApprovalRequest::create([
                'setting_id' => $settingId,
                'pos_session_id' => $sessionId,
                'action_type' => $actionType,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'request_payload' => $payload,
                'requested_by' => $requester->id,
                'status' => PosActionApprovalRequest::STATUS_PENDING,
            ]);
        });
    }

    public function checkStatus(int $requestId, User $requester): array
    {
        $request = PosActionApprovalRequest::query()
            ->where('id', $requestId)
            ->where('requested_by', $requester->id)
            ->with('token')
            ->first();

        if (! $request) {
            throw new DomainException('Request was not found.');
        }

        $tokenStr = null;
        if ($request->status === PosActionApprovalRequest::STATUS_APPROVED && $request->token) {
            $tokenStr = $request->token->token_hash;
            $expiresAt = $request->token->expires_at->toIso8601String();
        }

        $normalizedState = strtolower((string) $request->status);

        return [
            'status' => $request->status,
            'state' => $normalizedState,
            'token_ready' => $tokenStr !== null,
            'can_retry_check' => $request->status === PosActionApprovalRequest::STATUS_PENDING,
            'approval_token' => $tokenStr,
            'token' => $tokenStr,
            'expires_at' => $expiresAt ?? null,
            'decision_reason' => $request->decision_reason,
        ];
    }

    public function cancelRequest(int $requestId, User $requester): void
    {
        $request = PosActionApprovalRequest::query()
            ->where('id', $requestId)
            ->where('requested_by', $requester->id)
            ->with('token')
            ->first();

        if (! $request) {
            throw new DomainException('Request was not found.');
        }

        if (! in_array($request->status, [
            PosActionApprovalRequest::STATUS_PENDING,
            PosActionApprovalRequest::STATUS_APPROVED,
        ], true)) {
            throw new DomainException('NOT_PENDING');
        }

        $request->update(['status' => PosActionApprovalRequest::STATUS_CANCELLED]);

        if (
            $request->token
            && $request->token->consumed_at === null
        ) {
            $request->token->update([
                'consumed_at' => now(),
                'consumed_by' => $requester->id,
                'consumed_context' => [
                    'action' => 'cancelled_by_requester',
                ],
            ]);
        }
    }

    public function listPending(int $settingId, ?array $filters = []): LengthAwarePaginator
    {
        $query = PosActionApprovalRequest::query()
            ->where('setting_id', $settingId)
            ->with(['requester', 'session']);

        if (empty($filters['status'])) {
            $query->where('status', PosActionApprovalRequest::STATUS_PENDING);
        } else {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['action_type'])) {
            $query->where('action_type', $filters['action_type']);
        }

        if (! empty($filters['requested_by'])) {
            $query->where('requested_by', $filters['requested_by']);
        }

        return $query->latest()->paginate(15);
    }

    public function approveRequest(int $requestId, User $supervisor, ?string $note = null): array
    {
        $request = PosActionApprovalRequest::query()->find($requestId);

        if (! $request) {
            throw new DomainException('Request was not found.');
        }

        if ($request->status !== PosActionApprovalRequest::STATUS_PENDING) {
            throw new DomainException('ALREADY_DECIDED');
        }

        $requiredPermission = $this->requiredPermissionForAction($request->action_type);

        if (! $supervisor->can('pos.supervisor.approval') || ! $supervisor->can($requiredPermission)) {
            throw new DomainException('MISSING_PERMISSION');
        }

        $actionParam = $this->supervisorActionFor($request->action_type);

        $request->update([
            'status' => PosActionApprovalRequest::STATUS_APPROVED,
            'decided_by' => $supervisor->id,
            'decided_at' => now(),
            'decision_reason' => $note,
        ]);

        // Build audit context snapshot
        $contextSnapshot = [];
        if ($request->action_type === PosActionApprovalRequest::ACTION_TOTAL_PRICE_OVERRIDE && $request->request_payload) {
            $contextSnapshot = [
                'source_total' => $request->request_payload['source_total'] ?? null,
                'target_total' => $request->request_payload['target_total'] ?? null,
                'fingerprint' => $request->request_payload['fingerprint'] ?? null,
                'allocation' => $request->request_payload['allocation'] ?? null,
                'reason' => $request->request_payload['reason'] ?? null,
                'requester_id' => $request->requested_by,
                'approver_id' => $supervisor->id,
                'approval_timestamp' => now()->toIso8601String(),
            ];
        }

        PosSupervisorApproval::create([
            'setting_id' => $request->setting_id,
            'action_type' => $actionParam,
            'target_type' => 'pos_session',
            'target_id' => $request->pos_session_id,
            'requested_by' => $request->requested_by,
            'approved_by' => $supervisor->id,
            'approval_result' => PosSupervisorApproval::RESULT_APPROVED,
            'reason' => $note,
            'context_snapshot' => $contextSnapshot ?: null,
            'occurred_at' => now(),
        ]);

        $tokenTTL = 10;
        $this->tokenService->issueToken($request, $tokenTTL);

        return [
            'status' => PosActionApprovalRequest::STATUS_APPROVED,
            'state' => 'approved',
            'token_ttl_seconds' => $tokenTTL * 60,
        ];
    }

    public function rejectRequest(int $requestId, User $supervisor, string $reason): array
    {
        $request = PosActionApprovalRequest::query()->find($requestId);

        if (! $request) {
            throw new DomainException('Request was not found.');
        }

        if ($request->status !== PosActionApprovalRequest::STATUS_PENDING) {
            throw new DomainException('ALREADY_DECIDED');
        }

        if (! $supervisor->can('pos.supervisor.approval')) {
            throw new DomainException('MISSING_PERMISSION');
        }

        $actionParam = $this->supervisorActionFor($request->action_type);

        $request->update([
            'status' => PosActionApprovalRequest::STATUS_REJECTED,
            'decided_by' => $supervisor->id,
            'decided_at' => now(),
            'decision_reason' => $reason,
        ]);

        PosSupervisorApproval::create([
            'setting_id' => $request->setting_id,
            'action_type' => $actionParam,
            'target_type' => 'pos_session',
            'target_id' => $request->pos_session_id,
            'requested_by' => $request->requested_by,
            'approved_by' => $supervisor->id,
            'approval_result' => PosSupervisorApproval::RESULT_REJECTED,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);

        return [
            'status' => PosActionApprovalRequest::STATUS_REJECTED,
            'state' => 'rejected',
            'decision_reason' => $reason,
        ];
    }

    private function assertSupportedAction(string $actionType): void
    {
        $this->requiredPermissionForAction($actionType);
    }

    private function requiredPermissionForAction(string $actionType): string
    {
        return match ($actionType) {
            PosActionApprovalRequest::ACTION_CART_CLEAR => 'pos.cart.clear',
            PosActionApprovalRequest::ACTION_LINE_REMOVE => 'pos.cart.line.remove',
            PosActionApprovalRequest::ACTION_QTY_REDUCE => 'pos.cart.line.reduce',
            PosActionApprovalRequest::ACTION_PRICE_OVERRIDE => 'pos.overrides.price',
            PosActionApprovalRequest::ACTION_TOTAL_PRICE_OVERRIDE => 'pos.overrides.total-price',
            PosActionApprovalRequest::ACTION_TRANSACTION_CANCEL => 'pos.void',
            PosActionApprovalRequest::ACTION_CHECKOUT_AS_DEBT => 'pos.checkout.debt',
            default => throw new DomainException('Invalid action type.'),
        };
    }

    private function supervisorActionFor(string $actionType): string
    {
        return match ($actionType) {
            PosActionApprovalRequest::ACTION_CART_CLEAR => PosSupervisorApproval::ACTION_CART_CLEAR_APPROVAL,
            PosActionApprovalRequest::ACTION_LINE_REMOVE => PosSupervisorApproval::ACTION_LINE_REMOVE_APPROVAL,
            PosActionApprovalRequest::ACTION_QTY_REDUCE => PosSupervisorApproval::ACTION_QTY_REDUCE_APPROVAL,
            PosActionApprovalRequest::ACTION_PRICE_OVERRIDE => PosSupervisorApproval::ACTION_PRICE_OVERRIDE,
            PosActionApprovalRequest::ACTION_TOTAL_PRICE_OVERRIDE => PosSupervisorApproval::ACTION_TOTAL_PRICE_OVERRIDE_APPROVAL,
            PosActionApprovalRequest::ACTION_TRANSACTION_CANCEL => PosSupervisorApproval::ACTION_TRANSACTION_CANCEL_APPROVAL,
            PosActionApprovalRequest::ACTION_CHECKOUT_AS_DEBT => PosSupervisorApproval::ACTION_CHECKOUT_AS_DEBT_APPROVAL,
            default => throw new DomainException('Invalid action type.'),
        };
    }
}
