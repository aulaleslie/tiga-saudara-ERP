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
    /**
     * Walk a serial's product_replacement lineage forward to the current
     * held/leaf serial id by causal occurrence and checkout lineage.
     *
     * @throws \Exception with a message containing the literal string
     *         "component_replacement_in_flight" if any hop in the chain has
     *         an in-flight (approved but not yet STATUS_COMPLETED)
     *         replacement.
     * @throws \Exception if repeated events or ambiguous lineage are detected.
     */
    public function resolveCurrentHeldSerial(
        int $originalSerialId,
        ?int $posCheckoutId = null,
        ?int $posTransactionId = null,
        ?int $dispatchDetailId = null
    ): int {
        $current = $originalSerialId;
        $visitedLineIds = [];
        $currentLine = null;

        while (true) {
            if ($this->hasInFlightReplacement($current, null, $posCheckoutId, $dispatchDetailId)) {
                throw new \Exception(
                    "component_replacement_in_flight: Komponen bertipe serial memiliki penggantian yang belum selesai (belum dikirim ke pelanggan) — retur seluruh paket bundel tidak dapat diproses sampai penggantian tersebut selesai."
                );
            }

            $nextLine = $this->nextCompletedReplacementLine(
                $current,
                array_keys($visitedLineIds),
                $posCheckoutId,
                $posTransactionId,
                $currentLine,
                $dispatchDetailId
            );

            if (! $nextLine) {
                break;
            }

            if (isset($visitedLineIds[$nextLine->id])) {
                throw new \Exception(
                    "repeated_event_detected: Terdeteksi perulangan event penggantian serial pada baris retur #{$nextLine->id}."
                );
            }

            $nextSerialId = (int) $nextLine->replacement_serial_id;
            if ($nextSerialId <= 0) {
                throw new \Exception(
                    "ambiguous_lineage: Serial pengganti tidak valid atau tidak ditemukan pada baris retur #{$nextLine->id}."
                );
            }

            if ($nextSerialId === $current) {
                throw new \Exception(
                    "ambiguous_lineage: Serial pengganti tidak boleh sama dengan serial yang diretur pada baris retur #{$nextLine->id}."
                );
            }

            $visitedLineIds[$nextLine->id] = true;
            $currentLine = $nextLine;
            $current = $nextSerialId;
        }

        return $current;
    }

    /**
     * Read-only check: does the given serial currently have an in-flight
     * (approved but not yet STATUS_COMPLETED) product_replacement pending
     * against it? Does not throw — callers decide how to surface this
     * (submission throws, preview planning emits an actionable blocker).
     */
    public function hasInFlightReplacement(
        int $serialId,
        ?int $ignorePosReturnId = null,
        ?int $posCheckoutId = null,
        ?int $dispatchDetailId = null
    ): bool {
        $query = PosReturnLine::where('returned_serial_id', $serialId)
            ->where('resolution', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
            ->whereHas('posReturn', function ($q) use ($posCheckoutId) {
                $q->consumesReturnQuantity()
                    ->where('status', '!=', PosReturn::STATUS_COMPLETED);
                if ($posCheckoutId !== null) {
                    $q->where('pos_checkout_id', $posCheckoutId);
                }
            });

        if ($ignorePosReturnId !== null) {
            $query->where('pos_return_id', '!=', $ignorePosReturnId);
        }

        if ($dispatchDetailId !== null && $dispatchDetailId > 0) {
            $query->where('dispatch_detail_id', $dispatchDetailId);
        }

        return $query->exists();
    }

    private function nextCompletedReplacementLine(
        int $serialId,
        array $excludeLineIds = [],
        ?int $posCheckoutId = null,
        ?int $posTransactionId = null,
        ?PosReturnLine $afterLine = null,
        ?int $dispatchDetailId = null
    ): ?PosReturnLine {
        $query = PosReturnLine::query()
            ->select('pos_return_lines.*')
            ->join('pos_returns', 'pos_return_lines.pos_return_id', '=', 'pos_returns.id')
            ->where('pos_return_lines.returned_serial_id', $serialId)
            ->where('pos_return_lines.resolution', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
            ->where('pos_returns.status', PosReturn::STATUS_COMPLETED)
            ->where('pos_returns.is_reversed', false);

        if (! empty($excludeLineIds)) {
            $query->whereNotIn('pos_return_lines.id', $excludeLineIds);
        }

        if ($dispatchDetailId !== null && $dispatchDetailId > 0) {
            $query->where('pos_return_lines.dispatch_detail_id', $dispatchDetailId);
        }

        if ($posCheckoutId !== null) {
            $query->where('pos_returns.pos_checkout_id', $posCheckoutId);
        } elseif ($posTransactionId !== null) {
            $query->where('pos_returns.pos_transaction_id', $posTransactionId);
        }

        if ($afterLine !== null) {
            $afterTimestamp = $afterLine->posReturn?->approved_at ?? $afterLine->posReturn?->created_at ?? $afterLine->created_at;
            $afterReturnId = (int) $afterLine->pos_return_id;
            $afterLineId = (int) $afterLine->id;

            $query->where(function ($q) use ($afterTimestamp, $afterReturnId, $afterLineId) {
                $q->whereRaw('COALESCE(pos_returns.approved_at, pos_returns.created_at) > ?', [$afterTimestamp])
                    ->orWhere(function ($sub) use ($afterTimestamp, $afterReturnId, $afterLineId) {
                        $sub->whereRaw('COALESCE(pos_returns.approved_at, pos_returns.created_at) = ?', [$afterTimestamp])
                            ->where(function ($sub2) use ($afterReturnId, $afterLineId) {
                                $sub2->where('pos_returns.id', '>', $afterReturnId)
                                    ->orWhere(function ($sub3) use ($afterReturnId, $afterLineId) {
                                        $sub3->where('pos_returns.id', '=', $afterReturnId)
                                            ->where('pos_return_lines.id', '>', $afterLineId);
                                    });
                            });
                    });
            });
        }

        $query->orderByRaw('COALESCE(pos_returns.approved_at, pos_returns.created_at) ASC')
            ->orderBy('pos_returns.id', 'ASC')
            ->orderBy('pos_return_lines.id', 'ASC');

        return $query->first();
    }
}
