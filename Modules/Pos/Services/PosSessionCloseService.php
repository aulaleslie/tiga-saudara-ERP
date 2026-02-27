<?php

namespace Modules\Pos\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;

class PosSessionCloseService
{
    public function __construct(
        private readonly PosSessionExpectedCashCalculator $expectedCashCalculator,
        private readonly PosSupervisorApprovalService $supervisorApprovalService,
        private readonly PosSessionLifecycleService $sessionLifecycleService
    ) {
    }

    /**
     * @return array{
     *     blocked:bool,
     *     payload:array<string, mixed>
     * }
     */
    public function closeSession(
        int $settingId,
        int $sessionId,
        int $actorUserId,
        float $countedCashTotal,
        ?array $countedDenominations = null,
        ?string $notes = null,
        ?string $supervisorIdentifier = null,
        ?string $supervisorPin = null
    ): array {
        if ($countedCashTotal < 0) {
            throw new DomainException('Counted cash total must be at least zero.');
        }

        $belongsToSetting = DB::table('user_setting')
            ->where('setting_id', $settingId)
            ->where('user_id', $actorUserId)
            ->exists();

        if (! $belongsToSetting) {
            throw new DomainException('Actor user is not assigned to current setting.');
        }

        $normalizedCountedCash = round($countedCashTotal, 2);
        $normalizedDenominations = $this->normalizeDenominations($countedDenominations);

        if (! empty($normalizedDenominations)) {
            $denominationTotal = $this->calculateDenominationTotal($normalizedDenominations);

            if (abs($denominationTotal - $normalizedCountedCash) > 0.0001) {
                throw new DomainException('Counted denomination total must match counted cash total.');
            }
        }

        return DB::transaction(function () use (
            $settingId,
            $sessionId,
            $actorUserId,
            $normalizedCountedCash,
            $normalizedDenominations,
            $notes,
            $supervisorIdentifier,
            $supervisorPin
        ) {
            $session = PosSession::query()
                ->with('terminal.policy')
                ->where('id', $sessionId)
                ->where('setting_id', $settingId)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new DomainException('POS session not found for current setting.');
            }

            if ((int) $session->cashier_user_id !== $actorUserId) {
                throw new AuthorizationException('Only the session cashier can close this session.');
            }

            if ($session->status === PosSession::STATUS_CLOSED) {
                throw new DomainException('POS session is already closed.');
            }

            if ($session->status === PosSession::STATUS_OPEN) {
                $this->sessionLifecycleService->startClosing((int) $session->id, $actorUserId);
                $session->refresh();
            }

            if ($session->status !== PosSession::STATUS_CLOSING) {
                throw new DomainException('POS session must be in CLOSING state to finalize.');
            }

            $policy = $session->terminal?->policy;

            if (! $policy) {
                throw new DomainException('POS terminal policy is missing.');
            }

            $calculation = $this->expectedCashCalculator->calculate((int) $session->id);
            $session->refresh();

            $expectedCashTotal = round((float) $calculation['expected_cash_total'], 2);
            $varianceTotal = round($normalizedCountedCash - $expectedCashTotal, 2);
            $varianceThreshold = round((float) $policy->close_variance_approval_threshold, 2);
            $requiresApproval = abs($varianceTotal) > $varianceThreshold;

            $approvalId = null;
            $approvalResult = 'BYPASSED';
            $approvedBy = null;

            if ($requiresApproval) {
                if (! $supervisorIdentifier || ! $supervisorPin) {
                    return [
                        'blocked' => true,
                        'payload' => [
                            'message' => 'Supervisor approval is required to close session variance.',
                            'requires_supervisor_approval' => true,
                            'status' => PosSession::STATUS_CLOSING,
                        ],
                    ];
                }

                $approval = $this->supervisorApprovalService->approveSessionCloseVariance(
                    $settingId,
                    (int) $session->id,
                    $actorUserId,
                    $supervisorIdentifier,
                    $supervisorPin
                );

                $approvalId = (int) $approval['approval_id'];
                $approvalResult = (string) $approval['approval_result'];
                $approvedBy = $approval['approved_by'] !== null ? (int) $approval['approved_by'] : null;

                if (! $approval['approved']) {
                    return [
                        'blocked' => true,
                        'payload' => [
                            'message' => 'Supervisor approval failed for session close.',
                            'requires_supervisor_approval' => true,
                            'status' => PosSession::STATUS_CLOSING,
                        ],
                    ];
                }
            }

            $closedSession = $this->sessionLifecycleService->finalizeClosing(
                (int) $session->id,
                $actorUserId,
                $normalizedCountedCash,
                $notes,
                $approvedBy
            );

            $cashEvent = PosSessionCashEvent::query()->create([
                'setting_id' => $settingId,
                'pos_session_id' => (int) $closedSession->id,
                'event_type' => PosSessionCashEvent::EVENT_CLOSE_COUNT,
                'direction' => PosSessionCashEvent::DIRECTION_NEUTRAL,
                'amount' => $normalizedCountedCash,
                'denominations' => empty($normalizedDenominations) ? null : $normalizedDenominations,
                'performed_by' => $actorUserId,
                'approved_by' => $approvedBy,
                'notes' => $notes,
                'metadata' => [
                    'expected_cash_total' => $expectedCashTotal,
                    'variance_total' => $varianceTotal,
                    'variance_threshold' => $varianceThreshold,
                ],
                'occurred_at' => now(),
            ]);

            return [
                'blocked' => false,
                'payload' => [
                    'session_id' => (int) $closedSession->id,
                    'status' => (string) $closedSession->status,
                    'counted_cash_total' => round((float) $closedSession->counted_cash_total, 2),
                    'expected_cash_total' => $expectedCashTotal,
                    'variance_total' => round((float) $closedSession->variance_total, 2),
                    'variance_threshold' => $varianceThreshold,
                    'approval_id' => $approvalId,
                    'approval_result' => $approvalResult,
                    'closed_at' => $closedSession->closed_at?->toISOString(),
                    'close_count_event_id' => (int) $cashEvent->id,
                ],
            ];
        });
    }

    private function normalizeDenominations(?array $denominations): array
    {
        if (! is_array($denominations) || empty($denominations)) {
            return [];
        }

        $normalized = [];

        foreach ($denominations as $denomination => $quantity) {
            if (! is_numeric($denomination) || ! is_numeric($quantity)) {
                continue;
            }

            $denominationValue = (int) $denomination;
            $quantityValue = (int) $quantity;

            if ($denominationValue <= 0 || $quantityValue <= 0) {
                continue;
            }

            $normalized[(string) $denominationValue] = $quantityValue;
        }

        krsort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function calculateDenominationTotal(array $normalizedDenominations): float
    {
        $total = 0.0;

        foreach ($normalizedDenominations as $denomination => $quantity) {
            $total += ((float) $denomination) * ((int) $quantity);
        }

        return round($total, 2);
    }
}
