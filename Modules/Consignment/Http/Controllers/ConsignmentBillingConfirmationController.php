<?php

namespace Modules\Consignment\Http\Controllers;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService;
use Modules\Consignment\Services\ConsignmentReceiptAllocationService;
use Modules\Consignment\Services\ConsignmentReturnEligibilityService;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;

class ConsignmentBillingConfirmationController extends Controller
{
    protected ConsignmentBillingConfirmationLifecycleService $lifecycleService;
    protected ConsignmentReturnEligibilityService $eligibilityService;
    protected ConsignmentReceiptAllocationService $receiptAllocationService;

    public function __construct(
        ConsignmentBillingConfirmationLifecycleService $lifecycleService,
        ConsignmentReturnEligibilityService $eligibilityService,
        ConsignmentReceiptAllocationService $receiptAllocationService
    ) {
        $this->lifecycleService = $lifecycleService;
        $this->eligibilityService = $eligibilityService;
        $this->receiptAllocationService = $receiptAllocationService;
    }

    public function index(Request $request)
    {
        abort_if(Gate::denies('consignments.allocations.access'), 403);
        $settingId = (int) session('setting_id');

        $query = ConsignmentBillingConfirmation::forSetting($settingId)
            ->with(['supplier', 'creator', 'approver']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        $confirmations = $query->latest('id')->paginate(25)->withQueryString();

        // The supplier filter is an AJAX Select2: resolve only the selected label
        // instead of loading the whole shared supplier collection into the view.
        $selectedSupplierText = Supplier::whereKey($request->integer('supplier_id'))->value('supplier_name');

        return view('consignment::confirmations.index', compact('confirmations', 'selectedSupplierText'));
    }

    public function create(Request $request)
    {
        abort_if(Gate::denies('consignments.allocations.create'), 403);
        $settingId = (int) session('setting_id');

        $selectedSupplierId = $request->integer('supplier_id');

        // Selector labels are resolved individually; the shared Supplier/Product
        // collections are never loaded into the view.
        $selectedSupplierText = Supplier::whereKey($selectedSupplierId)->value('supplier_name');
        $selectedFilterProductText = Product::whereKey($request->integer('filter_product_id'))->value('product_name');
        $selectedFilterLocationText = Location::whereKey($request->integer('filter_location_id'))->value('name');

        // Eligible sources are Consignment evidence: setting-scoped, searchable and
        // paginated so the page never loads every source at once.
        $soldSources = ConsignmentSoldSource::forSetting($settingId)
            ->where('has_reconstruction_blocker', false)
            ->when($request->filled('filter_product_id'), fn ($q) => $q->where('product_id', $request->integer('filter_product_id')))
            ->when($request->filled('filter_location_id'), fn ($q) => $q->where('location_id', $request->integer('filter_location_id')))
            ->when($request->filled('source_q'), fn ($q) => $q->searchTerm(trim($request->input('source_q'))))
            ->with(['sale', 'product', 'location', 'serials.serialNumber'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Attach eligibility calculation & receipt pools to available sold sources
        $eligibleSources = [];
        foreach ($soldSources as $src) {
            $elig = $this->eligibilityService->calculateSoldEligibility($src);
            if ($elig['remaining_quantity'] > 0 && ! $elig['has_conflict']) {
                $src->eligibility = $elig;

                if ($selectedSupplierId > 0) {
                    $src->receipt_pools = $this->receiptAllocationService->getEligibleReceiptPools(
                        $settingId,
                        $selectedSupplierId,
                        $src->product_id,
                        $src->location_id
                    );

                    $returnedSerialIds = $this->eligibilityService->getEffectiveReturnedSerialIds($src->dispatch_detail_id);

                    $resolvedSerials = [];
                    foreach ($src->serials as $soldSerial) {
                        $psn = $soldSerial->serialNumber;
                        if ($psn && !in_array($psn->id, $returnedSerialIds)) {
                            $lineage = $this->receiptAllocationService->resolveSerialLineage(
                                $psn,
                                $settingId,
                                $src->location_id
                            );
                            if (! $lineage['has_blocker'] && $lineage['supplier_id'] == $selectedSupplierId) {
                                $resolvedSerials[] = [
                                    'serial_number' => $psn->serial_number,
                                    'product_serial_number_id' => $psn->id,
                                    'consignment_receiving_detail_id' => $lineage['consignment_receiving_detail_id'],
                                    'receiving_number' => $lineage['receiving_number'],
                                ];
                            }
                        }
                    }
                    $src->resolved_serials = $resolvedSerials;
                } else {
                    $src->receipt_pools = [];
                    $src->resolved_serials = [];
                }

                $eligibleSources[] = $src;
            }
        }

        // Keep the paginator instance (so links() renders) while showing only the
        // eligible subset of the current page.
        $eligibleSources = $soldSources->setCollection(collect($eligibleSources));

        return view('consignment::confirmations.create', compact(
            'eligibleSources',
            'selectedSupplierId',
            'selectedSupplierText',
            'selectedFilterProductText',
            'selectedFilterLocationText'
        ));
    }

    /**
     * Normalize submitted allocation lines.
     *
     * Rows arrive keyed by consignment_sold_source_id rather than by row index,
     * because filtering and pagination reuse indexes across pages. Only rows the
     * user actually checked are kept, and each row keeps only its selected serial
     * allocations, so receipt-pool quantities and serial choices survive exactly
     * rather than collapsing to the parent checkbox.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSubmittedLines($rawLines): array
    {
        if (! is_array($rawLines)) {
            return [];
        }

        $lines = [];

        foreach ($rawLines as $key => $line) {
            if (! is_array($line) || empty($line['selected'])) {
                continue;
            }

            // Fall back to the array key, which is the sold-source id.
            $sourceId = $line['consignment_sold_source_id'] ?? $key;
            if (empty($sourceId)) {
                continue;
            }
            $line['consignment_sold_source_id'] = (int) $sourceId;

            if (! empty($line['receipt_allocations']) && is_array($line['receipt_allocations'])) {
                $line['receipt_allocations'] = array_values(array_filter(
                    $line['receipt_allocations'],
                    fn ($ra) => is_array($ra)
                        && ! empty($ra['consignment_receiving_detail_id'])
                        && (float) ($ra['allocated_base_quantity'] ?? 0) > 0
                ));
            }

            if (! empty($line['serialized_allocations']) && is_array($line['serialized_allocations'])) {
                $line['serialized_allocations'] = array_values(array_filter(
                    $line['serialized_allocations'],
                    fn ($sa) => is_array($sa) && ! empty($sa['selected'])
                ));
            }

            // Last write wins if the same source somehow appears twice.
            $lines[$line['consignment_sold_source_id']] = $line;
        }

        return array_values($lines);
    }

    public function store(Request $request)
    {
        abort_if(Gate::denies('consignments.allocations.create'), 403);
        $settingId = (int) session('setting_id');

        // Lines arrive keyed by consignment_sold_source_id (pagination reuses row
        // indexes, so indexes are never authoritative) and may include sources the
        // user selected on other filtered pages.
        $filteredLines = $this->normalizeSubmittedLines($request->input('lines', []));

        $request->merge(['lines' => $filteredLines]);

        $request->validate([
            'supplier_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('suppliers', 'id')->where('is_active', true),
            ],
            'date' => 'required|date',
            'lines' => 'required|array|min:1',
            'lines.*.consignment_sold_source_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('consignment_sold_sources', 'id')->where('setting_id', $settingId),
            ],
            'lines.*.allocated_base_quantity' => 'required|numeric|min:0.001',
        ]);

        try {
            $confirmation = $this->lifecycleService->createDraft(
                $settingId,
                (int) $request->supplier_id,
                $request->date,
                $request->lines,
                $request->notes,
                auth()->id()
            );

            toast('Draft konfirmasi alokasi berhasil dibuat.', 'success');
            return redirect()->route('consignments.confirmations.show', $confirmation->id);
        } catch (Exception $e) {
            // Surface as a validation error too, so the failure is visible in the
            // form (and to tests) rather than only in a transient toast.
            toast($e->getMessage(), 'error');
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        abort_if(Gate::denies('consignments.allocations.access'), 403);
        $settingId = (int) session('setting_id');

        $confirmation = ConsignmentBillingConfirmation::forSetting($settingId)
            ->with([
                'supplier',
                'creator',
                'submitter',
                'approver',
                'rejecter',
                'lines.soldSource.sale',
                'lines.product',
                'lines.location',
                'lines.receiptAllocations.receivingDetail.consignmentReceiving',
                'lines.serializedAllocations.serialNumber',
                'auditLogs.actor',
            ])
            ->findOrFail($id);

        return view('consignment::confirmations.show', compact('confirmation'));
    }

    public function edit(Request $request, $id)
    {
        abort_if(Gate::denies('consignments.allocations.edit'), 403);
        $settingId = (int) session('setting_id');

        $confirmation = ConsignmentBillingConfirmation::forSetting($settingId)
            ->with(['lines.receiptAllocations', 'lines.serializedAllocations'])
            ->findOrFail($id);

        if (! $confirmation->canEdit()) {
            toast('Hanya draft atau konfirmasi yang ditolak yang dapat diubah.', 'warning');
            return redirect()->route('consignments.confirmations.show', $confirmation->id);
        }

        // Supplier is read-only on edit: identity cannot change once allocation
        // evidence exists, so only its label is needed.
        $selectedSupplierId = $confirmation->supplier_id;
        $selectedSupplierText = Supplier::whereKey($selectedSupplierId)->value('supplier_name');
        $selectedFilterProductText = Product::whereKey($request->integer('filter_product_id'))->value('product_name');
        $selectedFilterLocationText = Location::whereKey($request->integer('filter_location_id'))->value('name');

        $soldSources = ConsignmentSoldSource::forSetting($settingId)
            ->where('has_reconstruction_blocker', false)
            ->when($request->filled('filter_product_id'), fn ($q) => $q->where('product_id', $request->integer('filter_product_id')))
            ->when($request->filled('filter_location_id'), fn ($q) => $q->where('location_id', $request->integer('filter_location_id')))
            ->when($request->filled('source_q'), fn ($q) => $q->searchTerm(trim($request->input('source_q'))))
            ->with(['sale', 'product', 'location', 'serials.serialNumber'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $eligibleSources = [];
        foreach ($soldSources as $src) {
            $elig = $this->eligibilityService->calculateSoldEligibility($src, $confirmation->id);
            if ($elig['remaining_quantity'] > 0 && ! $elig['has_conflict']) {
                $src->eligibility = $elig;
                
                $src->receipt_pools = $this->receiptAllocationService->getEligibleReceiptPools(
                    $settingId,
                    $selectedSupplierId,
                    $src->product_id,
                    $src->location_id,
                    $confirmation->id
                );

                $returnedSerialIds = $this->eligibilityService->getEffectiveReturnedSerialIds($src->dispatch_detail_id);

                $resolvedSerials = [];
                foreach ($src->serials as $soldSerial) {
                    $psn = $soldSerial->serialNumber;
                    if ($psn && !in_array($psn->id, $returnedSerialIds)) {
                        $lineage = $this->receiptAllocationService->resolveSerialLineage(
                            $psn,
                            $settingId,
                            $src->location_id,
                            $confirmation->id
                        );
                        if (! $lineage['has_blocker'] && $lineage['supplier_id'] == $selectedSupplierId) {
                            $resolvedSerials[] = [
                                'serial_number' => $psn->serial_number,
                                'product_serial_number_id' => $psn->id,
                                'consignment_receiving_detail_id' => $lineage['consignment_receiving_detail_id'],
                                'receiving_number' => $lineage['receiving_number'],
                            ];
                        }
                    }
                }
                $src->resolved_serials = $resolvedSerials;

                $eligibleSources[] = $src;
            }
        }

        $eligibleSources = $soldSources->setCollection(collect($eligibleSources));

        return view('consignment::confirmations.edit', compact(
            'confirmation',
            'eligibleSources',
            'selectedSupplierId',
            'selectedSupplierText',
            'selectedFilterProductText',
            'selectedFilterLocationText'
        ));
    }

    public function update(Request $request, $id)
    {
        abort_if(Gate::denies('consignments.allocations.edit'), 403);
        $settingId = (int) session('setting_id');

        $confirmation = ConsignmentBillingConfirmation::forSetting($settingId)->findOrFail($id);

        if (! $confirmation->canEdit()) {
            toast('Konfirmasi ini tidak dapat diubah.', 'error');
            return redirect()->route('consignments.confirmations.show', $confirmation->id);
        }

        $filteredLines = $this->normalizeSubmittedLines($request->input('lines', []));

        $request->merge(['lines' => $filteredLines]);

        // The submitted page only ever shows a filtered/paginated slice of the
        // sources, so the payload alone is not the complete draft. The visible set
        // scopes which saved lines this submit is allowed to delete.
        $request->validate([
            'lines' => 'array',
            'lines.*.consignment_sold_source_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('consignment_sold_sources', 'id')->where('setting_id', $settingId),
            ],
            'lines.*.allocated_base_quantity' => 'required|numeric|min:0.001',
            'visible_sold_source_ids' => 'array',
            'visible_sold_source_ids.*' => [
                'integer',
                \Illuminate\Validation\Rule::exists('consignment_sold_sources', 'id')->where('setting_id', $settingId),
            ],
        ]);

        $visibleSoldSourceIds = $request->has('visible_sold_source_ids')
            ? array_map('intval', $request->input('visible_sold_source_ids', []))
            : null;

        // Reconstruct the complete resulting draft (submitted lines plus saved lines
        // that were not visible) and require that it is not left empty.
        if ($visibleSoldSourceIds !== null) {
            $submittedIds = collect($request->lines)->pluck('consignment_sold_source_id')->map(fn ($v) => (int) $v)->all();
            $survivingHidden = $confirmation->lines()
                ->whereNotIn('consignment_sold_source_id', array_unique(array_merge($visibleSoldSourceIds, $submittedIds)))
                ->count();

            if (count($submittedIds) === 0 && $survivingHidden === 0) {
                return back()->withInput()->withErrors([
                    'lines' => 'Konfirmasi harus memiliki minimal satu baris alokasi.',
                ]);
            }
        } elseif (empty($request->lines)) {
            return back()->withInput()->withErrors([
                'lines' => 'Konfirmasi harus memiliki minimal satu baris alokasi.',
            ]);
        }

        try {
            $updated = $this->lifecycleService->updateDraft(
                $confirmation,
                $request->lines,
                $request->notes,
                auth()->id(),
                $visibleSoldSourceIds
            );

            toast('Draft konfirmasi alokasi berhasil diperbarui.', 'success');
            return redirect()->route('consignments.confirmations.show', $updated->id);
        } catch (Exception $e) {
            // Surface as a validation error too, so the failure is visible in the
            // form (and to tests) rather than only in a transient toast.
            toast($e->getMessage(), 'error');
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        abort_if(Gate::denies('consignments.allocations.edit'), 403);
        $settingId = (int) session('setting_id');

        $confirmation = ConsignmentBillingConfirmation::forSetting($settingId)->findOrFail($id);

        try {
            $this->lifecycleService->deleteDraft($confirmation);
            toast('Draft konfirmasi berhasil dihapus.', 'success');
            return redirect()->route('consignments.confirmations.index');
        } catch (Exception $e) {
            toast($e->getMessage(), 'error');
            return back();
        }
    }

    public function submit($id)
    {
        abort_if(Gate::denies('consignments.allocations.submit'), 403);
        $settingId = (int) session('setting_id');

        $confirmation = ConsignmentBillingConfirmation::forSetting($settingId)->findOrFail($id);

        try {
            $submitted = $this->lifecycleService->submitConfirmation($confirmation, auth()->id());
            toast('Konfirmasi alokasi berhasil diajukan untuk persetujuan.', 'success');
            return redirect()->route('consignments.confirmations.show', $submitted->id);
        } catch (Exception $e) {
            $this->reportUnexpectedLifecycleFailure($e, 'submit', $confirmation);
            toast('Pengajuan gagal: ' . $e->getMessage(), 'error');
            return back();
        }
    }

    public function approve($id)
    {
        abort_if(Gate::denies('consignments.allocations.approve'), 403);
        $settingId = (int) session('setting_id');

        $confirmation = ConsignmentBillingConfirmation::forSetting($settingId)->findOrFail($id);

        try {
            $approved = $this->lifecycleService->approveConfirmation($confirmation, auth()->id());
            toast('Konfirmasi alokasi berhasil disetujui.', 'success');
            return redirect()->route('consignments.confirmations.show', $approved->id);
        } catch (Exception $e) {
            $this->reportUnexpectedLifecycleFailure($e, 'approve', $confirmation);
            toast('Persetujuan gagal: ' . $e->getMessage(), 'error');
            return back();
        }
    }

    /**
     * Report unexpected failures so they reach the log, while leaving expected domain
     * validation failures as user-facing messages only.
     *
     * Domain rule violations (DomainException / InvalidArgumentException) are normal
     * outcomes of guarded workflows and would be log noise. Anything else — a lazy-loading
     * violation, a query error, a programming fault — is a defect worth investigating, so
     * it is reported with just the identifiers needed to find it. No invoice, attachment,
     * or payload contents are logged.
     */
    private function reportUnexpectedLifecycleFailure(\Throwable $e, string $action, $confirmation): void
    {
        if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException) {
            return;
        }

        // One entry only: report() routes through the exception handler to the log, so a
        // separate Log::error() here would duplicate every failure. The identifying
        // context is attached to that single report via the handler's context mechanism.
        // Passing the exception itself keeps the stack trace on this one entry, so the
        // structured context and the trace stay together in a single log record.
        Log::error("Consignment confirmation {$action} failed unexpectedly.", [
            'action' => $action,
            'confirmation_id' => $confirmation->id ?? null,
            'setting_id' => $confirmation->setting_id ?? null,
            'actor_id' => auth()->id(),
            'exception' => $e,
        ]);
    }

    public function reject(Request $request, $id)
    {
        abort_if(Gate::denies('consignments.allocations.reject'), 403);
        $settingId = (int) session('setting_id');

        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $confirmation = ConsignmentBillingConfirmation::forSetting($settingId)->findOrFail($id);

        try {
            $rejected = $this->lifecycleService->rejectConfirmation($confirmation, $request->rejection_reason, auth()->id());
            toast('Konfirmasi alokasi ditolak.', 'warning');
            return redirect()->route('consignments.confirmations.show', $rejected->id);
        } catch (Exception $e) {
            $this->reportUnexpectedLifecycleFailure($e, 'reject', $confirmation);
            toast('Penolakan gagal: ' . $e->getMessage(), 'error');
            return back();
        }
    }
}
