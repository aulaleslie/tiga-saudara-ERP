<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GlobalPurchasePaymentController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('purchasePayments.global.access'), 403);
        
        return view('purchase::payments.global-index');
    }

    public function show($purchase_id)
    {
        abort_if(Gate::denies('purchasePayments.global.access'), 403);

        $purchase = \Modules\Purchase\Entities\Purchase::globalPaymentEligible()
            ->whereNull('archived_at')
            ->findOrFail($purchase_id);

        $purchase->load([
            'tags',
            'reportingDateAudits.actor',
            'dueDateAudits.actor',
            'consignmentBillingConfirmation',
            'purchaseDetails.consignmentLineages.confirmationLine',
            'purchaseDetails.uomNormalizationLines.batch.oldBaseUnit',
            'purchaseDetails.uomNormalizationLines.batch.newBaseUnit',
            'purchaseDetails.uomNormalizationLines.batch.legacyBaseUnit',
        ]);

        $supplier = \Modules\People\Entities\Supplier::findOrFail($purchase->supplier_id);

        $receivedNotes = \Modules\Purchase\Entities\ReceivedNote::where('po_id', $purchase->id)
            ->with([
                'purchase',
                'location',
                'receivedNoteDetails.purchaseDetail',
                'receivedNoteDetails.productSerialNumbers',
                'receivedNoteDetails.uomNormalizationLines.batch.oldBaseUnit',
                'receivedNoteDetails.uomNormalizationLines.batch.newBaseUnit',
                'receivedNoteDetails.uomNormalizationLines.batch.legacyBaseUnit',
            ])
            ->get();

        $resolver = new \Modules\Purchase\Services\ReturnedSerialNumberResolver();
        $returnedSerials = $resolver->resolveForPurchase($purchase->id, $receivedNotes->flatMap->receivedNoteDetails->pluck('id'));
        $resolver->mapToDetails($receivedNotes->flatMap->receivedNoteDetails, $returnedSerials);

        $setting = \Modules\Setting\Entities\Setting::findOrFail($purchase->setting_id);

        $normBatches = app(\Modules\Purchase\Services\PurchaseNormalizationHistoryQueryService::class)
            ->getExecutedBatchesForPurchase($purchase);

        // Use the standard purchase detail view but pass globalMode to adjust links/actions
        return view('purchase::show', [
            'purchase' => $purchase,
            'supplier' => $supplier,
            'receivedNotes' => $receivedNotes,
            'normBatches' => $normBatches,
            'globalMode' => true,
            'setting' => $setting
        ]);
    }

    public function history($purchase_id, \Modules\Purchase\DataTables\PurchasePaymentsDataTable $dataTable)
    {
        abort_if(Gate::denies('purchasePayments.global.access'), 403);

        $purchase = \Modules\Purchase\Entities\Purchase::globalPaymentEligible()
            ->whereNull('archived_at')
            ->findOrFail($purchase_id);

        return $dataTable->with(['globalMode' => true])->render('purchase::payments.index', [
            'purchase' => $purchase,
            'globalMode' => true
        ]);
    }

    public function datatable($purchase_id, \Modules\Purchase\DataTables\PurchasePaymentsDataTable $dataTable)
    {
        abort_if(Gate::denies('purchasePayments.global.access'), 403);

        $purchase = \Modules\Purchase\Entities\Purchase::globalPaymentEligible()
            ->whereNull('archived_at')
            ->findOrFail($purchase_id);
        
        return $dataTable->with(['globalMode' => true])->render('purchase::payments.index', [
            'purchase' => $purchase,
            'globalMode' => true
        ]);
    }

    public function create($supplier_id)
    {
        abort_if(Gate::denies('purchasePayments.global.access') || Gate::denies('purchasePayments.create'), 403);

        $supplier = \Modules\People\Entities\Supplier::findOrFail($supplier_id);

        $startingPurchaseId = request('purchase_id');

        // Find candidate purchases for this supplier
        // Conditions: global-payment-eligible status, non-archived, positive live outstanding balance, across all settings
        $candidatesQuery = \Modules\Purchase\Entities\Purchase::where('supplier_id', $supplier->id)
            ->globalPaymentEligible()
            ->whereNull('archived_at')
            ->whereLiveDueAmountGreaterThan(0)
            ->withSum(['purchasePayments as active_payments_sum' => function($q) {
                $q->where('status', \Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE);
            }], 'amount');

        if ($startingPurchaseId) {
            $candidatesQuery->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $startingPurchaseId]);
        }

        $candidates = $candidatesQuery
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();
            
        // If a starting purchase is provided, ensure it's eligible
        $startingPurchaseId = request('purchase_id');
        $startingPurchase = null;
        
        if ($startingPurchaseId) {
            $startingPurchase = $candidates->firstWhere('id', $startingPurchaseId);
            if (!$startingPurchase) {
                toast('Starting purchase is not eligible for payment.', 'error');
                return redirect()->route('purchases.global-payments.index');
            }
        }
        
        $payment_methods = \Modules\Setting\Entities\PaymentMethod::where('is_active', true)->get();
        
        return view('purchase::payments.global-create', compact('supplier', 'candidates', 'startingPurchase', 'payment_methods'));
    }

    public function store(Request $request, $supplier_id, \Modules\Purchase\Services\GlobalPurchasePaymentService $service)
    {
        abort_if(Gate::denies('purchasePayments.global.access') || Gate::denies('purchasePayments.create'), 403);

        $request->validate([
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'note' => 'nullable|string',
            'attachment' => 'nullable|string',
            'allocations' => 'required|array',
            'allocations.*' => 'numeric|min:0',
        ]);

        $service->storeMultiPayment($supplier_id, $request->only([
            'reference', 'date', 'payment_method_id', 'note', 'attachment', 'allocations'
        ]));

        toast('Pembayaran Global berhasil dibuat!', 'success');
        return redirect()->route('purchases.global-payments.index');
    }

    public function editMonetary($purchase_id)
    {
        abort_unless(
            Gate::allows('purchasePayments.global.access') &&
            Gate::allows('purchases.update') &&
            Gate::allows('purchases.received.monetary.edit'),
            403
        );

        $purchase = \Modules\Purchase\Entities\Purchase::globalPaymentEligible()
            ->whereNull('archived_at')
            ->findOrFail($purchase_id);

        abort_unless($purchase->resolveEditMode() === \Modules\Purchase\Entities\Purchase::EDIT_MODE_MONETARY_ONLY, 403);

        $editMode = \Modules\Purchase\Entities\Purchase::EDIT_MODE_MONETARY_ONLY;

        return view('purchase::edit', [
            'purchase' => $purchase,
            'editMode' => $editMode,
            'globalMode' => true,
        ]);
    }

    public function updateDateAdjustment(Request $request, $purchase_id)
    {
        abort_if(Gate::denies('purchasePayments.global.access'), 403);

        $purchase = \Modules\Purchase\Entities\Purchase::globalPaymentEligible()
            ->whereNull('archived_at')
            ->findOrFail($purchase_id);

        $validated = $request->validate([
            'reporting_action' => 'sometimes|string|in:keep,set,clear',
            'reporting_date' => 'nullable|date',
            'due_date_action' => 'sometimes|string|in:keep,set',
            'due_date' => 'nullable|date',
            'reason' => 'required|string|min:1|max:255',
        ]);

        $command = \App\DTOs\DateAdjustmentCommand::fromArray($validated);

        try {
            $result = app(\App\Services\DocumentDateAdjustmentService::class)->adjustDates(
                $purchase,
                $command,
                auth()->user(),
                authorize: true,
                isGlobal: true
            );

            return response()->json([
                'success' => true,
                'message' => 'Penyesuaian tanggal berhasil disimpan',
                'effective_date' => $result->document->effective_date,
                'due_date' => $result->document->due_date,
                'reporting_audit' => $result->reportingAudit,
                'due_date_audit' => $result->dueDateAudit,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
