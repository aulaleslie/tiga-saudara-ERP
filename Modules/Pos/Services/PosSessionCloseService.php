<?php

namespace Modules\Pos\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosSession;

class PosSessionCloseService
{
    public function __construct(
        private readonly PosSessionLifecycleService $sessionLifecycleService
    ) {
    }

    /**
     * Closes a POS terminal and releases it for reuse.
     * Variance reconciliation happens in the finalize stage.
     *
     * @return array{
     *     session_id: int,
     *     status: string,
     *     closed_at: string
     * }
     */
    public function closeSession(
        int $settingId,
        int $sessionId,
        int $actorUserId,
        ?string $reason = null
    ): array {
        $belongsToSetting = DB::table('user_setting')
            ->where('setting_id', $settingId)
            ->where('user_id', $actorUserId)
            ->exists();

        if (! $belongsToSetting) {
            throw new DomainException('Actor user is not assigned to current setting.');
        }

        return DB::transaction(function () use (
            $settingId,
            $sessionId,
            $actorUserId,
            $reason
        ) {
            $session = PosSession::query()
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
                throw new DomainException('POS session must be in CLOSING state to close.');
            }

            $closedSession = $this->sessionLifecycleService->finalizeClosing(
                (int) $session->id,
                $actorUserId,
                null,
                $reason,
                null
            );

            return [
                'session_id' => (int) $closedSession->id,
                'status' => (string) $closedSession->status,
                'closed_at' => $closedSession->closed_at?->toISOString(),
            ];
        });
    }
}
