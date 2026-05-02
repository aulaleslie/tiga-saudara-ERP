<?php

namespace App\Support\SalesReturn;

use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
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
     * Archive the source sale when cumulative COMPLETED returns fully cover dispatched qty.
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

        $dispatchedQuantity = (int) DispatchDetail::query()
            ->where('sale_id', $sale->id)
            ->sum('dispatched_quantity');

        if ($dispatchedQuantity <= 0) {
            return;
        }

        $returnedCompletedQuantity = (int) SaleReturnDetail::query()
            ->whereHas('saleReturn', function ($query) use ($sale) {
                $query->withArchived()
                    ->where('sale_id', $sale->id)
                    ->where('status', 'COMPLETED');
            })
            ->sum('quantity');

        if ($returnedCompletedQuantity < $dispatchedQuantity) {
            return;
        }

        $updates = [];
        if (is_null($sale->archived_at)) {
            $updates['archived_at'] = now();
            $updates['archived_by'] = $actorId;
        }

        // Preserve a human-readable trail like purchase-return archival.
        $noteLine = 'Barang sudah diretur ' . $saleReturn->reference;
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
