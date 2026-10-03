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
use Modules\Purchase\Services\IndividualPurchasePaymentAttachmentStagingService;
use Modules\Purchase\Services\PurchasePaymentStoreService;
use Modules\Setting\Entities\PaymentMethod;

class PurchasePaymentsController extends Controller
{
    public function __construct(
        protected PurchasePaymentStoreService $paymentStoreService,
        protected IndividualPurchasePaymentAttachmentStagingService $attachmentStagingService
    ) {}

    public function index($purchase_id, PurchasePaymentsDataTable $dataTable) {
        abort_if(Gate::denies('purchasePayments.access'), 403);

        $purchase = Purchase::withArchived()
            ->with(['tenantSetting', 'supplier', 'consignmentBillingConfirmation'])
            ->findOrFail($purchase_id);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        return $dataTable->render('purchase::payments.index', compact('purchase'));
    }


    public function create($purchase_id) {
        abort_if(Gate::denies('purchasePayments.create'), 403);

        $purchase = Purchase::withArchived()
            ->with(['tenantSetting', 'supplier', 'consignmentBillingConfirmation'])
            ->findOrFail($purchase_id);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $payment_methods = PaymentMethod::active()->get();
        return view('purchase::payments.create', compact('purchase', 'payment_methods'));
    }


    public function store(Request $request) {
        abort_if(Gate::denies('purchasePayments.create'), 403);

        $purchase = Purchase::withArchived()
            ->with(['tenantSetting', 'supplier', 'consignmentBillingConfirmation'])
            ->findOrFail($request->purchase_id);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $validated = $request->validate([
            'date' => 'required|date',
            'reference' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01|max:' . $purchase->live_due_amount,
            'note' => 'nullable|string|max:1000',
            'purchase_id' => 'required|integer|exists:purchases,id',
            'payment_method_id' => 'required|integer|exists:payment_methods,id,is_active,1',
            'attachments' => 'nullable|array',
            'attachments.*' => 'string',
            'attachment' => 'nullable|string', // Backward-compatible fallback
        ], [
            'amount.min' => 'Jumlah pembayaran harus minimal 0.01.',
            'amount.max' => 'The payment amount cannot be greater than the due amount.'
        ]);

        $attachments = [];
        if (!empty($validated['attachments']) && is_array($validated['attachments'])) {
            $attachments = array_values(array_filter($validated['attachments']));
        } elseif (!empty($validated['attachment'])) {
            $attachments = [$validated['attachment']];
        }

        $this->paymentStoreService->storeWithAttachments(
            purchase: $purchase,
            actor: auth()->user(),
            data: $validated,
            attachments: $attachments
        );

        toast('Pembayaran berhasil dibuat!', 'success');
        return redirect()->route('purchases.show', $purchase);
    }


    public function edit($purchase_id, PurchasePayment $purchasePayment) {
        abort_if(Gate::denies('purchasePayments.access'), 403);

        $purchase = Purchase::withArchived()->findOrFail($purchase_id);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);
        $this->ensurePurchaseBelongsToCurrentSetting($purchasePayment->purchase);

        if ((int) $purchasePayment->purchase_id !== (int) $purchase->id) {
            abort(404);
        }

        return view('purchase::payments.edit', compact('purchasePayment', 'purchase'));
    }


    public function update(Request $request, PurchasePayment $purchasePayment) {
        abort_if(Gate::denies('purchasePayments.edit'), 403);

        $purchase = $purchasePayment->purchase;
        if (! $purchase) {
            abort(404);
        }
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        if ($purchase->isArchived()) {
            abort(403, 'Tidak dapat memperbarui catatan pembayaran untuk pembelian yang diarsipkan.');
        }

        if (! $purchasePayment->isActive()) {
            abort(403, 'Hanya pembayaran aktif yang catatannya dapat diperbarui.');
        }

        $validated = $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);

        $normalizedNote = isset($validated['note']) && trim($validated['note']) !== ''
            ? trim($validated['note'])
            : null;

        $purchasePayment->update([
            'note' => $normalizedNote,
        ]);

        toast('Catatan pembayaran pembelian berhasil diperbarui!', 'info');

        return redirect()->route('purchases.show', $purchase);
    }


    public function destroy(PurchasePayment $purchasePayment) {
        abort_if(Gate::denies('purchasePayments.delete'), 403);

        $purchase = $purchasePayment->purchase;
        if (! $purchase) {
            abort(404);
        }
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        if ($purchase->isArchived()) {
            abort(403, 'Tidak dapat menghapus pembayaran untuk pembelian yang diarsipkan.');
        }

        if (! $purchasePayment->isEligibleForDeletion()) {
            abort(403, 'Pembayaran dengan riwayat sistem tidak dapat dihapus.');
        }

        DB::transaction(function () use ($purchasePayment, $purchase) {
            // Lock parent purchase row
            $lockedPurchase = Purchase::where('id', $purchase->id)->lockForUpdate()->firstOrFail();

            if ($lockedPurchase->isArchived()) {
                abort(403, 'Tidak dapat menghapus pembayaran untuk pembelian yang diarsipkan.');
            }

            // Lock and reload payment row
            $lockedPayment = PurchasePayment::where('id', $purchasePayment->id)->lockForUpdate()->firstOrFail();

            if (! $lockedPayment->isEligibleForDeletion()) {
                abort(403, 'Pembayaran dengan riwayat sistem tidak dapat dihapus.');
            }

            $lockedPayment->delete();

            // Reconcile parent purchase totals atomically
            $lockedPurchase->reconcileFromActivePayments();
        });

        toast('Pembayaran Pembelian Berhasil Dihapus!', 'warning');

        return redirect()->route('purchases.index');
    }

    public function invalidate(PurchasePayment $purchasePayment) {
        abort_if(Gate::denies('purchasePayments.delete'), 403);

        $purchase = $purchasePayment->purchase;
        if (! $purchase) {
            abort(404);
        }
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        if ($purchasePayment->status !== PurchasePayment::STATUS_ACTIVE) {
            abort(403, 'Hanya pembayaran aktif yang dapat dibatalkan.');
        }

        DB::transaction(function () use ($purchasePayment, $purchase) {
            $lockedPurchase = Purchase::where('id', $purchase->id)->lockForUpdate()->firstOrFail();

            /** @var PurchasePayment|null $lockedPayment */
            $lockedPayment = PurchasePayment::where('id', $purchasePayment->id)
                ->where('purchase_id', $lockedPurchase->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedPayment || $lockedPayment->status !== PurchasePayment::STATUS_ACTIVE) {
                abort(403, 'Hanya pembayaran aktif yang dapat dibatalkan.');
            }

            $lockedPayment->update([
                'status' => PurchasePayment::STATUS_INVALIDATED,
                'invalidated_at' => now(),
                'invalidated_by' => auth()->id(),
            ]);

            $lockedPurchase->reconcileFromActivePayments();
        });

        toast('Pembayaran Pembelian Berhasil Dibatalkan!', 'info');

        return redirect()->back();
    }

    public function datatable($purchase_id, PurchasePaymentsDataTable $dataTable)
    {
        abort_if(Gate::denies('purchasePayments.access'), 403);

        $purchase = Purchase::withArchived()->findOrFail($purchase_id);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        return $dataTable->with(['purchase_id' => $purchase_id])->render('purchase::payments.index', compact('purchase'));
    }

    public function uploadAttachment(Request $request)
    {
        abort_if(Gate::denies('purchasePayments.create'), 403);

        $request->validate([
            'file' => 'required|file',
        ]);

        try {
            $result = $this->attachmentStagingService->stageUploadedFile($request->file('file'));

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function deleteAttachment(Request $request)
    {
        abort_if(Gate::denies('purchasePayments.create'), 403);

        $request->validate([
            'file_name' => 'required|string',
        ]);

        $fileName = basename($request->string('file_name')->toString());
        if ($fileName !== $request->string('file_name')->toString() || str_contains($request->string('file_name')->toString(), '..')) {
            return response()->json(['message' => 'Invalid file name.'], 422);
        }

        $path = IndividualPurchasePaymentAttachmentStagingService::STAGING_DIR . '/' . $fileName;
        if (Storage::exists($path)) {
            Storage::delete($path);
        }

        $metaPath = IndividualPurchasePaymentAttachmentStagingService::STAGING_DIR . '/' . $fileName . '.meta';
        if (Storage::exists($metaPath)) {
            Storage::delete($metaPath);
        }

        return response()->json(['success' => true]);
    }

    private function ensurePurchaseBelongsToCurrentSetting(Purchase $purchase): void
    {
        $currentSettingId = session('setting_id');

        if (! is_null($currentSettingId) && (int) $purchase->setting_id !== (int) $currentSettingId) {
            abort(404);
        }
    }
}
