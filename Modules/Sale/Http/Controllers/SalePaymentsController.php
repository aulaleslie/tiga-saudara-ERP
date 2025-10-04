<?php

namespace Modules\Sale\Http\Controllers;

use Modules\Sale\DataTables\SalePaymentsDataTable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\PaymentMethod;

class SalePaymentsController extends Controller
{
    public function index($sale_id, SalePaymentsDataTable $dataTable) {
        abort_if(Gate::denies('salePayments.access'), 403);

        $sale = Sale::findOrFail($sale_id);

        return $dataTable->render('sale::payments.index', compact('sale'));
    }

    public function create($sale_id) {
        abort_if(Gate::denies('salePayments.create'), 403);

        $sale = Sale::findOrFail($sale_id);
        // Retrieve payment methods for the current setting.
        $payment_methods = PaymentMethod::where('setting_id', session('setting_id'))->get();

        return view('sale::payments.create', compact('sale', 'payment_methods'));
    }

    public function store(Request $request) {
        abort_if(Gate::denies('salePayments.create'), 403);

        // Retrieve sale to determine due amount.
        $sale = Sale::findOrFail($request->sale_id);

        $request->validate([
            'date'               => 'required|date',
            'reference'          => 'required|string|max:255',
            'amount'             => 'required|numeric|max:' . (float) $sale->due_amount,
            'note'               => 'nullable|string|max:1000',
            'sale_id'            => 'required',
            'payment_method_id'  => 'required|integer|exists:payment_methods,id',
            'attachment'         => 'nullable|string', // file upload via Dropzone returns file name
        ], [
            'amount.max' => 'The payment amount cannot be greater than the due amount.'
        ]);

        DB::transaction(function () use ($request, $sale) {
            // Create the sale payment record.
            $payment = SalePayment::create([
                'date'              => $request->date,
                'reference'         => $request->reference,
                'amount'            => $request->amount,
                'note'              => $request->note,
                'sale_id'           => $request->sale_id,
                'payment_method_id' => $request->payment_method_id,
                'payment_method'    => '', // Optionally fill in later from PaymentMethod
            ]);

            // If an attachment exists, add it to the payment's media collection.
            if ($request->attachment) {
                $payment->addMedia(Storage::path('temp/dropzone/' . $request->attachment))
                    ->toMediaCollection('attachments');
            }

            // Update the sale amounts.
            $due_amount = round((float) $sale->due_amount - (float) $request->amount, 2);
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
                'paid_amount'    => round((float) $sale->paid_amount + (float) $request->amount, 2),
                'due_amount'     => $due_amount,
                'payment_status' => $payment_status,
            ]);
        });

        toast('Pembayaran berhasil dibuat!', 'success');

        return redirect()->route('sales.index');
    }

    public function edit($sale_id, SalePayment $salePayment) {
        abort_if(Gate::denies('salePayments.edit'), 403);

        $sale = Sale::findOrFail($sale_id);
        // Retrieve payment methods for the current setting.
        $payment_methods = PaymentMethod::where('setting_id', session('setting_id'))->get();

        return view('sale::payments.edit', compact('salePayment', 'sale', 'payment_methods'));
    }

    public function update(Request $request, SalePayment $salePayment) {
        abort_if(Gate::denies('salePayments.edit'), 403);

        // Retrieve sale to check due amount.
        $sale = $salePayment->sale;

        $request->validate([
            'date'               => 'required|date',
            'reference'          => 'required|string|max:255',
            'amount'             => 'required|numeric|max:' . ((float) $sale->due_amount + (float) $salePayment->amount),
            'note'               => 'nullable|string|max:1000',
            'sale_id'            => 'required',
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

        $salePayment->delete();

        toast('Sale Payment Deleted!', 'warning');

        return redirect()->route('sales.index');
    }
}
