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
use Modules\SalesReturn\Entities\CustomerCredit;
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
        $payment_methods = PaymentMethod::active()->get();

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
            'payment_method_id'  => 'required|integer|exists:payment_methods,id,is_active,1',
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
        abort_if(Gate::denies('salePayments.access'), 403);

        $sale = Sale::findOrFail($sale_id);
        $this->ensureSaleBelongsToCurrentSetting($sale);

        if ((int) $salePayment->sale_id !== (int) $sale->id) {
            abort(404);
        }

        return view('sale::payments.edit', compact('salePayment', 'sale'));
    }

    public function update(Request $request, SalePayment $salePayment) {
        abort_if(Gate::denies('salePayments.edit'), 403);

        // Retrieve actual sale from relationship
        $sale = $salePayment->sale;
        if (! $sale) {
            abort(404);
        }
        $this->ensureSaleBelongsToCurrentSetting($sale);

        if ($sale->isArchived()) {
            abort(403, 'Tidak dapat memperbarui catatan pembayaran untuk penjualan yang diarsipkan.');
        }

        if (! $salePayment->isActive()) {
            abort(403, 'Hanya pembayaran aktif yang catatannya dapat diperbarui.');
        }

        $validated = $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);

        $normalizedNote = isset($validated['note']) && trim($validated['note']) !== ''
            ? trim($validated['note'])
            : null;

        $salePayment->update([
            'note' => $normalizedNote,
        ]);

        toast('Catatan pembayaran berhasil diperbarui!', 'info');

        return redirect()->route('sales.show', $sale);
    }

    public function destroy(SalePayment $salePayment) {
        abort_if(Gate::denies('salePayments.delete'), 403);

        $sale = $salePayment->sale;
        if (! $sale) {
            abort(404);
        }
        $this->ensureSaleBelongsToCurrentSetting($sale);

        if ($sale->isArchived()) {
            abort(403, 'Tidak dapat menghapus pembayaran untuk penjualan yang diarsipkan.');
        }

        if (! $salePayment->isEligibleForDeletion()) {
            toast('Pembayaran dengan kredit atau riwayat sistem tidak dapat dihapus.', 'warning');
            return redirect()->route('sale-payments.index', $sale->id);
        }

        DB::transaction(function () use ($salePayment, $sale) {
            // Lock parent sale row
            $lockedSale = Sale::where('id', $sale->id)->lockForUpdate()->firstOrFail();

            if ($lockedSale->isArchived()) {
                abort(403, 'Tidak dapat menghapus pembayaran untuk penjualan yang diarsipkan.');
            }

            // Lock and reload payment row
            $lockedPayment = SalePayment::where('id', $salePayment->id)->lockForUpdate()->firstOrFail();

            if (! $lockedPayment->isEligibleForDeletion()) {
                abort(403, 'Pembayaran dengan kredit atau riwayat sistem tidak dapat dihapus.');
            }

            $lockedPayment->delete();

            // Reconcile parent sale atomically from active payments
            $lockedSale->reconcileFromActivePayments();
        });

        toast('Sale Payment Deleted!', 'warning');

        return redirect()->route('sales.index');
    }

    private function ensureSaleBelongsToCurrentSetting(Sale $sale): void
    {
        $currentSettingId = session('setting_id');

        if (! is_null($currentSettingId) && (int) $sale->setting_id !== (int) $currentSettingId) {
            abort(404);
        }
    }
}
