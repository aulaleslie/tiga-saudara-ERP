<?php

namespace Modules\Consignment\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentHistoricalMapping;
use Modules\Consignment\Entities\ConsignmentProvenanceEvent as Event;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Product\Entities\ProductSerialNumber;

/**
 * Preview-first, operator-confirmed reconstruction of consignment custody for transfers executed before provenance
 * existed (track-transferred-consignment-provenance 7.1). Never runs from a migration, listener or discovery.
 *
 * preview(): read-only. Walks every frozen V3 consignment leg of a product without provenance, oldest first.
 *   serialized legs: exact through the serial receipt pivot.
 *   non-serialized legs: explicit operator quantities, or a SOLE fitting lot as a proposal; otherwise a blocker.
 * confirm(): requires the exact preview evidence hash, a reason and origin reconciliation authority; writes one
 *   immutable mapping revision per touched receipt and the events with their historical timestamps. Event keys equal
 *   the live posting keys, so live and historical posting can never duplicate. Nothing else is changed: no stock,
 *   serial location/status, transfer, sale, payment or bill.
 */
class ConsignmentHistoricalReconstructionService
{
    public function __construct(
        private ConsignmentTransferEvidenceAdapter $adapter = new ConsignmentTransferEvidenceAdapter(),
        private ConsignmentRemoteSaleResolver $resolver = new ConsignmentRemoteSaleResolver(),
    ) {
    }

    /**
     * @param array<int, array<int, float|string>> $choices dispatch_allocation_id => [receipt_id => quantity] (non-serialized)
     * @return array{product_id:int, evidence_hash:string, events: array<int, array>, receipts: int[], blockers: string[], legs: array<int, array>}
     */
    public function preview(int $productId, array $choices = [], ?int $originalSettingId = null): array
    {
        $legs = $this->adapter->legsForProduct($productId)->filter(fn ($l) => ! $l['has_provenance'])->values();
        $events = [];
        $blockers = [];
        $delta = [];   // simulated non-serialized custody moves: "receipt|location" => milli
        $legOut = [];

        foreach ($legs as $leg) {
            $ref = ($leg['document_number'] ?? "transfer #{$leg['transfer_id']}") . " alloc #{$leg['dispatch_allocation_id']}";
            if ($leg['outcome'] === ConsignmentTransferEvidenceAdapter::UNSUPPORTED || $leg['blockers']) {
                $blockers = array_merge($blockers, array_map(fn ($b) => "{$ref}: {$b}", $leg['blockers'] ?: ['unsupported evidence.']));
                continue;
            }
            $dispatchAt = (string) \Modules\Adjustment\Entities\TransferMovementAllocation::whereKey($leg['dispatch_allocation_id'])->value('created_at');
            $lots = [];
            if ($leg['serials']) {
                foreach ($leg['serials'] as $s) {
                    $lots[] = ['receipt_id' => (int) $s['receiving_detail_id'], 'serial_id' => (int) $s['serial_id'], 'quantity' => 1.0];
                }
            } else {
                $chosen = $choices[$leg['dispatch_allocation_id']] ?? null;
                $cands = $this->candidatesAt($productId, (int) $leg['source_location_id'], $dispatchAt, $delta);
                if ($chosen === null) {
                    $fit = array_filter($cands, fn ($m) => $m >= $leg['quantity'] * 1000);
                    if (count($cands) === 1 && count($fit) === 1) {
                        $chosen = [array_key_first($fit) => $leg['quantity']];
                    } else {
                        $blockers[] = "{$ref}: " . count($cands) . ' eligible receipt lot(s) at the source; explicit quantities are required (no FIFO/LIFO).';
                        $legOut[] = $leg + ['candidates' => array_map(fn ($m) => $m / 1000, $cands), 'proposed' => null];
                        continue;
                    }
                }
                $sum = 0;
                foreach ($chosen as $rid => $q) {
                    $m = ConsignmentBuyoutCapacityService::milli($q);
                    if ($m <= 0) {
                        continue;
                    }
                    if (($cands[(int) $rid] ?? 0) < $m) {
                        $blockers[] = "{$ref}: receipt #{$rid} had no sufficient custody at the source when dispatched.";
                    }
                    $lots[] = ['receipt_id' => (int) $rid, 'serial_id' => null, 'quantity' => $m / 1000];
                    $sum += $m;
                }
                if ($sum !== (int) $leg['quantity'] * 1000) {
                    $blockers[] = "{$ref}: chosen lot quantities do not equal the moved quantity {$leg['quantity']}.";
                    continue;
                }
            }

            foreach ($lots as $lot) {
                $d = ConsignmentReceivingDetail::with('receiving.receival')->find($lot['receipt_id']);
                if (! $d || ! $d->receiving || $d->receiving->status !== ConsignmentReceiving::STATUS_APPROVED || (int) $d->product_id !== $productId) {
                    $blockers[] = "{$ref}: receipt #{$lot['receipt_id']} is not an approved receipt of this product.";
                    continue;
                }
                if ($originalSettingId && (int) $d->receiving->setting_id !== $originalSettingId) {
                    continue;   // another original business's lot: shown to its own reconcilers only
                }
                if ($lot['serial_id']) {
                    $custody = $this->resolver->serialCustodyAt(ProductSerialNumber::find($lot['serial_id']), $dispatchAt);
                    $prior = collect($events)->where('serial_id', $lot['serial_id'])->last();
                    $at = $prior ? $prior['to_location_id'] : ($custody['location_id'] ?? null);
                    if ((int) $at !== (int) $leg['source_location_id']) {
                        $blockers[] = "{$ref}: serial #{$lot['serial_id']} custody was not at the source when dispatched.";
                        continue;
                    }
                }
                $base = ['receipt_id' => (int) $d->id, 'original_setting_id' => (int) $d->receiving->setting_id, 'supplier_id' => (int) $d->receiving->receival->supplier_id,
                    'serial_id' => $lot['serial_id'], 'quantity' => $lot['quantity'], 'stock_condition' => $leg['stock_condition']];
                $events[] = $base + ['kind' => 'TRANSFER_DISPATCH', 'allocation_id' => $leg['dispatch_allocation_id'],
                    'key' => "tma:{$leg['dispatch_allocation_id']}:dispatch:lot:{$d->id}:serial:" . ($lot['serial_id'] ?? 0),
                    'from_state' => 'SELLABLE', 'from_location_id' => $leg['source_location_id'], 'from_setting_id' => $leg['source_setting_id'],
                    'to_state' => 'TRANSIT', 'to_location_id' => $leg['destination_location_id'], 'to_setting_id' => $leg['destination_setting_id'], 'at' => $dispatchAt];
                if (in_array($leg['outcome'], ['RECEIPT', 'CANCELLATION'], true)) {
                    $toLoc = $leg['outcome'] === 'RECEIPT' ? $leg['destination_location_id'] : $leg['source_location_id'];
                    $toSet = $leg['outcome'] === 'RECEIPT' ? $leg['destination_setting_id'] : $leg['source_setting_id'];
                    $kind = $leg['outcome'] === 'RECEIPT' ? 'TRANSFER_RECEIPT' : 'TRANSFER_CANCEL';
                    $events[] = $base + ['kind' => $kind, 'allocation_id' => $leg['settled_allocation_id'],
                        'key' => "hist:tma:{$leg['settled_allocation_id']}:" . strtolower($kind) . ":lot:{$d->id}:serial:" . ($lot['serial_id'] ?? 0),
                        'from_state' => 'TRANSIT', 'from_location_id' => $leg['destination_location_id'], 'from_setting_id' => $leg['destination_setting_id'],
                        'to_state' => 'SELLABLE', 'to_location_id' => $toLoc, 'to_setting_id' => $toSet, 'at' => $leg['effective_at']];
                    if (! $lot['serial_id']) {
                        $delta[$d->id . '|' . $leg['source_location_id']] = ($delta[$d->id . '|' . $leg['source_location_id']] ?? 0) - (int) round($lot['quantity'] * 1000);
                        $delta[$d->id . '|' . $toLoc] = ($delta[$d->id . '|' . $toLoc] ?? 0) + (int) round($lot['quantity'] * 1000);
                    }
                }
            }
            $legOut[] = $leg + ['proposed' => $lots];
        }

        $receipts = array_values(array_unique(array_column($events, 'receipt_id')));
        sort($receipts);
        $hash = hash('sha256', json_encode(['product' => $productId, 'origin' => $originalSettingId,
            'events' => array_map(fn ($e) => array_intersect_key($e, array_flip(['key', 'receipt_id', 'serial_id', 'quantity', 'from_location_id', 'to_location_id', 'at'])), $events)]));

        return ['product_id' => $productId, 'evidence_hash' => $hash, 'events' => $events, 'receipts' => $receipts,
            'blockers' => array_values(array_unique($blockers)), 'legs' => $legOut];
    }

    /**
     * Confirm exactly the previewed mapping. Unresolved legs do not block resolvable ones: they simply stay
     * unmapped (and keep blocking dependent consignment actions) until a later confirmation.
     *
     * @return ConsignmentHistoricalMapping[]
     */
    public function confirm(int $productId, string $expectedHash, string $reason, User $actor, array $choices = [], ?int $originalSettingId = null): array
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reconstruction reason is required.');
        }
        if ($originalSettingId) {
            app(ConsignmentProvenanceAuthorizer::class)->authorizeOriginReconciliation($actor, $originalSettingId);
        }

        return DB::transaction(function () use ($productId, $expectedHash, $reason, $actor, $choices, $originalSettingId) {
            \Modules\Product\Entities\Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            // Repeating an already-applied confirmation is a no-op that returns the stored revisions - but only within the
            // requested origin scope and only to an actor who may reconcile every origin it would disclose.
            $applied = ConsignmentHistoricalMapping::where('evidence_hash', $expectedHash)
                ->whereIn('consignment_receiving_detail_id', ConsignmentReceivingDetail::where('product_id', $productId)->select('id'))
                ->when($originalSettingId, fn ($q) => $q->where('original_setting_id', $originalSettingId))
                ->get();
            if ($applied->isNotEmpty()) {
                foreach ($applied->pluck('original_setting_id')->unique() as $origin) {
                    app(ConsignmentProvenanceAuthorizer::class)->authorizeOriginReconciliation($actor, (int) $origin);
                }

                return $applied->all();
            }
            $p = $this->preview($productId, $choices, $originalSettingId);
            if (! hash_equals($p['evidence_hash'], $expectedHash)) {
                throw new \DomainException('The evidence changed since the preview; preview again before confirming.');
            }
            $origins = collect($p['events'])->pluck('original_setting_id')->unique()->all();
            foreach ($origins as $origin) {
                app(ConsignmentProvenanceAuthorizer::class)->authorizeOriginReconciliation($actor, (int) $origin);
            }

            $mappings = [];
            foreach ($p['receipts'] as $receiptId) {
                $mine = array_values(array_filter($p['events'], fn ($e) => $e['receipt_id'] === $receiptId));
                $existing = ConsignmentHistoricalMapping::where('consignment_receiving_detail_id', $receiptId)->where('evidence_hash', $p['evidence_hash'])->first();
                if ($existing) {
                    $mappings[] = $existing;
                    continue;
                }
                $mapping = ConsignmentHistoricalMapping::create([
                    'original_setting_id' => $mine[0]['original_setting_id'],
                    'consignment_receiving_detail_id' => $receiptId,
                    'revision' => (int) ConsignmentHistoricalMapping::where('consignment_receiving_detail_id', $receiptId)->max('revision') + 1,
                    'evidence_hash' => $p['evidence_hash'],
                    'mapping' => array_map(fn ($e) => array_diff_key($e, ['original_setting_id' => 1, 'supplier_id' => 1]), $mine),
                    'source_references' => array_values(array_unique(array_column($mine, 'allocation_id'))),
                    'unresolved_blockers' => $p['blockers'],
                    'status' => 'CONFIRMED',
                    'reason' => $reason,
                    'confirmed_by' => $actor->id,
                    'confirmed_at' => Carbon::now(),
                ]);
                foreach ($mine as $e) {
                    if (Event::where('source_event_key', $e['key'])->exists()) {
                        continue;
                    }
                    Event::create([
                        'consignment_receiving_detail_id' => $e['receipt_id'], 'original_setting_id' => $e['original_setting_id'], 'supplier_id' => $e['supplier_id'],
                        'product_id' => $productId, 'product_serial_number_id' => $e['serial_id'], 'kind' => $e['kind'],
                        'source_type' => Event::SOURCE_TRANSFER_ALLOCATION, 'source_id' => (int) $e['allocation_id'], 'source_event_key' => $e['key'],
                        'from_state' => $e['from_state'], 'from_location_id' => $e['from_location_id'], 'from_setting_id' => $e['from_setting_id'],
                        'to_state' => $e['to_state'], 'to_location_id' => $e['to_location_id'], 'to_setting_id' => $e['to_setting_id'],
                        'stock_condition' => $e['stock_condition'], 'base_quantity' => $e['quantity'], 'historical_mapping_id' => $mapping->id,
                        'actor_id' => $actor->id, 'effective_at' => $e['at'],
                    ]);
                }
                $mappings[] = $mapping;
            }

            return $mappings;
        });
    }

    /** Non-serialized receipt custody (milli) per receipt at a location as of a moment, including simulated moves. */
    private function candidatesAt(int $productId, int $locationId, ?string $at, array $delta): array
    {
        $out = [];
        $ids = app(ConsignmentTransferProvenanceService::class)->receiptsReachable($productId, $locationId)->pluck('id')
            ->merge(collect(array_keys($delta))->filter(fn ($k) => str_ends_with($k, '|' . $locationId))->map(fn ($k) => (int) explode('|', $k)[0]))->unique();
        foreach (ConsignmentReceivingDetail::with('receiving')->whereIn('id', $ids)->get() as $d) {
            if (! $d->receiving || $d->receiving->status !== ConsignmentReceiving::STATUS_APPROVED) {
                continue;
            }
            $m = $this->resolver->lotCustodyAt($d, $locationId, $at) + ($delta[$d->id . '|' . $locationId] ?? 0);
            if ($m > 0) {
                $out[(int) $d->id] = $m;
            }
        }

        return $out;
    }
}
