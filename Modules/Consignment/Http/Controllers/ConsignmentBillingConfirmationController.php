<?php

namespace Modules\Consignment\Http\Controllers;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService;
use Modules\Consignment\Services\ConsignmentReceiptAllocationService;
use Modules\Consignment\Services\ConsignmentReturnEligibilityService;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;

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
        // Suppliers are shared master data: not scoped by setting.
        $suppliers = Supplier::orderBy('supplier_name')->get();

        return view('consignment::confirmations.index', compact('confirmations', 'suppliers'));
    }

    public function create(Request $request)
    {
        abort_if(Gate::denies('consignments.allocations.create'), 403);
        $settingId = (int) session('setting_id');

        // Suppliers are shared master data: not scoped by setting.
        $suppliers = Supplier::orderBy('supplier_name')->get();
        $selectedSupplierId = $request->integer('supplier_id');

        $soldSources = ConsignmentSoldSource::forSetting($settingId)
            ->where('has_reconstruction_blocker', false)
            ->with(['sale', 'product', 'location', 'serials.serialNumber'])
            ->get();

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

        return view('consignment::confirmations.create', compact('suppliers', 'eligibleSources', 'selectedSupplierId'));
    }

    public function store(Request $request)
    {
        abort_if(Gate::denies('consignments.allocations.create'), 403);
        $settingId = (int) session('setting_id');

        // Filter lines to only include selected/checked rows
        $rawLines = $request->input('lines', []);
        $filteredLines = array_values(array_filter($rawLines, function ($line) {
            return ! empty($line['consignment_sold_source_id']) && ! empty($line['selected']);
        }));

        foreach ($filteredLines as &$line) {
            if (!empty($line['serialized_allocations'])) {
                $line['serialized_allocations'] = array_values(array_filter($line['serialized_allocations'], function ($sa) {
                    return !empty($sa['selected']);
                }));
            }
        }
        unset($line);

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
            toast($e->getMessage(), 'error');
            return back()->withInput();
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

    public function edit($id)
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

        // Suppliers are shared master data: not scoped by setting.
        $suppliers = Supplier::orderBy('supplier_name')->get();
        $selectedSupplierId = $confirmation->supplier_id;

        $soldSources = ConsignmentSoldSource::forSetting($settingId)
            ->where('has_reconstruction_blocker', false)
            ->with(['sale', 'product', 'location', 'serials.serialNumber'])
            ->get();

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

        return view('consignment::confirmations.edit', compact('confirmation', 'suppliers', 'eligibleSources'));
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

        // Filter lines to only include selected/checked rows
        $rawLines = $request->input('lines', []);
        $filteredLines = array_values(array_filter($rawLines, function ($line) {
            return ! empty($line['consignment_sold_source_id']) && ! empty($line['selected']);
        }));

        foreach ($filteredLines as &$line) {
            if (!empty($line['serialized_allocations'])) {
                $line['serialized_allocations'] = array_values(array_filter($line['serialized_allocations'], function ($sa) {
                    return !empty($sa['selected']);
                }));
            }
        }
        unset($line);

        $request->merge(['lines' => $filteredLines]);

        $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.consignment_sold_source_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('consignment_sold_sources', 'id')->where('setting_id', $settingId),
            ],
            'lines.*.allocated_base_quantity' => 'required|numeric|min:0.001',
        ]);

        try {
            $updated = $this->lifecycleService->updateDraft(
                $confirmation,
                $request->lines,
                $request->notes,
                auth()->id()
            );

            toast('Draft konfirmasi alokasi berhasil diperbarui.', 'success');
            return redirect()->route('consignments.confirmations.show', $updated->id);
        } catch (Exception $e) {
            toast($e->getMessage(), 'error');
            return back()->withInput();
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
            toast('Persetujuan gagal: ' . $e->getMessage(), 'error');
            return back();
        }
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
            toast('Penolakan gagal: ' . $e->getMessage(), 'error');
            return back();
        }
    }
}
