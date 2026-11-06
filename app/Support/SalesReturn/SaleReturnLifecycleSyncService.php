<?php

namespace App\Support\SalesReturn;

use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;

class SaleReturnLifecycleSyncService
{
    /**
     * Sync source sale return status. Workflow-explicit entry point:
     *  - POS-corrected sales (any linked POS return) use active-quantity status, which
     *    never depends on standard settlement rows, inspection or custody.
     *  - Standard sales use received-return coverage.
     *  - A POS-origin sale without POS corrections (legacy/mixed history) is never
     *    changed by a standard return; evidence is left exactly as it is.
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

        // Mixed history is checked BEFORE choosing either mutation branch: incompatible
        // quantity models are never combined and historical evidence is never reinterpreted.
        $classification = app(SalesReturnWorkflowResolver::class)->classifySale((int) $sale->id);
        if ($classification === SalesReturnWorkflowResolver::MIXED) {
            return;
        }

        if ($classification === SalesReturnWorkflowResolver::POS) {
            $this->syncPosActiveQuantityStatus($sale);

            return;
        }

        $this->syncStandardReceivedStatus($sale);
    }

    /** POS branch: status derives from remaining active quantities only. */
    private function syncPosActiveQuantityStatus(Sale $sale): void
    {
        $activeSaleQuantity = (float) SaleDetails::query()
            ->where('sale_id', $sale->id)
            ->sum('quantity');

        $activeDispatchedQuantity = (float) DispatchDetail::query()
            ->where('sale_id', $sale->id)
            ->sum('dispatched_quantity');

        $activeBundleItemQuantity = (float) SaleBundleItem::query()
            ->where('sale_id', $sale->id)
            ->sum('quantity');

        $isFullyReturned = $activeSaleQuantity <= 0 && $activeDispatchedQuantity <= 0 && $activeBundleItemQuantity <= 0;
        $target = $isFullyReturned ? Sale::STATUS_RETURNED : Sale::STATUS_RETURNED_PARTIALLY;

        if ($sale->status !== $target) {
            $sale->status = $target;
            $sale->save();
        }
    }

    /** Standard branch: status derives from received standard return quantities. */
    private function syncStandardReceivedStatus(Sale $sale): void
    {
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
        // POS returns complete through their own atomic approval; they never have (or need)
        // standard settlement rows, so settlement rollup does not apply to them.
        if ($this->isMixedHistory($saleReturn)) {
            return;
        }

        if (! empty($saleReturn->pos_return_id)) {
            $this->syncLinkedPosReturnCompletion($saleReturn, $actorId);

            return;
        }

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

        if ($this->isMixedHistory($saleReturn)) {
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

        $fullyReturned = ! empty($saleReturn->pos_return_id)
            ? $this->posArchivalSatisfied($sale)
            : $this->standardArchivalSatisfied($sale);

        if (! $fullyReturned) {
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

    private function isMixedHistory(SaleReturn $saleReturn): bool
    {
        $saleId = (int) ($saleReturn->sale_id ?? 0);

        return $saleId > 0
            && app(SalesReturnWorkflowResolver::class)->classifySale($saleId) === SalesReturnWorkflowResolver::MIXED;
    }

    /** POS archival: all active quantities are zero. Independent of settlement/inspection. */
    private function posArchivalSatisfied(Sale $sale): bool
    {
        $activeSaleQuantity = (float) SaleDetails::query()->where('sale_id', $sale->id)->sum('quantity');
        $activeDispatchedQuantity = (float) DispatchDetail::query()->where('sale_id', $sale->id)->sum('dispatched_quantity');
        $activeBundleItemQuantity = (float) SaleBundleItem::query()->where('sale_id', $sale->id)->sum('quantity');

        return $activeSaleQuantity <= 0 && $activeDispatchedQuantity <= 0 && $activeBundleItemQuantity <= 0;
    }

    /**
     * Standard archival: never on POS-origin or mixed-history sales (historical evidence
     * is not reinterpreted), otherwise by effective coverage of every dispatch line.
     */
    private function standardArchivalSatisfied(Sale $sale): bool
    {
        if (app(SalesReturnWorkflowResolver::class)->classifySale((int) $sale->id) !== SalesReturnWorkflowResolver::STANDARD) {
            return false;
        }

        if ($this->posArchivalSatisfied($sale)) {
            return true;
        }

        return $this->calculateEffectiveStandardReturnCoverage((int) $sale->id)['fully_covered'];
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
