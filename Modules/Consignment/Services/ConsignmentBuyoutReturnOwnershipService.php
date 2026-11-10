<?php

namespace Modules\Consignment\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentBuyoutOwnershipEvent;
use Modules\Consignment\Entities\ConsignmentBuyoutReturnApportionment;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Sale\Entities\DispatchDetail;
use Modules\SalesReturn\Entities\SaleReturnDetail;

/**
 * Ownership-aware customer-return routing. A finalized SOLD_UNBILLED buyout line makes those sold units company-owned:
 * when such a unit comes back it must land in the line's standard future-return destination (after the normal held
 * custody + authorized inspection), must never reopen the supplier's receipt/sold-source capacity, and the acquired
 * quantity can be returned at most once in total.
 *
 * Serialized units are resolved from exact serial ownership evidence. Non-serialized units are apportioned
 * EXPLICITLY (declare()) unless the answer is unambiguous: nothing acquired, everything still unreturned acquired,
 * or the acquired quantity already fully returned. Anything else blocks receiving - ownership is never guessed.
 */
class ConsignmentBuyoutReturnOwnershipService
{
    /** @return array{acquired_quantity: float, acquired_serial_ids: int[], allocations: array<int, array<string, mixed>>, source_id: ?int} */
    public function resolveForReceive(SaleReturnDetail $detail, DispatchDetail $dispatchDetail, Collection $serialIds, float $quantity, ?int $actorId = null): array
    {
        $none = ['acquired_quantity' => 0.0, 'acquired_serial_ids' => [], 'allocations' => [], 'source_id' => null];
        $source = ConsignmentSoldSource::where('dispatch_detail_id', $dispatchDetail->id)->first();
        if (! $source) {
            return $none;
        }
        $lines = $this->finalizedSoldLines($source);
        if ($lines->isEmpty()) {
            return $none;                      // legacy / never acquired: behaviour unchanged
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Return apportionment must run inside the receiving transaction.');
        }

        // Serialize cumulative-limit checks per sold source.
        ConsignmentSoldSource::whereKey($source->id)->lockForUpdate()->firstOrFail();

        $row = ConsignmentBuyoutReturnApportionment::where('sale_return_detail_id', $detail->id)->lockForUpdate()->first();
        if ($row && $row->status === ConsignmentBuyoutReturnApportionment::STATUS_APPLIED) {
            return ['acquired_quantity' => (float) $row->acquired_quantity, 'acquired_serial_ids' => $row->acquired_serial_ids ?? [],
                'allocations' => $row->allocations ?? [], 'source_id' => $source->id];
        }

        // ---- serialized units: exact ownership lookup
        $netAcquiredSerials = $this->netAcquiredSerialLines($source, $lines);   // serial id => line id
        $acquiredSerials = $serialIds->filter(fn ($id) => isset($netAcquiredSerials[(int) $id]))->map(fn ($id) => (int) $id)->values();
        $allocations = [];
        foreach ($acquiredSerials->groupBy(fn ($id) => $netAcquiredSerials[$id]) as $lineId => $ids) {
            $line = $lines->firstWhere('id', $lineId);
            $allocations[] = ['line_id' => (int) $lineId, 'quantity' => (float) $ids->count(), 'destination_location_id' => (int) $line->destination_location_id,
                'serial_ids' => $ids->values()->all()];
        }

        // ---- non-serialized units
        $nonSerial = round($quantity - $serialIds->count(), 3);
        $aNs = 0.0;
        $origin = $acquiredSerials->isNotEmpty() ? 'SERIAL' : 'NONE';
        $nsLines = $lines->filter(fn ($l) => $l->serials->isEmpty())->values();
        if ($nonSerial > 0 && $nsLines->isNotEmpty()) {
            $totalAcquired = $this->milli($nsLines->sum(fn ($l) => (float) $l->base_quantity));
            [$usedTotal, $usedByLine] = $this->usedNonSerial($source, $detail->id);
            $remaining = $totalAcquired - $usedTotal;
            $R = $this->milli($nonSerial);

            if ($row && $row->status === ConsignmentBuyoutReturnApportionment::STATUS_DECLARED) {
                $declared = $this->milli($row->acquired_quantity);
                if ($declared < 0 || $declared > $R || $declared > $remaining) {
                    throw new \DomainException('Declared acquired quantity exceeds the returned or the remaining acquired quantity.');
                }
                $this->assertUnacquiredRoom($source, $nsLines, $detail->id, $R - $declared);
                $a = $declared;
                $origin = 'EXPLICIT';
            } else {
                $raw = $this->milli((new ConsignmentReturnEligibilityService())->getRawReceivedReturnQuantity($dispatchDetail->id, $detail->id));
                $unreturned = $this->milli($source->original_base_quantity) - $raw;
                if ($remaining <= 0) {
                    $this->assertUnacquiredRoom($source, $nsLines, $detail->id, $R);
                    $a = 0;
                    $origin = $origin === 'SERIAL' ? 'SERIAL' : 'NONE';
                } elseif ($remaining >= $unreturned) {
                    if ($R > $remaining) {
                        throw new \DomainException('Returned quantity exceeds the acquired quantity still returnable for this sale.');
                    }
                    $a = $R;
                    $origin = 'ALL';
                } else {
                    throw new \DomainException('Ownership of the returned quantity is ambiguous (part of the sale was acquired by a buyout). '
                        . 'Declare how many returned units were acquired before receiving this return.');
                }
            }

            // Consume acquired capacity line by line in id order so each unit keeps its own future-return destination.
            $left = $a;
            foreach ($nsLines as $line) {
                if ($left <= 0) {
                    break;
                }
                $free = $this->milli($line->base_quantity) - ($usedByLine[$line->id] ?? 0);
                $take = min($free, $left);
                if ($take > 0) {
                    $allocations[] = ['line_id' => (int) $line->id, 'quantity' => $take / 1000, 'destination_location_id' => (int) $line->destination_location_id, 'serial_ids' => []];
                    $left -= $take;
                }
            }
            if ($left > 0) {
                throw new \DomainException('Acquired quantity cannot be placed on the finalized buyout lines.');
            }
            $aNs = $a / 1000;
        } elseif ($row && $row->status === ConsignmentBuyoutReturnApportionment::STATUS_DECLARED && (float) $row->acquired_quantity > 0) {
            throw new \DomainException('A declared acquired quantity needs non-serialized returned units on an acquired line.');
        }

        $acquiredTotal = round($aNs + $acquiredSerials->count(), 3);
        $payload = [
            'setting_id' => $source->setting_id, 'sale_return_detail_id' => $detail->id, 'sale_return_id' => $detail->sale_return_id,
            'dispatch_detail_id' => $dispatchDetail->id, 'consignment_sold_source_id' => $source->id,
            'status' => ConsignmentBuyoutReturnApportionment::STATUS_APPLIED, 'origin' => $origin,
            'returned_quantity' => round($quantity, 3), 'acquired_quantity' => $acquiredTotal,
            'unacquired_quantity' => round($quantity - $acquiredTotal, 3),
            'acquired_serial_ids' => $acquiredSerials->all(), 'allocations' => $allocations, 'actor_id' => $actorId,
        ];
        $row ? $row->update($payload) : ConsignmentBuyoutReturnApportionment::create($payload);

        return ['acquired_quantity' => $acquiredTotal, 'acquired_serial_ids' => $acquiredSerials->all(), 'allocations' => $allocations, 'source_id' => $source->id];
    }

    /** Operator mapping before receiving: how many of the returned NON-serialized units were acquired. */
    public function declare(SaleReturnDetail $detail, float $acquiredQuantity, ?int $actorId = null): ConsignmentBuyoutReturnApportionment
    {
        return DB::transaction(function () use ($detail, $acquiredQuantity, $actorId) {
            $dd = DispatchDetail::findOrFail($detail->dispatch_detail_id);
            $source = ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->first();
            if (! $source || $this->finalizedSoldLines($source)->isEmpty()) {
                throw new \DomainException('This sale has no finalized buyout; there is nothing to apportion.');
            }
            ConsignmentSoldSource::whereKey($source->id)->lockForUpdate()->firstOrFail();

            $existing = ConsignmentBuyoutReturnApportionment::where('sale_return_detail_id', $detail->id)->lockForUpdate()->first();
            if ($existing && $existing->status === ConsignmentBuyoutReturnApportionment::STATUS_APPLIED) {
                throw new \DomainException('This return has already been received and apportioned.');
            }
            $serialCount = count($detail->serial_number_ids ?? []);
            $nonSerial = round((float) $detail->quantity - $serialCount, 3);
            if ($acquiredQuantity < 0 || $this->milli($acquiredQuantity) > $this->milli($nonSerial)) {
                throw new \DomainException('Acquired quantity must be between 0 and the returned non-serialized quantity.');
            }
            [$used] = $this->usedNonSerial($source, $detail->id);
            $nsLines = $this->finalizedSoldLines($source)->filter(fn ($l) => $l->serials->isEmpty());
            $remaining = $this->milli($nsLines->sum(fn ($l) => (float) $l->base_quantity)) - $used;
            if ($this->milli($acquiredQuantity) > $remaining) {
                throw new \DomainException('Acquired quantity exceeds what the finalized buyout can still take back.');
            }
            $this->assertUnacquiredRoom($source, $nsLines, $detail->id, $this->milli($nonSerial) - $this->milli($acquiredQuantity));

            $payload = [
                'setting_id' => $source->setting_id, 'sale_return_detail_id' => $detail->id, 'sale_return_id' => $detail->sale_return_id,
                'dispatch_detail_id' => $dd->id, 'consignment_sold_source_id' => $source->id,
                'status' => ConsignmentBuyoutReturnApportionment::STATUS_DECLARED, 'origin' => 'EXPLICIT',
                'returned_quantity' => (float) $detail->quantity, 'acquired_quantity' => $acquiredQuantity,
                'unacquired_quantity' => round((float) $detail->quantity - $acquiredQuantity, 3), 'actor_id' => $actorId,
            ];

            return $existing ? tap($existing)->update($payload) : ConsignmentBuyoutReturnApportionment::create($payload);
        });
    }

    /** Finalized, non-reversed SOLD_UNBILLED lines of a source (with their serials), ordered by id. */
    public function finalizedSoldLines(ConsignmentSoldSource $source): Collection
    {
        return ConsignmentBuyoutLine::with('serials')
            ->where('consignment_sold_source_id', $source->id)
            ->where('line_type', ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED)
            ->whereHas('buyout', fn ($q) => $q->where('status', ConsignmentBuyout::STATUS_FINALIZED))
            ->orderBy('id')->get();
    }

    /** serial id => line id, for serials whose acquisition has not been reversed. */
    protected function netAcquiredSerialLines(ConsignmentSoldSource $source, Collection $lines): array
    {
        $lineIds = $lines->pluck('id')->all();
        $events = ConsignmentBuyoutOwnershipEvent::whereIn('consignment_buyout_line_id', $lineIds)->whereNotNull('product_serial_number_id')->get();
        $net = [];
        foreach ($events->where('kind', 'ACQUISITION') as $e) {
            $net[(int) $e->product_serial_number_id] = (int) $e->consignment_buyout_line_id;
        }
        foreach ($events->where('kind', 'REVERSAL') as $e) {
            unset($net[(int) $e->product_serial_number_id]);
        }

        return $net;
    }

    /** @return array{0: int, 1: array<int,int>} milli used overall and per line by OTHER details' non-serialized apportionments */
    protected function usedNonSerial(ConsignmentSoldSource $source, int $exceptDetailId): array
    {
        $total = 0;
        $byLine = [];
        $rows = ConsignmentBuyoutReturnApportionment::where('consignment_sold_source_id', $source->id)
            ->where('status', ConsignmentBuyoutReturnApportionment::STATUS_APPLIED)->where('sale_return_detail_id', '!=', $exceptDetailId)->get();
        foreach ($rows as $r) {
            foreach ($r->allocations ?? [] as $a) {
                if (! empty($a['serial_ids'])) {
                    continue;
                }
                $m = $this->milli($a['quantity']);
                $total += $m;
                $byLine[(int) $a['line_id']] = ($byLine[(int) $a['line_id']] ?? 0) + $m;
            }
        }

        return [$total, $byLine];
    }

    /**
     * Lower bound of the ownership balance: the units declared NOT acquired must still fit in the sale's unacquired,
     * unreturned room (sold - acquired - other unacquired returns). Otherwise acquired units would be misrouted to the supplier.
     * Applies to non-serialized sources; serialized ownership is exact and never declared.
     */
    protected function assertUnacquiredRoom(ConsignmentSoldSource $source, Collection $nsLines, int $exceptDetailId, int $unacquiredMilli): void
    {
        if ($unacquiredMilli <= 0 || \Modules\Consignment\Entities\ConsignmentSoldSourceSerial::where('consignment_sold_source_id', $source->id)->exists()) {
            return;
        }
        [$usedAcquired] = $this->usedNonSerial($source, $exceptDetailId);
        $totalAcquired = $this->milli($nsLines->sum(fn ($l) => (float) $l->base_quantity));
        $rawOther = $this->milli((new ConsignmentReturnEligibilityService())->getRawReceivedReturnQuantity($source->dispatch_detail_id, $exceptDetailId));
        $room = $this->milli($source->original_base_quantity) - $totalAcquired - ($rawOther - $usedAcquired);
        if ($unacquiredMilli > $room) {
            throw new \DomainException('Declared unacquired quantity exceeds the unacquired units left in this sale: the remaining units are company-owned '
                . '(room for unacquired returns: ' . max(0, $room) / 1000 . ').');
        }
    }

    protected function milli($v): int
    {
        return (int) round(((float) $v) * 1000);
    }
}
