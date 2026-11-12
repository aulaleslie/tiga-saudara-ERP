<?php

namespace Modules\Consignment\Services;

use Illuminate\Support\Collection;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovementAllocation;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Consignment\Entities\ConsignmentProvenanceEvent as Event;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;

/**
 * Version-specific, read-only evidence for consignment transfer movements (task 2.5).
 *
 * V3: frozen TransferMovementAllocation rows (dispatch -> receipt/cancellation) and TransferMovementSerial rows.
 * Serialized legs are exact through the serial's receipt pivot; non-serialized legs carry quantities only and
 * need an explicit or sole-lot confirmed mapping. V1/V2 consignment movements are reported unsupported:
 * they are never guessed into V3 shape. Writes nothing.
 */
class ConsignmentTransferEvidenceAdapter
{
    public const UNSUPPORTED = 'UNSUPPORTED_WORKFLOW_VERSION';

    /**
     * @return array<int, array{
     *   transfer_id:int, document_number:?string, workflow_version:int, dispatch_allocation_id:?int,
     *   product_id:int, source_location_id:?int, source_setting_id:?int, destination_location_id:?int, destination_setting_id:?int,
     *   quantity:int, stock_condition:?string, outcome:string, settled_allocation_id:?int, effective_at:?string,
     *   serials: array<int, array{serial_id:int, receiving_detail_id:?int}>, has_provenance:bool, blockers:string[]
     * }>
     */
    public function consignmentLegs(Transfer $transfer): array
    {
        if ((int) $transfer->workflow_version !== Transfer::WORKFLOW_V3) {
            $origin = Location::find($transfer->origin_location_id);
            if ($origin && $origin->is_consignment) {
                return [[
                    'transfer_id' => (int) $transfer->id, 'document_number' => $transfer->document_number,
                    'workflow_version' => (int) $transfer->workflow_version, 'dispatch_allocation_id' => null,
                    'product_id' => 0, 'source_location_id' => (int) $origin->id, 'source_setting_id' => (int) $origin->setting_id,
                    'destination_location_id' => $transfer->destination_location_id ? (int) $transfer->destination_location_id : null,
                    'destination_setting_id' => null, 'quantity' => 0, 'stock_condition' => $transfer->stock_condition,
                    'outcome' => self::UNSUPPORTED, 'settled_allocation_id' => null, 'effective_at' => null,
                    'serials' => [], 'has_provenance' => false,
                    'blockers' => ['Transfer versi ' . $transfer->workflow_version . ' dari lokasi konsinyasi tidak memiliki adaptor bukti; rekonsiliasi manual diperlukan.'],
                ]];
            }

            return [];
        }

        $dispatches = TransferMovementAllocation::where('transfer_id', $transfer->id)->where('kind', TransferMovementAllocation::KIND_DISPATCH)->orderBy('id')->get();
        $consignmentSources = Location::whereIn('id', $dispatches->pluck('source_location_id')->unique()->all())->where('is_consignment', true)->pluck('id')->all();
        $settlements = TransferMovementAllocation::whereIn('dispatch_allocation_id', $dispatches->pluck('id'))->get()->keyBy('dispatch_allocation_id');

        $legs = [];
        foreach ($dispatches->whereIn('source_location_id', $consignmentSources) as $a) {
            $settled = $settlements->get($a->id);
            $serials = $this->serials($a);
            $blockers = [];
            foreach ($serials as $s) {
                if (! $s['receiving_detail_id']) {
                    $blockers[] = "Nomor seri #{$s['serial_id']} tidak memiliki asal penerimaan konsinyasi.";
                }
            }
            $legs[] = [
                'transfer_id' => (int) $transfer->id,
                'document_number' => $transfer->document_number,
                'workflow_version' => Transfer::WORKFLOW_V3,
                'dispatch_allocation_id' => (int) $a->id,
                'product_id' => (int) $a->product_id,
                'source_location_id' => (int) $a->source_location_id,
                'source_setting_id' => (int) $a->source_setting_id,
                'destination_location_id' => (int) $a->destination_location_id,
                'destination_setting_id' => (int) $a->destination_setting_id,
                'quantity' => (int) $a->quantity,
                'stock_condition' => $a->stock_condition,
                'outcome' => $settled ? $settled->kind : 'IN_TRANSIT',
                'settled_allocation_id' => $settled ? (int) $settled->id : null,
                'effective_at' => optional($settled?->created_at ?? $a->created_at)->toDateTimeString(),
                'serials' => $serials,
                'has_provenance' => Event::where('source_type', Event::SOURCE_TRANSFER_ALLOCATION)->where('source_id', $a->id)->exists(),
                'blockers' => $blockers,
            ];
        }

        return $legs;
    }

    /** All V3 consignment legs touching a product, oldest first (for historical preview). */
    public function legsForProduct(int $productId): Collection
    {
        $transferIds = TransferMovementAllocation::where('product_id', $productId)->where('kind', TransferMovementAllocation::KIND_DISPATCH)
            ->whereIn('source_location_id', Location::where('is_consignment', true)->pluck('id'))
            ->distinct()->pluck('transfer_id');

        return Transfer::whereIn('id', $transferIds)->orderBy('id')->get()
            ->flatMap(fn (Transfer $t) => $this->consignmentLegs($t))
            ->where('product_id', $productId)
            ->sortBy(fn ($l) => [$l['effective_at'], $l['dispatch_allocation_id']])->values();
    }

    private function serials(TransferMovementAllocation $a): array
    {
        $rows = TransferMovementSerial::where('transfer_movement_allocation_id', $a->id)->orderBy('id')->get();
        $pivots = ProductSerialNumber::whereIn('id', $rows->pluck('product_serial_number_id'))->pluck('consignment_receiving_detail_id', 'id');

        return $rows->map(fn ($r) => [
            'serial_id' => (int) $r->product_serial_number_id,
            'receiving_detail_id' => $pivots[$r->product_serial_number_id] ? (int) $pivots[$r->product_serial_number_id] : null,
        ])->all();
    }
}
