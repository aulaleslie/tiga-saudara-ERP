<?php

namespace Modules\Pos\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use App\Models\User;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;

/**
 * Supervisor Cash Finalization Service
 *
 * Handles supervisor finalization of closed POS sessions. This is the second stage
 * of the two-stage settlement flow. After an admin force-closes a session (or a
 * cashier completes the normal close flow), a supervisor receives the actual cash
 * from the cashier and enters the amount received. This service:
 *
 * 1. Calculates expected cash from session data
 * 2. Compares against actual cash received
 * 3. Calculates variance
 * 4. Gates finalization on variance approval if variance exceeds threshold
 *
 * Flow: CLOSED → FINALIZED (with optional approval gate)
 *
 * Permissions: requires pos.supervisor.approval + pos.sessions.approve-variance
 * (if variance exceeds threshold)
 */
class PosSessionFinalizeService
{
    public function __construct(
        private readonly PosSessionExpectedCashCalculator $expectedCashCalculator,
    ) {
    }

    /**
     * Finalize a closed POS session after supervisor receives and counts cash
     *
     * @return array{
     *     blocked: bool,
     *     payload: array<string, mixed>
     * }
     */
    public function finalizeSession(
        int $settingId,
        int $sessionId,
        int $supervisorUserId,
        float $actualCashReceived,
        ?string $notes = null
    ): array {
        if ($actualCashReceived < 0) {
            throw new DomainException('Actual cash received must be at least zero.');
        }

        $belongsToSetting = DB::table('user_setting')
            ->where('setting_id', $settingId)
            ->where('user_id', $supervisorUserId)
            ->exists();

        if (! $belongsToSetting) {
            throw new DomainException('Supervisor user is not assigned to current setting.');
        }

        return DB::transaction(function () use (
            $settingId,
            $sessionId,
            $supervisorUserId,
            $actualCashReceived,
            $notes
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

            if ($session->status !== PosSession::STATUS_CLOSED) {
                throw new DomainException('POS session must be in CLOSED status to finalize.');
            }

            $policy = $session->terminal?->policy;

            if (! $policy) {
                throw new DomainException('POS terminal policy is missing.');
            }

            // Calculate expected cash using the dedicated calculator
            $calculation = $this->expectedCashCalculator->calculate((int) $session->id);
            $session->refresh();

            $expectedCashTotal = round((float) $calculation['expected_cash_total'], 2);
            $normalizedActualCash = round($actualCashReceived, 2);
            $varianceTotal = round($normalizedActualCash - $expectedCashTotal, 2);
            $varianceThreshold = round((float) $policy->close_variance_approval_threshold, 2);

            // Check if variance exceeds threshold
            if (abs($varianceTotal) > $varianceThreshold) {
                // Get supervisor user for permission check
                $supervisor = User::find($supervisorUserId);

                if (! $supervisor) {
                    throw new DomainException('Supervisor user not found.');
                }

                // Check if supervisor has permission to approve variance
                if (! $supervisor->can('pos.sessions.approve-variance')) {
                    return [
                        'blocked' => true,
                        'payload' => [
                            'message' => 'Variance approval permission required to finalize session with variance exceeding threshold.',
                            'requires_variance_approval' => true,
                            'status' => PosSession::STATUS_CLOSED,
                            'variance_total' => $varianceTotal,
                            'variance_threshold' => $varianceThreshold,
                        ],
                    ];
                }
            }

            // Update session to FINALIZED status
            $session->update([
                'status' => PosSession::STATUS_FINALIZED,
                'finalized_at' => now(),
            ]);

            // Create PosSessionCashEvent for finalization
            PosSessionCashEvent::query()->create([
                'setting_id' => $settingId,
                'pos_session_id' => (int) $session->id,
                'event_type' => PosSessionCashEvent::EVENT_FINALIZE_COUNT,
                'direction' => PosSessionCashEvent::DIRECTION_NEUTRAL,
                'amount' => $normalizedActualCash,
                'performed_by' => $supervisorUserId,
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
                    'session_id' => (int) $session->id,
                    'status' => (string) $session->status,
                    'finalized_at' => $session->finalized_at->toISOString(),
                    'variance_total' => $varianceTotal,
                    'variance_threshold' => $varianceThreshold,
                    'expected_cash_total' => $expectedCashTotal,
                    'actual_cash_received' => $normalizedActualCash,
                    'approval_result' => abs($varianceTotal) > $varianceThreshold ? 'APPROVED' : 'WITHIN_THRESHOLD',
                ],
            ];
        });
    }
}
