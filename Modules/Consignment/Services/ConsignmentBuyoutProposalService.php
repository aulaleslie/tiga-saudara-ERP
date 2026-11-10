<?php

namespace Modules\Consignment\Services;

use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Entities\ConsignmentSoldSourceSerial;

/**
 * READ-ONLY "buy all eligible" preview for one supplier and one operator-chosen destination.
 *
 * It never writes, reserves, or authorizes anything, and it never substitutes suppliers, receipts, locations or
 * quantities: every receipt/sold source of the supplier appears exactly once, either INCLUDED with the exact quantity,
 * serials and physical buckets proposed, or EXCLUDED with every reason. A quantity clipped by physical stock shows both
 * the requested and the proposed amount. Rows are editable in the review and are fully re-validated by the normal
 * draft/approval services, so this class only proposes - the lifecycle remains the authority.
 *
 * Shared physical pools (several receipts of one product/location) are consumed in receipt-id order and the running
 * remainder is visible in each row, so two receipts can never both be proposed the same physical goods.
 */
class ConsignmentBuyoutProposalService
{
    public function __construct(private ?ConsignmentBuyoutCapacityService $capacity = null)
    {
        $this->capacity ??= new ConsignmentBuyoutCapacityService();
    }

    /**
     * @return array{unsold: array<int, array>, sold: array<int, array>, unattributed: array<int, array>, totals: array<string, float>}
     */
    public function allEligible(int $settingId, int $supplierId, int $destinationLocationId): array
    {
        $details = ConsignmentReceivingDetail::query()
            ->whereHas('receiving', fn ($q) => $q->where('setting_id', $settingId)->where('status', ConsignmentReceiving::STATUS_APPROVED)
                ->whereHas('receival', fn ($r) => $r->where('supplier_id', $supplierId)))
            ->with(['receiving:id,receiving_number,location_id', 'receiving.location:id,name', 'product:id,product_name,serial_number_required'])
            ->orderBy('id')->get();

        // Sold sources in the affected pools: an unattributed sale blocks buying unsold stock of that pool.
        $unattributedPools = [];
        $unattributed = [];
        $sold = [];
        $sourceLeft = [];
        $pools = $details->map(fn ($d) => [(int) $d->product_id, (int) $d->receiving->location_id])->unique(fn ($p) => $p[0] . ':' . $p[1]);
        foreach ($pools as [$productId, $locationId]) {
            foreach (ConsignmentSoldSource::forSetting($settingId)->where('product_id', $productId)->where('location_id', $locationId)->orderBy('id')->get() as $src) {
                $att = $this->capacity->soldAttribution($src);
                if (! $att['complete']) {
                    $unattributedPools[$productId . ':' . $locationId] = true;
                    $unattributed[] = ['source_id' => $src->id, 'reasons' => $att['blockers']];
                    continue;
                }
                $cap = $this->capacity->soldSourceCapacity($src);
                $sourceLeft[$src->id] ??= (float) $cap['available'];
                $claimed = ConsignmentActiveSerialClaim::query()->select('product_serial_number_id');
                foreach ($att['per_receipt'] as $detailId => $attributed) {
                    $detail = $details->firstWhere('id', (int) $detailId);
                    if (! $detail) {
                        continue;            // attributed to another supplier's receipt: not part of this document
                    }
                    $reasons = array_merge($cap['blockers'] ?? [], $cap['conservation_errors'] ?? []);
                    // Receipt-specific residual, further limited by what earlier receipts of this proposal already take.
                    $residual = $this->capacity->soldReceiptResidual($src, (int) $detailId);
                    $qty = min($residual, $sourceLeft[$src->id]);
                    $serialIds = [];
                    if ($detail->product && $detail->product->serial_number_required && $qty > 0) {
                        $soldIds = ConsignmentSoldSourceSerial::where('consignment_sold_source_id', $src->id)
                            ->whereHas('serialNumber', fn ($q) => $q->where('consignment_receiving_detail_id', (int) $detailId)
                                ->whereNotIn('id', $claimed))
                            ->orderBy('product_serial_number_id')->pluck('product_serial_number_id')->map(fn ($x) => (int) $x)->all();
                        $returned = $src->dispatch_detail_id
                            ? (new ConsignmentReturnEligibilityService())->getEffectiveReturnedSerialIds($src->dispatch_detail_id) : [];
                        $serialIds = array_slice(array_values(array_diff($soldIds, $returned)), 0, (int) floor($qty));
                        $qty = (float) count($serialIds);
                    }
                    if (! $reasons) {
                        $sourceLeft[$src->id] = round($sourceLeft[$src->id] - $qty, 3);
                    }
                    if ($qty <= 0 && ! $reasons) {
                        $reasons[] = 'Tidak ada kuantitas terjual yang tersedia untuk ditagih.';
                    }
                    $sold[] = $this->row('SOLD_UNBILLED', $detail, (float) $attributed, $reasons ? 0.0 : $qty, $reasons, [
                        'consignment_sold_source_id' => $src->id, 'sale_id' => $src->sale_id, 'serial_ids' => $reasons ? [] : $serialIds,
                    ], (float) $cap['available']);
                }
            }
        }

        // Unsold rows: per receipt, sharing the physical pool remainder deterministically.
        $remaining = [];
        $unsold = [];
        foreach ($details as $d) {
            $productId = (int) $d->product_id;
            $locationId = (int) $d->receiving->location_id;
            $key = $productId . ':' . $locationId;
            $cap = $this->capacity->receiptCapacity($d->id);
            $pool = $this->capacity->physicalPool($settingId, $productId, $locationId);
            $remaining[$key] ??= ['taxed' => (float) $pool['bucket_available']['taxed'], 'non_taxed' => (float) $pool['bucket_available']['non_taxed']];

            $requested = (float) $cap['unsold_available'];
            $reasons = array_values(array_unique(array_map(fn ($b) => is_array($b) ? (string) ($b['reason'] ?? json_encode($b)) : (string) $b,
                array_merge($cap['conservation_errors'], $cap['blockers'], $pool['blockers']))));
            if (isset($unattributedPools[$key])) {
                $reasons[] = 'Ada penjualan di pool ini yang belum diatribusikan ke penerimaan.';
            }
            if ($requested <= 0 && ! $reasons) {
                $reasons[] = 'Tidak ada stok belum terjual yang tersedia.';
            }

            $extra = ['buckets' => ['TAXED' => 0.0, 'NON_TAXED' => 0.0], 'serial_ids' => [], 'pool_remaining_before' => $remaining[$key]];
            $proposed = 0.0;
            if (! $reasons) {
                $serialized = $d->product && $d->product->serial_number_required;
                $cap2 = $serialized ? count($this->capacity->selectableSerialIds($productId, $locationId, $d->id)) : INF;
                $proposed = min($requested, $remaining[$key]['taxed'] + $remaining[$key]['non_taxed'], (float) $cap2);
                if ($serialized) {
                    $proposed = (float) floor($proposed);
                    $extra['serial_ids'] = array_slice($this->capacity->selectableSerialIds($productId, $locationId, $d->id), 0, (int) $proposed);
                }
                // Buckets are consumed in a fixed, visible order (non-taxed first, then taxed); the operator may change them.
                $nonTax = min($proposed, $remaining[$key]['non_taxed']);
                $tax = round($proposed - $nonTax, 3);
                $extra['buckets'] = ['TAXED' => $tax, 'NON_TAXED' => $nonTax];
                $remaining[$key]['non_taxed'] = round($remaining[$key]['non_taxed'] - $nonTax, 3);
                $remaining[$key]['taxed'] = round($remaining[$key]['taxed'] - $tax, 3);
                if ($proposed + 0.0004 < $requested) {
                    $reasons[] = 'Dibatasi stok fisik/serial yang tersedia: kapasitas ' . $requested . ', diusulkan ' . $proposed . '.';
                }
                if ($proposed <= 0) {
                    $reasons[] = 'Stok fisik yang tersedia sudah dialokasikan penerimaan lain atau habis.';
                }
            }
            $unsold[] = $this->row('UNSOLD_BUYOUT', $d, $requested, $proposed, $reasons, $extra, $requested);
        }

        $sum = fn (array $rows) => round(array_sum(array_map(fn ($r) => $r['included'] ? $r['proposed'] : 0.0, $rows)), 3);

        return [
            'unsold' => $unsold, 'sold' => $sold, 'unattributed' => $unattributed,
            'totals' => ['unsold' => $sum($unsold), 'sold' => $sum($sold)],
        ];
    }

    private function row(string $type, ConsignmentReceivingDetail $d, float $requested, float $proposed, array $reasons, array $extra, float $available): array
    {
        // A row is INCLUDED when a positive quantity is proposed; clipping notes are informational, other reasons exclude it.
        $clipOnly = $proposed > 0 && count($reasons) === 1 && str_starts_with($reasons[0], 'Dibatasi');
        $included = $proposed > 0 && (! $reasons || $clipOnly);

        return [
            'line_type' => $type,
            'consignment_receiving_detail_id' => (int) $d->id,
            'receiving_number' => $d->receiving->receiving_number,
            'location' => $d->receiving->location->name ?? '-',
            'product' => $d->product->product_name ?? '-',
            'serialized' => (bool) ($d->product && $d->product->serial_number_required),
            'requested' => $requested,
            'available' => $available,
            'proposed' => $included ? $proposed : 0.0,
            'included' => $included,
            'reasons' => $reasons,
        ] + $extra;
    }
}
