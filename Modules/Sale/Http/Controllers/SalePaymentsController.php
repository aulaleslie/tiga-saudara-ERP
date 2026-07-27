<?php

namespace Modules\Sale\Http\Controllers;

use Modules\Sale\DataTables\SalePaymentsDataTable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Services\SalePaymentSettlementService;
use Modules\Setting\Entities\PaymentMethod;

class SalePaymentsController extends Controller
{
    public function index($sale_id, SalePaymentsDataTable $dataTable) {
        abort_if(Gate::denies('salePayments.access'), 403);

        $sale = Sale::findOrFail($sale_id);

        return $dataTable->with(['sale_id' => $sale_id])->render('sale::payments.index', compact('sale'));
    }

    public function create($sale_id) {
        abort_if(Gate::denies('salePayments.create'), 403);

        $sale = Sale::findOrFail($sale_id);
        $payment_methods = PaymentMethod::all();

        $customerCredits = CustomerCredit::query()
            ->with('saleReturn')
            ->open()
            ->where('customer_id', $sale->customer_id)
            ->orderByDesc('created_at')
            ->get();

        return view('sale::payments.create', compact('sale', 'payment_methods', 'customerCredits'));
    }

    public function store(Request $request) {
        abort_if(Gate::denies('salePayments.create'), 403);

        // Validate request shape and scalar formats
        $validated = $request->validate([
            'date'               => 'required|date',
            'reference'          => 'required|string|max:255',
            'amount'             => 'required|numeric|min:0',
            'note'               => 'nullable|string|max:1000',
            'sale_id'            => 'required|integer|exists:sales,id',
            'payment_method_id'  => 'required|integer|exists:payment_methods,id',
            'attachment'         => 'nullable|string',
            'credit_customer_credit_id' => 'nullable|integer|exists:customer_credits,id',
            'credit_amount'      => 'nullable|numeric|min:0',
        ]);

        // Retrieve sale to pass customer_id to service
        $sale = Sale::findOrFail($request->sale_id);

        // Normalize amounts for service call
        $normalized = [
            'amount' => (float) $validated['amount'],
            'date' => $validated['date'],
            'reference' => $validated['reference'],
            'payment_method_id' => (int) $validated['payment_method_id'],
            'note' => $validated['note'] ?? null,
            'attachment' => $validated['attachment'] ?? null,
            'credit_customer_credit_id' => !empty($validated['credit_customer_credit_id'])
                ? (int) $validated['credit_customer_credit_id']
                : null,
            'credit_amount' => !empty($validated['credit_amount'])
                ? (float) $validated['credit_amount']
                : null,
        ];

        // Delegate to service for authoritative settlement logic and locking
        $service = new SalePaymentSettlementService();
        $service->settle($sale->id, $sale->customer_id, $normalized);

        toast('Pembayaran berhasil dibuat!', 'success');

        return redirect()->route('sales.index');
    }

    public function edit($sale_id, SalePayment $salePayment) {
        abort_if(Gate::denies('salePayments.edit'), 403);

        $sale = Sale::findOrFail($sale_id);
        $payment_methods = PaymentMethod::all();

        if ($salePayment->creditApplications()->exists()) {
            toast('Pembayaran dengan kredit tidak dapat diedit. Batalkan pembayaran dan buat baru jika diperlukan.', 'warning');
            return redirect()->route('sale-payments.index', $sale_id);
        }

        return view('sale::payments.edit', compact('salePayment', 'sale', 'payment_methods'));
    }

    public function update(Request $request, SalePayment $salePayment) {
        abort_if(Gate::denies('salePayments.edit'), 403);

        // Retrieve sale to check due amount.
        $sale = $salePayment->sale;

        if ($salePayment->creditApplications()->exists()) {
            toast('Pembayaran dengan kredit tidak dapat diperbarui.', 'warning');
            return redirect()->route('sale-payments.index', $sale->id);
        }

        $request->validate([
            'date'               => 'required|date',
            'reference'          => 'required|string|max:255',
            'amount'             => 'required|numeric|max:' . ((float) $sale->due_amount + (float) $salePayment->amount),
            'note'               => 'nullable|string|max:1000',
            'sale_id'            => 'required|integer|exists:sales,id',
            'payment_method_id'  => 'required|integer|exists:payment_methods,id',
            // Attachment is optional on update.
            'attachment'         => 'nullable|string',
        ], [
            'amount.max' => 'The payment amount cannot be greater than the due amount.'
        ]);

        DB::transaction(function () use ($request, $salePayment, $sale) {
            $due_amount = round((float) $sale->due_amount + (float) $salePayment->amount - (float) $request->amount, 2);
            $due_amount = max($due_amount, 0);
            $total_amount = round((float) $sale->total_amount, 2);

            if (round($due_amount, 2) >= $total_amount) {
                $payment_status = 'Unpaid';
            } elseif ($due_amount > 0) {
                $payment_status = 'Partial';
            } else {
                $payment_status = 'Paid';
            }

            $sale->update([
                'paid_amount'    => round(((float) $sale->paid_amount - (float) $salePayment->amount) + (float) $request->amount, 2),
                'due_amount'     => $due_amount,
                'payment_status' => $payment_status,
            ]);

            $salePayment->update([
                'date'              => $request->date,
                'reference'         => $request->reference,
                'amount'            => $request->amount,
                'note'              => $request->note,
                'sale_id'           => $request->sale_id,
                'payment_method_id' => $request->payment_method_id,
                'payment_method'    => '', // Optionally update based on PaymentMethod
            ]);

            // (Optional) Handle attachment update if needed.
            // For example, you might add a new attachment if one is provided.
            if ($request->attachment) {
                $salePayment->addMedia(Storage::path('temp/dropzone/' . $request->attachment))
                    ->toMediaCollection('attachments');
            }
        });

        toast('Sale Payment Updated!', 'info');

        return redirect()->route('sales.index');
    }

    public function destroy(SalePayment $salePayment) {
        abort_if(Gate::denies('salePayments.delete'), 403);

        if ($salePayment->creditApplications()->exists()) {
            toast('Pembayaran dengan kredit tidak dapat dihapus.', 'warning');
            return redirect()->route('sale-payments.index', $salePayment->sale_id);
        }

        DB::transaction(function () use ($salePayment) {
            $sale = $salePayment->sale;
            $deletedAmount = round((float) $salePayment->amount, 2);

            $salePayment->delete();

            // Recalculate sale balances
            $paid_amount = round((float) $sale->paid_amount - $deletedAmount, 2);
            $paid_amount = max($paid_amount, 0);
            $due_amount = round((float) $sale->total_amount - $paid_amount, 2);
            $due_amount = max($due_amount, 0);
            $total_amount = round((float) $sale->total_amount, 2);

            if ($paid_amount <= 0) {
                $payment_status = 'Unpaid';
            } elseif ($due_amount > 0) {
                $payment_status = 'Partial';
            } else {
                $payment_status = 'Paid';
            }

            $sale->update([
                'paid_amount' => $paid_amount,
                'due_amount' => $due_amount,
                'payment_status' => $payment_status,
            ]);
        });

        toast('Sale Payment Deleted!', 'warning');

        return redirect()->route('sales.index');
    }
}
