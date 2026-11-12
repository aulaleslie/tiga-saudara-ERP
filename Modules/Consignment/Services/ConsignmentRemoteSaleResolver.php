<?php

namespace Modules\Consignment\Services;

use Illuminate\Support\Carbon;
use Modules\Consignment\Entities\ConsignmentObligationBinding as Binding;
use Modules\Consignment\Entities\ConsignmentProvenanceEvent as Event;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Entities\ConsignmentSoldSourceSerial;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;

/**
 * Sales of receipt-backed consignment goods away from their receiving location (phase 3).
 *
 * The sold envelope (ConsignmentSoldSource) is the actual selling business's dispatch evidence and is
 * never rewritten. Each original receipt contribution is a separate, hashed, immutable obligation binding.
 * Serialized bindings are created only from exact custody at dispatch time; non-serialized remote bindings
 * come only from explicit back-office attribution. Discovery stays manual: nothing here is event-driven.
 */
class ConsignmentRemoteSaleResolver
{
    public const BINDING_VERSION = 'b1';

    /**
     * Consignment locations of OTHER businesses that received this business's receipt custody.
     *
     * @return array<int, array<int, Carbon>> location_id => [product_id => earliest arrival]
     */
    public function reachableRemoteLocations(int $originalSettingId): array
    {
        $rows = Event::where('original_setting_id', $originalSettingId)
            ->where('to_state', Event::STATE_SELLABLE)
            ->whereNotNull('to_location_id')
            ->where('to_setting_id', '!=', $originalSettingId)
            ->selectRaw('to_location_id, product_id, MIN(effective_at) as first_at')
            ->groupBy('to_location_id', 'product_id')
            ->get();
        $consignment = Location::whereIn('id', $rows->pluck('to_location_id')->unique())->where('is_consignment', true)->pluck('id')->flip();

        $out = [];
        foreach ($rows as $r) {
            if (isset($consignment[$r->to_location_id])) {
                $out[(int) $r->to_location_id][(int) $r->product_id] = Carbon::parse($r->first_at);
            }
        }

        return $out;
    }

    /** Receipt custody of one serial as of a moment: latest event at or before it, else the receipt itself. */
    public function serialCustodyAt(ProductSerialNumber $serial, $at): ?array
    {
        if (! $serial->consignment_receiving_detail_id) {
            return null;
        }
        $q = Event::where('product_serial_number_id', $serial->id)->orderByDesc('effective_at')->orderByDesc('id');
        if ($at) {
            $q->where('effective_at', '<=', Carbon::parse($at));
        }
        $events = Event::where('product_serial_number_id', $serial->id)->where('effective_at', '<=', $at ? Carbon::parse($at) : now())
            ->orderBy('effective_at')->orderBy('id')->pluck('id')->all();
        $last = $q->first();
        if ($last) {
            return ['state' => $last->to_state, 'location_id' => $last->to_location_id ? (int) $last->to_location_id : null, 'path' => $events];
        }
        $d = ConsignmentReceivingDetail::with('receiving')->find($serial->consignment_receiving_detail_id);

        return $d ? ['state' => Event::STATE_SELLABLE, 'location_id' => (int) $d->receiving->location_id, 'path' => []] : null;
    }

    /** True when the serial's receipt was received at the sale location and never moved before the sale. */
    private function isLocal(ProductSerialNumber $serial, ConsignmentReceivingDetail $d, ConsignmentSoldSource $src, array $custody): bool
    {
        return $custody['path'] === [] && (int) $d->receiving->location_id === (int) $src->location_id;
    }

    /**
     * Per-serial verification for a serialized envelope.
     *
     * @return array<int, array{serial_id:int, receipt_id:?int, local:bool, path:int[], blocker:?string}>
     */
    public function verifySerials(ConsignmentSoldSource $src): array
    {
        $out = [];
        $rows = ConsignmentSoldSourceSerial::where('consignment_sold_source_id', $src->id)->with('serialNumber')->get();
        foreach ($rows as $row) {
            $serial = $row->serialNumber;
            $entry = ['serial_id' => (int) $row->product_serial_number_id, 'receipt_id' => null, 'local' => false, 'path' => [], 'blocker' => null];
            $d = $serial && $serial->consignment_receiving_detail_id
                ? ConsignmentReceivingDetail::with('receiving.receival')->find($serial->consignment_receiving_detail_id) : null;
            if (! $serial || ! $d) {
                $out[] = ['blocker' => "Serial #{$row->product_serial_number_id} has no receipt lineage."] + $entry;
                continue;
            }
            $entry['receipt_id'] = (int) $d->id;
            if ((int) $serial->product_id !== (int) $src->product_id || (int) $d->product_id !== (int) $src->product_id) {
                $out[] = ['blocker' => "Serial {$serial->serial_number} belongs to another product or receipt."] + $entry;
                continue;
            }
            if (! $d->receiving || $d->receiving->status !== ConsignmentReceiving::STATUS_APPROVED) {
                $out[] = ['blocker' => "Receipt of serial {$serial->serial_number} is not approved or was reversed."] + $entry;
                continue;
            }
            $custody = $this->serialCustodyAt($serial, $src->dispatched_at);
            $entry['path'] = $custody['path'] ?? [];
            if ($custody && $this->isLocal($serial, $d, $src, $custody)) {
                $out[] = ['local' => true] + $entry;
                continue;
            }
            if (! $custody || $custody['state'] !== Event::STATE_SELLABLE || (int) $custody['location_id'] !== (int) $src->location_id) {
                $where = $custody ? "{$custody['state']} at location " . ($custody['location_id'] ?? '-') : 'unknown';
                $out[] = ['blocker' => "Serial {$serial->serial_number}: no completed receipt custody path to the sale location (custody was {$where})."] + $entry;
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /** Create missing bindings for verified transferred serials. Idempotent; existing bindings never move. */
    public function bindSerializedSource(ConsignmentSoldSource $src): int
    {
        $created = 0;
        $byReceipt = [];
        foreach ($this->verifySerials($src) as $v) {
            if ($v['blocker'] === null && ! $v['local']) {
                $byReceipt[$v['receipt_id']][] = $v;
            }
        }
        foreach ($byReceipt as $receiptId => $serials) {
            if (Binding::where('consignment_sold_source_id', $src->id)->where('consignment_receiving_detail_id', $receiptId)->exists()) {
                continue;
            }
            $d = ConsignmentReceivingDetail::with('receiving.receival')->findOrFail($receiptId);
            $attrs = [
                'consignment_sold_source_id' => $src->id,
                'consignment_receiving_detail_id' => $d->id,
                'original_setting_id' => (int) $d->receiving->setting_id,
                'supplier_id' => (int) $d->receiving->receival->supplier_id,
                'product_id' => (int) $src->product_id,
                'seller_setting_id' => (int) Location::whereKey($src->location_id)->value('setting_id'),
                'seller_location_id' => (int) $src->location_id,
                'base_quantity' => count($serials),
                'serial_identities' => array_column($serials, 'serial_id'),
                'custody_path' => array_values(array_unique(array_merge(...array_column($serials, 'path')))),
                'binding_version' => self::BINDING_VERSION,
                'revision' => 1,
                'status' => 'ACTIVE',
                'attribution_mode' => 'SERIAL',
            ];
            $attrs['binding_hash'] = $this->hash($attrs, $src);
            Binding::create($attrs);
            $created++;
        }

        return $created;
    }

    /** Canonical binding hash: envelope hash + receipt + identities + quantity + serials + path. */
    public function hash(array $b, ConsignmentSoldSource $src): string
    {
        $serials = array_map('intval', (array) ($b['serial_identities'] ?? []));
        sort($serials);
        $path = array_map('intval', (array) ($b['custody_path'] ?? []));
        sort($path);

        return hash('sha256', json_encode([
            'binding_version' => $b['binding_version'],
            'sold_source_id' => (int) $src->id,
            'sold_source_hash' => strtolower((string) $src->source_hash),
            'receipt_id' => (int) $b['consignment_receiving_detail_id'],
            'original_setting_id' => (int) $b['original_setting_id'],
            'supplier_id' => (int) $b['supplier_id'],
            'seller_location_id' => (int) $b['seller_location_id'],
            'base_quantity' => number_format((float) $b['base_quantity'], 3, '.', ''),
            'serials' => $serials,
            'path' => $path,
            'mode' => $b['attribution_mode'],
        ]));
    }

    /**
     * Live verification of a stored binding against current evidence.
     *
     * @return string[] blockers (empty = valid)
     */
    public function bindingBlockers(Binding $b): array
    {
        $src = ConsignmentSoldSource::find($b->consignment_sold_source_id);
        if (! $src) {
            return ["Binding #{$b->id}: sold source missing."];
        }
        if (! hash_equals((string) $b->binding_hash, $this->hash($b->toArray(), $src))) {
            return ["Binding #{$b->id}: evidence hash mismatch (envelope or binding altered)."];
        }
        $d = ConsignmentReceivingDetail::with('receiving')->find($b->consignment_receiving_detail_id);
        if (! $d || ! $d->receiving || $d->receiving->status !== ConsignmentReceiving::STATUS_APPROVED) {
            return ["Binding #{$b->id}: receipt is not approved or was reversed."];
        }
        if ($b->attribution_mode === 'SERIAL') {
            $verified = collect($this->verifySerials($src))->where('receipt_id', (int) $d->id)->where('blocker', null)->pluck('serial_id')->all();
            if (array_diff(array_map('intval', (array) $b->serial_identities), $verified)) {
                return ["Binding #{$b->id}: serial custody path no longer verifies."];
            }
        }

        return [];
    }

    public function activeBinding(int $soldSourceId, int $receiptId): ?Binding
    {
        return Binding::where('consignment_sold_source_id', $soldSourceId)->where('consignment_receiving_detail_id', $receiptId)
            ->where('status', 'ACTIVE')->orderByDesc('revision')->first();
    }

    /* ------------------------------------------------------------------ non-serialized (explicit attribution) */

    /** Receipt-backed sellable custody (milli) of one receipt at a location from provenance events up to a moment. */
    public function lotCustodyAt(ConsignmentReceivingDetail $d, int $locationId, $at): int
    {
        $d->loadMissing('receiving');
        $events = Event::where('consignment_receiving_detail_id', $d->id)->whereNull('product_serial_number_id')
            ->where('effective_at', '<=', $at ? Carbon::parse($at) : now())->get();
        $m = 0;
        if (! $events->contains(fn ($e) => $e->from_state === Event::STATE_EXTERNAL) && (int) $d->receiving->location_id === $locationId) {
            $m += ConsignmentBuyoutCapacityService::milli($d->received_base_quantity ?? $d->quantity_received);
        }
        foreach ($events as $e) {
            $q = ConsignmentBuyoutCapacityService::milli($e->base_quantity);
            if ($e->to_state === Event::STATE_SELLABLE && (int) $e->to_location_id === $locationId) {
                $m += $q;
            }
            if ($e->from_state === Event::STATE_SELLABLE && (int) $e->from_location_id === $locationId) {
                $m -= $q;
            }
        }

        return $m;
    }

    private function isLocalAttribution(ConsignmentReceivingDetail $d, ConsignmentSoldSource $src): bool
    {
        $d->loadMissing('receiving');

        return (int) $d->receiving->location_id === (int) $src->location_id && (int) $d->receiving->setting_id === (int) $src->setting_id;
    }

    /**
     * Read-only proposal for a non-serialized remote sale: the sole receipt whose transferred custody at the sale
     * location covers the sold quantity. Never applied without explicit confirmation; never FIFO/LIFO.
     *
     * @return array{receipt_id:?int, candidates: int[]}
     */
    public function proposeLotAttribution(ConsignmentSoldSource $src): array
    {
        $ids = Event::where('product_id', $src->product_id)->where('to_location_id', $src->location_id)->whereNull('product_serial_number_id')
            ->pluck('consignment_receiving_detail_id')
            ->merge(ConsignmentReceivingDetail::where('product_id', $src->product_id)
                ->whereHas('receiving', fn ($q) => $q->where('location_id', $src->location_id)->where('status', ConsignmentReceiving::STATUS_APPROVED))->pluck('id'))
            ->unique()->values();
        $need = ConsignmentBuyoutCapacityService::milli($src->original_base_quantity);
        $cands = ConsignmentReceivingDetail::with('receiving')->whereIn('id', $ids)->get()
            ->filter(fn ($d) => $d->receiving && $d->receiving->status === ConsignmentReceiving::STATUS_APPROVED
                && $this->lotCustodyAt($d, (int) $src->location_id, $src->dispatched_at) > 0)
            ->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
        $sole = count($cands) === 1
            && $this->lotCustodyAt(ConsignmentReceivingDetail::find($cands[0]), (int) $src->location_id, $src->dispatched_at) >= $need
            ? $cands[0] : null;

        return ['receipt_id' => $sole, 'candidates' => $cands];
    }

    /** Keep one ACTIVE CONFIRMED_LOT binding revision per remote attributed receipt (called inside setAttribution). */
    public function syncLotBindings(ConsignmentSoldSource $src, ?int $actorId): void
    {
        $rows = \Modules\Consignment\Entities\ConsignmentSoldReceiptAttribution::where('consignment_sold_source_id', $src->id)->get()
            ->keyBy('consignment_receiving_detail_id');
        $active = Binding::where('consignment_sold_source_id', $src->id)->where('attribution_mode', 'CONFIRMED_LOT')->where('status', 'ACTIVE')->get()
            ->keyBy('consignment_receiving_detail_id');

        foreach ($active as $crd => $b) {
            $row = $rows->get($crd);
            if (! $row || ConsignmentBuyoutCapacityService::milli($row->attributed_base_quantity) !== ConsignmentBuyoutCapacityService::milli($b->base_quantity)) {
                $b->update(['status' => 'SUPERSEDED']);
                $active->forget($crd);
            }
        }
        foreach ($rows as $crd => $row) {
            $d = ConsignmentReceivingDetail::with('receiving.receival')->find($crd);
            if (! $d || $this->isLocalAttribution($d, $src) || $active->has($crd)) {
                continue;
            }
            $path = Event::where('consignment_receiving_detail_id', $d->id)->whereNull('product_serial_number_id')
                ->where('effective_at', '<=', $src->dispatched_at ? Carbon::parse($src->dispatched_at) : now())
                ->orderBy('id')->pluck('id')->all();
            $attrs = [
                'consignment_sold_source_id' => $src->id,
                'consignment_receiving_detail_id' => $d->id,
                'original_setting_id' => (int) $d->receiving->setting_id,
                'supplier_id' => (int) $d->receiving->receival->supplier_id,
                'product_id' => (int) $src->product_id,
                'seller_setting_id' => (int) Location::whereKey($src->location_id)->value('setting_id'),
                'seller_location_id' => (int) $src->location_id,
                'base_quantity' => ConsignmentBuyoutCapacityService::fromMilli(ConsignmentBuyoutCapacityService::milli($row->attributed_base_quantity)),
                'serial_identities' => null,
                'custody_path' => $path,
                'binding_version' => self::BINDING_VERSION,
                'revision' => (int) Binding::where('consignment_sold_source_id', $src->id)->where('consignment_receiving_detail_id', $d->id)->max('revision') + 1,
                'status' => 'ACTIVE',
                'attribution_mode' => 'CONFIRMED_LOT',
                'confirmed_by' => $actorId,
                'confirmed_at' => now(),
            ];
            $attrs['binding_hash'] = $this->hash($attrs, $src);
            Binding::create($attrs);
        }
    }

    /** @return string[] blockers for one non-serialized attribution row */
    public function lotBindingBlockers(ConsignmentSoldSource $src, $row): array
    {
        $d = ConsignmentReceivingDetail::with('receiving')->find($row->consignment_receiving_detail_id);
        if (! $d || $this->isLocalAttribution($d, $src)) {
            return [];
        }
        $b = $this->activeBinding((int) $src->id, (int) $d->id);
        if (! $b || ConsignmentBuyoutCapacityService::milli($b->base_quantity) !== ConsignmentBuyoutCapacityService::milli($row->attributed_base_quantity)) {
            return ["Receipt #{$d->id}: transferred attribution has no confirmed receipt binding."];
        }

        return $this->bindingBlockers($b);
    }
}
