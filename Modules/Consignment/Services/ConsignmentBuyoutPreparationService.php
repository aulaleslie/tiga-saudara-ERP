<?php

namespace Modules\Consignment\Services;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

/**
 * READ-ONLY read model for the three-step buyout preparation screen.
 *
 * It reuses the "all eligible" proposal (the single source of eligibility, serial and blocker rules) with destinations
 * deferred to the same form, and reshapes it into one list grouped by product and receipt. Every row keeps its exact
 * receipt / sold source / source location / owner identity as hidden line data; nothing is substituted. Rows that
 * cannot be bought are returned separately with plain explanations and an action, never dropped.
 *
 * Must be called inside ConsignmentBuyoutCapacityService::readOnlyEvidence() so shared evidence is computed once.
 */
class ConsignmentBuyoutPreparationService
{
    public function __construct(private ConsignmentBuyoutCapacityService $capacity)
    {
    }

    public function prepare(int $settingId, int $supplierId, User $actor): array
    {
        $proposal = (new ConsignmentBuyoutProposalService($this->capacity))->allEligible($settingId, $supplierId, null, [], true);

        $raw = array_merge($proposal['unsold'], $proposal['sold']);
        $details = ConsignmentReceivingDetail::whereIn('id', array_unique(array_column($raw, 'consignment_receiving_detail_id')))
            ->with(['product:id,product_name,product_code'])->get()->keyBy('id');
        $sources = ConsignmentSoldSource::whereIn('id', array_merge(
            array_filter(array_column($raw, 'consignment_sold_source_id')), array_column($proposal['unattributed'], 'source_id'),
        ))->with('product:id,product_name,product_code')->get()->keyBy('id');
        $sales = Sale::whereIn('id', $sources->pluck('sale_id')->filter()->unique())->pluck('reference', 'id');
        $documents = $this->soldDocuments($sources, $sales, $settingId, $actor);
        $serialLabels = ProductSerialNumber::whereIn('id', collect($raw)->flatMap(fn ($r) => $r['serial_options'] ?? [])->unique()->values())
            ->pluck('serial_number', 'id');
        $locationIds = collect($raw)->pluck('source_location_id')->merge($sources->pluck('location_id'))->filter()->unique();
        $locations = Location::whereIn('id', $locationIds)->get(['id', 'name', 'setting_id'])->keyBy('id');

        $ownerIds = collect($raw)->pluck('inventory_owner_setting_id')->filter()->push($settingId)->unique();
        $businessNames = Setting::whereIn('id', $ownerIds)->pluck('company_name', 'id');

        $mayRemote = Gate::forUser($actor)->allows('consignments.buyouts.acquire_remote');
        $authorizer = app(ConsignmentProvenanceAuthorizer::class);
        $remoteAccess = [];

        $rows = [];
        $blocked = [];
        $settled = [];
        $emptyStock = [];
        $seenSerials = [];
        $pools = [];
        $soldLimits = [];
        $holdingIds = [];
        $u = 0;
        $s = 0;
        foreach ($raw as $r) {
            $detail = $details->get($r['consignment_receiving_detail_id']);
            $sold = $r['line_type'] === 'SOLD_UNBILLED';
            $source = $sold ? $sources->get($r['consignment_sold_source_id']) : null;
            $ownerId = (int) ($r['inventory_owner_setting_id'] ?? $settingId);
            $remote = ! $sold && $ownerId !== $settingId;
            $reasons = $r['blocked_reasons'] ?? $r['reasons'];

            if ($remote && ! $reasons) {
                $remoteAccess[$ownerId] ??= $mayRemote && $authorizer->canAccessBusiness($actor, $ownerId);
                if (! $remoteAccess[$ownerId]) {
                    $reasons[] = 'REMOTE_ACCESS';
                }
            }

            $receivedQty = (float) ($detail->received_base_quantity ?? $detail->quantity_received ?? 0);
            $base = [
                'kind' => $sold ? 'sold' : 'unsold',
                'product_id' => (int) ($detail->product_id ?? 0),
                'product' => $detail->product->product_name ?? $r['product'],
                'product_code' => $detail->product->product_code ?? null,
                'receipt' => $r['receiving_number'],
                'receipt_detail_id' => (int) $r['consignment_receiving_detail_id'],
                'location' => $sold ? ($locations[$source->location_id ?? 0]->name ?? '-') : ($r['location'] ?? '-'),
                'owner_setting_id' => $ownerId,
                'owner' => $businessNames[$ownerId] ?? '-',
                'remote' => $remote,
                'sale_reference' => $source ? ($sales[$source->sale_id] ?? null) : null,
                'document' => $source ? ($documents[$source->id] ?? null) : null,
                'serialized' => (bool) $r['serialized'],
            ];

            // A sale already fully billed / reserved elsewhere is not a blocker: it is simply not awaiting a bill.
            if ($sold && $reasons === ['Tidak ada kuantitas terjual yang tersedia untuk ditagih.'] && (float) $r['available'] <= 0) {
                $settled[] = $base;
                continue;
            }

            // Zero stock alone is informational. Keep any additional evidence/permission blocker actionable.
            if (! $sold && $reasons === ['Tidak ada stok belum terjual yang tersedia.']) {
                $emptyStock[] = $base;
                continue;
            }

            // Each serial is offered exactly once on the screen, in the first receipt/product group that can take it.
            if (! $reasons && $r['serialized']) {
                $r['serial_options'] = array_values(array_filter($r['serial_options'] ?? [], fn ($id) => ! isset($seenSerials[(int) $id])));
                foreach ($r['serial_options'] as $id) {
                    $seenSerials[(int) $id] = true;
                }
                $r['max'] = min((float) $r['max'], (float) count($r['serial_options']));
                if (! $r['serial_options']) {
                    $reasons[] = 'Tidak ada serial yang dapat dipilih (sudah tampil di kelompok lain).';
                }
            }

            if ($reasons) {
                $blocked[] = $base + ['explanations' => $this->explain($reasons, $base)];
                continue;
            }

            $key = $sold ? 's' . ($s++) : 'u' . ($u++);
            $unitPrice = (float) ($detail->unit_dpp ?? 0) > 0 ? (float) $detail->unit_dpp : (float) ($detail->unit_cost ?? 0);
            $row = $base + [
                'key' => $key,
                'max' => round((float) $r['max'], 3),
                'unit_price' => $unitPrice,
                'unit_tax' => $receivedQty > 0 ? (float) ($detail->tax_amount ?? 0) / $receivedQty : 0.0,
                'serials' => array_map(fn ($id) => ['id' => (int) $id, 'label' => (string) ($serialLabels[$id] ?? $id)], $r['serial_options'] ?? []),
                'fields' => array_filter([
                    'line_type' => $r['line_type'],
                    'consignment_receiving_detail_id' => (int) $r['consignment_receiving_detail_id'],
                    'consignment_sold_source_id' => $sold ? (int) $r['consignment_sold_source_id'] : null,
                    'source_location_id' => $sold ? null : (int) $r['source_location_id'],
                ], fn ($v) => $v !== null),
            ];
            if ($sold) {
                $row['source_key'] = (string) $r['consignment_sold_source_id'];
                $soldLimits[$row['source_key']] = round(max(0.0, (float) $r['available']), 3);
            } else {
                $row['pool_key'] = $base['product_id'] . ':' . $r['source_location_id'];
                $pools[$row['pool_key']] ??= [
                    'taxed' => (float) $r['pool_buckets']['taxed'], 'non_taxed' => (float) $r['pool_buckets']['non_taxed'],
                ];
            }
            $holdingIds[$ownerId] = true;      // sold rows: future-return destination of the selling business
            $rows[] = $row;
        }

        foreach ($proposal['unattributed'] as $u2) {
            $src = $sources->get($u2['source_id']);
            $b = [
                'kind' => 'sold', 'product' => $src->product->product_name ?? '-', 'product_code' => $src->product->product_code ?? null,
                'receipt' => null, 'location' => $locations[$src->location_id ?? 0]->name ?? '-', 'owner' => $businessNames[$settingId] ?? '-',
                'remote' => false, 'sale_reference' => $src ? ($sales[$src->sale_id] ?? null) : null, 'serialized' => false,
                'document' => $src ? ($documents[$src->id] ?? null) : null,
                'quantity' => $src ? (float) $src->original_base_quantity : null,
            ];
            $blocked[] = $b + ['explanations' => $this->explain(array_merge(['UNATTRIBUTED'], $u2['reasons']), $b)];
        }

        // Group: product -> receipt -> row keys (receipt order as returned by the proposal = receipt id order).
        $groups = [];
        foreach ($rows as $row) {
            $g = &$groups[$row['product_id']];
            $g['product'] ??= $row['product'];
            $g['product_code'] ??= $row['product_code'];
            $g['serialized'] = $row['serialized'];
            $g['receipts'][$row['receipt']][] = $row['key'];
            unset($g);
        }
        // Selection tree: receiving document -> product -> unsold (per current location / acquiring business) and sold.
        $tree = [];
        foreach ($rows as $row) {
            $rc = &$tree[$row['receipt']];
            $rc['receipt'] = $row['receipt'];
            $pg = &$rc['products'][$row['product_id']];
            $pg['product'] ??= $row['product'];
            $pg['product_code'] ??= $row['product_code'];
            $pg['serialized'] = $row['serialized'];
            $pg['unsold'] ??= [];
            $pg['sold'] ??= [];
            if ($row['kind'] === 'unsold') {
                $lg = &$pg['unsold'][$row['location'] . '|' . $row['owner_setting_id']];
                $lg['location'] = $row['location'];
                $lg['owner'] = $row['owner'];
                $lg['remote'] = $row['remote'];
                $lg['keys'][] = $row['key'];
                unset($lg);
            } else {
                $pg['sold'][] = $row['key'];
            }
            unset($pg, $rc);
        }
        foreach ($tree as &$rc) {
            foreach ($rc['products'] as &$pg) {
                $pg['unsold'] = array_values($pg['unsold']);
            }
            unset($pg);
        }
        unset($rc);

        // Unsold receipt order inside each pool is the tax-bucket consumption order.
        $poolOrder = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'unsold') {
                $poolOrder[$row['pool_key']][] = $row['key'];
            }
        }

        $holdings = [];
        foreach (array_keys($holdingIds + [$settingId => true]) as $sid) {
            $holdings[$sid] = [
                'name' => $businessNames[$sid] ?? Setting::whereKey($sid)->value('company_name'),
                'local' => $sid === $settingId,
                'destinations' => Location::where('setting_id', $sid)->where('is_consignment', false)->where('is_active', true)
                    ->orderBy('name')->get(['id', 'name'])->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name])->values()->all(),
            ];
        }

        return [
            'billing_business' => $businessNames[$settingId] ?? Setting::whereKey($settingId)->value('company_name'),
            'billing_setting_id' => $settingId,
            'holdings' => $holdings,
            'rows' => array_column($rows, null, 'key'),
            'groups' => $groups,
            'tree' => array_values($tree),
            'settled' => $settled,
            'empty_stock' => $emptyStock,
            'pools' => $pools,
            'pool_order' => $poolOrder,
            'sold_limits' => $soldLimits,
            'blocked' => $blocked,
        ];
    }

    /**
     * Human-readable sold document per source: the originating POS receipt when the sale came from POS checkout,
     * otherwise the Sales reference. A link is given only when the active business may open it (same business +
     * the target screen's own permissions); otherwise the number is shown as plain text.
     *
     * @return array<int, array{label:string, secondary:?string, url:?string, selling_business:?string}>
     */
    public function soldDocuments($sources, $sales, int $settingId, User $actor): array
    {
        $saleIds = $sources->pluck('sale_id')->filter()->unique()->values();
        $pos = $saleIds->isEmpty() || ! \Illuminate\Support\Facades\Schema::hasTable('pos_checkout_sales') ? collect() : \DB::table('pos_checkout_sales as pcs')
            ->join('pos_checkouts as pc', 'pc.id', '=', 'pcs.pos_checkout_id')
            ->leftJoin('pos_transactions as pt', 'pt.id', '=', 'pc.pos_transaction_id')
            ->whereIn('pcs.sale_id', $saleIds)
            ->orderBy('pcs.id')
            ->get(['pcs.sale_id', 'pc.receipt_number', 'pc.setting_id as pos_setting_id', 'pc.pos_transaction_id', 'pt.code as transaction_code'])
            ->unique('sale_id')->keyBy('sale_id');
        $saleSettings = Sale::whereIn('id', $saleIds)->pluck('setting_id', 'id');
        $names = Setting::whereIn('id', $pos->pluck('pos_setting_id')->merge($saleSettings->values())->push($settingId)->filter()->unique())
            ->get(['id', 'company_name', 'pos_enabled', 'pos_transactions_enabled'])->keyBy('id');
        $gate = Gate::forUser($actor);
        $active = $names->get($settingId);
        $mayPos = $gate->allows('pos.access') && $gate->allows('pos.transactions.view')
            && $active && $active->pos_enabled && $active->pos_transactions_enabled;
        $maySale = $gate->allows('sales.show');

        $out = [];
        foreach ($sources as $src) {
            $saleRef = $sales[$src->sale_id] ?? null;
            $saleSetting = (int) ($saleSettings[$src->sale_id] ?? $src->setting_id);
            $p = $pos->get($src->sale_id);
            $saleUrl = $saleRef && $maySale && $saleSetting === $settingId ? route('sales.show', $src->sale_id) : null;
            if ($p && ($p->receipt_number || $p->transaction_code)) {
                $posSetting = (int) $p->pos_setting_id;
                $out[$src->id] = [
                    'label' => 'POS ' . ($p->receipt_number ?: $p->transaction_code),
                    'secondary' => $saleRef ? 'Penjualan ' . $saleRef : null,
                    'url' => $mayPos && $p->pos_transaction_id && $posSetting === $settingId
                        ? route('pos.transactions.show', $p->pos_transaction_id) : $saleUrl,
                    'pos_business' => $names[$posSetting]->company_name ?? null,
                    'selling_business' => $names[$saleSetting]->company_name ?? null,
                ];
            } else {
                $out[$src->id] = [
                    'label' => $saleRef ? 'Penjualan ' . $saleRef : 'Penjualan',
                    'secondary' => null,
                    'url' => $saleUrl,
                    'pos_business' => null,
                    'selling_business' => $saleSetting !== $settingId ? ($names[$saleSetting]->company_name ?? null) : null,
                ];
            }
        }

        return $out;
    }

    /**
     * Server-side tax buckets for unsold lines submitted without them, using the existing proposal rule: per
     * product/location pool, in receipt order, take the non-taxed bucket first and then taxed. Reads the pool FRESH
     * (call outside readOnlyEvidence); explicit buckets are left untouched and the lifecycle still validates all.
     *
     * @param array<int|string, array> $lines validated draft lines
     */
    public function deriveBuckets(int $settingId, array $lines): array
    {
        $detailIds = array_column($lines, 'consignment_receiving_detail_id');
        $details = ConsignmentReceivingDetail::whereIn('id', $detailIds)->with('receiving:id,location_id')->get()->keyBy('id');
        $order = array_keys($lines);
        usort($order, fn ($a, $b) => [(int) $lines[$a]['consignment_receiving_detail_id'], (string) $a] <=> [(int) $lines[$b]['consignment_receiving_detail_id'], (string) $b]);

        $remaining = [];
        foreach ($order as $k) {
            $l = $lines[$k];
            if (($l['line_type'] ?? null) !== 'UNSOLD_BUYOUT' || ! empty($l['buckets']) && array_filter($l['buckets'], fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            $d = $details->get((int) $l['consignment_receiving_detail_id']);
            if (! $d) {
                continue;                               // reported by the lifecycle
            }
            $locationId = (int) ($l['source_location_id'] ?? 0) ?: (int) $d->receiving->location_id;
            $poolKey = $d->product_id . ':' . $locationId;
            if (! isset($remaining[$poolKey])) {
                $poolSetting = (int) (Location::whereKey($locationId)->value('setting_id') ?? $settingId);
                $pool = $this->capacity->physicalPool($poolSetting, (int) $d->product_id, $locationId);
                $remaining[$poolKey] = ['taxed' => ConsignmentBuyoutCapacityService::milli($pool['bucket_available']['taxed']),
                    'non_taxed' => ConsignmentBuyoutCapacityService::milli($pool['bucket_available']['non_taxed'])];
            }
            $qty = ConsignmentBuyoutCapacityService::milli($l['base_quantity']);
            $nonTax = max(0, min($qty, $remaining[$poolKey]['non_taxed']));
            $tax = $qty - $nonTax;                      // any shortfall stays visible to bucket validation
            $remaining[$poolKey]['non_taxed'] -= $nonTax;
            $remaining[$poolKey]['taxed'] -= $tax;
            $lines[$k]['buckets'] = ['TAXED' => ConsignmentBuyoutCapacityService::fromMilli($tax),
                'NON_TAXED' => ConsignmentBuyoutCapacityService::fromMilli($nonTax)];
        }

        return $lines;
    }

    /**
     * Plain-language explanation + operator action for each (technical) blocker. Unknown reasons are still shown.
     *
     * @return array<int, array{text:string, action:?array{label:string, url:string}}>
     */
    public function explain(array $reasons, array $row = []): array
    {
        $soldSources = ['label' => 'Buka Sumber Terjual (discovery manual)', 'url' => route('consignments.sold-sources.index')];
        $reconciliation = ['label' => 'Buka Rekonsiliasi', 'url' => route('consignments.reconciliation.index')];
        $rules = [
            ['UNATTRIBUTED', 'Penjualan ini belum dihubungkan (diatribusikan) ke penerimaan supplier, sehingga stok di lokasi yang sama belum dapat dibeli.', $reconciliation],
            ['REMOTE_ACCESS', 'Barang berada di bisnis lain dan Anda tidak memiliki izin/akses untuk membeli stok di bisnis tersebut.', null],
            ['run discovery', 'Ada pengiriman penjualan yang belum tercatat. Jalankan discovery secara manual terlebih dahulu.', $soldSources],
            ['manual discovery', 'Barang pindahan belum terhubung ke penerimaannya. Jalankan discovery secara manual terlebih dahulu.', $soldSources],
            ['not fully attributed', 'Penjualan di lokasi ini belum seluruhnya dihubungkan ke penerimaan supplier.', $reconciliation],
            ['belum diatribusikan', 'Penjualan di lokasi ini belum seluruhnya dihubungkan ke penerimaan supplier.', $reconciliation],
            ['not explicitly apportioned', 'Retur pelanggan belum dibagi ke penerimaan supplier.', $reconciliation],
            ['Return exceeds', 'Jumlah retur melebihi penjualan yang dihubungkan ke penerimaan.', $reconciliation],
            ['Sellable stock', 'Stok yang dapat dijual di lokasi lebih kecil dari sisa titipan menurut penerimaan (barang mungkin dipindah atau rusak).', $reconciliation],
            ['Reservations exceed', 'Stok sudah dicadangkan dokumen lain melebihi stok yang ada.', $reconciliation],
            ['exceed the quantity received', 'Catatan kuantitas penerimaan tidak seimbang (terjual + dibeli melebihi yang diterima).', $reconciliation],
            ['Financial consumption', 'Catatan tagihan penerimaan tidak seimbang (ditagih melebihi yang diterima).', $reconciliation],
            ['over-consumed', 'Penjualan ini sudah ditagih/dicadangkan melebihi jumlahnya.', $reconciliation],
            ['snapshot version', 'Bukti penjualan memakai format lama yang tidak dikenali.', $soldSources],
            ['receipt lineage', 'Serial yang terjual tidak memiliki asal penerimaan.', $reconciliation],
            ['not yet bound', 'Barang pindahan belum terhubung ke penerimaannya. Jalankan discovery secara manual terlebih dahulu.', $soldSources],
            ['binding', 'Penjualan barang pindahan belum terverifikasi ke penerimaannya.', $reconciliation],
        ];

        $out = [];
        foreach ($reasons as $reason) {
            $reason = is_array($reason) ? (string) ($reason['reason'] ?? '') : (string) $reason;
            $match = null;
            foreach ($rules as [$needle, $text, $action]) {
                if (stripos($reason, $needle) !== false) {
                    $match = ['text' => $text, 'action' => $action];
                    break;
                }
            }
            // Already-plain Indonesian reasons from the proposal (e.g. "Tidak ada ...") are shown as they are.
            $match ??= ['text' => $reason, 'action' => null];
            $out[$match['text']] = $match;
        }

        return array_values($out);
    }
}
