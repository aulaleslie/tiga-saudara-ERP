<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;

/**
 * Records version 3 lifecycle events inside the caller's transaction, so an
 * event exists only when its action committed. Metadata carries evidence
 * links (revisions, movement ids, active business) — never route locations;
 * those live in the allocation/evidence tables behind approval authority.
 */
class TransferV3EventRecorder
{
    public static function record(
        Transfer $transfer,
        string $action,
        ?string $fromStatus,
        string $toStatus,
        int $actorId,
        ?int $activeSettingId,
        ?string $reason = null,
        array $metadata = [],
        ?string $idempotencyKey = null
    ): TransferActionHistory {
        return TransferActionHistory::create([
            'transfer_id'     => $transfer->id,
            'revision'        => $transfer->revision,
            'action'          => $action,
            'from_status'     => $fromStatus,
            'to_status'       => $toStatus,
            'actor_id'        => $actorId,
            'reason'          => $reason,
            'metadata'        => array_merge(['workflow_version' => Transfer::WORKFLOW_V3, 'active_setting_id' => $activeSettingId], $metadata),
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Returns the committed event for a replayed operation key, if any.
     */
    public static function replayed(Transfer $transfer, string $action, ?string $idempotencyKey): ?TransferActionHistory
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        return TransferActionHistory::where('transfer_id', $transfer->id)
            ->where('action', $action)
            // BaseModel uppercases stored strings; compare the same way.
            ->where('idempotency_key', mb_strtoupper(trim($idempotencyKey), 'UTF-8'))
            ->first();
    }
}
