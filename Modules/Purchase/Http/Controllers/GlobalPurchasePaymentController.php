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
        
        $purchase = \Modules\Purchase\Entities\Purchase::where('status', \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED)
            ->whereNull('archived_at')
            ->findOrFail($purchase_id);
        $supplier = \Modules\People\Entities\Supplier::findOrFail($purchase->supplier_id);
        
        $receivedNotes = \Modules\Purchase\Entities\ReceivedNote::where('po_id', $purchase->id)
            ->with([
                'purchase',
                'location',
                'receivedNoteDetails.purchaseDetail',
                'receivedNoteDetails.productSerialNumbers'
            ])
            ->get();

        $resolver = new \Modules\Purchase\Services\ReturnedSerialNumberResolver();
        $returnedSerials = $resolver->resolveForPurchase($purchase->id, $receivedNotes->flatMap->receivedNoteDetails->pluck('id'));
        $resolver->mapToDetails($receivedNotes->flatMap->receivedNoteDetails, $returnedSerials);
        
        $setting = \Modules\Setting\Entities\Setting::findOrFail($purchase->setting_id);
        
        // Use the standard purchase detail view but pass globalMode to adjust links/actions
        return view('purchase::show', [
            'purchase' => $purchase,
            'supplier' => $supplier,
            'receivedNotes' => $receivedNotes,
            'globalMode' => true,
            'setting' => $setting
        ]);
    }

    public function history($purchase_id, \Modules\Purchase\DataTables\PurchasePaymentsDataTable $dataTable)
    {
        abort_if(Gate::denies('purchasePayments.global.access'), 403);
        
        $purchase = \Modules\Purchase\Entities\Purchase::where('status', \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED)
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
        
        $purchase = \Modules\Purchase\Entities\Purchase::where('status', \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED)
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
        
        // Find candidate purchases for this supplier
        // Conditions: exact RECEIVED, non-archived, positive live outstanding balance, across all settings
        $candidates = \Modules\Purchase\Entities\Purchase::where('supplier_id', $supplier->id)
            ->where('status', \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED)
            ->whereNull('archived_at')
            ->whereLiveDueAmountGreaterThan(0)
            ->withSum(['purchasePayments as active_payments_sum' => function($q) {
                $q->where('status', \Modules\Purchase\Entities\PurchasePayment::STATUS_ACTIVE);
            }], 'amount')
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
        
        $payment_methods = \Modules\Setting\Entities\PaymentMethod::all();
        
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
}
