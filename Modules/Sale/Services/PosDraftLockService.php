<?php

namespace Modules\Sale\Services;

use App\Exceptions\PosException;
use App\Support\PosMetrics;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\PosDraft;

class PosDraftLockService
{
    public const TTL_MINUTES = 15;

    public function acquire(PosDraft $draft, int $userId, bool $override = false): PosDraft
    {
        return DB::transaction(function () use ($draft, $userId, $override) {
            $draft = PosDraft::query()->lockForUpdate()->findOrFail($draft->id);
            $this->assertPayableState($draft);

            $isExpiredLockTakeover = $draft->locked_by_user_id
                && ! $draft->hasActiveLock()
                && (int) $draft->locked_by_user_id !== $userId;

            if ($draft->hasActiveLock() && (int) $draft->locked_by_user_id !== $userId && ! $override) {
                throw new PosException(
                    'POS_LOCK_CONFLICT',
                    'Draft sedang dikunci oleh kasir lain.',
                    409,
                    [
                        'locked_by_user_id' => (int) $draft->locked_by_user_id,
                        'locked_until' => optional($draft->locked_until)?->toIso8601String(),
                    ]
                );
            }

            $now = now();
            $draft->forceFill([
                'locked_by_user_id' => $userId,
                'locked_at' => $now,
                'locked_until' => $now->copy()->addMinutes(self::TTL_MINUTES),
                'last_touched_at' => $now,
            ])->save();

            if ($isExpiredLockTakeover) {
                PosMetrics::increment('lock_timeout', [
                    'setting_id' => $draft->setting_id,
                ]);
            }

            return $draft->fresh();
        });
    }

    public function heartbeat(PosDraft $draft, int $userId): PosDraft
    {
        return DB::transaction(function () use ($draft, $userId) {
            $draft = PosDraft::query()->lockForUpdate()->findOrFail($draft->id);
            $this->assertPayableState($draft);

            if ((int) $draft->locked_by_user_id !== $userId || ! $draft->hasActiveLock()) {
                throw new PosException('POS_LOCK_CONFLICT', 'Lock tidak aktif atau bukan milik Anda.', 409);
            }

            $now = now();
            $draft->forceFill([
                'locked_at' => $now,
                'locked_until' => $now->copy()->addMinutes(self::TTL_MINUTES),
                'last_touched_at' => $now,
            ])->save();

            return $draft->fresh();
        });
    }

    public function release(PosDraft $draft, int $userId, bool $override = false): PosDraft
    {
        return DB::transaction(function () use ($draft, $userId, $override) {
            $draft = PosDraft::query()->lockForUpdate()->findOrFail($draft->id);

            if ($draft->locked_by_user_id && (int) $draft->locked_by_user_id !== $userId && ! $override) {
                throw new PosException('POS_LOCK_CONFLICT', 'Anda tidak memiliki lock draft ini.', 409);
            }

            $draft->forceFill([
                'locked_by_user_id' => null,
                'locked_at' => null,
                'locked_until' => null,
                'last_touched_at' => now(),
            ])->save();

            return $draft->fresh();
        });
    }

    public function ensureOwner(PosDraft $draft, int $userId): void
    {
        if (! $draft->hasActiveLock() || (int) $draft->locked_by_user_id !== $userId) {
            throw new PosException('POS_LOCK_CONFLICT', 'Draft harus dikunci oleh pengguna saat ini.', 409);
        }
    }

    public function assertPayableState(PosDraft $draft): void
    {
        if ($draft->status === PosDraft::STATUS_TERBAYAR) {
            throw new PosException('POS_DRAFT_ALREADY_PAID', 'Draft sudah dibayar.', 409);
        }

        if ($draft->status === PosDraft::STATUS_DIBATALKAN) {
            throw new PosException('POS_DRAFT_VOIDED', 'Draft sudah dibatalkan.', 409);
        }

        if ($draft->isExpired()) {
            if ($draft->status === PosDraft::STATUS_AJUKAN_PEMBAYARAN) {
                $draft->forceFill(['status' => PosDraft::STATUS_KEDALUWARSA])->save();
            }

            throw new PosException('POS_DRAFT_EXPIRED', 'Draft sudah kedaluwarsa.', 409);
        }
    }
}
