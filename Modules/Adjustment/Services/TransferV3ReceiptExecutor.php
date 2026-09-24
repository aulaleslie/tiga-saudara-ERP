<?php

namespace Modules\Adjustment\Services;

use App\Models\User;
use App\Services\SerialNumberHistoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;
use Modules\Adjustment\Entities\TransferActiveSerialClaim;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementAllocation;
use Modules\Adjustment\Entities\TransferMovementHistory;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Setting\Entities\Location;
use RuntimeException;

/**
 * Terima Barang for version 3: the receiver's whole-document confirmation
 * posts every frozen dispatch allocation to its approved destination in one
 * transaction and completes the transfer. Quantities, destinations, serials
 * and tax come only from immutable dispatch evidence. No counts, mismatch
 * handling, partial receipt or return obligation exists in this path.
 */
class TransferV3ReceiptExecutor
{
    public function receive(Transfer $transfer, User $actor, int $activeSettingId, ?string $operationKey = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $actor, $activeSettingId, $operationKey) {
            $locked = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            TransferV3Access::assertV3($locked);

            if (TransferV3EventRecorder::replayed($locked, TransferActionHistory::ACTION_RECEIVED, $operationKey)) {
                return $locked;
            }

            if ($locked->status !== Transfer::STATUS_DISPATCHED) {
                throw new RuntimeException('Transfer tidak dalam status Dikirim sehingga tidak dapat diterima (status saat ini: ' . $locked->status . ').');
            }

            [$dispatch, $allocations] = self::lockDispatchEvidence($locked);

            $productIds = $allocations->pluck('product_id')->unique()->sort()->values()->all();
            $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $destinationIds = $allocations->pluck('destination_location_id')->unique()->all();
            $locations = Location::whereIn('id', $allocations->pluck('source_location_id')->merge($destinationIds)->unique()->all())->get()->keyBy('id');
            $stocks = ProductStock::whereIn('product_id', $productIds)->whereIn('location_id', $destinationIds)
                ->orderBy('id')->lockForUpdate()->get()
                ->keyBy(fn (ProductStock $stock) => $stock->product_id . ':' . $stock->location_id);
            [$movementSerials, $liveSerials, $claims] = self::lockSerialCustody($dispatch);

            $now = Carbon::now();

            $receipt = TransferMovement::create([
                'transfer_id'             => $locked->id,
                'type'                    => TransferMovement::TYPE_FORWARD_RECEIPT,
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
                'metadata'                => ['workflow_version' => Transfer::WORKFLOW_V3, 'confirmation' => 'WHOLE_DOCUMENT'],
            ]);

            $lines = [];
            foreach ($allocations->groupBy('product_id') as $productId => $rows) {
                $lines[(int) $productId] = TransferMovementLine::create([
                    'transfer_movement_id' => $receipt->id,
                    'product_id'           => (int) $productId,
                    'quantity'             => (int) $rows->sum('quantity'),
                    'count_confirmed'      => true,
                ]);
            }

            foreach ($allocations as $allocation) {
                $product = $products->get($allocation->product_id);
                $destination = $locations->get($allocation->destination_location_id);
                $source = $locations->get($allocation->source_location_id);
                $key = $allocation->product_id . ':' . $allocation->destination_location_id;

                $stock = $stocks->get($key) ?? ProductStock::create([
                    'product_id'              => $allocation->product_id,
                    'location_id'             => $allocation->destination_location_id,
                    'quantity'                => 0,
                    'quantity_tax'            => 0,
                    'quantity_non_tax'        => 0,
                    'broken_quantity'         => 0,
                    'broken_quantity_tax'     => 0,
                    'broken_quantity_non_tax' => 0,
                ]);
                $stocks->put($key, $stock);

                $buckets = self::destinationBuckets($allocation);
                $allocationSerials = $movementSerials->where('transfer_movement_allocation_id', $allocation->id);
                self::assertAllocationSerials($allocation, $allocationSerials, self::dispatchedSerialized($dispatch, $allocation));

                foreach ($allocationSerials as $movementSerial) {
                    $live = self::assertCustody($movementSerial, $allocation, $liveSerials, $claims, $dispatch);

                    $newTaxId = match ($allocation->destination_classification) {
                        TransferRoutePolicy::CLASSIFICATION_PRESERVE => $live->tax_id,
                        TransferRoutePolicy::CLASSIFICATION_TAX      => $allocation->tax_id,
                        default                                      => null,
                    };

                    $live->update(['location_id' => $allocation->destination_location_id, 'tax_id' => $newTaxId]);
                    $movementSerial->update([
                        'transit_custody_status' => TransferMovementSerial::CUSTODY_CLOSED,
                        'custody_closed_at'      => $now,
                    ]);
                    $claims->get($live->id)->delete();

                    SerialNumberHistoryService::record(
                        $live->id,
                        SerialNumberHistory::EVENT_LOCATION_TRANSFER,
                        $allocation->destination_location_id,
                        $receipt,
                        sprintf('Diterima transfer %s di %s dari %s', $locked->document_number, $destination?->name ?? '-', $source?->name ?? '-'),
                        $actor->id
                    );
                }

                $snapshot = TransferV3InventoryPoster::apply($product, $stock, $buckets, 1);
                $trx = TransferV3InventoryPoster::recordTransaction(
                    (int) $product->id,
                    (int) $allocation->destination_setting_id,
                    (int) $allocation->destination_location_id,
                    (int) $allocation->quantity,
                    $snapshot,
                    $actor->id,
                    sprintf('Penerimaan transfer %s (Transfer #%d) di %s dari %s', $locked->document_number, $locked->id, $destination?->name ?? '-', $source?->name ?? '-')
                );

                TransferMovementAllocation::create(array_merge(self::copyRoute($allocation), [
                    'transfer_movement_id'            => $receipt->id,
                    'transfer_movement_line_id'       => $lines[(int) $allocation->product_id]->id,
                    'dispatch_allocation_id'          => $allocation->id,
                    'kind'                            => TransferMovementAllocation::KIND_RECEIPT,
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
                'transfer_movement_id' => $receipt->id,
                'revision'             => 1,
                'action'               => TransferMovementHistory::ACTION_APPROVED,
                'to_status'            => TransferMovement::STATUS_APPROVED,
                'actor_id'             => $actor->id,
                'reason'               => 'Penerimaan seluruh barang dikonfirmasi.',
                'idempotency_key'      => $operationKey,
            ]);

            $locked->update([
                'status'      => Transfer::STATUS_COMPLETED,
                'revision'    => $locked->revision + 1,
                'received_by' => $actor->id,
                'received_at' => $now,
            ]);

            $evidence = ['dispatch_movement_id' => $dispatch->id, 'receipt_movement_id' => $receipt->id];
            TransferV3EventRecorder::record($locked, TransferActionHistory::ACTION_RECEIVED, Transfer::STATUS_DISPATCHED, Transfer::STATUS_COMPLETED, $actor->id, $activeSettingId, 'Seluruh barang dikonfirmasi diterima', $evidence, $operationKey);
            TransferV3EventRecorder::record($locked, TransferActionHistory::ACTION_COMPLETED, Transfer::STATUS_DISPATCHED, Transfer::STATUS_COMPLETED, $actor->id, $activeSettingId, 'Transfer selesai', $evidence);

            return $locked;
        });
    }

    /**
     * Frozen destination buckets: same-business preserves source buckets;
     * cross-business reclassifies the whole quantity to the snapshotted
     * destination tax or non-tax bucket within the document condition.
     */
    public static function destinationBuckets(TransferMovementAllocation $allocation): array
    {
        $source = $allocation->appliedBuckets();

        if ($allocation->destination_classification === TransferRoutePolicy::CLASSIFICATION_PRESERVE) {
            return $source;
        }

        $total = array_sum($source);
        $isBroken = $allocation->stock_condition === Transfer::CONDITION_BREAKAGE;
        $bucket = ($isBroken ? 'broken_' : '') . ($allocation->destination_classification === TransferRoutePolicy::CLASSIFICATION_TAX ? 'tax' : 'non_tax');

        return array_merge(TransferV3InventoryPoster::EMPTY_BUCKETS, [$bucket => $total]);
    }

    /**
     * @return array{0: TransferMovement, 1: Collection<int, TransferMovementAllocation>}
     */
    public static function lockDispatchEvidence(Transfer $transfer): array
    {
        $dispatch = TransferMovement::where('transfer_id', $transfer->id)
            ->where('type', TransferMovement::TYPE_FORWARD_DISPATCH)
            ->where('status', TransferMovement::STATUS_APPROVED)
            ->lockForUpdate()
            ->first();

        if (! $dispatch) {
            throw new RuntimeException('Bukti pengiriman transfer tidak ditemukan.');
        }

        $allocations = TransferMovementAllocation::where('transfer_movement_id', $dispatch->id)
            ->where('kind', TransferMovementAllocation::KIND_DISPATCH)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($allocations->isEmpty()) {
            throw new RuntimeException('Alokasi pengiriman transfer tidak ditemukan.');
        }

        $settled = TransferMovementAllocation::whereIn('dispatch_allocation_id', $allocations->pluck('id'))->exists();
        if ($settled) {
            throw new RuntimeException('Pengiriman ini sudah diselesaikan.');
        }

        return [$dispatch, $allocations];
    }

    /**
     * @return array{0: Collection, 1: Collection, 2: Collection}
     */
    public static function lockSerialCustody(TransferMovement $dispatch): array
    {
        $movementSerials = TransferMovementSerial::where('transfer_movement_id', $dispatch->id)->orderBy('id')->lockForUpdate()->get();
        $serialIds = $movementSerials->pluck('product_serial_number_id')->filter()->sort()->values()->all();
        $liveSerials = ProductSerialNumber::whereIn('id', $serialIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $claims = TransferActiveSerialClaim::whereIn('product_serial_number_id', $serialIds)->lockForUpdate()->get()->keyBy('product_serial_number_id');

        return [$movementSerials, $liveSerials, $claims];
    }

    /**
     * The live serial and its active claim must still agree exactly with
     * the immutable dispatch evidence; any drift blocks the whole action.
     */
    public static function assertCustody(TransferMovementSerial $movementSerial, TransferMovementAllocation $allocation, Collection $liveSerials, Collection $claims, TransferMovement $dispatch): ProductSerialNumber
    {
        $live = $liveSerials->get($movementSerial->product_serial_number_id);
        $claim = $live ? $claims->get($live->id) : null;

        if (! $live
            || (int) $live->product_id !== (int) $allocation->product_id
            || (int) $live->location_id !== (int) $allocation->source_location_id
            || $movementSerial->transit_custody_status !== TransferMovementSerial::CUSTODY_IN_TRANSIT
            || ! $claim
            || (int) $claim->transfer_movement_id !== (int) $dispatch->id
            || (int) $claim->transfer_movement_serial_id !== (int) $movementSerial->id
            || (int) $movementSerial->transfer_movement_allocation_id !== (int) $allocation->id
            || ProductSerialNumber::normalize((string) $live->serial_number) !== TransferMovementSerial::normalize((string) $movementSerial->serial_number)
            || (int) ($live->tax_id ?? 0) !== (int) ($movementSerial->tax_id ?? 0)
            || ! self::liveStateMatchesCondition($live, $allocation->stock_condition)) {
            throw new RuntimeException('Status nomor seri ' . $movementSerial->serial_number . ' tidak sesuai dengan bukti pengiriman. Proses dihentikan untuk diperiksa.');
        }

        return $live;
    }

    /**
     * A serial in transfer custody must still hold the dispatched condition
     * and must not have entered another flow (dispatch, return, sale, loss).
     * isSellable()/isAvailableBroken() cannot be used: the active claim
     * itself makes both false.
     */
    private static function liveStateMatchesCondition(ProductSerialNumber $live, string $condition): bool
    {
        if ($live->dispatch_detail_id !== null || $live->is_in_return_process) {
            return false;
        }

        $status = strtoupper((string) ($live->getAttributes()['status'] ?? ProductSerialNumber::STATUS_ACTIVE));

        if ($condition === Transfer::CONDITION_BREAKAGE) {
            return $status === ProductSerialNumber::STATUS_BROKEN
                || ((bool) $live->is_broken && $status === ProductSerialNumber::STATUS_ACTIVE);
        }

        return ! $live->is_broken && $status === ProductSerialNumber::STATUS_ACTIVE;
    }

    /**
     * Serialized allocations must still carry exactly their dispatched serials,
     * and those serials' frozen tax snapshots must add up to the applied buckets.
     */
    public static function assertAllocationSerials(TransferMovementAllocation $allocation, Collection $allocationSerials, bool $dispatchedSerialized): void
    {
        if (! $dispatchedSerialized) {
            if ($allocationSerials->isNotEmpty()) {
                throw new RuntimeException('Bukti nomor seri pengiriman tidak konsisten. Proses dihentikan untuk diperiksa.');
            }

            return;
        }

        $prefix = $allocation->stock_condition === Transfer::CONDITION_BREAKAGE ? 'broken_' : '';
        $expected = TransferV3InventoryPoster::EMPTY_BUCKETS;
        foreach ($allocationSerials as $serial) {
            $expected[$prefix . ($serial->tax_id ? 'tax' : 'non_tax')]++;
        }

        if ($allocationSerials->count() !== (int) $allocation->quantity || $expected !== $allocation->appliedBuckets()) {
            throw new RuntimeException('Bukti nomor seri pengiriman tidak konsisten. Proses dihentikan untuk diperiksa.');
        }
    }

    /**
     * Tracking mode frozen at dispatch: serialized when the dispatch line
     * recorded serials for this product, independent of later product edits.
     */
    public static function dispatchedSerialized(TransferMovement $dispatch, TransferMovementAllocation $allocation): bool
    {
        return (bool) ($dispatch->metadata['serialized_products'][(string) $allocation->product_id] ?? false);
    }

    public static function copyRoute(TransferMovementAllocation $allocation): array
    {
        return [
            'transfer_id'                => $allocation->transfer_id,
            'approval_allocation_id'     => $allocation->approval_allocation_id,
            'product_id'                 => $allocation->product_id,
            'source_location_id'         => $allocation->source_location_id,
            'destination_location_id'    => $allocation->destination_location_id,
            'source_setting_id'          => $allocation->source_setting_id,
            'destination_setting_id'     => $allocation->destination_setting_id,
            'cross_business'             => $allocation->cross_business,
            'stock_condition'            => $allocation->stock_condition,
            'quantity'                   => $allocation->quantity,
            'destination_classification' => $allocation->destination_classification,
            'tax_id'                     => $allocation->tax_id,
            'tax_name'                   => $allocation->tax_name,
            'tax_rate'                   => $allocation->tax_rate,
            'tax_resolver_provenance'    => $allocation->tax_resolver_provenance,
        ];
    }
}
