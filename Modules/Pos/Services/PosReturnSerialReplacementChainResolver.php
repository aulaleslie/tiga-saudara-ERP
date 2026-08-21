<?php

namespace Modules\Pos\Services;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;

/**
 * Shared chain-resolution/in-flight-detection algorithm for a serial's
 * product_replacement lineage.
 *
 * Extracted from PosReturnSubmissionService::resolveCurrentHeldSerial() so
 * both draft submission (Phase 2) and approval preview planning (Phase 3)
 * agree exactly on which statuses count as "in-flight" (blocks) versus
 * "completed" (the replacement has actually happened, so the chain
 * advances) versus irrelevant (draft/pending/rejected/cancelled — never a
 * link at all, since those never dispatched a replacement).
 *
 * A replacement PosReturnLine only represents a real, physically-executed
 * hand-off once its own PosReturn has reached STATUS_COMPLETED — that is
 * the point PosReturnLifecycleService::dispatchReplacement() (or the
 * same-owner synchronous dispatch path) has actually run and the customer
 * is physically holding the replacement unit. Any other consuming status
 * (STATUS_APPROVED, STATUS_AWAITING_RECEIVING, STATUS_AWAITING_SETTLEMENT,
 * STATUS_AWAITING_DISPATCH, STATUS_MANUAL_CORRECTION_REQUIRED) means the
 * current serial is itself mid-replacement and must block rather than be
 * treated as resolved.
 */
class PosReturnSerialReplacementChainResolver
{
    private const MAX_ITERATIONS = 50;

    /**
     * Walk a serial's product_replacement lineage forward to the current
     * held/leaf serial id.
     *
     * @throws \Exception with a message containing the literal string
     *         "component_replacement_in_flight" if any hop in the chain has
     *         an in-flight (approved but not yet STATUS_COMPLETED)
     *         replacement.
     */
    public function resolveCurrentHeldSerial(int $originalSerialId): int
    {
        $current = $originalSerialId;
        $visited = [$current => true];

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            if ($this->hasInFlightReplacement($current)) {
                throw new \Exception(
                    "component_replacement_in_flight: Komponen bertipe serial memiliki penggantian yang belum selesai (belum dikirim ke pelanggan) — retur seluruh paket bundel tidak dapat diproses sampai penggantian tersebut selesai."
                );
            }

            $nextSerialId = $this->nextCompletedReplacementSerial($current);

            if (! $nextSerialId || isset($visited[$nextSerialId])) {
                break;
            }

            $current = (int) $nextSerialId;
            $visited[$current] = true;
        }

        return $current;
    }

    /**
     * Read-only check: does the given serial currently have an in-flight
     * (approved but not yet STATUS_COMPLETED) product_replacement pending
     * against it? Does not throw — callers decide how to surface this
     * (submission throws, preview planning emits an actionable blocker).
     */
    public function hasInFlightReplacement(int $serialId, ?int $ignorePosReturnId = null): bool
    {
        $query = PosReturnLine::where('returned_serial_id', $serialId)
            ->where('resolution', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
            ->whereHas('posReturn', function ($q) {
                $q->consumesReturnQuantity()
                    ->where('status', '!=', PosReturn::STATUS_COMPLETED);
            });

        if ($ignorePosReturnId !== null) {
            $query->where('pos_return_id', '!=', $ignorePosReturnId);
        }

        return $query->exists();
    }

    private function nextCompletedReplacementSerial(int $serialId): ?int
    {
        $next = PosReturnLine::where('returned_serial_id', $serialId)
            ->where('resolution', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
            ->whereHas('posReturn', function ($q) {
                $q->where('status', PosReturn::STATUS_COMPLETED);
            })
            ->value('replacement_serial_id');

        return $next ? (int) $next : null;
    }
}
