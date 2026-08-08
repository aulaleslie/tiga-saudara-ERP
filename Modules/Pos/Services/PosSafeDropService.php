<?php

namespace Modules\Pos\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Services\PosCashDrawerService;
use Modules\Pos\Services\PosSessionExpectedCashCalculator;
use Modules\Pos\Services\PosSupervisorApprovalService;

class PosSafeDropService
{
    public function __construct(
        private readonly PosSessionExpectedCashCalculator $expectedCashCalculator,
        private readonly PosSupervisorApprovalService $supervisorApprovalService,
        private readonly PosCashDrawerService $cashDrawerService
    ) {
    }

    /**
     * @return array{
     *     session_id:int,
     *     cash_event_id:int,
     *     approval_id:int|null,
     *     approval_result:string,
     *     expected_cash_before:float,
     *     expected_cash_after:float,
     *     dropped_amount:float,
     *     threshold_value:float,
     *     is_threshold_breached:bool,
     *     occurred_at:string
     * }
     */
    public function createSafeDrop(
        int $settingId,
        int $sessionId,
        int $actorUserId,
        float $amount,
        ?array $denominations = null,
        ?string $notes = null,
        ?string $supervisorIdentifier = null,
        ?string $supervisorPin = null,
        ?array $preApproved = null
    ): array {
        if ($amount <= 0) {
            throw new DomainException('Safe drop amount must be greater than zero.');
        }

        $user = User::query()->find($actorUserId);
        $isSuperAdmin = $user?->hasRole('Super Admin') ?? false;

        $belongsToSetting = DB::table('user_setting')
            ->where('setting_id', $settingId)
            ->where('user_id', $actorUserId)
            ->exists();

        if (! $isSuperAdmin && ! $belongsToSetting) {
            throw new DomainException('Actor user is not assigned to current setting.');
        }

        $normalizedAmount = round($amount, 2);
        $normalizedDenominations = $this->normalizeDenominations($denominations);

        if (! empty($normalizedDenominations)) {
            $denominationTotal = $this->calculateDenominationTotal($normalizedDenominations);

            if (abs($denominationTotal - $normalizedAmount) > 0.0001) {
                throw new DomainException('Safe drop denomination total must match safe drop amount.');
            }
        }

        $result = DB::transaction(function () use (
            $settingId,
            $sessionId,
            $actorUserId,
            $isSuperAdmin,
            $normalizedAmount,
            $normalizedDenominations,
            $notes,
            $supervisorIdentifier,
            $supervisorPin,
            $preApproved
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

            if ($session->status !== PosSession::STATUS_OPEN) {
                throw new DomainException('Safe drop is only allowed for OPEN sessions.');
            }

            $expectedBefore = round((float) $session->expected_cash_total, 2);

            if ($normalizedAmount > $expectedBefore) {
                throw new DomainException('Safe drop amount cannot exceed expected cash.');
            }

            $policy = $session->terminal?->policy;

            if (! $policy) {
                throw new DomainException('POS terminal policy is missing.');
            }

            $approvalId = null;
            $approvedBy = null;
            $approvalResult = 'BYPASSED';

            if ($preApproved !== null) {
                // Supervisor was already verified upstream (e.g. OTP pickup flow);
                // reuse that approval instead of re-running credential checks.
                $approvalId = (int) $preApproved['approval_id'];
                $approvalResult = (string) $preApproved['approval_result'];
                $approvedBy = isset($preApproved['approved_by']) ? (int) $preApproved['approved_by'] : null;
            } elseif (! $isSuperAdmin && (bool) $policy->require_pickup_supervisor_approval) {
                if (! $supervisorIdentifier || ! $supervisorPin) {
                    throw new DomainException('Supervisor credentials are required for safe drop.');
                }

                $approval = $this->supervisorApprovalService->approveSafeDrop(
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
                        'approval_rejected' => true,
                    ];
                }
            }

            $expectedBefore = round((float) $session->expected_cash_total, 2);

            $cashEvent = PosSessionCashEvent::query()->create([
                'setting_id' => $settingId,
                'pos_session_id' => (int) $session->id,
                'event_type' => PosSessionCashEvent::EVENT_SAFE_DROP_OUT,
                'direction' => PosSessionCashEvent::DIRECTION_OUT,
                'amount' => $normalizedAmount,
                'denominations' => empty($normalizedDenominations) ? null : $normalizedDenominations,
                'performed_by' => $actorUserId,
                'approved_by' => $approvedBy,
                'notes' => $notes,
                'metadata' => null,
                'occurred_at' => now(),
            ]);

            $this->cashDrawerService->triggerDrawerOpen(
                PosCashDrawerService::TRIGGER_PICKUP,
                (int) $session->terminal_id,
                $settingId,
                [
                    'pos_session_id' => $session->id,
                    'cash_event_id' => $cashEvent->id,
                ],
                $session->terminal
            );

            $calculation = $this->expectedCashCalculator->calculate((int) $session->id);
            $expectedAfter = round((float) $calculation['expected_cash_total'], 2);

            $thresholdValue = $policy->cash_threshold;

            if ($thresholdValue === null) {
                $thresholdValue = (float) config('pos.default_cash_threshold', 10000000);
            }

            $threshold = round((float) $thresholdValue, 2);

            return [
                'approval_rejected' => false,
                'payload' => [
                    'session_id' => (int) $session->id,
                    'cash_event_id' => (int) $cashEvent->id,
                    'approval_id' => $approvalId,
                    'approval_result' => $approvalResult,
                    'expected_cash_before' => $expectedBefore,
                    'expected_cash_after' => $expectedAfter,
                    'dropped_amount' => $normalizedAmount,
                    'threshold_value' => $threshold,
                    'is_threshold_breached' => $expectedAfter > $threshold,
                    'occurred_at' => $cashEvent->occurred_at?->toISOString() ?? now()->toISOString(),
                ],
            ];
        });

        if ((bool) ($result['approval_rejected'] ?? false)) {
            throw new DomainException('Supervisor approval failed for safe drop.');
        }

        return $result['payload'];
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
