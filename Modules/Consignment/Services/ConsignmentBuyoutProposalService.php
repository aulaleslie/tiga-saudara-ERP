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
    /**
     * @param array<int,int> $remoteDestinations holding setting_id => standard destination location_id for unsold
     *                                           receipt custody held by other businesses (transfer provenance)
     * @param bool $deferDestinations preparation screen: destinations are chosen later in the same form, so a missing
     *                                remote destination is not a blocker here (the lifecycle still requires one)
     *
     * Every row also carries `max` (selectable ceiling independent of proposal ordering), `serial_options` (every
     * eligible serial id, not only the proposed slice) and `blocked_reasons` (hard blockers only, without the
     * informational clip / pool-ordering notes).
     */
    public function allEligible(int $settingId, int $supplierId, ?int $destinationLocationId, array $remoteDestinations = [], bool $deferDestinations = false): array
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
        // Remote envelopes verifiably bound to this business's receipts of this supplier are sold candidates too.
        $boundSources = \Modules\Consignment\Entities\ConsignmentObligationBinding::where('original_setting_id', $settingId)
            ->where('supplier_id', $supplierId)->where('status', 'ACTIVE')->pluck('consignment_sold_source_id')->unique()->all();
        $poolSources = [];
        foreach ($pools as [$productId, $locationId]) {
            $poolSources[] = [$productId, $locationId, ConsignmentSoldSource::forSetting($settingId)->where('product_id', $productId)->where('location_id', $locationId)->orderBy('id')->get()];
        }
        $remoteSources = ConsignmentSoldSource::whereIn('id', $boundSources)->where('setting_id', '!=', $settingId)->orderBy('id')->get();
        if ($remoteSources->isNotEmpty()) {
            $poolSources[] = [null, null, $remoteSources];
        }
        foreach ($poolSources as [$productId, $locationId, $poolList]) {
            foreach ($poolList as $src) {
                $productId ??= (int) $src->product_id;
                $att = $this->capacity->soldAttribution($src);
                if (! $att['complete']) {
                    $unattributedPools[$src->product_id . ':' . $src->location_id] = true;
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
                    $max = min($residual, max(0.0, (float) $cap['available']));
                    $serialIds = [];
                    $serialOptions = [];
                    if ($detail->product && $detail->product->serial_number_required && $max > 0) {
                        $soldIds = ConsignmentSoldSourceSerial::where('consignment_sold_source_id', $src->id)
                            ->whereHas('serialNumber', fn ($q) => $q->where('consignment_receiving_detail_id', (int) $detailId)
                                ->whereNotIn('id', $claimed))
                            ->orderBy('product_serial_number_id')->pluck('product_serial_number_id')->map(fn ($x) => (int) $x)->all();
                        $returned = $src->dispatch_detail_id
                            ? $this->capacity->returnedSerialIds((int) $src->dispatch_detail_id) : [];
                        $serialOptions = array_values(array_diff($soldIds, $returned));
                        $max = min($max, (float) count($serialOptions));
                        $serialIds = $qty > 0 ? array_slice($serialOptions, 0, (int) floor($qty)) : [];
                        $qty = (float) count($serialIds);
                    }
                    $hard = $reasons;
                    if ($max <= 0 && ! $hard) {
                        $hard[] = 'Tidak ada kuantitas terjual yang tersedia untuk ditagih.';
                    }
                    if (! $reasons) {
                        $sourceLeft[$src->id] = round($sourceLeft[$src->id] - $qty, 3);
                    }
                    if ($qty <= 0 && ! $reasons) {
                        $reasons[] = 'Tidak ada kuantitas terjual yang tersedia untuk ditagih.';
                    }
                    $sold[] = $this->row('SOLD_UNBILLED', $detail, (float) $attributed, $reasons ? 0.0 : $qty, $reasons, [
                        'consignment_sold_source_id' => $src->id, 'sale_id' => $src->sale_id, 'serial_ids' => $reasons ? [] : $serialIds,
                        'serial_options' => $hard ? [] : $serialOptions, 'max' => $hard ? 0.0 : $max, 'blocked_reasons' => $hard,
                        'source_location_id' => (int) $src->location_id, 'inventory_owner_setting_id' => (int) $src->setting_id,
                    ], (float) $cap['available']);
                }
            }
        }

        // Unsold rows: per receipt, sharing the physical pool remainder deterministically.
        $remaining = [];
        $unsold = [];
        $prov = app(ConsignmentTransferProvenanceService::class);
        $custodyRows = [];
        foreach ($details as $d) {
            if (! $this->capacity->hasCustodyEvents((int) $d->id)) {
                $custodyRows[] = [$d, (int) $d->receiving->location_id];   // legacy: exactly the receipt location
                continue;
            }
            foreach ($prov->project($d)['custody'] as $c) {
                // One row per receipt and location: several custody entries at one location are the same goods.
                if ($c['state'] === \Modules\Consignment\Entities\ConsignmentProvenanceEvent::STATE_SELLABLE && $c['location_id']) {
                    $custodyRows[$d->id . ':' . (int) $c['location_id']] = [$d, (int) $c['location_id']];
                }
            }
        }
        foreach ($custodyRows as [$d, $locationId]) {
            $productId = (int) $d->product_id;
            $key = $productId . ':' . $locationId;
            $locSetting = (int) (\Modules\Setting\Entities\Location::whereKey($locationId)->value('setting_id') ?? $settingId);
            $remote = $locSetting !== $settingId;
            $cap = $this->capacity->receiptCapacity($d->id);
            $pool = $this->capacity->physicalPool($locSetting, $productId, $locationId);
            $remaining[$key] ??= ['taxed' => (float) $pool['bucket_available']['taxed'], 'non_taxed' => (float) $pool['bucket_available']['non_taxed']];

            $requested = (float) ($pool['receipts'][$d->id]['location_unsold_available'] ?? $cap['unsold_available']);
            $reasons = array_values(array_unique(array_map(fn ($b) => is_array($b) ? (string) ($b['reason'] ?? json_encode($b)) : (string) $b,
                array_merge($cap['conservation_errors'], $cap['blockers'], $pool['blockers']))));
            if (isset($unattributedPools[$key])) {
                $reasons[] = 'Ada penjualan di pool ini yang belum diatribusikan ke penerimaan.';
            }
            if ($requested <= 0 && ! $reasons) {
                $reasons[] = 'Tidak ada stok belum terjual yang tersedia.';
            }
            $destination = $remote ? ($remoteDestinations[$locSetting] ?? null) : $destinationLocationId;
            if ($remote && ! $destination && ! $deferDestinations) {
                $reasons[] = 'Pilih lokasi tujuan standar milik bisnis yang memegang barang.';
            }

            $extra = ['buckets' => ['TAXED' => 0.0, 'NON_TAXED' => 0.0], 'serial_ids' => [], 'pool_remaining_before' => $remaining[$key], 'pool_buckets' => $pool['bucket_available'],
                'source_location_id' => $locationId, 'destination_location_id' => $destination,
                'inventory_owner_setting_id' => $locSetting, 'remote_custody' => $remote,
                'location' => \Modules\Setting\Entities\Location::whereKey($locationId)->value('name') ?? '-'];
            $proposed = 0.0;
            $extra['blocked_reasons'] = $reasons;
            $extra['max'] = 0.0;
            $extra['serial_options'] = [];
            if (! $reasons) {
                $serialOptions = $d->product && $d->product->serial_number_required
                    ? $this->capacity->selectableSerialIds($productId, $locationId, $d->id) : null;
                $poolTotal = (float) $pool['bucket_available']['taxed'] + (float) $pool['bucket_available']['non_taxed'];
                $extra['max'] = (float) min($requested, $poolTotal, $serialOptions === null ? INF : count($serialOptions));
                $extra['serial_options'] = $serialOptions ?? [];
                if ($serialOptions !== null && ! $serialOptions) {
                    $extra['blocked_reasons'][] = 'Tidak ada serial yang dapat dipilih di lokasi ini.';
                } elseif ($extra['max'] <= 0) {
                    $extra['blocked_reasons'][] = 'Stok fisik yang tersedia sudah dialokasikan penerimaan lain atau habis.';
                }
            }
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
        $location = $extra['location'] ?? ($d->receiving->location->name ?? '-');
        unset($extra['location']);
        // A row is INCLUDED when a positive quantity is proposed; clipping notes are informational, other reasons exclude it.
        $clipOnly = $proposed > 0 && count($reasons) === 1 && str_starts_with($reasons[0], 'Dibatasi');
        $included = $proposed > 0 && (! $reasons || $clipOnly);

        return [
            'line_type' => $type,
            'consignment_receiving_detail_id' => (int) $d->id,
            'receiving_number' => $d->receiving->receiving_number,
            'location' => $location,
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
