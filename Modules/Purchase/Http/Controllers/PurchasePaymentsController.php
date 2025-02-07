<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Modules\Purchase\DataTables\PurchaseDataTable;
use Modules\Purchase\DataTables\PurchasePaymentsDataTable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\PaymentMethod;

class PurchasePaymentsController extends Controller
{

    public function index($purchase_id, PurchasePaymentsDataTable $dataTable) {
        abort_if(Gate::denies('access_purchase_payments'), 403);

        $purchase = Purchase::findOrFail($purchase_id);

        return $dataTable->render('purchase::payments.index', compact('purchase'));
    }


    public function create($purchase_id) {
        abort_if(Gate::denies('access_purchase_payments'), 403);

        $purchase = Purchase::findOrFail($purchase_id);

        $payment_methods = PaymentMethod::where('setting_id', session('setting_id'))->get();
        return view('purchase::payments.create', compact('purchase', 'payment_methods'));
    }


    public function store(Request $request) {
        abort_if(Gate::denies('access_purchase_payments'), 403);


        $purchase = Purchase::findOrFail($request->purchase_id);

        $request->validate([
            'date' => 'required|date',
            'reference' => 'required|string|max:255',
            'amount' => 'required|numeric|max:' . $purchase->due_amount,
            'note' => 'nullable|string|max:1000',
            'purchase_id' => 'required',
            'payment_method_id' => 'required|string|max:255',
            'attachment' => 'nullable|string', // Validation for file upload
        ], [
            'amount.max' => 'The payment amount cannot be greater than the due amount.'
        ]);

        DB::transaction(function () use ($request, $purchase) {
            $payment = PurchasePayment::create([
                'date' => $request->date,
                'reference' => $request->reference,
                'amount' => $request->amount,
                'note' => $request->note,
                'purchase_id' => $request->purchase_id,
                'payment_method_id' => $request->payment_method_id,
                'payment_method' => '',
            ]);

            // Store the uploaded file if it exists
            if ($request->attachment) {
                $payment->addMedia(Storage::path('temp/dropzone/' . $request->attachment))->toMediaCollection('attachments');
            }

            $due_amount = $purchase->due_amount - $request->amount;

            $payment_status = $due_amount == $purchase->total_amount ? 'Unpaid' : ($due_amount > 0 ? 'Partial' : 'Paid');

            $purchase->update([
                'paid_amount' => $purchase->paid_amount + $request->amount,
                'due_amount' => $due_amount,
                'payment_status' => $payment_status,
            ]);
        });

        toast('Purchase Payment Created!', 'success');
        return redirect()->route('purchases.index');
    }


    public function edit($purchase_id, PurchasePayment $purchasePayment) {
        abort_if(Gate::denies('access_purchase_payments'), 403);

        $purchase = Purchase::findOrFail($purchase_id);

        return view('purchase::payments.edit', compact('purchasePayment', 'purchase'));
    }


    public function update(Request $request, PurchasePayment $purchasePayment) {
        abort_if(Gate::denies('access_purchase_payments'), 403);

        $request->validate([
            'date' => 'required|date',
            'reference' => 'required|string|max:255',
            'amount' => 'required|numeric',
            'note' => 'nullable|string|max:1000',
            'purchase_id' => 'required',
            'payment_method' => 'required|string|max:255'
        ]);

        DB::transaction(function () use ($request, $purchasePayment) {
            $purchase = $purchasePayment->purchase;

            $due_amount = ($purchase->due_amount + $purchasePayment->amount) - $request->amount;

            if ($due_amount == $purchase->total_amount) {
                $payment_status = 'Unpaid';
            } elseif ($due_amount > 0) {
                $payment_status = 'Partial';
            } else {
                $payment_status = 'Paid';
            }

            $purchase->update([
                'paid_amount' => (($purchase->paid_amount - $purchasePayment->amount) + $request->amount) * 100,
                'due_amount' => $due_amount * 100,
                'payment_status' => $payment_status
            ]);

            $purchasePayment->update([
                'date' => $request->date,
                'reference' => $request->reference,
                'amount' => $request->amount,
                'note' => $request->note,
                'purchase_id' => $request->purchase_id,
                'payment_method' => $request->payment_method
            ]);
        });

        toast('Purchase Payment Updated!', 'info');

        return redirect()->route('purchases.index');
    }


    public function destroy(PurchasePayment $purchasePayment) {
        abort_if(Gate::denies('access_purchase_payments'), 403);

        $purchasePayment->delete();

        toast('Purchase Payment Deleted!', 'warning');

        return redirect()->route('purchases.index');
    }

    public function datatable($purchase_id, PurchasePaymentsDataTable $dataTable)
    {
        $purchase = Purchase::findOrFail($purchase_id);

        return $dataTable->render('purchase::payments.index', compact('purchase'));
    }
}
