<?php

namespace Modules\Pos\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;

/**
 * Admin Force-Close Service
 *
 * Handles privileged admin force-close of POS sessions. This is distinct from the normal
 * cashier close flow (PosSessionCloseService). Used when an admin needs to release a
 * terminal immediately without waiting for the cashier to complete cash counting.
 *
 * Flow: OPEN → CLOSED (skips CLOSING state)
 *
 * The session will remain CLOSED until a supervisor finalizes it (CLOSED → FINALIZED).
 * This separation allows multiple cashiers to use the same terminal while cash
 * settlement happens asynchronously.
 *
 * Permissions: requires pos.sessions.close-admin
 */
class PosSessionAdminCloseService
{
    /**
     * Force-close a POS session as admin, immediately transitioning from OPEN to CLOSED
     *
     * @return array{
     *     session_id: int,
     *     status: string,
     *     closed_at: string,
     *     closed_by: int
     * }
     */
    public function closeSessionAsAdmin(
        int $settingId,
        int $sessionId,
        int $adminUserId,
        ?string $reason = null
    ): array {
        $belongsToSetting = DB::table('user_setting')
            ->where('setting_id', $settingId)
            ->where('user_id', $adminUserId)
            ->exists();

        if (! $belongsToSetting) {
            throw new DomainException('Admin user is not assigned to current setting.');
        }

        return DB::transaction(function () use ($settingId, $sessionId, $adminUserId, $reason) {
            $session = PosSession::query()
                ->where('id', $sessionId)
                ->where('setting_id', $settingId)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new DomainException('POS session not found for current setting.');
            }

            if ($session->status !== PosSession::STATUS_OPEN) {
                throw new DomainException('POS session must be in OPEN status to force-close.');
            }

            // Update session to CLOSED status
            $session->update([
                'status' => PosSession::STATUS_CLOSED,
                'closed_by' => $adminUserId,
                'closed_at' => now(),
                'metadata' => array_merge($session->metadata ?? [], [
                    'closed_by_role' => 'admin',
                    'admin_close_reason' => $reason,
                ]),
            ]);

            // Create PosSessionCashEvent for audit trail
            PosSessionCashEvent::query()->create([
                'setting_id' => $settingId,
                'pos_session_id' => (int) $session->id,
                'event_type' => PosSessionCashEvent::EVENT_CLOSE_COUNT,
                'direction' => PosSessionCashEvent::DIRECTION_NEUTRAL,
                'amount' => null,
                'performed_by' => $adminUserId,
                'notes' => $reason,
                'metadata' => [
                    'closed_by_role' => 'admin',
                ],
                'occurred_at' => now(),
            ]);

            return [
                'session_id' => (int) $session->id,
                'status' => (string) $session->status,
                'closed_at' => $session->closed_at->toISOString(),
                'closed_by' => (int) $session->closed_by,
            ];
        });
    }
}
