<?php

namespace Modules\Sale\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Services\GlobalSalePaymentService;
use Modules\Sale\DataTables\SalePaymentsDataTable;
use Modules\Setting\Entities\PaymentMethod;

class GlobalSalePaymentController extends Controller
{
    /**
     * 3.1 Index: List all eligible sales globally across settings
     * 3.2 Protected by salePayments.global.access
     */
    public function index()
    {
        abort_if(Gate::denies('salePayments.global.access'), 403);

        return view('sale::global-payments.index');
    }

    /**
     * 3.4 Show: Dedicated cross-setting read-only detail
     * Loads the sale's actual setting and payment history without mutation controls
     */
    public function show($sale_id)
    {
        abort_if(Gate::denies('salePayments.global.access'), 403);

        $sale = Sale::approvedUp()
            ->whereNull('archived_at')
            ->with(['customer', 'tenantSetting', 'saleDispatches', 'salePayments'])
            ->findOrFail($sale_id);

        // Enforce eligibility: must have positive live due amount
        if ($sale->live_due_amount <= 0) {
            abort(404, 'Penjualan ini tidak memiliki saldo tagihan yang positif.');
        }

        // Load sale's actual setting for company presentation
        $setting = $sale->tenantSetting;

        return view('sale::global-payments.show', compact('sale', 'setting'));
    }

    /**
     * 3.5 History: Adapt the sale-payment history view for global routing
     * Payment-only actions while preserving normal history behavior
     */
    public function history($sale_id, SalePaymentsDataTable $dataTable)
    {
        abort_if(Gate::denies('salePayments.global.access'), 403);

        $sale = Sale::approvedUp()
            ->whereNull('archived_at')
            ->findOrFail($sale_id);

        // Enforce eligibility: must have positive live due amount
        if ($sale->live_due_amount <= 0) {
            abort(404, 'Penjualan ini tidak memiliki saldo tagihan yang positif.');
        }

        return $dataTable->with([
            'sale_id' => $sale_id,
            'globalMode' => true
        ])->render('sale::global-payments.history', compact('sale'));
    }

    /**
     * 3.3 Create: Show allocation form with eligible candidates
     * Candidate loading across all settings for exact customer and approved-up eligibility
     */
    public function create($sale_id)
    {
        abort_if(Gate::denies('salePayments.global.access') || Gate::denies('salePayments.create'), 403);

        $startingSale = Sale::approvedUp()
            ->whereNull('archived_at')
            ->with(['customer'])
            ->findOrFail($sale_id);

        // Enforce starting-sale eligibility: must have positive live due amount
        if ($startingSale->live_due_amount <= 0) {
            toast('Penjualan ini tidak memiliki saldo tagihan yang positif.', 'error');
            return redirect()->route('sales.show', $startingSale->id);
        }

        // Load all candidates with same customer, approved-up status, positive live due
        $candidates = Sale::where('customer_id', $startingSale->customer_id)
            ->approvedUp()
            ->whereNull('archived_at')
            ->whereLiveDueAmountGreaterThan(0)
            ->with(['customer', 'tenantSetting', 'posCheckout.transactions', 'checkoutSale.checkout.transactions'])
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            toast('Tidak ada penjualan yang memenuhi syarat untuk pembayaran.', 'error');
            return redirect()->route('sales.global-payments.index');
        }

        $paymentMethods = PaymentMethod::all();

        return view('sale::global-payments.create', compact('startingSale', 'candidates', 'paymentMethods'));
    }

    /**
     * 3.2 Store: Submit atomic global payment
     * Protected by salePayments.global.access + salePayments.create
     * Uses idempotency middleware for mutations
     */
    public function store(Request $request, $sale_id, GlobalSalePaymentService $service)
    {
        abort_if(Gate::denies('salePayments.global.access') || Gate::denies('salePayments.create'), 403);

        $startingSale = Sale::approvedUp()
            ->whereNull('archived_at')
            ->findOrFail($sale_id);

        // Enforce starting-sale eligibility: must have positive live due amount
        if ($startingSale->live_due_amount <= 0) {
            throw ValidationException::withMessages([
                'sale_id' => 'Penjualan ini tidak memiliki saldo tagihan yang positif.',
            ]);
        }

        $request->validate([
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'note' => 'nullable|string|max:1000',
            'attachment' => 'nullable|string',
            'allocations' => 'required|array',
            'allocations.*' => 'numeric|min:0',
        ]);

        $attachmentPath = null;
        if ($request->attachment) {
            $basename = basename($request->attachment);
            $attachmentPath = \Illuminate\Support\Facades\Storage::path('temp/dropzone/' . $basename);
        }

        try {
            $service->storeMultiPayment($startingSale->customer_id, array_merge(
                $request->only(['reference', 'date', 'payment_method_id', 'note', 'allocations']),
                ['attachment' => $attachmentPath]
            ));

            toast('Pembayaran Global berhasil dibuat!', 'success');
            return redirect()->route('sales.global-payments.index');
        } finally {
            // Always clean up temp file after transaction completes (success or failure)
            if ($attachmentPath && file_exists($attachmentPath)) {
                @unlink($attachmentPath);
            }
        }
    }
}
