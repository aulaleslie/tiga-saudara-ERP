<?php

namespace Modules\Consignment\Services;

use Illuminate\Support\Collection;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldReceiptAttribution;
use Modules\Consignment\Entities\ConsignmentBuyoutReservation;

/**
 * Read-only reconciliation view of the buyout path next to the legacy one. Every figure comes from the shared
 * capacity authority, so the same goods are never shown both as available consignment and as acquired stock, and
 * non-financial attribution stays separate from billing.
 */
class ConsignmentBuyoutReconciliationProjection
{
    public function __construct(protected ?ConsignmentBuyoutCapacityService $capacity = null)
    {
        $this->capacity ??= new ConsignmentBuyoutCapacityService();
    }

    /**
     * @param Collection<int, ConsignmentReceivingDetail> $details
     * @return array<int, array<string, mixed>> keyed by receiving detail id
     */
    public function forDetails(Collection $details): array
    {
        $ids = $details->pluck('id')->all();
        if (! $ids) {
            return [];
        }

        // Bulk, aggregate-only lookups (no per-row lazy loading).
        $attributionCount = ConsignmentSoldReceiptAttribution::whereIn('consignment_receiving_detail_id', $ids)
            ->selectRaw('consignment_receiving_detail_id as id, COUNT(*) as n')->groupBy('consignment_receiving_detail_id')->pluck('n', 'id');
        $lineSums = ConsignmentBuyoutLine::whereIn('consignment_receiving_detail_id', $ids)
            ->whereHas('buyout', fn ($q) => $q->where('status', ConsignmentBuyout::STATUS_FINALIZED))
            ->selectRaw('consignment_receiving_detail_id as id, line_type, SUM(base_quantity) as qty')->groupBy('consignment_receiving_detail_id', 'line_type')->get();
        $reserved = ConsignmentBuyoutReservation::whereIn('consignment_receiving_detail_id', $ids)->where('resource_type', 'RECEIPT')
            ->where('status', ConsignmentBuyoutReservation::STATUS_ACTIVE)->selectRaw('consignment_receiving_detail_id as id, COUNT(DISTINCT consignment_buyout_id) as docs')
            ->groupBy('consignment_receiving_detail_id')->pluck('docs', 'id');

        $rows = [];
        foreach ($details as $d) {
            $cap = $this->capacity->receiptCapacity($d->id);
            $sold = (float) $lineSums->where('id', $d->id)->where('line_type', ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED)->sum('qty');
            $unsold = (float) $lineSums->where('id', $d->id)->where('line_type', ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT)->sum('qty');
            $rows[$d->id] = [
                'received' => $cap['received'],
                'attributed_sold_net' => $cap['attributed_sold_net'],           // non-financial attribution
                'attributions' => (int) ($attributionCount[$d->id] ?? 0),
                'reserved_financial' => $cap['buyout_reserved'],
                'reserved_unsold' => $cap['reserved_unsold'],                   // physical reservations
                'reserved_documents' => (int) ($reserved[$d->id] ?? 0),
                'billed_sold' => $sold,                                          // finalized sold-unbilled
                'acquired_unsold' => $unsold,                                    // finalized, moved into owned stock
                'physical_residual' => $cap['physical_residual'],
                'unsold_available' => $cap['unsold_available'],
                'financial_available' => $cap['financial_available'],
                'conservation_errors' => $cap['conservation_errors'],
                'blockers' => $cap['blockers'],
            ];
        }

        return $rows;
    }

    /**
     * Document-level rows: reservation vs finalized movement, Purchase, paid and due, reversal evidence.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function documents(int $settingId, ?int $supplierId = null): Collection
    {
        $docs = ConsignmentBuyout::forSetting($settingId)
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->with(['purchase:id,reference,total_amount,paid_amount,due_amount,status,payment_status', 'supplier:id,supplier_name'])
            ->withCount([
                'reservations as active_reservations' => fn ($q) => $q->where('status', 'ACTIVE'),
                'reservations as consumed_reservations' => fn ($q) => $q->where('status', 'CONSUMED'),
            ])
            ->latest('id')->limit(100)->get();

        $movements = \DB::table('consignment_buyout_movements')->whereIn('consignment_buyout_id', $docs->pluck('id'))
            ->selectRaw("consignment_buyout_id as id, kind, COUNT(*) as legs")->groupBy('consignment_buyout_id', 'kind')->get()->groupBy('id');

        return $docs->map(function (ConsignmentBuyout $b) use ($movements) {
            $m = $movements->get($b->id, collect());

            return [
                'id' => $b->id,
                'reference' => $b->reference,
                'status' => $b->status,
                'supplier' => $b->supplier?->supplier_name,
                'active_reservations' => (int) $b->active_reservations,
                'consumed_reservations' => (int) $b->consumed_reservations,
                'acquisition_legs' => (int) ($m->firstWhere('kind', 'ACQUISITION')->legs ?? 0),
                'reversal_legs' => (int) ($m->firstWhere('kind', 'REVERSAL')->legs ?? 0),
                'purchase_reference' => $b->purchase?->reference,
                'total' => $b->purchase ? (float) $b->purchase->total_amount : null,
                'paid' => $b->purchase ? (float) $b->purchase->paid_amount : null,
                'due' => $b->purchase ? ($b->purchase->isCancelled() ? 0.0 : (float) $b->purchase->due_amount) : null,
                'cancelled' => (bool) ($b->purchase?->isCancelled()),
                'reversed_at' => $b->reversed_at,
            ];
        });
    }
}
