<?php

namespace Modules\Consignment\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovementAllocation;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentProvenanceEvent as Event;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldReceiptAttribution;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Entities\ConsignmentSoldSourceSerial;
use Modules\Consignment\Entities\ConsignmentTransferLotSelection as Selection;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use RuntimeException;

/**
 * Consignment-only receipt-lot provenance for stock transfers (track-transferred-consignment-provenance phase 2).
 *
 * Transfers whose source is not a consignment location never reach this service. It never posts stock,
 * serial location, supplier bills or stock-debt; it only records which original receipt moved where,
 * atomically inside the caller's existing transfer transaction.
 */
class ConsignmentTransferProvenanceService
{
    public function __construct(
        private ConsignmentCustodyProjection $projection = new ConsignmentCustodyProjection(),
        private ConsignmentBuyoutCapacityService $capacity = new ConsignmentBuyoutCapacityService(),
    ) {
    }

    /** Actionable operator message for a refused V1/V2 consignment dispatch (documented compatibility deviation). */
    public const LEGACY_REFUSAL = 'Pengiriman dari lokasi konsinyasi harus menggunakan transfer versi 3 agar asal penerimaan supplier tercatat. '
        . 'Dokumen ini dibuat dengan alur transfer lama dan belum mengubah stok: batalkan atau arsipkan dokumen ini, lalu buat transfer baru '
        . '(transfer baru memakai versi 3 bila STOCK_TRANSFERS_V3_CREATION_ENABLED aktif) dan konfirmasi Lot Penerimaan Konsinyasi di layar persetujuan.';

    public static function appliesTo(?Location $source): bool
    {
        return $source !== null && (bool) $source->is_consignment;
    }

    /**
     * V1/V2 executors cannot post receipt provenance. New dispatches from consignment sources must use V3;
     * receipt/return-receipt of already-dispatched legacy documents is untouched (reconstructed later).
     */
    public static function assertLegacyDispatchAllowed(?int $originLocationId): void
    {
        if ($originLocationId && Location::whereKey($originLocationId)->value('is_consignment')) {
            throw new RuntimeException(self::LEGACY_REFUSAL);
        }
    }

    /* ------------------------------------------------------------------ custody reads */

    /** Receipt identities: original billing business, supplier, receiving location. */
    public function receiptIdentity(ConsignmentReceivingDetail $d): array
    {
        $d->loadMissing('receiving.receival');

        return [
            'original_setting_id' => (int) $d->receiving->setting_id,
            'supplier_id' => (int) optional($d->receiving->receival)->supplier_id,
            'receiving_location_id' => (int) $d->receiving->location_id,
        ];
    }

    /** Sold and acquired exits read from their authoritative records, per location. */
    public function derivedExits(ConsignmentReceivingDetail $d): array
    {
        $exits = [];
        $sourceIds = ConsignmentSoldReceiptAttribution::where('consignment_receiving_detail_id', $d->id)->pluck('consignment_sold_source_id')
            ->merge(ConsignmentSoldSourceSerial::query()
                ->whereHas('serialNumber', fn ($q) => $q->where('consignment_receiving_detail_id', $d->id))
                ->pluck('consignment_sold_source_id'))->unique();
        foreach (ConsignmentSoldSource::whereIn('id', $sourceIds)->get() as $src) {
            $qty = $this->capacity->soldAttribution($src)['per_receipt'][$d->id] ?? 0;
            $setting = Location::whereKey($src->location_id)->value('setting_id');
            $exits[] = ['state' => Event::STATE_SOLD, 'location_id' => (int) $src->location_id, 'setting_id' => $setting ? (int) $setting : null, 'quantity' => $qty];
            // Effective returns left the SOLD exit above; while they sit in held return custody they are NOT sellable.
            foreach ($this->heldReturns($src, $d) as $held) {
                $exits[] = $held;
            }
        }
        $acquired = ConsignmentBuyoutLine::where('consignment_receiving_detail_id', $d->id)
            ->where('line_type', ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT)
            ->whereHas('buyout', fn ($q) => $q->where('status', ConsignmentBuyout::STATUS_FINALIZED))->get();
        foreach ($acquired as $line) {
            $setting = Location::whereKey($line->source_location_id)->value('setting_id');
            $exits[] = ['state' => Event::STATE_ACQUIRED, 'location_id' => (int) $line->source_location_id, 'setting_id' => $setting ? (int) $setting : null, 'quantity' => $line->base_quantity];
        }

        return $exits;
    }

    /**
     * Unacquired returned units of one sold source still in HELD return custody, attributed to this receipt exactly:
     * serialized by the custody row's serial receipt pivot; non-serialized only when unambiguous (one attributed
     * receipt, or held equal to the explicitly mapped returns). Ambiguity becomes a blocker - never a guess.
     * Released units are sellable again (existing release policy); dispatched-out units are no longer held.
     *
     * @return array<int, array{state:string, location_id:?int, setting_id:?int, quantity:float}|array{blocker:string}>
     */
    public function heldReturns(ConsignmentSoldSource $src, ConsignmentReceivingDetail $d): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('sale_return_custodies')) {
            return [];
        }
        // Unacquired returns keep the consignment route; acquired ones are held for an owned (standard) destination.
        $rows = DB::table('sale_return_custodies')->where('dispatch_detail_id', $src->dispatch_detail_id)
            ->whereIn('location_id', Location::where('is_consignment', true)->select('id'))->get();
        $out = [];
        $held = fn ($r) => ConsignmentBuyoutCapacityService::milli($r->quantity_received) - ConsignmentBuyoutCapacityService::milli($r->quantity_released)
            - ConsignmentBuyoutCapacityService::milli($r->quantity_dispatched);
        $byLoc = [];
        $serialRows = $rows->whereNotNull('product_serial_number_id');
        if ($serialRows->isNotEmpty()) {
            $pivots = ProductSerialNumber::whereIn('id', $serialRows->pluck('product_serial_number_id'))->pluck('consignment_receiving_detail_id', 'id');
            foreach ($serialRows as $r) {
                if ((int) ($pivots[$r->product_serial_number_id] ?? 0) === (int) $d->id && $held($r) > 0) {
                    $byLoc[(int) $r->location_id] = ($byLoc[(int) $r->location_id] ?? 0) + $held($r);
                }
            }
        }
        $plain = $rows->whereNull('product_serial_number_id');
        $total = $plain->sum(fn ($r) => max(0, $held($r)));
        if ($total > 0) {
            $attr = ConsignmentSoldReceiptAttribution::where('consignment_sold_source_id', $src->id)->get();
            $mine = $attr->firstWhere('consignment_receiving_detail_id', $d->id);
            if ($attr->count() === 1 && $mine) {
                $share = $total;
            } elseif ($attr->count() > 1 && $total === ConsignmentBuyoutCapacityService::milli($attr->sum('returned_base_quantity'))) {
                $share = $mine ? ConsignmentBuyoutCapacityService::milli($mine->returned_base_quantity) : 0;
            } elseif ($mine) {
                return [['blocker' => "Sold source #{$src->id}: held return custody cannot be apportioned exactly to receipts; map returns explicitly."]];
            } else {
                $share = 0;
            }
            foreach ($plain as $r) {
                $take = min($share, max(0, $held($r)));
                if ($take > 0) {
                    $byLoc[(int) $r->location_id] = ($byLoc[(int) $r->location_id] ?? 0) + $take;
                    $share -= $take;
                }
            }
        }
        foreach ($byLoc as $loc => $m) {
            $setting = Location::whereKey($loc)->value('setting_id');
            $out[] = ['state' => Event::STATE_HELD, 'location_id' => $loc, 'setting_id' => $setting ? (int) $setting : null,
                'quantity' => ConsignmentBuyoutCapacityService::fromMilli($m), 'from_location_id' => (int) $src->location_id];
        }

        return $out;
    }

    public function project(ConsignmentReceivingDetail $d): array
    {
        return $this->projection->forReceipt($d, null, $this->derivedExits($d));
    }

    /** Receipts that may hold custody at a location: received there, or moved there by provenance. */
    public function receiptsReachable(int $productId, int $locationId): Collection
    {
        $direct = ConsignmentReceivingDetail::where('product_id', $productId)
            ->whereHas('receiving', fn ($q) => $q->where('location_id', $locationId)->where('status', ConsignmentReceiving::STATUS_APPROVED))
            ->pluck('id');
        $moved = Event::where('product_id', $productId)->where('to_location_id', $locationId)->pluck('consignment_receiving_detail_id');

        return ConsignmentReceivingDetail::whereIn('id', $direct->merge($moved)->unique()->all())->orderBy('id')->get();
    }

    /**
     * Unsold, unreserved supplier-owned sellable quantity of each receipt at a location.
     *
     * @return array<int, array{receipt_id:int, original_setting_id:int, supplier_id:int, available:float, blockers:string[]}>
     */
    public function lotCandidates(int $productId, int $locationId): array
    {
        $out = [];
        foreach ($this->receiptsReachable($productId, $locationId) as $d) {
            $p = $this->project($d);
            $available = ConsignmentBuyoutCapacityService::milli($this->projection->quantityAt($p, Event::STATE_SELLABLE, $locationId));
            $available -= $this->reservedUnsoldAt($d->id, $locationId);
            if ($available <= 0 && $p['blockers'] === []) {
                continue;
            }
            $id = $this->receiptIdentity($d);
            $out[$d->id] = [
                'receipt_id' => (int) $d->id,
                'original_setting_id' => $id['original_setting_id'],
                'supplier_id' => $id['supplier_id'],
                'available' => ConsignmentBuyoutCapacityService::fromMilli(max(0, $available)),
                'blockers' => $p['blockers'],
            ];
        }

        return $out;
    }

    private function reservedUnsoldAt(int $detailId, int $locationId): int
    {
        $q = \Modules\Consignment\Entities\ConsignmentBuyoutReservation::where('resource_type', 'RECEIPT')
            ->where('consignment_receiving_detail_id', $detailId)
            ->where('status', \Modules\Consignment\Entities\ConsignmentBuyoutReservation::STATUS_ACTIVE)
            ->whereHas('line', fn ($l) => $l->where('line_type', ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT)->where('source_location_id', $locationId));
        ConsignmentBuyoutReservationGuard::latest($q);

        return ConsignmentBuyoutCapacityService::milli($q->sum('base_quantity'));
    }

    /** Where one serial's receipt custody currently is, from its latest provenance event or its receipt. */
    public function serialCustody(ProductSerialNumber $serial): ?array
    {
        if (! $serial->consignment_receiving_detail_id) {
            return null;
        }
        $last = Event::where('product_serial_number_id', $serial->id)->orderByDesc('effective_at')->orderByDesc('id')->first();
        if ($last) {
            return ['state' => $last->to_state, 'location_id' => $last->to_location_id ? (int) $last->to_location_id : null];
        }
        $d = ConsignmentReceivingDetail::with('receiving')->find($serial->consignment_receiving_detail_id);

        return $d ? ['state' => Event::STATE_SELLABLE, 'location_id' => (int) $d->receiving->location_id] : null;
    }

    /* ------------------------------------------------------------------ preparation (draft/pending; no stock effect) */

    /** Plan rows of a transfer that need non-serialized lot selection. */
    private function lotRows(Transfer $transfer): Collection
    {
        $transfer = Transfer::findOrFail($transfer->id);   // never trust a caller's stale revision
        $plan = app(\Modules\Adjustment\Services\TransferV3AllocationService::class)->currentPlan($transfer);
        $locations = Location::whereIn('id', $plan->pluck('source_location_id')->unique()->all())->get()->keyBy('id');

        return $plan->filter(fn ($row) => self::appliesTo($locations->get($row->source_location_id)) && $row->serials->isEmpty())->values();
    }

    private function currentRevision(Transfer $transfer): int
    {
        return (int) Selection::where('transfer_id', $transfer->id)->max('selection_revision');
    }

    /**
     * Propose the sole eligible lot for each unambiguous row (PROPOSED, awaiting operator confirmation).
     * Rows with several eligible lots get no proposal: the operator must choose quantities explicitly.
     *
     * @return array{proposed:int, ambiguous: array<int, array>}
     */
    public function proposeSoleLots(Transfer $transfer): array
    {
        return DB::transaction(function () use ($transfer) {
            $revision = $this->currentRevision($transfer);
            if ($revision > 0 && Selection::where('transfer_id', $transfer->id)->where('selection_revision', $revision)->exists()) {
                return ['proposed' => 0, 'ambiguous' => []];   // never overwrite an existing selection revision
            }
            $revision++;
            $proposed = 0;
            $ambiguous = [];
            foreach ($this->lotRows($transfer) as $row) {
                $cands = array_filter($this->lotCandidates((int) $row->product_id, (int) $row->source_location_id), fn ($c) => $c['available'] > 0);
                if (count($cands) !== 1) {
                    $ambiguous[$row->id] = array_values($cands);
                    continue;
                }
                $c = reset($cands);
                if (ConsignmentBuyoutCapacityService::milli($c['available']) < ConsignmentBuyoutCapacityService::milli($row->quantity)) {
                    $ambiguous[$row->id] = [$c];
                    continue;
                }
                $this->writeSelection($transfer, $row, $c['receipt_id'], $row->quantity, $revision, 'PROPOSED', 'SOLE_LOT', null);
                $proposed++;
            }

            return ['proposed' => $proposed, 'ambiguous' => $ambiguous];
        });
    }

    /**
     * Save an explicit, confirmed selection as a new revision.
     *
     * @param array<int, array<int, float|string>> $rows approval_allocation_id => [receiving_detail_id => quantity]
     */
    public function confirmSelection(Transfer $transfer, array $rows, User $actor): int
    {
        return DB::transaction(function () use ($transfer, $rows, $actor) {
            Transfer::whereKey($transfer->id)->lockForUpdate()->first();
            if (! in_array(Transfer::whereKey($transfer->id)->value('status'), [Transfer::STATUS_DRAFT, Transfer::STATUS_PENDING], true)) {
                throw new RuntimeException('Pilihan lot konsinyasi hanya dapat diubah sebelum pengiriman.');
            }
            $planRows = $this->lotRows($transfer)->keyBy('id');
            $errors = [];
            foreach ($planRows as $id => $row) {
                if (! isset($rows[$id])) {
                    $errors[] = "Baris #{$id}: pilih lot penerimaan konsinyasi.";
                }
            }
            foreach ($rows as $id => $lots) {
                $row = $planRows->get($id);
                if (! $row) {
                    $errors[] = "Baris #{$id} bukan baris konsinyasi non-seri pada transfer ini.";
                    continue;
                }
                $errors = array_merge($errors, $this->validateRowLots($row, $lots));
            }
            if ($errors) {
                throw new RuntimeException(implode(' ', $errors));
            }

            $revision = $this->currentRevision($transfer) + 1;
            Selection::where('transfer_id', $transfer->id)->where('status', '!=', 'VOIDED')->update(['status' => 'VOIDED']);
            foreach ($rows as $id => $lots) {
                foreach ($lots as $detailId => $qty) {
                    if (ConsignmentBuyoutCapacityService::milli($qty) > 0) {
                        $this->writeSelection($transfer, $planRows->get($id), (int) $detailId, $qty, $revision, 'CONFIRMED', count($lots) === 1 ? 'SOLE_LOT' : 'EXPLICIT', $actor);
                    }
                }
            }

            return $revision;
        });
    }

    /** Confirm the current PROPOSED sole-lot revision as is. */
    public function confirmProposal(Transfer $transfer, User $actor): int
    {
        $revision = $this->currentRevision($transfer);
        $rows = [];
        foreach (Selection::where('transfer_id', $transfer->id)->where('selection_revision', $revision)->where('status', 'PROPOSED')->get() as $s) {
            $rows[(int) $this->rowIdFor($transfer, $s)][$s->consignment_receiving_detail_id] = $s->base_quantity;
        }

        return $this->confirmSelection($transfer, $rows, $actor);
    }

    private function rowIdFor(Transfer $transfer, Selection $s): int
    {
        $row = $this->lotRows($transfer)->first(fn ($r) => (int) $r->product_id === (int) $s->product_id
            && (int) $r->source_location_id === (int) $s->source_location_id && (int) $r->destination_location_id === (int) $s->destination_location_id);

        return $row ? (int) $row->id : 0;
    }

    /** @return string[] */
    private function validateRowLots($row, array $lots): array
    {
        $errors = [];
        $cands = $this->lotCandidates((int) $row->product_id, (int) $row->source_location_id);
        $total = 0;
        foreach ($lots as $detailId => $qty) {
            $m = ConsignmentBuyoutCapacityService::milli($qty);
            if ($m < 0) {
                $errors[] = "Jumlah lot #{$detailId} tidak boleh negatif.";
                continue;
            }
            $d = ConsignmentReceivingDetail::find($detailId);
            if (! $d || (int) $d->product_id !== (int) $row->product_id) {
                $errors[] = "Lot #{$detailId} bukan penerimaan konsinyasi produk ini.";
                continue;
            }
            $c = $cands[(int) $detailId] ?? null;
            if (! $c) {
                $errors[] = "Lot #{$detailId} tidak berada di lokasi sumber.";
                continue;
            }
            if ($c['blockers']) {
                $errors[] = "Lot #{$detailId}: " . implode(' ', $c['blockers']);
            }
            if ($m > ConsignmentBuyoutCapacityService::milli($c['available'])) {
                $errors[] = "Lot #{$detailId} hanya memiliki {$c['available']} unit tersedia.";
            }
            $total += $m;
        }
        if ($total !== ConsignmentBuyoutCapacityService::milli($row->quantity)) {
            $errors[] = "Total lot baris #{$row->id} (" . ConsignmentBuyoutCapacityService::fromMilli($total) . ") harus sama dengan jumlah transfer ({$row->quantity}).";
        }

        return $errors;
    }

    private function writeSelection(Transfer $transfer, $row, int $detailId, $qty, int $revision, string $status, string $mode, ?User $actor): Selection
    {
        $d = ConsignmentReceivingDetail::findOrFail($detailId);
        $id = $this->receiptIdentity($d);
        $locs = Location::whereIn('id', [$row->source_location_id, $row->destination_location_id])->pluck('setting_id', 'id');

        return Selection::create([
            'transfer_id' => $transfer->id,
            'consignment_receiving_detail_id' => $d->id,
            'original_setting_id' => $id['original_setting_id'],
            'supplier_id' => $id['supplier_id'],
            'product_id' => $row->product_id,
            'source_location_id' => $row->source_location_id,
            'source_setting_id' => $locs[$row->source_location_id],
            'destination_location_id' => $row->destination_location_id,
            'destination_setting_id' => $locs[$row->destination_location_id],
            'base_quantity' => $qty,
            'selection_revision' => $revision,
            'status' => $status,
            'selection_mode' => $mode,
            'confirmed_by' => $actor?->id,
            'confirmed_at' => $actor ? Carbon::now() : null,
        ]);
    }

    /**
     * Read-only panel data for the approval workspace: rows, candidate lots, confirmed quantities and a
     * sole-lot prefill. Writes nothing; an empty array means the transfer needs no consignment lot step.
     */
    public function preparationView(Transfer $transfer): array
    {
        $rows = $this->lotRows($transfer);
        if ($rows->isEmpty()) {
            return [];
        }
        $revision = $this->currentRevision($transfer);
        $confirmed = Selection::where('transfer_id', $transfer->id)->where('selection_revision', $revision)->where('status', 'CONFIRMED')->get();
        $supplierNames = \Modules\People\Entities\Supplier::pluck('supplier_name', 'id');
        $out = [];
        foreach ($rows as $row) {
            $cands = array_values(array_filter($this->lotCandidates((int) $row->product_id, (int) $row->source_location_id), fn ($c) => $c['available'] > 0 || $c['blockers']));
            $mine = $confirmed->filter(fn ($s) => (int) $s->product_id === (int) $row->product_id
                && (int) $s->source_location_id === (int) $row->source_location_id && (int) $s->destination_location_id === (int) $row->destination_location_id);
            $values = $mine->mapWithKeys(fn ($s) => [(int) $s->consignment_receiving_detail_id => (float) $s->base_quantity])->all();
            $sole = count($cands) === 1 && $values === [] ? (int) $cands[0]['receipt_id'] : null;
            $out[] = [
                'row_id' => (int) $row->id,
                'product_name' => optional(\Modules\Product\Entities\Product::find($row->product_id))->product_name,
                'source' => optional(Location::find($row->source_location_id))->name,
                'destination' => optional(Location::find($row->destination_location_id))->name,
                'quantity' => (float) $row->quantity,
                'confirmed' => $values !== [],
                'candidates' => array_map(fn ($c) => $c + [
                    'reference' => optional(ConsignmentReceivingDetail::with('receiving')->find($c['receipt_id'])->receiving)->receiving_number,
                    'supplier' => $supplierNames[$c['supplier_id']] ?? '-',
                    'value' => $values[$c['receipt_id']] ?? ($sole === $c['receipt_id'] ? min((float) $row->quantity, $c['available']) : 0),
                ], $cands),
                'sole_proposal' => $sole !== null,
            ];
        }

        return $out;
    }

    /** Blocking problems for the pending transfer, shown before approval and rechecked at dispatch. */
    public function preparationErrors(Transfer $transfer): array
    {
        $errors = [];
        $revision = $this->currentRevision($transfer);
        $confirmed = Selection::where('transfer_id', $transfer->id)->where('selection_revision', $revision)->where('status', 'CONFIRMED')->get();
        foreach ($this->lotRows($transfer) as $row) {
            $lots = $confirmed->filter(fn ($s) => (int) $s->product_id === (int) $row->product_id
                && (int) $s->source_location_id === (int) $row->source_location_id
                && (int) $s->destination_location_id === (int) $row->destination_location_id)
                ->mapWithKeys(fn ($s) => [$s->consignment_receiving_detail_id => $s->base_quantity])->all();
            if ($lots === []) {
                $errors[] = "Baris #{$row->id}: lot penerimaan konsinyasi belum dikonfirmasi.";
                continue;
            }
            $errors = array_merge($errors, $this->validateRowLots($row, $lots));
        }

        return $errors;
    }

    /* ------------------------------------------------------------------ execution (inside the transfer transaction) */

    /**
     * Post source custody to transit for one consignment dispatch allocation. Throws to roll back the whole dispatch.
     *
     * @param ProductSerialNumber[] $serials
     */
    public function onDispatch(Transfer $transfer, TransferMovementAllocation $allocation, array $serials, User $actor): void
    {
        $source = Location::find($allocation->source_location_id);
        if (! self::appliesTo($source)) {
            return;
        }
        $this->lockReceipts($serials ? array_map(fn ($s) => (int) $s->consignment_receiving_detail_id, $serials)
            : $this->confirmedFor($transfer, $allocation)->pluck('consignment_receiving_detail_id')->all());
        $now = Carbon::now();

        if ($serials) {
            foreach ($serials as $serial) {
                $custody = $this->serialCustody($serial);
                if (! $custody) {
                    throw new RuntimeException("Nomor seri {$serial->serial_number} tidak memiliki asal penerimaan konsinyasi.");
                }
                if ($custody['state'] !== Event::STATE_SELLABLE || (int) $custody['location_id'] !== (int) $source->id) {
                    throw new RuntimeException("Asal penerimaan nomor seri {$serial->serial_number} tidak berada di lokasi sumber.");
                }
                $d = ConsignmentReceivingDetail::findOrFail($serial->consignment_receiving_detail_id);
                if ((int) $d->product_id !== (int) $allocation->product_id) {
                    throw new RuntimeException("Nomor seri {$serial->serial_number} berasal dari penerimaan produk lain.");
                }
                $this->postDispatchEvent($allocation, $d, '1', $serial, $actor, $now);
            }

            return;
        }

        $selections = $this->confirmedFor($transfer, $allocation);
        $byLot = $selections->groupBy('consignment_receiving_detail_id')->map(fn ($g) => $g->sum(fn ($s) => ConsignmentBuyoutCapacityService::milli($s->base_quantity)));
        if ($byLot->sum() !== ConsignmentBuyoutCapacityService::milli($allocation->quantity)) {
            throw new RuntimeException('Lot penerimaan konsinyasi yang dikonfirmasi tidak sama dengan jumlah pengiriman. Konfirmasi ulang pilihan lot.');
        }
        $blockers = $this->capacity->poolBlockers((int) $source->setting_id, (int) $allocation->product_id, (int) $source->id);
        if ($blockers && count($this->lotCandidates((int) $allocation->product_id, (int) $source->id)) > 1) {
            throw new RuntimeException('Penjualan konsinyasi di lokasi sumber belum diatribusikan ke lot penerimaan; selesaikan rekonsiliasi sebelum pengiriman.');
        }
        $cands = $this->lotCandidates((int) $allocation->product_id, (int) $source->id);
        foreach ($byLot as $detailId => $m) {
            $c = $cands[(int) $detailId] ?? null;
            if (! $c || $c['blockers'] || ConsignmentBuyoutCapacityService::milli($c['available']) < $m) {
                throw new RuntimeException("Lot penerimaan #{$detailId} tidak lagi memiliki stok konsinyasi yang cukup di lokasi sumber.");
            }
            $this->postDispatchEvent($allocation, ConsignmentReceivingDetail::findOrFail($detailId), ConsignmentBuyoutCapacityService::fromMilli($m), null, $actor, $now);
        }
    }

    /** Close transit into destination custody for a receipt allocation. */
    public function onReceipt(TransferMovementAllocation $dispatchAllocation, TransferMovementAllocation $receiptAllocation, User $actor): void
    {
        $this->settleTransit($dispatchAllocation, $receiptAllocation, 'TRANSFER_RECEIPT', (int) $dispatchAllocation->destination_location_id, (int) $dispatchAllocation->destination_setting_id, $actor);
    }

    /** Return transit to source custody for a cancellation allocation. */
    public function onCancellation(TransferMovementAllocation $dispatchAllocation, TransferMovementAllocation $cancelAllocation, User $actor): void
    {
        $this->settleTransit($dispatchAllocation, $cancelAllocation, 'TRANSFER_CANCEL', (int) $dispatchAllocation->source_location_id, (int) $dispatchAllocation->source_setting_id, $actor);
    }

    private function settleTransit(TransferMovementAllocation $dispatch, TransferMovementAllocation $settlement, string $kind, int $toLocation, int $toSetting, User $actor): void
    {
        $events = Event::where('source_type', Event::SOURCE_TRANSFER_ALLOCATION)->where('source_id', $dispatch->id)->where('kind', 'TRANSFER_DISPATCH')->orderBy('id')->get();
        if ($events->isEmpty()) {
            // Ordinary source, or a consignment dispatch made before provenance existed (every post-deploy
            // consignment dispatch has events, enforced in onDispatch). Warehouse settlement is never stranded;
            // the pre-provenance chain is mapped later by preview-first historical reconstruction.
            return;
        }
        $this->lockReceipts($events->pluck('consignment_receiving_detail_id')->all());
        $now = Carbon::now();
        foreach ($events as $e) {
            Event::create([
                'consignment_receiving_detail_id' => $e->consignment_receiving_detail_id,
                'original_setting_id' => $e->original_setting_id,
                'supplier_id' => $e->supplier_id,
                'product_id' => $e->product_id,
                'product_serial_number_id' => $e->product_serial_number_id,
                'kind' => $kind,
                'source_type' => Event::SOURCE_TRANSFER_ALLOCATION,
                'source_id' => $settlement->id,
                'source_event_key' => "tma:{$settlement->id}:" . strtolower($kind) . ":of:{$e->id}",
                'from_state' => Event::STATE_TRANSIT,
                'from_location_id' => $e->to_location_id,
                'from_setting_id' => $e->to_setting_id,
                'to_state' => Event::STATE_SELLABLE,
                'to_location_id' => $toLocation,
                'to_setting_id' => $toSetting,
                'stock_condition' => $e->stock_condition,
                'tax_bucket' => $e->tax_bucket,
                'base_quantity' => $e->base_quantity,
                'actor_id' => $actor->id,
                'effective_at' => $now,
            ]);
        }
    }

    /** Bucket of a non-serialized allocation as applied by the existing poster: TAX, NON_TAX or MIXED. */
    private static function allocationBucket(TransferMovementAllocation $a): string
    {
        $tax = (int) $a->applied_quantity_tax + (int) $a->applied_quantity_broken_tax;
        $nonTax = (int) $a->applied_quantity_non_tax + (int) $a->applied_quantity_broken_non_tax;

        return $tax > 0 && $nonTax > 0 ? 'MIXED' : ($tax > 0 ? 'TAX' : 'NON_TAX');
    }

    private function confirmedFor(Transfer $transfer, TransferMovementAllocation $a): Collection
    {
        return Selection::where('transfer_id', $transfer->id)->where('selection_revision', $this->currentRevision($transfer))
            ->where('status', 'CONFIRMED')->where('product_id', $a->product_id)
            ->where('source_location_id', $a->source_location_id)->where('destination_location_id', $a->destination_location_id)
            ->orderBy('id')->get();
    }

    private function lockReceipts(array $detailIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $detailIds))));
        sort($ids);
        if ($ids) {
            ConsignmentReceivingDetail::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        }
    }

    private function postDispatchEvent(TransferMovementAllocation $a, ConsignmentReceivingDetail $d, $qty, ?ProductSerialNumber $serial, User $actor, Carbon $now): void
    {
        $id = $this->receiptIdentity($d);
        Event::create([
            'consignment_receiving_detail_id' => $d->id,
            'original_setting_id' => $id['original_setting_id'],
            'supplier_id' => $id['supplier_id'],
            'product_id' => $a->product_id,
            'product_serial_number_id' => $serial?->id,
            'kind' => 'TRANSFER_DISPATCH',
            'source_type' => Event::SOURCE_TRANSFER_ALLOCATION,
            'source_id' => $a->id,
            'source_event_key' => "tma:{$a->id}:dispatch:lot:{$d->id}:serial:" . ($serial?->id ?? 0),
            'from_state' => Event::STATE_SELLABLE,
            'from_location_id' => $a->source_location_id,
            'from_setting_id' => $a->source_setting_id,
            'to_state' => Event::STATE_TRANSIT,
            'to_location_id' => $a->destination_location_id,
            'to_setting_id' => $a->destination_setting_id,
            'stock_condition' => $a->stock_condition,
            'tax_bucket' => $serial ? ($serial->tax_id ? 'TAX' : 'NON_TAX') : self::allocationBucket($a),
            'base_quantity' => $qty,
            'actor_id' => $actor->id,
            'effective_at' => $now,
        ]);
    }
}
