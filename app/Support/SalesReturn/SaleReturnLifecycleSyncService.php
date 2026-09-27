<?php

namespace App\Support\SalesReturn;

use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;

class SaleReturnLifecycleSyncService
{
    /**
     * Sync source sale return status based on sales returns that have been received.
     * Received returns are sale returns in AWAITING SETTLEMENT or COMPLETED status.
     */
    public function syncSourceSaleReturnStatusFromReceivedReturns(SaleReturn $saleReturn): void
    {
        $saleId = (int) ($saleReturn->sale_id ?? 0);
        if ($saleId <= 0) {
            return;
        }

        $sale = Sale::withArchived()
            ->whereKey($saleId)
            ->lockForUpdate()
            ->first();

        if (! $sale) {
            return;
        }

        $dispatchedQuantity = (int) DispatchDetail::query()
            ->where('sale_id', $sale->id)
            ->sum('dispatched_quantity');

        $returnedReceivedQuantity = (int) SaleReturnDetail::query()
            ->whereHas('saleReturn', function ($query) use ($sale) {
                $query->withArchived()
                    ->where('sale_id', $sale->id)
                    ->whereIn('status', ['AWAITING SETTLEMENT', 'COMPLETED']);
            })
            ->sum('quantity');

        if ($dispatchedQuantity > 0 && $returnedReceivedQuantity >= $dispatchedQuantity) {
            if ($sale->status !== Sale::STATUS_RETURNED) {
                $sale->status = Sale::STATUS_RETURNED;
                $sale->save();
            }

            return;
        }

        if ($returnedReceivedQuantity > 0 && $sale->status !== Sale::STATUS_RETURNED_PARTIALLY) {
            $sale->status = Sale::STATUS_RETURNED_PARTIALLY;
            $sale->save();
        }
    }

    /**
     * Roll up sale return completion based on final settlement item states.
     */
    public function syncSaleReturnCompletionRollup(SaleReturn $saleReturn, int $actorId): void
    {
        $saleReturn->load('settlementItems');

        $newStatus = $saleReturn->settlement_status === 'Settled'
            ? 'Completed'
            : $saleReturn->status;

        $saleReturn->update([
            'status' => $newStatus,
            'settled_at' => strtoupper((string) $newStatus) === 'COMPLETED'
                ? ($saleReturn->settled_at ?? now())
                : $saleReturn->settled_at,
            'settled_by' => strtoupper((string) $newStatus) === 'COMPLETED'
                ? ($saleReturn->settled_by ?? $actorId)
                : $saleReturn->settled_by,
        ]);

        $this->syncLinkedPosReturnCompletion($saleReturn, $actorId);
    }

    /**
     * Determine whether cumulative effective (received, AWAITING SETTLEMENT or COMPLETED)
     * Sales Return quantities fully cover every dispatched line for the given sale.
     *
     * Coverage is evaluated per dispatch_detail_id so an over-return on one line cannot
     * mask an under-return on another. Sale Return details without a dispatch_detail_id
     * (legacy/ambiguous lineage) are excluded from proving coverage and are reported
     * separately so archival never guesses.
     *
     * @return array{fully_covered: bool, ambiguous_quantity: float, dispatch_lines: array<int, array{dispatched: float, returned: float, covered: bool}>}
     */
    public function calculateEffectiveStandardReturnCoverage(int $saleId): array
    {
        $dispatchLines = DispatchDetail::query()
            ->where('sale_id', $saleId)
            ->selectRaw('id, SUM(dispatched_quantity) as dispatched_quantity')
            ->groupBy('id')
            ->pluck('dispatched_quantity', 'id')
            ->map(fn ($quantity) => (float) $quantity)
            ->all();

        $returnedByDispatchDetail = SaleReturnDetail::query()
            ->whereNotNull('dispatch_detail_id')
            ->whereHas('saleReturn', function ($query) use ($saleId) {
                $query->withArchived()
                    ->where('sale_id', $saleId)
                    ->whereIn('status', ['AWAITING SETTLEMENT', 'COMPLETED']);
            })
            ->selectRaw('dispatch_detail_id, SUM(quantity) as returned_quantity')
            ->groupBy('dispatch_detail_id')
            ->pluck('returned_quantity', 'dispatch_detail_id')
            ->map(fn ($quantity) => (float) $quantity)
            ->all();

        $ambiguousQuantity = (float) SaleReturnDetail::query()
            ->whereNull('dispatch_detail_id')
            ->whereHas('saleReturn', function ($query) use ($saleId) {
                $query->withArchived()
                    ->where('sale_id', $saleId)
                    ->whereIn('status', ['AWAITING SETTLEMENT', 'COMPLETED']);
            })
            ->sum('quantity');

        if (empty($dispatchLines)) {
            return [
                'fully_covered' => false,
                'ambiguous_quantity' => $ambiguousQuantity,
                'dispatch_lines' => [],
            ];
        }

        $lines = [];
        $fullyCovered = true;
        foreach ($dispatchLines as $dispatchDetailId => $dispatchedQuantity) {
            $returnedQuantity = (float) ($returnedByDispatchDetail[$dispatchDetailId] ?? 0);
            $covered = $dispatchedQuantity > 0 && $returnedQuantity >= $dispatchedQuantity;

            $lines[$dispatchDetailId] = [
                'dispatched' => $dispatchedQuantity,
                'returned' => $returnedQuantity,
                'covered' => $covered,
            ];

            if (! $covered) {
                $fullyCovered = false;
            }
        }

        return [
            'fully_covered' => $fullyCovered,
            'ambiguous_quantity' => $ambiguousQuantity,
            'dispatch_lines' => $lines,
        ];
    }

    /**
     * Archive the source sale when either POS-corrected active quantities are zero
     * (existing fast path) or effective standard-return coverage is fully complete.
     * This is intentionally triggered only after the sale return itself is completed.
     */
    public function archiveSourceSaleIfFullyReturnedAndCompleted(SaleReturn $saleReturn, int $actorId): void
    {
        if (strtoupper((string) $saleReturn->status) !== 'COMPLETED') {
            return;
        }

        $this->syncLinkedPosReturnCompletion($saleReturn, $actorId);

        $saleId = (int) ($saleReturn->sale_id ?? 0);
        if ($saleId <= 0) {
            return;
        }

        $sale = Sale::withArchived()
            ->whereKey($saleId)
            ->lockForUpdate()
            ->first();

        if (! $sale) {
            return;
        }

        $activeSaleQuantity = (float) SaleDetails::query()
            ->where('sale_id', $sale->id)
            ->sum('quantity');

        $activeDispatchedQuantity = (float) DispatchDetail::query()
            ->where('sale_id', $sale->id)
            ->sum('dispatched_quantity');

        $posCorrectedFullyReturned = $activeSaleQuantity <= 0 && $activeDispatchedQuantity <= 0;

        // POS returns mutate sale-detail and dispatch quantities directly during receiving,
        // so the standard-return effective-coverage fallback (which reasons over persisted
        // dispatched quantities) only applies to sales that were never POS-corrected; it must
        // never be combined with POS-mutated quantities to avoid double-counting.
        $saleHasPosCorrection = SaleReturn::withArchived()
            ->where('sale_id', $sale->id)
            ->whereNotNull('pos_return_id')
            ->exists();

        $standardFullyCovered = false;
        if (! $posCorrectedFullyReturned && ! $saleHasPosCorrection) {
            $effectiveCoverage = $this->calculateEffectiveStandardReturnCoverage($sale->id);
            $standardFullyCovered = $effectiveCoverage['fully_covered'];
        }

        if (! $posCorrectedFullyReturned && ! $standardFullyCovered) {
            return;
        }

        $updates = [];
        if (is_null($sale->archived_at)) {
            $updates['archived_at'] = now();
            $updates['archived_by'] = $actorId;
        }

        $posReturnReference = (string) optional($saleReturn->posReturn)->reference;
        $noteLine = collect([
            'Barang sudah diretur penuh',
            $posReturnReference !== '' ? 'POS Return ' . $posReturnReference : null,
            $saleReturn->reference ? 'Sales Return ' . $saleReturn->reference : null,
        ])->filter()->implode(' | ');

        $currentNote = (string) ($sale->note ?? '');
        if ($noteLine !== '' && stripos($currentNote, $noteLine) === false) {
            $updates['note'] = $currentNote !== ''
                ? rtrim($currentNote) . "\n" . $noteLine
                : $noteLine;
        }

        if ($sale->status !== Sale::STATUS_RETURNED) {
            $updates['status'] = Sale::STATUS_RETURNED;
        }

        if (! empty($updates)) {
            $sale->update($updates);
        }
    }

    private function syncLinkedPosReturnCompletion(SaleReturn $saleReturn, int $actorId): void
    {
        $posReturnId = (int) ($saleReturn->pos_return_id ?? 0);
        if ($posReturnId <= 0) {
            return;
        }

        $posReturn = \Modules\Pos\Entities\PosReturn::query()
            ->with('saleReturns:id,pos_return_id,status')
            ->whereKey($posReturnId)
            ->lockForUpdate()
            ->first();

        if (! $posReturn) {
            return;
        }

        $allCompleted = $posReturn->saleReturns->isNotEmpty()
            && $posReturn->saleReturns->every(function (SaleReturn $linkedSaleReturn) {
                return strtoupper((string) $linkedSaleReturn->status) === 'COMPLETED';
            });

        if (! $allCompleted) {
            return;
        }

        $posReturn->update([
            'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED,
            'settled_at' => $posReturn->settled_at ?? now(),
            'settled_by' => $posReturn->settled_by ?? $actorId,
        ]);
    }
}
