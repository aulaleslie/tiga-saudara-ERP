<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;

/**
 * Sanitized version 3 event timeline. Callers must already hold show and
 * view-history. Structured metadata is removed before serialization; only
 * approvers additionally see revision evidence. Route locations are never
 * part of events (they live in approver-only allocation evidence).
 */
class TransferV3HistoryProjection
{
    public const LABELS = [
        TransferActionHistory::ACTION_CREATED          => 'Dibuat',
        TransferActionHistory::ACTION_EDITED           => 'Barang diubah',
        TransferActionHistory::ACTION_SUBMITTED        => 'Diajukan untuk persetujuan',
        TransferActionHistory::ACTION_ALLOCATION_SAVED => 'Progres alokasi disimpan',
        TransferActionHistory::ACTION_REJECTED         => 'Ditolak',
        TransferActionHistory::ACTION_ACKNOWLEDGED     => 'Dikembalikan ke Draf',
        TransferActionHistory::ACTION_APPROVED         => 'Disetujui',
        TransferActionHistory::ACTION_DISPATCHED       => 'Dikirim',
        TransferActionHistory::ACTION_RECEIVED         => 'Diterima',
        TransferActionHistory::ACTION_COMPLETED        => 'Selesai',
        TransferActionHistory::ACTION_CANCELLED        => 'Pengiriman dibatalkan',
    ];

    /**
     * @return array<int, array{action: string, label: string, actor: string, at: ?string, reason: ?string, revision: int, evidence?: array}>
     */
    public static function forTransfer(Transfer $transfer): array
    {
        $includeEvidence = TransferV3Access::canConfigureAllocations();

        return TransferActionHistory::with('actor')
            ->where('transfer_id', $transfer->id)
            ->orderBy('id')
            ->get()
            ->map(function (TransferActionHistory $event) use ($includeEvidence) {
                $row = [
                    'action'   => $event->action,
                    'label'    => self::LABELS[$event->action] ?? $event->action,
                    'actor'    => $event->actor?->name ?? '-',
                    'at'       => $event->created_at?->format('d-m-Y H:i'),
                    'reason'   => $event->reason,
                    'revision' => (int) $event->revision,
                ];

                if ($includeEvidence) {
                    $metadata = $event->metadata ?? [];
                    $row['evidence'] = array_filter([
                        'request_revision_number' => $metadata['request_revision_number'] ?? null,
                        'configuration_revision'  => $metadata['configuration_revision'] ?? null,
                    ], fn ($value) => $value !== null);
                }

                return $row;
            })
            ->all();
    }
}
