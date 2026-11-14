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
use Modules\Consignment\Services\ConsignmentBuyoutPreparationService;
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

    /**
     * Preparation (three steps on one page): supplier & destinations, select goods, review & save draft. Read-only:
     * nothing is selected, reserved or substituted for the operator; evidence is reused within this request only.
     */
    public function create(Request $request)
    {
        abort_if(Gate::denies('consignments.buyouts.access'), 403);
        $settingId = (int) session('setting_id');
        $supplierId = $request->integer('supplier_id');

        $prep = $supplierId
            ? $this->capacity->readOnlyEvidence(fn () => (new ConsignmentBuyoutPreparationService($this->capacity))->prepare($settingId, $supplierId, $this->actor()))
            : null;

        // Initial destinations: old input (failed save) > explicit query (local + per holding business). Never guessed.
        $destinations = (array) $request->old('destinations', []);
        if (! $destinations) {
            $destinations = (array) $request->input('remote_destinations', []);
            if ($request->filled('destination_location_id')) {
                $destinations[$settingId] = $request->integer('destination_location_id');
            }
        }
        if ($prep) {
            $destinations = collect($destinations)->filter(fn ($loc, $sid) => isset($prep['holdings'][(int) $sid])
                && collect($prep['holdings'][(int) $sid]['destinations'])->contains('id', (int) $loc))
                ->mapWithKeys(fn ($loc, $sid) => [(int) $sid => (int) $loc])->all();
        }

        return view('consignment::buyouts.create', [
            'supplierId' => $supplierId,
            'selectedSupplierText' => Supplier::whereKey($supplierId)->value('supplier_name'),
            'prep' => $prep,
            'initialDestinations' => (object) $destinations,
            'originName' => \Modules\Setting\Entities\Setting::whereKey($settingId)->value('company_name'),
            'mutationsEnabled' => self::mutationsEnabled(),
        ] + $this->invoiceContext($settingId));
    }

    /**
     * Reopen a DRAFT or REJECTED buyout in the same three-step wizard, hydrated from its saved selections, destinations,
     * product terms and invoice data, opening on step 3. Saving goes through the existing revision PUT.
     */
    public function edit(Request $request, int $id)
    {
        abort_if(Gate::denies('consignments.buyouts.access'), 403);
        abort_if(Gate::denies('consignments.buyouts.edit'), 403);
        $settingId = (int) session('setting_id');
        $buyout = ConsignmentBuyout::forSetting($settingId)->with(['lines.serials', 'lines.receivingDetail.receiving:id,receiving_number', 'lines.receivingDetail.product:id,product_name'])->findOrFail($id);
        if (! in_array($buyout->status, [ConsignmentBuyout::STATUS_DRAFT, ConsignmentBuyout::STATUS_REJECTED], true)) {
            toast('Hanya draft atau dokumen yang ditolak yang dapat diubah.', 'warning');

            return redirect()->route('consignments.buyouts.show', $buyout->id);
        }

        $prep = $this->capacity->readOnlyEvidence(fn () => (new ConsignmentBuyoutPreparationService($this->capacity))->prepare($settingId, (int) $buyout->supplier_id, $this->actor()));

        // Saved lines -> wizard rows by their operational identity; anything no longer offered is reported, never dropped
        // silently or substituted.
        $index = [];
        foreach ($prep['rows'] as $key => $row) {
            $f = $row['fields'];
            $index[implode('|', [$f['line_type'], (int) $f['consignment_receiving_detail_id'], (int) ($f['consignment_sold_source_id'] ?? 0), (int) ($f['source_location_id'] ?? 0)])] = $key;
        }
        $saved = [];
        $unavailable = [];
        $destinations = [];
        foreach ($buyout->lines as $line) {
            $sourceLocation = $line->line_type === ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT ? (int) $line->source_location_id : 0;
            $key = $index[implode('|', [$line->line_type, (int) $line->consignment_receiving_detail_id, (int) ($line->consignment_sold_source_id ?? 0), $sourceLocation])] ?? null;
            $serialIds = $line->serials->pluck('product_serial_number_id')->map(fn ($v) => (int) $v)->all();
            $row = $key !== null ? $prep['rows'][$key] : null;
            $offeredSerials = $row ? collect($row['serials'])->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
            if (! $row || ($serialIds && array_diff($serialIds, $offeredSerials)) || (! $serialIds && (float) $line->base_quantity > (float) $row['max'] + 0.0005)) {
                $unavailable[] = [
                    'receipt' => $line->receivingDetail?->receiving?->receiving_number,
                    'product' => $line->receivingDetail?->product?->product_name,
                    'kind' => $line->line_type === ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED ? 'sold' : 'unsold',
                    'quantity' => (float) $line->base_quantity,
                ];
                if (! $row) {
                    continue;
                }
                $serialIds = array_values(array_intersect($serialIds, $offeredSerials));
            }
            $saved[$key] = ['row_key' => $key, 'base_quantity' => $serialIds ? count($serialIds) : min((float) $line->base_quantity, (float) $row['max']), 'serial_ids' => $serialIds];
            if ($line->destination_location_id) {
                $destinations[$line->inventoryOwnerSettingId()] = (int) $line->destination_location_id;
            }
        }

        return view('consignment::buyouts.create', [
            'buyout' => $buyout,
            'supplierId' => (int) $buyout->supplier_id,
            'selectedSupplierText' => Supplier::whereKey($buyout->supplier_id)->value('supplier_name'),
            'prep' => $prep,
            'initialDestinations' => (object) ($request->old('destinations') ?: $destinations),
            'savedLines' => $saved,
            'legacyIntent' => ConsignmentBuyoutPricingService::isProductIntent($buyout->pricing_intent) ? null : $this->pricing->projectLegacyIntent($buyout),
            'unavailable' => $unavailable,
            'originName' => \Modules\Setting\Entities\Setting::whereKey($settingId)->value('company_name'),
            'mutationsEnabled' => self::mutationsEnabled(),
        ] + $this->invoiceContext($settingId));
    }

    /**
     * Authoritative tab-3 preview: the same server calculation used by draft save, approval and finalization, over the
     * currently selected (unsaved) goods. Read-only; the client discards responses older than its latest request.
     */
    public function preview(Request $request)
    {
        abort_if(Gate::denies('consignments.buyouts.access'), 403);
        $settingId = (int) session('setting_id');
        $data = $request->validate([
            'supplier_id' => 'required|integer',
            'seq' => 'nullable|integer',
            'pricing_intent' => 'nullable|array',
            'lines' => 'nullable|array|max:2000',
            'lines.*.line_type' => 'required|in:' . ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED . ',' . ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT,
            'lines.*.consignment_receiving_detail_id' => 'required|integer',
            'lines.*.base_quantity' => 'required|numeric|gt:0',
        ]);
        $crdIds = collect($data['lines'] ?? [])->pluck('consignment_receiving_detail_id')->map(fn ($v) => (int) $v)->unique()->all();
        // Only this business's receipts of this supplier can be priced; anything else is indistinguishable from missing.
        $details = ConsignmentReceivingDetail::whereIn('id', $crdIds)
            ->whereHas('receiving.receival', fn ($q) => $q->where('setting_id', $settingId)->where('supplier_id', (int) $data['supplier_id']))
            ->pluck('product_id', 'id');
        $lines = [];
        foreach (array_values($data['lines'] ?? []) as $i => $l) {
            $crd = (int) $l['consignment_receiving_detail_id'];
            if (! $details->has($crd)) {
                return response()->json(['message' => 'Unknown receipt detail.'], 422);
            }
            $line = new ConsignmentBuyoutLine(['line_type' => $l['line_type'], 'consignment_receiving_detail_id' => $crd,
                'product_id' => (int) $details[$crd], 'base_quantity' => $l['base_quantity']]);
            $line->id = $i + 1;
            $lines[] = $line;
        }
        $result = $this->pricing->calculateLines($settingId, $lines, $this->pricingIntent($request, $settingId));

        return response()->json([
            'seq' => $data['seq'] ?? null,
            'valid' => $result['valid'],
            'blockers' => $result['blockers'],
            'is_pkp' => $result['is_pkp'],
            'is_tax_included' => $result['is_tax_included'],
            'products' => array_map(fn ($p) => array_intersect_key($p, array_flip(['product_id', 'product_name', 'quantity', 'original', 'prices_agree', 'tax_agree',
                'default_unit_price', 'default_tax_id', 'default_tax_rate', 'needs_price', 'needs_tax', 'accepted', 'payable'])), $result['products']),
            'totals' => $result['totals'],
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
            'lines.receivingDetail.product:id,product_name',
            'lines.serials.serialNumber:id,serial_number',
            'lines.soldSource:id,sale_id,dispatch_detail_id',
            'lines.soldSource.sale:id,reference',
        ])->findOrFail($id);

        $open = in_array($buyout->status, [ConsignmentBuyout::STATUS_DRAFT, ConsignmentBuyout::STATUS_WAITING_APPROVAL, ConsignmentBuyout::STATUS_REJECTED], true);
        $documentBlockers = $buyout->status === ConsignmentBuyout::STATUS_DRAFT || $buyout->status === ConsignmentBuyout::STATUS_WAITING_APPROVAL
            ? $this->lifecycle->validateDocument($buyout) : [];
        $pricing = in_array($buyout->status, [ConsignmentBuyout::STATUS_DRAFT, ConsignmentBuyout::STATUS_WAITING_APPROVAL, ConsignmentBuyout::STATUS_APPROVED], true)
            ? $this->pricing->calculate($buyout) : null;
        $reversalPreview = $buyout->status === ConsignmentBuyout::STATUS_FINALIZED ? $this->reversal->preview($buyout) : null;

        $locationIds = $buyout->lines->flatMap(fn ($l) => [$l->source_location_id, $l->destination_location_id])->filter()->unique();
        $actors = User::whereIn('id', $buyout->auditEvents->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        $owners = \Modules\Setting\Entities\Setting::whereIn('id', $buyout->lines->pluck('inventory_owner_setting_id')->filter()->push($buyout->setting_id)->unique())
            ->pluck('company_name', 'id');

        return view('consignment::buyouts.show', [
            'buyout' => $buyout,
            'owners' => $owners,
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
            $data['lines'] = (new ConsignmentBuyoutPreparationService($this->capacity))->deriveBuckets((int) session('setting_id'), $data['lines']);
            $buyout = $this->withAttachments($request, null, fn (array $meta) => $this->lifecycle->createDraft(
                (int) session('setting_id'), (int) $data['supplier_id'], $data['date'], $this->lines($data), $this->actor(), $meta), $this->meta($request, $data));

            return [$buyout, 'Draft pembelian putus berhasil dibuat.'];
        });
    }

    public function update(Request $request, int $id)
    {
        return $this->mutating(function () use ($request, $id) {
            $data = $this->validateDraft($request, false);
            $buyout = $this->find($id);
            $this->assertLegacyConflictsResolved($buyout, $request);
            if (! empty($data['lines']) && isset($data['lines'][0]['row_key'])) {
                $data['lines'] = (new ConsignmentBuyoutPreparationService($this->capacity))->deriveBuckets((int) session('setting_id'), $data['lines']);
            }
            $this->withAttachments($request, $buyout, fn (array $meta) => $this->lifecycle->reviseDraft(
                $buyout, $this->lines($data), $this->actor(), $request->integer('revision') ?: null, $meta), $this->meta($request, $data, $buyout));

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

        // Serial selector rows: the quantity is the number of serials picked (the lifecycle re-checks the equality).
        $request->merge(['lines' => array_map(function ($l) {
            if (is_array($l) && ! empty($l['serial_ids']) && is_array($l['serial_ids']) && trim((string) ($l['base_quantity'] ?? '')) === '') {
                $l['base_quantity'] = (string) count($l['serial_ids']);
            }

            return $l;
        }, (array) $request->input('lines', []))]);

        // Rows the operator left blank are simply not part of the document (nothing is invented for them).
        $request->merge(['lines' => array_values(array_filter((array) $request->input('lines', []),
            fn ($l) => is_array($l) && isset($l['base_quantity']) && trim((string) $l['base_quantity']) !== '' && (float) $l['base_quantity'] > 0))]);

        // One owned-stock destination per holding business (step 1) applies to every line that business will own.
        $chosen = array_filter(array_map('intval', (array) $request->input('destinations', [])));
        if ($chosen) {
            $request->merge(['lines' => array_map(function ($l) use ($chosen) {
                if (is_array($l) && empty($l['destination_location_id'])) {
                    $owner = $this->lineOwnerSetting($l);
                    if ($owner && isset($chosen[$owner])) {
                        $l['destination_location_id'] = $chosen[$owner];
                    }
                }

                return $l;
            }, (array) $request->input('lines', []))]);
        }

        $settingId = (int) session('setting_id');
        $isPkp = (bool) \Modules\Setting\Entities\Setting::whereKey($settingId)->value('is_pkp');
        if (! $request->filled('date') && $request->filled('invoice_metadata.invoice_date')) {
            $request->merge(['date' => $request->input('invoice_metadata.invoice_date')]);
        }

        return $request->validate([
            'destinations' => 'nullable|array',
            'destinations.*' => 'nullable|integer',
            'supplier_id' => ($creating ? 'required' : 'nullable') . '|integer|exists:suppliers,id',
            'date' => 'required|date',
            'notes' => 'nullable|string|max:2000',
            'invoice_metadata' => 'nullable|array',
            'invoice_metadata.supplier_invoice_number' => 'nullable|string|max:100',
            'invoice_metadata.invoice_date' => 'nullable|date',
            // The reporting date always starts as the invoice date; later changes go through the Purchase date adjustment.
            'invoice_metadata.reporting_date' => 'prohibited',
            'invoice_metadata.due_date' => 'nullable|date|after_or_equal:invoice_metadata.invoice_date',
            'invoice_metadata.due_date_manual' => 'nullable|boolean',
            'invoice_metadata.payment_term_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('payment_terms', 'id')->where('is_active', true)],
            'invoice_metadata.tax_ref_no' => 'nullable|string|max:100',
            'pricing_intent' => 'nullable|array',
            'pricing_intent.is_tax_included' => 'nullable|boolean',
            'pricing_intent.global_discount_type' => 'nullable|in:fixed,percentage',
            'pricing_intent.global_discount_value' => 'nullable|numeric|min:0',
            'pricing_intent.products' => 'nullable|array',
            'pricing_intent.products.*.unit_price' => 'nullable|numeric|min:0|max:999999999.999999',
            'pricing_intent.products.*.discount_type' => 'nullable|in:fixed,percentage',
            'pricing_intent.products.*.discount_value' => 'nullable|numeric|min:0',
            'pricing_intent.products.*.row_total_override' => 'nullable|numeric|min:0|max:9999999999999.99',
            'pricing_intent.products.*.tax_id' => $isPkp
                ? ['nullable', 'integer', function ($attr, $value, $fail) {
                    if ((string) $value !== ConsignmentBuyoutPricingService::NO_TAX && ! \Modules\Setting\Entities\Tax::active()->whereKey((int) $value)->exists()) {
                        $fail('Pajak yang dipilih tidak aktif.');
                    }
                }]
                : 'nullable',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
            'keep_attachments' => 'nullable|array',
            'keep_attachments.*' => 'integer',
            'lines.*.row_key' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.line_type' => 'required|in:' . ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED . ',' . ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT,
            'lines.*.consignment_receiving_detail_id' => 'required|integer|exists:consignment_receiving_details,id',
            'lines.*.consignment_sold_source_id' => 'nullable|integer|exists:consignment_sold_sources,id',
            'lines.*.destination_location_id' => 'required|integer|exists:locations,id',
            'lines.*.source_location_id' => 'nullable|integer|exists:locations,id',
            'lines.*.base_quantity' => 'required|numeric|gt:0',
            'lines.*.buckets' => 'nullable|array',
            'lines.*.buckets.TAXED' => 'nullable|numeric|min:0',
            'lines.*.buckets.NON_TAXED' => 'nullable|numeric|min:0',
            'lines.*.serial_ids' => 'nullable|array',
            'lines.*.serial_ids.*' => 'integer',
        ]);
    }

    /** Business that will own the line's goods: unsold = holding business of the source location; sold = selling business. */
    private function lineOwnerSetting(array $l): ?int
    {
        if (($l['line_type'] ?? null) === ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED) {
            $sid = ConsignmentSoldSource::whereKey((int) ($l['consignment_sold_source_id'] ?? 0))->value('setting_id');

            return $sid ? (int) $sid : null;
        }
        $locationId = (int) ($l['source_location_id'] ?? 0)
            ?: (int) ConsignmentReceivingDetail::whereKey((int) ($l['consignment_receiving_detail_id'] ?? 0))->with('receiving')->first()?->receiving?->location_id;
        $sid = $locationId ? Location::whereKey($locationId)->value('setting_id') : null;

        return $sid ? (int) $sid : null;
    }

    private function lines(array $data): array
    {
        return array_values($data['lines']);
    }

    /**
     * Draft metadata. Tab-3 submissions always produce the versioned product intent; the due date is derived from the
     * payment term unless the operator overrode it; non-PKP businesses never carry financial tax inputs.
     */
    private function meta(Request $request, array $data, ?ConsignmentBuyout $existing = null): array
    {
        $settingId = (int) session('setting_id');
        $invoice = $data['invoice_metadata'] ?? null;
        if (is_array($invoice)) {
            $invoice = array_intersect_key($invoice, array_flip(['supplier_invoice_number', 'invoice_date', 'due_date', 'due_date_manual', 'payment_term_id', 'tax_ref_no']));
            $invoice['invoice_date'] = $invoice['invoice_date'] ?? $data['date'];
            $invoice['due_date_manual'] = (bool) ($invoice['due_date_manual'] ?? false);
            if (! empty($invoice['payment_term_id']) && ! $invoice['due_date_manual']) {
                $days = (int) \Modules\Purchase\Entities\PaymentTerm::whereKey((int) $invoice['payment_term_id'])->where('is_active', true)->value('longevity');
                $invoice['due_date'] = \Illuminate\Support\Carbon::parse($invoice['invoice_date'])->addDays($days)->toDateString();
            }
            $invoice['billing_notes'] = $data['notes'] ?? null;
            $invoice = array_filter($invoice, fn ($v) => $v !== null && $v !== '');
        }

        return [
            'invoice_metadata' => $invoice,
            'pricing_intent' => $request->has('pricing_intent') ? $this->pricingIntent($request, $settingId) : null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * A legacy draft whose saved per-line terms disagree for a product needs one explicit product price or total; the
     * receipt default must not silently replace the saved decisions.
     */
    private function assertLegacyConflictsResolved(ConsignmentBuyout $buyout, Request $request): void
    {
        if (ConsignmentBuyoutPricingService::isProductIntent($buyout->pricing_intent) || ! $request->has('pricing_intent')) {
            return;
        }
        $submitted = (array) $request->input('pricing_intent.products', []);
        $selected = collect((array) $request->input('lines', []))->map(fn ($l) => (int) ConsignmentReceivingDetail::whereKey((int) ($l['consignment_receiving_detail_id'] ?? 0))->value('product_id'))->unique();
        foreach ($this->pricing->projectLegacyIntent($buyout)['conflicts'] as $pid) {
            $p = (array) ($submitted[$pid] ?? []);
            $explicit = fn ($k) => isset($p[$k]) && $p[$k] !== '' && $p[$k] !== null;
            if ($selected->contains($pid) && ! $explicit('unit_price') && ! $explicit('row_total_override')) {
                throw new \InvalidArgumentException('Draft lama memiliki harga tersimpan yang berbeda untuk produk yang sama; isi satu harga satuan atau total baris.');
            }
        }
    }

    /** Versioned product-level intent from the wizard; only recognised keys survive, tax is dropped for non-PKP. */
    private function pricingIntent(Request $request, int $settingId): array
    {
        $isPkp = (bool) \Modules\Setting\Entities\Setting::whereKey($settingId)->value('is_pkp');
        $in = (array) $request->input('pricing_intent', []);
        $products = [];
        foreach ((array) ($in['products'] ?? []) as $pid => $p) {
            if (! is_array($p)) {
                $products[$pid] = $p;          // the calculator reports malformed terms
                continue;
            }
            $p = array_intersect_key($p, array_flip(['unit_price', 'discount_type', 'discount_value', 'row_total_override', 'tax_id']));
            if (! $isPkp) {
                unset($p['tax_id']);
            }
            $products[$pid] = array_filter($p, fn ($v) => $v !== null && $v !== '');
        }
        ksort($products);

        return array_filter([
            'version' => ConsignmentBuyoutPricingService::INTENT_VERSION,
            'products' => $products,
            'rows' => $in['rows'] ?? null,                        // never accepted with product terms; reported as a blocker
            'global_discount_type' => in_array($in['global_discount_type'] ?? 'fixed', ['fixed', 'percentage'], true) ? ($in['global_discount_type'] ?? 'fixed') : 'fixed',
            'global_discount_value' => (float) ($in['global_discount_value'] ?? 0),
            'is_tax_included' => $isPkp ? filter_var($in['is_tax_included'] ?? true, FILTER_VALIDATE_BOOLEAN) : false,
        ], fn ($v) => $v !== null);
    }

    /**
     * Save the draft and its invoice files atomically: kept existing files (by id, this buyout only) plus newly uploaded
     * ones are referenced from invoice_metadata.attachments so approval freezes them with the other terms. A failure
     * rolls back the draft and removes any newly stored file; files dropped by a revision are removed after commit.
     */
    private function withAttachments(Request $request, ?ConsignmentBuyout $existing, \Closure $save, array $meta): ConsignmentBuyout
    {
        $created = [];
        $dropped = collect();
        try {
            $buyout = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $existing, $save, $meta, &$created, &$dropped) {
                $keepIds = collect((array) $request->input('keep_attachments', []))->map(fn ($v) => (int) $v)->all();
                $current = $existing ? $existing->getMedia(ConsignmentBuyout::ATTACHMENT_COLLECTION) : collect();
                $kept = $current->filter(fn ($m) => in_array((int) $m->id, $keepIds, true));
                $dropped = $current->reject(fn ($m) => in_array((int) $m->id, $keepIds, true));
                $files = array_values(array_filter((array) $request->file('attachments', [])));
                if ($kept->count() + count($files) > 10) {
                    throw new \InvalidArgumentException('Maksimal 10 lampiran faktur.');
                }

                $buyout = $save($meta);

                $refs = $kept->map(fn ($m) => $this->attachmentRef($m))->values()->all();
                foreach ($files as $file) {
                    $hash = hash_file('sha256', $file->getRealPath());
                    $media = $buyout->addMedia($file)
                        ->preservingOriginal()
                        ->withCustomProperties(['original_name' => $file->getClientOriginalName(), 'file_hash' => $hash])
                        ->toMediaCollection(ConsignmentBuyout::ATTACHMENT_COLLECTION);
                    $created[] = $media;
                    $refs[] = $this->attachmentRef($media);
                }
                $invoice = $buyout->fresh()->invoice_metadata ?? [];
                unset($invoice['attachments']);
                if ($refs) {
                    $invoice['attachments'] = $refs;
                }
                ConsignmentBuyout::whereKey($buyout->id)->update(['invoice_metadata' => $invoice ? json_encode($invoice) : null]);

                return $buyout->fresh();
            });
        } catch (\Throwable $e) {
            foreach ($created as $media) {
                try {
                    $path = $media->getPath();
                    if ($path && file_exists($path)) {
                        @unlink($path);
                    }
                    $media->delete();
                } catch (\Throwable $cleanup) {
                    \Illuminate\Support\Facades\Log::error('Failed to remove a buyout draft attachment after a failed save.', ['error' => $cleanup->getMessage()]);
                }
            }
            throw $e;
        }
        foreach ($dropped as $media) {
            $media->delete();
        }

        return $buyout;
    }

    private function attachmentRef($media): array
    {
        return [
            'media_id' => (int) $media->id,
            'original_name' => (string) ($media->getCustomProperty('original_name') ?: $media->file_name),
            'mime_type' => $media->mime_type,
            'size' => (int) $media->size,
            'sha256' => $media->getCustomProperty('file_hash'),
        ];
    }

    private function invoiceContext(int $settingId): array
    {
        $isPkp = (bool) \Modules\Setting\Entities\Setting::whereKey($settingId)->value('is_pkp');

        return [
            'isPkp' => $isPkp,
            'paymentTerms' => \Modules\Purchase\Entities\PaymentTerm::where('is_active', true)->get(['id', 'name', 'longevity']),
            'activeTaxes' => $isPkp ? \Modules\Setting\Entities\Tax::active()->get(['id', 'name', 'value']) : collect(),
        ];
    }
}
