<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Support\Facades\Storage;
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
        abort_if(Gate::denies('purchasePayments.access'), 403);

        $purchase = Purchase::withArchived()->findOrFail($purchase_id);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        return $dataTable->render('purchase::payments.index', compact('purchase'));
    }


    public function create($purchase_id) {
        abort_if(Gate::denies('purchasePayments.create'), 403);

        $purchase = Purchase::withArchived()->findOrFail($purchase_id);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $payment_methods = PaymentMethod::all();
        return view('purchase::payments.create', compact('purchase', 'payment_methods'));
    }


    public function store(Request $request) {
        abort_if(Gate::denies('purchasePayments.create'), 403);


        $purchase = Purchase::withArchived()->findOrFail($request->purchase_id);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $request->validate([
            'date' => 'required|date',
            'reference' => 'required|string|max:255',
            'amount' => 'required|numeric|max:' . $purchase->due_amount,
            'note' => 'nullable|string|max:1000',
            'purchase_id' => 'required|integer|exists:purchases,id',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
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

            // After creating payment, recalculate from active payments
            $effectivePaid = $purchase->getEffectivePaidAmount();
            $dueAmount = max(0, $purchase->total_amount - $effectivePaid);
            $paymentStatus = $dueAmount <= 0.01 ? 'PAID' : ($effectivePaid > 0 ? 'PARTIAL' : 'UNPAID');

            $purchase->update([
                'paid_amount' => $effectivePaid,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
            ]);
        });

        toast('Pembayaran berhasil dibuat!', 'success');
        return redirect()->route('purchases.index');
    }


    public function edit($purchase_id, PurchasePayment $purchasePayment) {
        abort_if(Gate::denies('purchasePayments.edit'), 403);

        $purchase = Purchase::withArchived()->findOrFail($purchase_id);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);
        $this->ensurePurchaseBelongsToCurrentSetting($purchasePayment->purchase);

        return view('purchase::payments.edit', compact('purchasePayment', 'purchase'));
    }


    public function update(Request $request, PurchasePayment $purchasePayment) {
        abort_if(Gate::denies('purchasePayments.edit'), 403);

        $this->ensurePurchaseBelongsToCurrentSetting($purchasePayment->purchase);

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

            $purchasePayment->update([
                'date' => $request->date,
                'reference' => $request->reference,
                'amount' => $request->amount,
                'note' => $request->note,
                'purchase_id' => $request->purchase_id,
                'payment_method' => $request->payment_method
            ]);

            // After updating payment, recalculate from active payments
            $effectivePaid = $purchase->getEffectivePaidAmount();
            $dueAmount = max(0, $purchase->total_amount - $effectivePaid);
            $paymentStatus = $dueAmount <= 0.01 ? 'PAID' : ($effectivePaid > 0 ? 'PARTIAL' : 'UNPAID');

            $purchase->update([
                'paid_amount' => $effectivePaid,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus
            ]);
        });

        toast('Purchase Payment Updated!', 'info');

        return redirect()->route('purchases.index');
    }


    public function destroy(PurchasePayment $purchasePayment) {
        abort_if(Gate::denies('purchasePayments.delete'), 403);

        $this->ensurePurchaseBelongsToCurrentSetting($purchasePayment->purchase);

        // Per TODO 5: Only allow delete for invalidated payments
        if ($purchasePayment->status !== PurchasePayment::STATUS_INVALIDATED) {
            abort(403, 'Hanya pembayaran yang sudah dibatalkan (invalidated) yang dapat dihapus.');
        }

        DB::transaction(function () use ($purchasePayment) {
            $purchase = $purchasePayment->purchase;
            $purchasePayment->delete();

            // After deleting, recalculate totals
            $effectivePaid = $purchase->getEffectivePaidAmount();
            $dueAmount = max(0, $purchase->total_amount - $effectivePaid);
            $paymentStatus = $dueAmount <= 0.01 ? 'PAID' : ($effectivePaid > 0 ? 'PARTIAL' : 'UNPAID');

            $purchase->update([
                'paid_amount' => $effectivePaid,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
            ]);
        });

        toast('Pembayaran Pembelian Berhasil Dihapus!', 'warning');

        return redirect()->route('purchases.index');
    }

    public function invalidate(PurchasePayment $purchasePayment) {
        abort_if(Gate::denies('purchasePayments.delete'), 403);

        $this->ensurePurchaseBelongsToCurrentSetting($purchasePayment->purchase);

        if ($purchasePayment->status !== PurchasePayment::STATUS_ACTIVE) {
            abort(403, 'Hanya pembayaran aktif yang dapat dibatalkan.');
        }

        DB::transaction(function () use ($purchasePayment) {
            $purchase = $purchasePayment->purchase;
            
            $purchasePayment->update([
                'status' => PurchasePayment::STATUS_INVALIDATED,
                'invalidated_at' => now(),
                'invalidated_by' => auth()->id(),
            ]);

            // After invalidating, recalculate totals
            $effectivePaid = $purchase->getEffectivePaidAmount();
            $dueAmount = max(0, $purchase->total_amount - $effectivePaid);
            $paymentStatus = $dueAmount <= 0.01 ? 'PAID' : ($effectivePaid > 0 ? 'PARTIAL' : 'UNPAID');

            $purchase->update([
                'paid_amount' => $effectivePaid,
                'due_amount' => $dueAmount,
                'payment_status' => $paymentStatus,
            ]);
        });

        toast('Pembayaran Pembelian Berhasil Dibatalkan!', 'info');

        return redirect()->back();
    }

    public function datatable($purchase_id, PurchasePaymentsDataTable $dataTable)
    {
        $purchase = Purchase::withArchived()->findOrFail($purchase_id);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        return $dataTable->render('purchase::payments.index', compact('purchase'));
    }

    private function ensurePurchaseBelongsToCurrentSetting(Purchase $purchase): void
    {
        $currentSettingId = session('setting_id');

        if (! is_null($currentSettingId) && (int) $purchase->setting_id !== (int) $currentSettingId) {
            abort(404);
        }
    }
}
