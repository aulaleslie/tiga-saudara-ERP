<?php

namespace Modules\Pos\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Pos\Entities\PosSession;

class PosKasBonReceivingSessionResolver
{
    /**
     * Resolve and validate the actor's active-business OPEN receiving session.
     *
     * @param User $actor
     * @param int $settingId
     * @param int|null $clientSessionId
     * @return PosSession
     * @throws ValidationException
     */
    public function resolve(User $actor, int $settingId, ?int $clientSessionId = null): PosSession
    {
        if ($settingId <= 0) {
            throw ValidationException::withMessages([
                'session' => 'Konteks bisnis/cabang aktif diperlukan.',
            ]);
        }

        // Always query server-side for the actor's current open session in this setting
        $session = PosSession::query()
            ->where('setting_id', $settingId)
            ->where('cashier_user_id', $actor->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->whereNotNull('active_marker')
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->first();

        if (! $session) {
            // Check if there is a closed or closing session for more specific error message
            $staleSession = PosSession::query()
                ->where('setting_id', $settingId)
                ->where('cashier_user_id', $actor->id)
                ->orderByDesc('opened_at')
                ->orderByDesc('id')
                ->first();

            if ($staleSession && in_array($staleSession->status, [PosSession::STATUS_CLOSING, PosSession::STATUS_CLOSED, PosSession::STATUS_FINALIZED], true)) {
                throw ValidationException::withMessages([
                    'session' => 'Sesi POS kasir telah ditutup atau sedang dalam proses penutupan.',
                ]);
            }

            throw ValidationException::withMessages([
                'session' => 'Tidak ada sesi POS aktif dan terbuka untuk kasir pada cabang ini.',
            ]);
        }

        // If client provided a session ID, verify it matches the authoritative server session
        if ($clientSessionId !== null && (int) $session->id !== (int) $clientSessionId) {
            throw ValidationException::withMessages([
                'session' => 'Sesi POS yang diminta tidak sesuai dengan sesi aktif kasir.',
            ]);
        }

        // Verify session invariants
        if ((int) $session->cashier_user_id !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'session' => 'Sesi POS bukan milik kasir yang sedang aktif.',
            ]);
        }

        if ((int) $session->setting_id !== (int) $settingId) {
            throw ValidationException::withMessages([
                'session' => 'Sesi POS bukan milik cabang yang sedang aktif.',
            ]);
        }

        if ($session->status !== PosSession::STATUS_OPEN || $session->active_marker === null) {
            throw ValidationException::withMessages([
                'session' => 'Sesi POS tidak dalam status terbuka.',
            ]);
        }

        return $session;
    }
}
