<?php

namespace Modules\Adjustment\Services;

use App\Models\User;
use App\Services\SerialNumberHistoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementAllocation;
use Modules\Adjustment\Entities\TransferMovementHistory;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use RuntimeException;

/**
 * Batalkan Pengiriman for version 3: exact compensation of a dispatched,
 * unreceived document. Adds each immutable dispatch bucket delta back onto
 * the current source balance (never restoring old snapshots), releases only
 * matching claims, closes custody as cancelled and appends compensating
 * evidence. Shares the transfer-row lock with receipt, so only one terminal
 * outcome can commit.
 */
class TransferV3CancellationExecutor
{
    public function cancel(Transfer $transfer, User $actor, int $activeSettingId, string $reason, ?string $operationKey = null): Transfer
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Alasan pembatalan pengiriman harus diisi.');
        }

        return DB::transaction(function () use ($transfer, $actor, $activeSettingId, $reason, $operationKey) {
            $locked = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            TransferV3Access::assertV3($locked);

            if (TransferV3EventRecorder::replayed($locked, TransferActionHistory::ACTION_CANCELLED, $operationKey)) {
                return $locked;
            }

            if ($locked->status !== Transfer::STATUS_DISPATCHED) {
                throw new RuntimeException('Hanya transfer berstatus Dikirim yang belum diterima yang dapat dibatalkan (status saat ini: ' . $locked->status . ').');
            }

            [$dispatch, $allocations] = TransferV3ReceiptExecutor::lockDispatchEvidence($locked);

            $productIds = $allocations->pluck('product_id')->unique()->sort()->values()->all();
            $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sourceIds = $allocations->pluck('source_location_id')->unique()->all();
            $locations = Location::whereIn('id', $sourceIds)->get()->keyBy('id');
            $stocks = ProductStock::whereIn('product_id', $productIds)->whereIn('location_id', $sourceIds)
                ->orderBy('id')->lockForUpdate()->get()
                ->keyBy(fn (ProductStock $stock) => $stock->product_id . ':' . $stock->location_id);
            [$movementSerials, $liveSerials, $claims] = TransferV3ReceiptExecutor::lockSerialCustody($dispatch);

            $now = Carbon::now();

            $cancellation = TransferMovement::create([
                'transfer_id'             => $locked->id,
                'type'                    => TransferMovement::TYPE_DISPATCH_CANCELLATION,
                'revision'                => 1,
                'transfer_revision'       => $locked->revision,
                'status'                  => TransferMovement::STATUS_APPROVED,
                'stock_condition'         => $locked->stock_condition,
                'origin_location_id'      => null,
                'destination_location_id' => null,
                'source_movement_id'      => $dispatch->id,
                'created_by'              => $actor->id,
                'submitted_by'            => $actor->id,
                'reviewed_by'             => $actor->id,
                'submitted_at'            => $now,
                'reviewed_at'             => $now,
                'cancellation_reason'     => $reason,
                'metadata'                => ['workflow_version' => Transfer::WORKFLOW_V3],
            ]);

            $lines = [];
            foreach ($allocations->groupBy('product_id') as $productId => $rows) {
                $lines[(int) $productId] = TransferMovementLine::create([
                    'transfer_movement_id' => $cancellation->id,
                    'product_id'           => (int) $productId,
                    'quantity'             => (int) $rows->sum('quantity'),
                    'count_confirmed'      => true,
                ]);
            }

            foreach ($allocations as $allocation) {
                $product = $products->get($allocation->product_id);
                $source = $locations->get($allocation->source_location_id);
                $stock = $stocks->get($allocation->product_id . ':' . $allocation->source_location_id);

                // The original deduction must still be the recorded one.
                $dispatchTrx = Transaction::whereKey($allocation->inventory_transaction_id)->first();
                if (! $stock || ! $dispatchTrx
                    || (int) $dispatchTrx->product_id !== (int) $allocation->product_id
                    || (int) $dispatchTrx->location_id !== (int) $allocation->source_location_id
                    || (int) $dispatchTrx->quantity !== -((int) $allocation->quantity)) {
                    throw new RuntimeException('Bukti transaksi pengiriman tidak sesuai. Pembatalan dihentikan untuk diperiksa.');
                }

                $allocationSerials = $movementSerials->where('transfer_movement_allocation_id', $allocation->id);
                TransferV3ReceiptExecutor::assertAllocationSerials($allocation, $allocationSerials, TransferV3ReceiptExecutor::dispatchedSerialized($dispatch, $allocation));

                foreach ($allocationSerials as $movementSerial) {
                    $live = TransferV3ReceiptExecutor::assertCustody($movementSerial, $allocation, $liveSerials, $claims, $dispatch);

                    $movementSerial->update([
                        'transit_custody_status' => TransferMovementSerial::CUSTODY_CANCELLED,
                        'custody_closed_at'      => $now,
                    ]);
                    $claims->get($live->id)->delete();

                    SerialNumberHistoryService::record(
                        $live->id,
                        SerialNumberHistory::EVENT_LOCATION_TRANSFER,
                        $allocation->source_location_id,
                        $cancellation,
                        sprintf('Pengiriman transfer %s dibatalkan; tetap di %s', $locked->document_number, $source?->name ?? '-'),
                        $actor->id
                    );
                }

                $buckets = $allocation->appliedBuckets();
                $snapshot = TransferV3InventoryPoster::apply($product, $stock, $buckets, 1);
                $trx = TransferV3InventoryPoster::recordTransaction(
                    (int) $product->id,
                    (int) $allocation->source_setting_id,
                    (int) $allocation->source_location_id,
                    (int) $allocation->quantity,
                    $snapshot,
                    $actor->id,
                    sprintf('Pembatalan pengiriman transfer %s (Transfer #%d): %s', $locked->document_number, $locked->id, $reason)
                );

                TransferMovementAllocation::create(array_merge(TransferV3ReceiptExecutor::copyRoute($allocation), [
                    'transfer_movement_id'            => $cancellation->id,
                    'transfer_movement_line_id'       => $lines[(int) $allocation->product_id]->id,
                    'dispatch_allocation_id'          => $allocation->id,
                    'kind'                            => TransferMovementAllocation::KIND_CANCELLATION,
                    'applied_quantity_non_tax'        => $buckets['non_tax'],
                    'applied_quantity_tax'            => $buckets['tax'],
                    'applied_quantity_broken_non_tax' => $buckets['broken_non_tax'],
                    'applied_quantity_broken_tax'     => $buckets['broken_tax'],
                    'stock_snapshot_before'           => $snapshot['previous_stock'],
                    'stock_snapshot_after'            => $snapshot['current_stock'],
                    'inventory_transaction_id'        => $trx->id,
                    'actor_id'                        => $actor->id,
                ]));
            }

            TransferMovementHistory::create([
                'transfer_movement_id' => $cancellation->id,
                'revision'             => 1,
                'action'               => TransferMovementHistory::ACTION_APPROVED,
                'to_status'            => TransferMovement::STATUS_APPROVED,
                'actor_id'             => $actor->id,
                'reason'               => $reason,
                'idempotency_key'      => $operationKey,
            ]);

            $locked->update([
                'status'              => Transfer::STATUS_CANCELLED,
                'revision'            => $locked->revision + 1,
                'cancelled_by'        => $actor->id,
                'cancelled_at'        => $now,
                'cancellation_reason' => $reason,
            ]);

            TransferV3EventRecorder::record($locked, TransferActionHistory::ACTION_CANCELLED, Transfer::STATUS_DISPATCHED, Transfer::STATUS_CANCELLED, $actor->id, $activeSettingId, $reason, [
                'dispatch_movement_id'     => $dispatch->id,
                'cancellation_movement_id' => $cancellation->id,
            ], $operationKey);

            return $locked;
        });
    }
}
