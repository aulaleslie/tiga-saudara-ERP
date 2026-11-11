<?php

namespace Modules\Consignment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBuyoutCapacityService;
use Modules\Consignment\Services\ConsignmentBuyoutFinalizationService;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Services\ConsignmentBuyoutPricingService;
use Modules\Consignment\Services\ConsignmentBuyoutReturnOwnershipService;
use Modules\Consignment\Services\ConsignmentBuyoutReversalService;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Location;

/**
 * Buyout screens. The READ screens (list, preparation, detail) are always available to permitted users. Every
 * MUTATION endpoint is hidden (404, no side effect) unless config('consignment.buyouts.ui_enabled') is true - that
 * flag must stay off until every stock consumer, returns and reversal safety are verified together on MySQL.
 */
class ConsignmentBuyoutController extends Controller
{
    public function __construct(
        protected ConsignmentBuyoutLifecycleService $lifecycle,
        protected ConsignmentBuyoutCapacityService $capacity,
        protected ConsignmentBuyoutPricingService $pricing,
        protected ConsignmentBuyoutFinalizationService $finalization,
        protected ConsignmentBuyoutReversalService $reversal,
        protected ConsignmentBuyoutReturnOwnershipService $returns,
    ) {
    }

    public static function mutationsEnabled(): bool
    {
        return (bool) config('consignment.buyouts.ui_enabled', false);
    }

    /* ------------------------------------------------------------------ read screens */

    public function index(Request $request)
    {
        abort_if(Gate::denies('consignments.buyouts.access'), 403);
        $settingId = (int) session('setting_id');

        $buyouts = ConsignmentBuyout::forSetting($settingId)
            ->with(['supplier:id,supplier_name', 'purchase:id,reference,total_amount,due_amount,status'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->latest('id')->paginate(25)->withQueryString();

        return view('consignment::buyouts.index', [
            'buyouts' => $buyouts,
            'selectedSupplierText' => Supplier::whereKey($request->integer('supplier_id'))->value('supplier_name'),
            'mutationsEnabled' => self::mutationsEnabled(),
        ]);
    }

    /** Preparation: shows exactly what exists and what blocks; nothing is selected or substituted for the operator. */
    public function create(Request $request)
    {
        abort_if(Gate::denies('consignments.buyouts.access'), 403);
        $settingId = (int) session('setting_id');
        $supplierId = $request->integer('supplier_id');

        $receipts = collect();
        $poolBlockers = [];
        $unattributed = collect();
        $soldRows = collect();
        $destinations = Location::where('setting_id', $settingId)->where('is_consignment', false)->orderBy('name')->get(['id', 'name']);

        if ($supplierId) {
            $details = ConsignmentReceivingDetail::query()
                ->whereHas('receiving', fn ($q) => $q->where('setting_id', $settingId)->where('status', ConsignmentReceiving::STATUS_APPROVED)
                    ->whereHas('receival', fn ($r) => $r->where('supplier_id', $supplierId)))
                ->with(['receiving:id,receiving_number,location_id', 'receiving.location:id,name', 'product:id,product_name,product_code,serial_number_required'])
                ->orderBy('id')->get();

            foreach ($details as $d) {
                $cap = $this->capacity->receiptCapacity($d->id);
                $receipts->push(['detail' => $d, 'capacity' => $cap]);
                $key = $d->product_id . ':' . $d->receiving->location_id;
                if (! isset($poolBlockers[$key])) {
                    $poolBlockers[$key] = $this->capacity->poolBlockers($settingId, (int) $d->product_id, (int) $d->receiving->location_id);
                }
            }

            // Every effective sale in an affected pool must be attributed to a receipt before unsold stock can be bought.
            $pools = $details->map(fn ($d) => [$d->product_id, $d->receiving->location_id])->unique(fn ($p) => $p[0] . ':' . $p[1]);
            foreach ($pools as [$productId, $locationId]) {
                foreach (ConsignmentSoldSource::forSetting($settingId)->where('product_id', $productId)->where('location_id', $locationId)->with('product:id,product_name')->get() as $src) {
                    $att = $this->capacity->soldAttribution($src);
                    if (! $att['complete']) {
                        $unattributed->push(['source' => $src, 'blockers' => $att['blockers']]);
                        continue;
                    }
                    $cap = $this->capacity->soldSourceCapacity($src);
                    foreach ($att['per_receipt'] as $crd => $qty) {
                        if ($details->contains('id', (int) $crd)) {
                            $soldRows->push(['source' => $src, 'receiving_detail_id' => (int) $crd, 'attributed' => $qty, 'available' => $cap['available'], 'sale_id' => $src->sale_id]);
                        }
                    }
                }
            }
        }

        // "Buy all eligible": a read-only proposal for ONE explicitly chosen destination, shown for review before any draft exists.
        $proposal = null;
        $proposalError = null;
        $destinationId = $request->integer('destination_location_id');
        if ($supplierId && $request->query('propose') === 'all') {
            if (! $destinations->contains('id', $destinationId)) {
                $proposalError = 'Pilih lokasi tujuan (stok milik sendiri) sebelum mengusulkan semua yang memenuhi syarat; sistem tidak memilihnya otomatis.';
            } else {
                $proposal = (new \Modules\Consignment\Services\ConsignmentBuyoutProposalService($this->capacity))->allEligible($settingId, $supplierId, $destinationId);
            }
        }

        return view('consignment::buyouts.create', [
            'proposal' => $proposal,
            'proposalError' => $proposalError,
            'destinationId' => $destinationId ?: null,
            'supplierId' => $supplierId,
            'selectedSupplierText' => Supplier::whereKey($supplierId)->value('supplier_name'),
            'receipts' => $receipts,
            'poolBlockers' => $poolBlockers,
            'unattributed' => $unattributed,
            'soldRows' => $soldRows,
            'destinations' => $destinations,
            'mutationsEnabled' => self::mutationsEnabled(),
        ]);
    }

    public function show(int $id)
    {
        abort_if(Gate::denies('consignments.buyouts.access'), 403);
        $buyout = ConsignmentBuyout::forSetting((int) session('setting_id'))->with([
            'supplier:id,supplier_name',
            'purchase.purchasePayments',
            'auditEvents',
            'reservations',
            'lines.receivingDetail.receiving:id,receiving_number,location_id',
            'lines.serials.serialNumber:id,serial_number',
            'lines.soldSource:id,sale_id,dispatch_detail_id',
        ])->findOrFail($id);

        $open = in_array($buyout->status, [ConsignmentBuyout::STATUS_DRAFT, ConsignmentBuyout::STATUS_WAITING_APPROVAL, ConsignmentBuyout::STATUS_REJECTED], true);
        $documentBlockers = $buyout->status === ConsignmentBuyout::STATUS_DRAFT || $buyout->status === ConsignmentBuyout::STATUS_WAITING_APPROVAL
            ? $this->lifecycle->validateDocument($buyout) : [];
        $pricing = in_array($buyout->status, [ConsignmentBuyout::STATUS_DRAFT, ConsignmentBuyout::STATUS_WAITING_APPROVAL, ConsignmentBuyout::STATUS_APPROVED], true)
            ? $this->pricing->calculate($buyout) : null;
        $reversalPreview = $buyout->status === ConsignmentBuyout::STATUS_FINALIZED ? $this->reversal->preview($buyout) : null;

        $locationIds = $buyout->lines->flatMap(fn ($l) => [$l->source_location_id, $l->destination_location_id])->filter()->unique();
        $actors = User::whereIn('id', $buyout->auditEvents->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return view('consignment::buyouts.show', [
            'buyout' => $buyout,
            'locations' => Location::whereIn('id', $locationIds)->pluck('name', 'id'),
            'actors' => $actors,
            'documentBlockers' => $documentBlockers,
            'pricing' => $pricing,
            'reversalPreview' => $reversalPreview,
            'hashIsCurrent' => $buyout->approved_revision_hash ? $this->lifecycle->approvedHashIsCurrent($buyout) : null,
            'mutationsEnabled' => self::mutationsEnabled(),
            'canEditOpen' => $open,
        ]);
    }

    /* ------------------------------------------------------------------ mutations (hidden unless enabled) */

    public function store(Request $request)
    {
        return $this->mutating(function () use ($request) {
            $data = $this->validateDraft($request, true);
            $buyout = $this->lifecycle->createDraft((int) session('setting_id'), (int) $data['supplier_id'], $data['date'], $this->lines($data), $this->actor(), $this->meta($data));

            return [$buyout, 'Draft pembelian putus berhasil dibuat.'];
        });
    }

    public function update(Request $request, int $id)
    {
        return $this->mutating(function () use ($request, $id) {
            $data = $this->validateDraft($request, false);
            $buyout = $this->find($id);
            $this->lifecycle->reviseDraft($buyout, $this->lines($data), $this->actor(), $request->integer('revision') ?: null, $this->meta($data));

            return [$buyout, 'Draft pembelian putus berhasil direvisi.'];
        });
    }

    public function submit(Request $request, int $id)
    {
        return $this->mutating(function () use ($request, $id) {
            $buyout = $this->find($id);
            $this->lifecycle->submit($buyout, $this->actor(), $request->integer('revision') ?: null);

            return [$buyout, 'Pembelian putus diajukan untuk persetujuan.'];
        });
    }

    public function approve(Request $request, int $id)
    {
        return $this->mutating(function () use ($request, $id) {
            $buyout = $this->find($id);
            $this->lifecycle->approve($buyout, $this->actor(), $request->integer('revision') ?: null);

            return [$buyout, 'Pembelian putus disetujui dan sumber daya dicadangkan.'];
        });
    }

    public function reject(Request $request, int $id)
    {
        return $this->mutating(function () use ($request, $id) {
            $reason = $request->validate(['reason' => 'required|string|max:1000'])['reason'];
            $buyout = $this->find($id);
            $this->lifecycle->reject($buyout, $reason, $this->actor(), $request->integer('revision') ?: null);

            return [$buyout, 'Pembelian putus ditolak.'];
        });
    }

    public function cancel(Request $request, int $id)
    {
        return $this->mutating(function () use ($request, $id) {
            $request->validate(['confirm' => 'accepted']);
            $reason = $request->validate(['reason' => 'required|string|max:1000'])['reason'];
            $buyout = $this->find($id);
            $this->lifecycle->cancel($buyout, $reason, $this->actor(), $request->integer('revision') ?: null);

            return [$buyout, 'Pembelian putus dibatalkan dan cadangan dilepas.'];
        });
    }

    public function finalize(Request $request, int $id)
    {
        return $this->mutating(function () use ($request, $id) {
            $request->validate(['confirm' => 'accepted']);
            $buyout = $this->find($id);
            $this->finalization->finalize($buyout, $this->actor());

            return [$buyout, 'Pembelian putus difinalisasi.'];
        });
    }

    public function reverse(Request $request, int $id)
    {
        return $this->mutating(function () use ($request, $id) {
            $request->validate(['confirm' => 'accepted']);
            $reason = $request->validate(['reason' => 'required|string|max:1000'])['reason'];
            $buyout = $this->find($id);
            $this->reversal->reverse($buyout, $this->actor(), $reason);

            return [$buyout, 'Pembelian putus dibatalkan (reversal).'];
        });
    }

    /** Explicit non-financial sold-sale attribution to supplier receipts. */
    public function attribute(Request $request, int $sourceId)
    {
        return $this->mutating(function () use ($request, $sourceId) {
            abort_if(Gate::denies('consignments.buyouts.create'), 403);
            $data = $request->validate([
                'quantities' => 'required|array|min:1', 'quantities.*' => 'required|numeric|gt:0',
                'returns' => 'nullable|array', 'returns.*' => 'nullable|numeric|min:0',
                'reason' => 'required|string|max:1000',
            ]);
            $source = ConsignmentSoldSource::forSetting((int) session('setting_id'))->findOrFail($sourceId);
            $this->capacity->setAttribution($source->id, $data['quantities'], auth()->id(), $data['reason'], $data['returns'] ?? []);

            return [null, 'Atribusi penjualan ke penerimaan disimpan.'];
        }, back: true);
    }

    /** Explicit acquired quantity of a returned (non-serialized) detail before receiving. */
    public function declareReturn(Request $request, int $detailId)
    {
        return $this->mutating(function () use ($request, $detailId) {
            abort_if(Gate::denies('consignments.buyouts.acquire_unsold'), 403);
            $data = $request->validate(['acquired_quantity' => 'required|numeric|min:0']);
            // Scoped to the active business: the return's own business or the sold source's inventory-owner business
            // (POS split-owner). Anything else is indistinguishable from a missing record.
            $active = (int) session('setting_id');
            $detail = \Modules\SalesReturn\Entities\SaleReturnDetail::whereKey($detailId)
                ->where(fn ($q) => $q->whereHas('saleReturn', fn ($r) => $r->where('setting_id', $active))
                    ->orWhereIn('dispatch_detail_id', ConsignmentSoldSource::where('setting_id', $active)->select('dispatch_detail_id')))
                ->firstOrFail();
            $this->returns->declare($detail, (float) $data['acquired_quantity'], $this->actor());

            return [null, 'Pembagian kuantitas retur (milik/bukan milik) disimpan.'];
        }, back: true);
    }

    /* ------------------------------------------------------------------ plumbing */

    private function mutating(\Closure $work, bool $back = false)
    {
        // Direct forgery against a disabled feature changes nothing and reveals nothing.
        abort_unless(self::mutationsEnabled(), 404);

        try {
            [$buyout, $message] = $work();
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());          // never swallowed into a toast
        } catch (\InvalidArgumentException|\DomainException $e) {
            toast($e->getMessage(), 'error');

            return back()->withInput();
        }
        toast($message, 'success');

        return ($back || ! $buyout)
            ? back()
            : redirect()->route('consignments.buyouts.show', $buyout->id);
    }

    private function actor(): User
    {
        return auth()->user();
    }

    private function find(int $id): ConsignmentBuyout
    {
        return ConsignmentBuyout::forSetting((int) session('setting_id'))->findOrFail($id);
    }

    private function validateDraft(Request $request, bool $creating): array
    {
        // "12, 13" typed into the serial box becomes an exact id list; duplicates/garbage are caught by validation.
        $request->merge(['lines' => array_map(function ($l) {
            if (is_array($l) && isset($l['serial_ids_text'])) {
                $ids = array_values(array_filter(array_map('trim', explode(',', (string) $l['serial_ids_text'])), fn ($v) => $v !== ''));
                $l['serial_ids'] = $ids;
                unset($l['serial_ids_text']);
            }

            return $l;
        }, (array) $request->input('lines', []))]);

        // A row from the "all eligible" review that the operator un-ticked is an explicit exclusion.
        $request->merge(['lines' => array_values(array_filter((array) $request->input('lines', []),
            fn ($l) => ! is_array($l) || empty($l['reviewed']) || ! empty($l['include'])))]);
        $request->merge(['lines' => array_map(function ($l) {
            if (is_array($l)) {
                unset($l['reviewed'], $l['include']);
            }

            return $l;
        }, (array) $request->input('lines', []))]);

        // Rows the operator left blank are simply not part of the document (nothing is invented for them).
        $request->merge(['lines' => array_values(array_filter((array) $request->input('lines', []),
            fn ($l) => is_array($l) && isset($l['base_quantity']) && trim((string) $l['base_quantity']) !== '' && (float) $l['base_quantity'] > 0))]);

        return $request->validate([
            'supplier_id' => ($creating ? 'required' : 'nullable') . '|integer|exists:suppliers,id',
            'date' => 'required|date',
            'notes' => 'nullable|string|max:2000',
            'invoice_metadata' => 'nullable|array',
            'pricing_intent' => 'nullable|array',
            'lines' => 'required|array|min:1',
            'lines.*.line_type' => 'required|in:' . ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED . ',' . ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT,
            'lines.*.consignment_receiving_detail_id' => 'required|integer|exists:consignment_receiving_details,id',
            'lines.*.consignment_sold_source_id' => 'nullable|integer|exists:consignment_sold_sources,id',
            'lines.*.destination_location_id' => 'required|integer|exists:locations,id',
            'lines.*.base_quantity' => 'required|numeric|gt:0',
            'lines.*.buckets' => 'nullable|array',
            'lines.*.buckets.TAXED' => 'nullable|numeric|min:0',
            'lines.*.buckets.NON_TAXED' => 'nullable|numeric|min:0',
            'lines.*.serial_ids' => 'nullable|array',
            'lines.*.serial_ids.*' => 'integer',
        ]);
    }

    private function lines(array $data): array
    {
        return array_values($data['lines']);
    }

    private function meta(array $data): array
    {
        return ['invoice_metadata' => $data['invoice_metadata'] ?? null, 'pricing_intent' => $data['pricing_intent'] ?? null, 'notes' => $data['notes'] ?? null];
    }
}
