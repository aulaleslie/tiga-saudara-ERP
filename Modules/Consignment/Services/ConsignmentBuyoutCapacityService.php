<?php

namespace Modules\Consignment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentBuyoutReservation;
use Modules\Consignment\Entities\ConsignmentInventoryCoordination;
use Modules\Consignment\Entities\ConsignmentReceiptAllocation;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldReceiptAttribution;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Entities\ConsignmentSoldSourceSerial;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;

/**
 * Single capacity authority shared by sold-only confirmations and buyouts.
 *
 * Quantities are compared as integer thousandths so decimal(15,3) values never drift.
 * An inconsistent ledger is reported as a conservation error, never clamped to zero.
 *
 * Equations (per receipt r):
 *   financial_available = received - legacy_approved - legacy_waiting - buyout_finalized - buyout_reserved
 *   physical_residual   = received - net_attributed_sold - finalized_unsold
 *   unsold_available    = physical_residual - active_unsold_reservations   (then bucket-constrained)
 * Per sold source:
 *   sold_available = original - effective_returned(unacquired) - legacy(approved+waiting) - buyout(reserved+finalized)
 */
class ConsignmentBuyoutCapacityService
{
    public function __construct(
        protected ?ConsignmentReturnEligibilityService $returns = null
    ) {
        $this->returns ??= new ConsignmentReturnEligibilityService();
    }

    public static function milli($value): int
    {
        return (int) round(((float) $value) * 1000);
    }

    public static function fromMilli(int $m): float
    {
        return round($m / 1000, 3);
    }

    /* ------------------------------------------------------------------ 2.1 pool completeness */

    /**
     * Blockers that make a product/location pool's sold ledger unreliable.
     *
     * @return array<int, array{type:string, reference:string, reason:string}>
     */
    public function poolBlockers(int $settingId, int $productId, int $locationId): array
    {
        $blockers = [];

        $uncaptured = DispatchDetail::whereHas('dispatch', fn ($q) => $q->where('status', Dispatch::STATUS_APPROVED))
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->whereNotIn('id', ConsignmentSoldSource::query()->select('dispatch_detail_id'))
            ->pluck('id');
        foreach ($uncaptured as $id) {
            $blockers[] = ['type' => 'DISCOVERY_INCOMPLETE', 'reference' => "dispatch_detail:{$id}",
                'reason' => 'Approved dispatch has no captured sold source; run discovery first.'];
        }

        $sources = ConsignmentSoldSource::where('setting_id', $settingId)
            ->where('product_id', $productId)->where('location_id', $locationId)->get();
        foreach ($sources as $source) {
            foreach ($this->sourceBlockers($source) as $b) {
                $blockers[] = $b;
            }
        }

        return $blockers;
    }

    protected function sourceBlockers(ConsignmentSoldSource $source): array
    {
        $ref = "sold_source:{$source->id}";
        $out = [];
        if ($source->has_reconstruction_blocker) {
            $out[] = ['type' => 'RECONSTRUCTION', 'reference' => $ref, 'reason' => (string) $source->blocker_reason];
        }
        if (ConsignmentSoldSourceDiscoveryService::resolveSnapshotVersion($source->source_snapshot) === null) {
            $out[] = ['type' => 'UNKNOWN_SNAPSHOT_VERSION', 'reference' => $ref, 'reason' => 'Unsupported sold snapshot version.'];
        }
        $att = $this->soldAttribution($source);
        foreach ($att['blockers'] as $reason) {
            $out[] = ['type' => 'ATTRIBUTION', 'reference' => $ref, 'reason' => $reason];
        }

        return $out;
    }

    /* ------------------------------------------------------------------ attribution */

    /**
     * Net physically-out sold quantity of one source per receipt, plus completeness blockers.
     *
     * @return array{per_receipt: array<int,float>, attributed: float, complete: bool, blockers: string[], serialized: bool}
     */
    public function soldAttribution(ConsignmentSoldSource $source): array
    {
        $blockers = [];
        $perReceipt = [];
        $serialRows = ConsignmentSoldSourceSerial::where('consignment_sold_source_id', $source->id)->with('serialNumber')->get();

        if ($serialRows->isNotEmpty()) {
            $returned = $this->returns->getEffectiveReturnedSerialIds($source->dispatch_detail_id);
            foreach ($serialRows as $row) {
                $crd = optional($row->serialNumber)->consignment_receiving_detail_id;
                if (! $crd) {
                    $blockers[] = "Serial #{$row->product_serial_number_id} has no receipt lineage.";
                    continue;
                }
                if (in_array((int) $row->product_serial_number_id, $returned, true)) {
                    continue;
                }
                $perReceipt[$crd] = ($perReceipt[$crd] ?? 0) + 1;
            }

            return ['per_receipt' => array_map('floatval', $perReceipt), 'attributed' => (float) array_sum($perReceipt),
                'complete' => empty($blockers), 'blockers' => $blockers, 'serialized' => true];
        }

        $rows = ConsignmentSoldReceiptAttribution::where('consignment_sold_source_id', $source->id)->get();
        $original = self::milli($source->original_base_quantity);
        $attributedGross = 0;
        $mappedReturn = 0;
        $net = [];
        foreach ($rows as $r) {
            $attributedGross += self::milli($r->attributed_base_quantity);
            $mappedReturn += self::milli($r->returned_base_quantity);
            $net[$r->consignment_receiving_detail_id] = self::milli($r->attributed_base_quantity) - self::milli($r->returned_base_quantity);
        }

        if ($attributedGross !== $original) {
            $blockers[] = 'Sold quantity is not fully attributed to supplier receipts ('
                . self::fromMilli($attributedGross) . ' of ' . self::fromMilli($original) . ').';
        }

        $effectiveReturn = self::milli($this->returns->getEffectiveReturnQuantity($source->dispatch_detail_id));
        if ($effectiveReturn > 0) {
            if ($rows->count() === 1) {
                // Unambiguous: the single attributed receipt takes the whole return.
                $only = $rows->first();
                $net[$only->consignment_receiving_detail_id] = self::milli($only->attributed_base_quantity) - $effectiveReturn;
                $mappedReturn = $effectiveReturn;
            } elseif ($mappedReturn !== $effectiveReturn) {
                $blockers[] = 'Effective return of ' . self::fromMilli($effectiveReturn) . ' is not explicitly apportioned across receipts.';
            }
        }

        foreach ($net as $crd => $m) {
            if ($m < 0) {
                $blockers[] = "Return exceeds attribution for receipt #{$crd}.";
            }
        }

        return [
            'per_receipt' => array_map(fn ($m) => self::fromMilli(max(0, $m)), $net),
            'attributed' => self::fromMilli(array_sum(array_map(fn ($m) => max(0, $m), $net))),
            'complete' => empty($blockers),
            'blockers' => $blockers,
            'serialized' => false,
        ];
    }

    /**
     * Explicitly attribute a non-serialized sold source to receipts (and optionally map effective returns).
     * Creates no supplier invoice or financial allocation.
     *
     * @param array<int,float|string> $quantities receiving_detail_id => attributed quantity (must sum to original)
     * @param array<int,float|string> $returns    receiving_detail_id => returned quantity (optional)
     */
    public function setAttribution(int $soldSourceId, array $quantities, ?int $actorId, string $reason, array $returns = []): array
    {
        return DB::transaction(function () use ($soldSourceId, $quantities, $actorId, $reason, $returns) {
            $probe = ConsignmentSoldSource::findOrFail($soldSourceId);
            ConsignmentInventoryCoordination::lockFor($probe->setting_id, $probe->product_id);
            $source = ConsignmentSoldSource::whereKey($soldSourceId)->lockForUpdate()->firstOrFail();

            if (ConsignmentSoldSourceSerial::where('consignment_sold_source_id', $source->id)->exists()) {
                throw new \DomainException('Serialized sales derive attribution from exact serial lineage and cannot be edited.');
            }
            if (trim($reason) === '') {
                throw new \DomainException('An attribution reason is required.');
            }

            $details = ConsignmentReceivingDetail::with('receiving.receival')
                ->whereIn('id', array_keys($quantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sum = 0;
            foreach ($quantities as $crd => $qty) {
                $m = self::milli($qty);
                $d = $details->get($crd);
                if ($m <= 0 || ! $d) {
                    throw new \DomainException("Invalid attribution for receipt #{$crd}.");
                }
                $rcv = $d->receiving;
                if (! $rcv || (int) $rcv->setting_id !== (int) $source->setting_id
                    || (int) $rcv->location_id !== (int) $source->location_id
                    || (int) $d->product_id !== (int) $source->product_id
                    || $rcv->status !== ConsignmentReceiving::STATUS_APPROVED) {
                    throw new \DomainException("Receipt #{$crd} is not an approved receipt of this sold source's product, location and business.");
                }
                $sum += $m;
            }
            if ($sum !== self::milli($source->original_base_quantity)) {
                throw new \DomainException('Attributed quantities must equal the sold quantity ('
                    . self::fromMilli(self::milli($source->original_base_quantity)) . ').');
            }

            $returnSum = 0;
            foreach ($returns as $crd => $qty) {
                if (! isset($quantities[$crd]) || self::milli($qty) < 0 || self::milli($qty) > self::milli($quantities[$crd])) {
                    throw new \DomainException("Invalid returned quantity for receipt #{$crd}.");
                }
                $returnSum += self::milli($qty);
            }
            if ($returnSum > self::milli($this->returns->getEffectiveReturnQuantity($source->dispatch_detail_id))) {
                throw new \DomainException('Mapped returns exceed the physically effective return quantity.');
            }

            // Billed (legacy) evidence is respected: attribution cannot move away from a receipt already allocated.
            foreach ($this->legacyEvidence($source) as $crd => $evidence) {
                if (self::milli($quantities[$crd] ?? 0) < self::milli($evidence)) {
                    throw new \DomainException("Receipt #{$crd} already has {$evidence} billed or reserved for this sale; attribution cannot be lower.");
                }
            }

            $existing = ConsignmentSoldReceiptAttribution::where('consignment_sold_source_id', $source->id)
                ->lockForUpdate()->get()->keyBy('consignment_receiving_detail_id');

            // Immutable once a reservation or finalization depends on it.
            foreach ($existing as $crd => $row) {
                $changed = ! isset($quantities[$crd])
                    || self::milli($quantities[$crd]) !== self::milli($row->attributed_base_quantity)
                    || self::milli($returns[$crd] ?? 0) !== self::milli($row->returned_base_quantity);
                if ($changed && $this->attributionIsDependedOn($source, (int) $crd)) {
                    throw new \DomainException("Attribution for receipt #{$crd} is relied on by a reserved or finalized buyout and cannot change.");
                }
            }
            foreach ($quantities as $crd => $qty) {
                if (! $existing->has($crd) && $this->attributionIsDependedOn($source, (int) $crd)) {
                    throw new \DomainException("Receipt #{$crd} pool is relied on by a reserved or finalized buyout and cannot take new attribution.");
                }
            }

            // Receipt cannot be over-attributed beyond what it received.
            foreach ($quantities as $crd => $qty) {
                $others = $this->attributedGross((int) $crd, $source->id);
                $received = self::milli($details[$crd]->received_base_quantity ?? $details[$crd]->quantity_received);
                if ($others + self::milli($qty) > $received) {
                    throw new \DomainException("Attribution would exceed the quantity received on receipt #{$crd}.");
                }
            }

            foreach ($existing as $crd => $row) {
                if (! isset($quantities[$crd])) {
                    $row->delete();
                }
            }
            foreach ($quantities as $crd => $qty) {
                $row = $existing->get($crd) ?? new ConsignmentSoldReceiptAttribution([
                    'setting_id' => $source->setting_id,
                    'consignment_sold_source_id' => $source->id,
                    'consignment_receiving_detail_id' => $crd,
                    'origin' => 'EXPLICIT',
                ]);
                $row->attributed_base_quantity = self::fromMilli(self::milli($qty));
                $row->returned_base_quantity = self::fromMilli(self::milli($returns[$crd] ?? 0));
                $row->revision = $row->exists ? $row->revision + 1 : 1;
                $row->actor_id = $actorId;
                $row->reason = $reason;
                $row->save();
            }

            return $this->soldAttribution($source->fresh());
        });
    }

    protected function attributedGross(int $detailId, ?int $exceptSourceId = null): int
    {
        $q = ConsignmentSoldReceiptAttribution::where('consignment_receiving_detail_id', $detailId);
        if ($exceptSourceId) {
            $q->where('consignment_sold_source_id', '!=', $exceptSourceId);
        }

        return self::milli($q->sum('attributed_base_quantity'));
    }

    protected function attributionIsDependedOn(ConsignmentSoldSource $source, int $detailId): bool
    {
        $reservation = ConsignmentBuyoutReservation::where('status', ConsignmentBuyoutReservation::STATUS_ACTIVE)
            ->where(fn ($q) => $q->where('consignment_receiving_detail_id', $detailId)->orWhere('consignment_sold_source_id', $source->id))
            ->exists();
        if ($reservation) {
            return true;
        }

        return ConsignmentBuyoutLine::whereHas('buyout', fn ($q) => $q->where('status', ConsignmentBuyout::STATUS_FINALIZED))
            ->where(fn ($q) => $q->where('consignment_receiving_detail_id', $detailId)->orWhere('consignment_sold_source_id', $source->id))
            ->exists();
    }

    /**
     * Legacy allocation evidence for a source: receipt => billed quantity (not a complete attribution).
     */
    public function legacyEvidence(ConsignmentSoldSource $source): array
    {
        $rows = ConsignmentReceiptAllocation::whereHas('line', fn ($q) => $q->where('consignment_sold_source_id', $source->id)
            ->whereHas('confirmation', fn ($c) => $c->whereIn('status', [
                ConsignmentBillingConfirmation::STATUS_APPROVED, ConsignmentBillingConfirmation::STATUS_WAITING_APPROVAL])))
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->consignment_receiving_detail_id] = ($out[$r->consignment_receiving_detail_id] ?? 0) + self::milli($r->allocated_base_quantity);
        }

        return array_map(fn ($m) => self::fromMilli($m), $out);
    }

    /* ------------------------------------------------------------------ 2.3 shared capacity */

    /**
     * Receipt financial and physical capacity. Throws on conservation mismatch.
     */
    public function receiptCapacity(int $detailId, ?int $excludeBuyoutId = null, ?int $excludeConfirmationId = null): array
    {
        $detail = ConsignmentReceivingDetail::findOrFail($detailId);
        $received = self::milli($detail->received_base_quantity ?? $detail->quantity_received);

        $legacy = fn (string $status) => self::milli(ConsignmentReceiptAllocation::where('consignment_receiving_detail_id', $detailId)
            ->whereHas('line.confirmation', function ($q) use ($status, $excludeConfirmationId) {
                $q->where('status', $status);
                if ($excludeConfirmationId) {
                    $q->where('id', '!=', $excludeConfirmationId);
                }
            })->sum('allocated_base_quantity'));
        $legacyApproved = $legacy(ConsignmentBillingConfirmation::STATUS_APPROVED);
        $legacyWaiting = $legacy(ConsignmentBillingConfirmation::STATUS_WAITING_APPROVAL);

        // Finalized (non-reversed) buyouts are consumed; approved ones hold reservations. Never both.
        $finalizedQ = ConsignmentBuyoutLine::where('consignment_receiving_detail_id', $detailId)
            ->whereHas('buyout', fn ($q) => $q->where('status', ConsignmentBuyout::STATUS_FINALIZED));
        $finalizedAll = self::milli((clone $finalizedQ)->sum('base_quantity'));
        $finalizedUnsold = self::milli((clone $finalizedQ)->where('line_type', ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT)->sum('base_quantity'));

        $reservedQ = ConsignmentBuyoutReservation::where('resource_type', 'RECEIPT')
            ->where('consignment_receiving_detail_id', $detailId)
            ->where('status', ConsignmentBuyoutReservation::STATUS_ACTIVE);
        \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::latest($reservedQ);
        if ($excludeBuyoutId) {
            $reservedQ->where('consignment_buyout_id', '!=', $excludeBuyoutId);
        }
        $reservedFinancial = self::milli($reservedQ->sum('base_quantity'));

        $reservedUnsoldQ = ConsignmentBuyoutReservation::where('resource_type', 'RECEIPT')
            ->where('consignment_receiving_detail_id', $detailId)
            ->where('status', ConsignmentBuyoutReservation::STATUS_ACTIVE)
            ->whereHas('line', fn ($q) => $q->where('line_type', ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT));
        \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::latest($reservedUnsoldQ);
        if ($excludeBuyoutId) {
            $reservedUnsoldQ->where('consignment_buyout_id', '!=', $excludeBuyoutId);
        }
        $reservedUnsold = self::milli($reservedUnsoldQ->sum('base_quantity'));

        $attributedNet = 0;
        $blockers = [];
        $sourceIds = ConsignmentSoldReceiptAttribution::where('consignment_receiving_detail_id', $detailId)->pluck('consignment_sold_source_id')
            ->merge(ConsignmentSoldSourceSerial::query()
                ->whereHas('serialNumber', fn ($q) => $q->where('consignment_receiving_detail_id', $detailId))
                ->pluck('consignment_sold_source_id'))->unique();
        foreach (ConsignmentSoldSource::whereIn('id', $sourceIds)->get() as $src) {
            $a = $this->soldAttribution($src);
            $attributedNet += self::milli($a['per_receipt'][$detailId] ?? 0);
            foreach ($a['blockers'] as $b) {
                $blockers[] = "sold_source:{$src->id}: {$b}";
            }
        }

        $financialAvailable = $received - $legacyApproved - $legacyWaiting - $finalizedAll - $reservedFinancial;
        $physicalResidual = $received - $attributedNet - $finalizedUnsold;
        $unsoldAvailable = $physicalResidual - $reservedUnsold;

        $mismatch = [];
        if ($financialAvailable < 0) {
            $mismatch[] = 'Financial consumption exceeds the quantity received.';
        }
        if ($physicalResidual < 0) {
            $mismatch[] = 'Attributed sales plus acquired unsold stock exceed the quantity received.';
        }

        return [
            'received' => self::fromMilli($received),
            'legacy_approved' => self::fromMilli($legacyApproved),
            'legacy_waiting' => self::fromMilli($legacyWaiting),
            'buyout_finalized' => self::fromMilli($finalizedAll),
            'buyout_reserved' => self::fromMilli($reservedFinancial),
            'financial_available' => self::fromMilli($financialAvailable),
            'attributed_sold_net' => self::fromMilli($attributedNet),
            'acquired_unsold' => self::fromMilli($finalizedUnsold),
            'physical_residual' => self::fromMilli($physicalResidual),
            'reserved_unsold' => self::fromMilli($reservedUnsold),
            'unsold_available' => self::fromMilli($unsoldAvailable),
            'conservation_errors' => $mismatch,
            'blockers' => $blockers,
        ];
    }

    /**
     * Sold-source remaining eligible quantity across legacy and buyout consumers.
     */
    public function soldSourceCapacity(ConsignmentSoldSource $source, ?int $excludeBuyoutId = null, ?int $excludeConfirmationId = null): array
    {
        $original = self::milli($source->original_base_quantity);
        $returned = self::milli($this->returns->getEffectiveReturnQuantity($source->dispatch_detail_id));

        $legacy = fn (string $status) => self::milli(ConsignmentBillingConfirmationLine::where('consignment_sold_source_id', $source->id)
            ->whereHas('confirmation', function ($q) use ($status, $excludeConfirmationId) {
                $q->where('status', $status);
                if ($excludeConfirmationId) {
                    $q->where('id', '!=', $excludeConfirmationId);
                }
            })->sum('allocated_base_quantity'));
        $legacyApproved = $legacy(ConsignmentBillingConfirmation::STATUS_APPROVED);
        $legacyWaiting = $legacy(ConsignmentBillingConfirmation::STATUS_WAITING_APPROVAL);

        $finalized = self::milli(ConsignmentBuyoutLine::where('consignment_sold_source_id', $source->id)
            ->where('line_type', ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED)
            ->whereHas('buyout', fn ($q) => $q->where('status', ConsignmentBuyout::STATUS_FINALIZED))->sum('base_quantity'));

        $reservedQ = ConsignmentBuyoutReservation::where('resource_type', 'SOLD_SOURCE')
            ->where('consignment_sold_source_id', $source->id)
            ->where('status', ConsignmentBuyoutReservation::STATUS_ACTIVE);
        \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::latest($reservedQ);
        if ($excludeBuyoutId) {
            $reservedQ->where('consignment_buyout_id', '!=', $excludeBuyoutId);
        }
        $reserved = self::milli($reservedQ->sum('base_quantity'));

        $available = $original - $returned - $legacyApproved - $legacyWaiting - $finalized - $reserved;
        $errors = [];
        if ($available < 0) {
            $errors[] = 'Sold source is over-consumed by approved, waiting and buyout documents.';
        }

        return [
            'original' => self::fromMilli($original),
            'effective_returned' => self::fromMilli($returned),
            'legacy_approved' => self::fromMilli($legacyApproved),
            'legacy_waiting' => self::fromMilli($legacyWaiting),
            'buyout_finalized' => self::fromMilli($finalized),
            'buyout_reserved' => self::fromMilli($reserved),
            'available' => self::fromMilli($available),
            'conservation_errors' => $errors,
        ];
    }

    /**
     * Quantity of one sold source still attributable to ONE supplier receipt: the receipt's attributed share minus what
     * finalized buyouts and other active reservations already took from exactly that source/receipt pair.
     */
    public function soldReceiptResidual(ConsignmentSoldSource $source, int $detailId, ?int $excludeBuyoutId = null): float
    {
        $att = $this->soldAttribution($source);
        $attributed = self::milli($att['per_receipt'][$detailId] ?? 0);

        $finalized = self::milli(ConsignmentBuyoutLine::where('consignment_sold_source_id', $source->id)
            ->where('line_type', ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED)->where('consignment_receiving_detail_id', $detailId)
            ->whereHas('buyout', fn ($q) => $q->where('status', ConsignmentBuyout::STATUS_FINALIZED))->sum('base_quantity'));

        $reservedQ = ConsignmentBuyoutReservation::where('resource_type', 'SOLD_SOURCE')
            ->where('consignment_sold_source_id', $source->id)
            ->where('status', ConsignmentBuyoutReservation::STATUS_ACTIVE)
            ->whereHas('line', fn ($q) => $q->where('consignment_receiving_detail_id', $detailId));
        ConsignmentBuyoutReservationGuard::latest($reservedQ);
        if ($excludeBuyoutId) {
            $reservedQ->where('consignment_buyout_id', '!=', $excludeBuyoutId);
        }

        // Legacy pending/approved allocations consume the exact source/receipt pair too.
        $legacy = 0;
        foreach ([ConsignmentBillingConfirmation::STATUS_APPROVED, ConsignmentBillingConfirmation::STATUS_WAITING_APPROVAL] as $status) {
            $legacy += self::milli(\Modules\Consignment\Entities\ConsignmentReceiptAllocation::where('consignment_receiving_detail_id', $detailId)
                ->whereHas('line', fn ($l) => $l->where('consignment_sold_source_id', $source->id)
                    ->whereHas('confirmation', fn ($c) => $c->where('status', $status)))->sum('allocated_base_quantity'));
        }

        return self::fromMilli(max(0, $attributed - $finalized - $legacy - self::milli($reservedQ->sum('base_quantity'))));
    }

    /**
     * Exact source/receipt/product/location relationship for sold slices: the source must belong to the receipt's
     * product and location, the receipt must hold an attribution of that source, and the summed request per pair may
     * not exceed that pair's remaining attributed quantity.
     *
     * @param array<int, array{sold_source_id:int, receiving_detail_id:int, quantity:float|string}> $slices
     * @return string[]
     */
    public function validateSoldPairs(array $slices, ?int $excludeBuyoutId = null): array
    {
        $errors = [];
        $pairs = [];
        foreach ($slices as $s) {
            $sid = (int) ($s['sold_source_id'] ?? 0);
            $crd = (int) $s['receiving_detail_id'];
            if ($sid) {
                $pairs[$sid . ':' . $crd] = ($pairs[$sid . ':' . $crd] ?? 0) + self::milli($s['quantity']);
            }
        }
        foreach ($pairs as $key => $m) {
            [$sid, $crd] = array_map('intval', explode(':', $key));
            $src = ConsignmentSoldSource::find($sid);
            $detail = ConsignmentReceivingDetail::with('receiving')->find($crd);
            if (! $src || ! $detail) {
                continue;               // reported elsewhere
            }
            if ((int) $src->product_id !== (int) $detail->product_id || (int) $src->location_id !== (int) $detail->receiving->location_id) {
                $errors[] = "Sold source #{$sid} does not belong to the product/location of receipt #{$crd}.";
                continue;
            }
            $residual = $this->soldReceiptResidual($src, $crd, $excludeBuyoutId);
            if ($m > self::milli($residual)) {
                $errors[] = "Sold source #{$sid} has only {$residual} attributed to receipt #{$crd}; requested " . self::fromMilli($m) . '.';
            }
        }

        return $errors;
    }

    /**
     * Validate a set of requested slices at once (duplicate slices are aggregated) against shared capacity.
     *
     * @param array<int, array{type:string, receiving_detail_id:int, sold_source_id:?int, quantity:float|string}> $slices
     * @return string[] errors (empty when permitted)
     */
    public function validateSlices(array $slices, ?int $excludeBuyoutId = null): array
    {
        $errors = [];
        $receiptFinancial = [];
        $receiptUnsold = [];
        $sold = [];
        foreach ($slices as $s) {
            $m = self::milli($s['quantity']);
            if ($m <= 0) {
                $errors[] = 'Slice quantity must be positive.';
                continue;
            }
            $crd = (int) $s['receiving_detail_id'];
            $receiptFinancial[$crd] = ($receiptFinancial[$crd] ?? 0) + $m;
            if ($s['type'] === ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT) {
                $receiptUnsold[$crd] = ($receiptUnsold[$crd] ?? 0) + $m;
            } else {
                $sid = (int) ($s['sold_source_id'] ?? 0);
                if (! $sid) {
                    $errors[] = 'Sold slice requires a sold source.';
                    continue;
                }
                $sold[$sid] = ($sold[$sid] ?? 0) + $m;
            }
        }

        foreach ($receiptFinancial as $crd => $m) {
            $cap = $this->receiptCapacity($crd, $excludeBuyoutId);
            foreach ($cap['conservation_errors'] as $e) {
                $errors[] = "Receipt #{$crd}: {$e}";
            }
            if ($m > self::milli($cap['financial_available'])) {
                $errors[] = "Receipt #{$crd}: requested " . self::fromMilli($m) . " exceeds financial availability {$cap['financial_available']}.";
            }
            if (($receiptUnsold[$crd] ?? 0) > self::milli($cap['unsold_available'])) {
                $errors[] = "Receipt #{$crd}: requested unsold " . self::fromMilli($receiptUnsold[$crd]) . " exceeds physically unsold availability {$cap['unsold_available']}.";
            }
            foreach ($cap['blockers'] as $b) {
                $errors[] = "Receipt #{$crd}: {$b}";
            }
        }
        foreach ($sold as $sid => $m) {
            $src = ConsignmentSoldSource::find($sid);
            if (! $src) {
                $errors[] = "Sold source #{$sid} not found.";
                continue;
            }
            $cap = $this->soldSourceCapacity($src, $excludeBuyoutId);
            foreach ($cap['conservation_errors'] as $e) {
                $errors[] = "Sold source #{$sid}: {$e}";
            }
            if ($m > self::milli($cap['available'])) {
                $errors[] = "Sold source #{$sid}: requested " . self::fromMilli($m) . " exceeds available {$cap['available']}.";
            }
        }

        return $errors;
    }

    /* ------------------------------------------------------------------ 2.4 physical availability */

    /**
     * Physical pool view for a product/location: receipt residuals vs good stock buckets and operational claims.
     */
    public function physicalPool(int $settingId, int $productId, int $locationId, ?int $excludeBuyoutId = null): array
    {
        $details = ConsignmentReceivingDetail::where('product_id', $productId)
            ->whereHas('receiving', fn ($q) => $q->where('setting_id', $settingId)->where('location_id', $locationId)
                ->where('status', ConsignmentReceiving::STATUS_APPROVED))->pluck('id');

        $residual = 0;
        $receipts = [];
        $conservation = [];
        foreach ($details as $id) {
            $cap = $this->receiptCapacity($id, $excludeBuyoutId);
            $receipts[$id] = $cap;
            $residual += self::milli($cap['unsold_available']);
            $conservation = array_merge($conservation, $cap['conservation_errors']);
        }

        $stock = \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::latest(ProductStock::where('product_id', $productId)->where('location_id', $locationId))->first();
        $buckets = [
            'taxed' => $stock ? self::milli($stock->quantity_tax) : 0,
            'non_taxed' => $stock ? self::milli($stock->quantity_non_tax) : 0,
        ];
        $good = array_sum($buckets);
        $total = $stock ? self::milli($stock->quantity) : 0;

        $reservedQ = ConsignmentBuyoutReservation::where('resource_type', 'PHYSICAL_BUCKET')
            ->where('product_id', $productId)->where('location_id', $locationId)
            ->where('status', ConsignmentBuyoutReservation::STATUS_ACTIVE);
        \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::latest($reservedQ);
        if ($excludeBuyoutId) {
            $reservedQ->where('consignment_buyout_id', '!=', $excludeBuyoutId);
        }
        $reservedByBucket = ['taxed' => 0, 'non_taxed' => 0];
        foreach ($reservedQ->get() as $r) {
            $key = strtoupper((string) $r->tax_bucket) === 'TAXED' ? 'taxed' : 'non_taxed'; // BaseModel upper-cases strings
            $reservedByBucket[$key] += self::milli($r->base_quantity);
        }
        $reservedPhysical = array_sum($reservedByBucket);
        $bucketAvailable = [
            'taxed' => $buckets['taxed'] - $reservedByBucket['taxed'],
            'non_taxed' => $buckets['non_taxed'] - $reservedByBucket['non_taxed'],
        ];

        $blockers = [];
        // Pool-wide: receipts say units are still supplier-owned here, but sellable stock cannot cover them.
        $sumResidualAll = array_sum(array_map(fn ($c) => self::milli($c['physical_residual']), $receipts));
        if ($good < $sumResidualAll) {
            $blockers[] = 'Sellable stock (' . self::fromMilli($good) . ') is lower than the supplier-owned residual ('
                . self::fromMilli($sumResidualAll) . '); moved or damaged stock is ambiguous.';
        }
        foreach ($bucketAvailable as $name => $m) {
            if ($m < 0) {
                $blockers[] = "Reservations exceed the {$name} stock bucket.";
            }
        }

        $physicalAvailable = max(0, min($residual, max(0, $bucketAvailable['taxed']) + max(0, $bucketAvailable['non_taxed'])));

        return [
            'receipts' => $receipts,
            'receipt_unsold_available' => self::fromMilli($residual),
            'sellable_stock' => self::fromMilli($good),
            'total_stock' => self::fromMilli($total),
            'reserved_physical' => self::fromMilli($reservedPhysical),
            'bucket_available' => array_map(fn ($m) => self::fromMilli(max(0, $m)), $bucketAvailable),
            'physically_available' => $blockers ? 0.0 : self::fromMilli($physicalAvailable),
            'blockers' => array_merge($blockers, $this->poolBlockers($settingId, $productId, $locationId)),
            'conservation_errors' => $conservation,
        ];
    }

    /**
     * Check requested per-bucket unsold quantities against bucket availability (no relabelling between buckets).
     *
     * @param array{taxed?:float|string, non_taxed?:float|string} $requested
     * @return string[]
     */
    public function validateBuckets(int $settingId, int $productId, int $locationId, array $requested, ?int $excludeBuyoutId = null): array
    {
        $pool = $this->physicalPool($settingId, $productId, $locationId, $excludeBuyoutId);
        $errors = $pool['blockers'];
        $total = 0;
        foreach (['taxed', 'non_taxed'] as $b) {
            $m = self::milli($requested[$b] ?? 0);
            $total += $m;
            if ($m > self::milli($pool['bucket_available'][$b])) {
                $errors[] = "Requested {$b} quantity " . self::fromMilli($m) . " exceeds the available {$b} bucket {$pool['bucket_available'][$b]}.";
            }
        }
        if ($total > self::milli($pool['physically_available'])) {
            $errors[] = 'Requested quantity exceeds physically available unsold stock.';
        }

        return $errors;
    }

    /**
     * Serials that may be bought out: active, sellable, unheld, unclaimed, same product/location, with receipt lineage.
     */
    public function selectableSerialIds(int $productId, int $locationId, ?int $receivingDetailId = null): array
    {
        $q = \Modules\Product\Entities\ProductSerialNumber::query()
            ->where('product_id', $productId)->where('location_id', $locationId)
            ->where(fn ($w) => $w->whereNull('status')->orWhereRaw('UPPER(status) = ?', [\Modules\Product\Entities\ProductSerialNumber::STATUS_ACTIVE]))
            ->where(fn ($w) => $w->whereNull('is_broken')->orWhere('is_broken', false))
            ->where(fn ($w) => $w->whereNull('is_in_return_process')->orWhere('is_in_return_process', false))
            ->whereNotNull('consignment_receiving_detail_id')
            ->whereNotIn('id', \Modules\Consignment\Entities\ConsignmentActiveSerialClaim::query()->select('product_serial_number_id'));
        if ($receivingDetailId) {
            $q->where('consignment_receiving_detail_id', $receivingDetailId);
        }
        $ids = $q->orderBy('id')->pluck('id')->all();

        // Serials in held return custody are not sellable stock even if flagged otherwise.
        return array_values(array_filter($ids, fn ($id) => ! \App\Support\SalesReturn\HeldSerialGuard::isHeld($id)));
    }

    /** Buyout financial/physical consumption of a receipt (finalized + active reservations) for legacy checks. */
    public function buyoutReceiptConsumption(int $detailId): float
    {
        $finalized = ConsignmentBuyoutLine::where('consignment_receiving_detail_id', $detailId)
            ->whereHas('buyout', fn ($q) => $q->where('status', ConsignmentBuyout::STATUS_FINALIZED))->sum('base_quantity');
        $reserved = \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::latest(ConsignmentBuyoutReservation::where('resource_type', 'RECEIPT')
            ->where('consignment_receiving_detail_id', $detailId)
            ->where('status', ConsignmentBuyoutReservation::STATUS_ACTIVE))->sum('base_quantity');

        return (float) $finalized + (float) $reserved;
    }

    /**
     * Adopt a legacy confirmation's non-serialized receipt allocations as sold-receipt attribution.
     * Never reassigns another supplier's attribution: a conflict throws so the caller's transaction rolls back.
     */
    public function adoptLegacyAllocations(ConsignmentBillingConfirmation $header): void
    {
        $lines = $header->lines()->with(['receiptAllocations', 'serializedAllocations'])->get();
        foreach ($lines as $line) {
            if ($line->serializedAllocations->isNotEmpty() || ConsignmentSoldSourceSerial::where('consignment_sold_source_id', $line->consignment_sold_source_id)->exists()) {
                continue; // serialized attribution comes from exact serial lineage
            }
            $source = ConsignmentSoldSource::find($line->consignment_sold_source_id);
            if (! $source) {
                continue;
            }
            // Required attribution = all active legacy evidence for this source plus this confirmation's lines.
            $required = [];
            foreach ($this->legacyEvidenceMilli($source, $header->id) as $crd => $m) {
                $required[$crd] = $m;
            }
            foreach ($line->receiptAllocations as $ra) {
                $required[$ra->consignment_receiving_detail_id] = ($required[$ra->consignment_receiving_detail_id] ?? 0) + self::milli($ra->allocated_base_quantity);
            }
            $this->raiseAttribution($source, $required, $header);
        }
    }

    protected function legacyEvidenceMilli(ConsignmentSoldSource $source, int $exceptConfirmationId): array
    {
        $rows = ConsignmentReceiptAllocation::whereHas('line', fn ($q) => $q->where('consignment_sold_source_id', $source->id)
            ->whereHas('confirmation', fn ($c) => $c->where('id', '!=', $exceptConfirmationId)->whereIn('status', [
                ConsignmentBillingConfirmation::STATUS_APPROVED, ConsignmentBillingConfirmation::STATUS_WAITING_APPROVAL])))->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->consignment_receiving_detail_id] = ($out[$r->consignment_receiving_detail_id] ?? 0) + self::milli($r->allocated_base_quantity);
        }

        return $out;
    }

    protected function raiseAttribution(ConsignmentSoldSource $source, array $required, ConsignmentBillingConfirmation $header): void
    {
        $rows = ConsignmentSoldReceiptAttribution::where('consignment_sold_source_id', $source->id)->lockForUpdate()->get()->keyBy('consignment_receiving_detail_id');
        $original = self::milli($source->original_base_quantity);
        $total = $rows->sum(fn ($r) => self::milli($r->attributed_base_quantity));

        $increase = [];
        foreach ($required as $crd => $need) {
            $have = $rows->has($crd) ? self::milli($rows[$crd]->attributed_base_quantity) : 0;
            if ($need > $have) {
                $increase[$crd] = $need - $have;
            }
        }
        if (! $increase) {
            return;
        }
        if ($total + array_sum($increase) > $original) {
            throw new \DomainException("Sold source #{$source->id} is already attributed to other supplier receipts; confirmation #{$header->id} conflicts with that supplier assignment.");
        }
        foreach ($increase as $crd => $delta) {
            if ($this->attributionIsDependedOn($source, (int) $crd)) {
                throw new \DomainException("Receipt #{$crd} attribution is relied on by a reserved or finalized buyout and cannot change.");
            }
            $row = $rows->get($crd) ?? new ConsignmentSoldReceiptAttribution([
                'setting_id' => $source->setting_id, 'consignment_sold_source_id' => $source->id,
                'consignment_receiving_detail_id' => $crd, 'origin' => 'LEGACY_ALLOCATION', 'attributed_base_quantity' => 0,
            ]);
            $row->attributed_base_quantity = self::fromMilli(self::milli($row->attributed_base_quantity) + $delta);
            $row->revision = $row->exists ? $row->revision + 1 : 1;
            $row->actor_id = $header->submitted_by ?? $header->created_by;
            $row->reason = "Adopted from legacy confirmation {$header->confirmation_number}.";
            $row->save();
        }
    }
}
