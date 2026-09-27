<?php

namespace Modules\Pos\Http\Controllers;

use App\Scopes\ArchivingScope;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Pos\Entities\GlobalPosPaymentAllocation;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReceiptPrintLog;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\GlobalPosMultiPaymentService;
use Modules\Pos\Services\PosReceiptService;
use Modules\Pos\Services\PosReachableSalesResolver;
use Modules\Pos\Services\PosSettlementProjectionService;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\PaymentMethod;

class GlobalPosPaymentController extends Controller
{
    /**
     * List all eligible POS transactions globally across settings.
     * Protected by posPayments.global.access.
     */
    public function index()
    {
        abort_if(Gate::denies('posPayments.global.access'), 403);

        return view('pos::global-payments.index');
    }

    /**
     * Dedicated cross-setting read-only POS transaction detail.
     */
    public function show($transaction_id, PosSettlementProjectionService $projectionService, PosReachableSalesResolver $resolver)
    {
        abort_if(Gate::denies('posPayments.global.access'), 403);

        $transaction = PosTransaction::with([
            'setting',
            'customer',
            'creator',
            'owner',
            'lines.product',
            'lines.serials',
            'completedCheckout.terminal',
            'completedCheckout.cashier',
            'completedCheckout.paymentMethod',
            'completedCheckout.payments.paymentMethod',
        ])->findOrFail($transaction_id);

        $checkout = $transaction->completedCheckout;
        $projection = $projectionService->project($transaction);
        $diagnostics = $resolver->resolveWithDiagnostics($transaction);
        $reachableSales = $diagnostics['sales'];

        // Load relations on reachable sales
        if ($reachableSales->isNotEmpty()) {
            $reachableSales->loadMissing([
                'tenantSetting',
                'saleDetails.product',
                'salePayments.paymentMethod',
                'saleDispatches.details.product',
            ]);
        }

        $printLogs = $this->loadPrintLogs($transaction, $checkout);

        return view('pos::global-payments.show', [
            'transaction' => $transaction,
            'checkout' => $checkout,
            'projection' => $projection,
            'reachableSales' => $reachableSales,
            'diagnostics' => $diagnostics,
            'printLogs' => $printLogs,
            'globalMode' => true,
        ]);
    }

    /**
     * Resolve the combined print/reprint history for a POS transaction.
     *
     * Ordinary transaction printing and global receipt reprinting both log via
     * PosReceiptService::logTransactionPrint(), which persists pos_transaction_id (not
     * pos_checkout_id), so history must be sourced primarily by transaction ID.
     * Legacy checkout-scoped rows (from PosReceiptService::logPrint(), still used by the
     * setting-scoped sell flow) are keyed by pos_checkout_id only and are included as a
     * secondary source so older print history is not silently dropped.
     */
    protected function loadPrintLogs(PosTransaction $transaction, ?PosCheckout $checkout): \Illuminate\Support\Collection
    {
        $byTransaction = PosReceiptPrintLog::query()
            ->where('pos_transaction_id', $transaction->id)
            ->with('printer:id,name')
            ->get();

        $byCheckout = collect();
        if ($checkout) {
            $byCheckout = PosReceiptPrintLog::query()
                ->where('pos_checkout_id', $checkout->id)
                ->whereNull('pos_transaction_id')
                ->with('printer:id,name')
                ->get();
        }

        return $byTransaction->concat($byCheckout)
            ->unique('id')
            ->sortByDesc(fn (PosReceiptPrintLog $log) => $log->printed_at)
            ->values();
    }

    /**
     * Cross-setting payment history for a POS transaction.
     */
    public function history($transaction_id, PosSettlementProjectionService $projectionService, PosReachableSalesResolver $resolver)
    {
        abort_if(Gate::denies('posPayments.global.access') || Gate::denies('posPayments.global.history'), 403);

        $transaction = PosTransaction::with([
            'setting',
            'customer',
            'completedCheckout.payments.paymentMethod',
            'completedCheckout.paymentMethod',
        ])->findOrFail($transaction_id);

        $projection = $projectionService->project($transaction);
        $diagnostics = $resolver->resolveWithDiagnostics($transaction);
        $reachableSales = $diagnostics['sales'];

        $saleIds = $reachableSales->pluck('id')->all();

        // Load all active and invalidated sale payments for reachable sales
        $salePayments = SalePayment::withoutGlobalScope(ArchivingScope::class)
            ->with(['paymentMethod', 'sale.tenantSetting', 'media'])
            ->whereIn('sale_id', $saleIds)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        // Load global POS payment audit allocations
        $allocations = GlobalPosPaymentAllocation::with(['batch.user', 'batch.paymentMethod', 'sale.tenantSetting'])
            ->where('pos_transaction_id', $transaction->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return view('pos::global-payments.history', [
            'transaction' => $transaction,
            'projection' => $projection,
            'salePayments' => $salePayments,
            'allocations' => $allocations,
            'reachableSales' => $reachableSales,
            'globalMode' => true,
        ]);
    }

    /**
     * Show multi-POS payment form for a customer starting from a given transaction.
     */
    public function create($transaction_id, PosSettlementProjectionService $projectionService)
    {
        abort_if(Gate::denies('posPayments.global.access') || Gate::denies('posPayments.global.create'), 403);

        $startingTransaction = PosTransaction::with(['setting', 'customer', 'completedCheckout'])
            ->findOrFail($transaction_id);

        if ($startingTransaction->status !== PosTransaction::STATUS_COMPLETED) {
            toast('Transaksi POS ini belum selesai atau tidak valid untuk pembayaran.', 'error');
            return redirect()->route('pos.global-payments.index');
        }

        $startingProjection = $projectionService->project($startingTransaction);
        if (!$startingProjection['is_payable']) {
            toast('Transaksi POS ini tidak memiliki saldo tagihan yang belum lunas atau tidak memenuhi syarat untuk pembayaran.', 'error');
            return redirect()->route('pos.global-payments.index');
        }

        // Load candidate transactions for the exact same customer across all settings
        $candidateQuery = $projectionService->baseEligibleQuery()
            ->where('pos_transactions.customer_id', $startingTransaction->customer_id)
            ->with(array_merge(
                PosSettlementProjectionService::reachableSalesEagerLoad(),
                [
                    'setting',
                    'customer',
                    'completedCheckout.paymentMethod',
                ]
            ))
            ->orderByRaw('CASE WHEN pos_transactions.id = ? THEN 0 ELSE 1 END', [(int) $startingTransaction->id])
            ->orderBy('pos_transactions.created_at', 'desc')
            ->orderBy('pos_transactions.id', 'asc');

        $allCandidates = $candidateQuery->get();

        // Filter to only payable transactions (live_due > 0)
        $payableCandidates = collect();
        foreach ($allCandidates as $candidate) {
            $proj = $projectionService->project($candidate);
            if ($proj['is_payable'] && $proj['live_due'] > 0) {
                $candidate->projection = $proj;
                $payableCandidates->push($candidate);
            }
        }

        if ($payableCandidates->isEmpty()) {
            toast('Tidak ada transaksi POS yang memenuhi syarat untuk pembayaran.', 'error');
            return redirect()->route('pos.global-payments.index');
        }

        $paymentMethods = PaymentMethod::all();

        return view('pos::global-payments.create', [
            'startingTransaction' => $startingTransaction,
            'startingProjection' => $startingProjection,
            'candidateTransactions' => $payableCandidates,
            'paymentMethods' => $paymentMethods,
            'customer' => $startingTransaction->customer,
        ]);
    }

    /**
     * Preview payment allocation expansion.
     */
    public function preview(Request $request, $transaction_id, GlobalPosMultiPaymentService $service)
    {
        abort_if(Gate::denies('posPayments.global.access') || Gate::denies('posPayments.global.create'), 403);

        $startingTransaction = PosTransaction::findOrFail($transaction_id);

        $validated = $request->validate([
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'allocations' => 'required|array',
            'allocations.*' => 'numeric|min:0',
        ]);

        $preview = $service->previewAllocations(
            (int) $startingTransaction->customer_id,
            $validated['allocations'],
            (int) $validated['payment_method_id']
        );

        return response()->json([
            'success' => true,
            'data' => $preview,
        ]);
    }

    /**
     * Store multi-POS payment atomically.
     */
    public function store(Request $request, $transaction_id, GlobalPosMultiPaymentService $service)
    {
        abort_if(Gate::denies('posPayments.global.access') || Gate::denies('posPayments.global.create'), 403);

        $startingTransaction = PosTransaction::findOrFail($transaction_id);

        $request->validate([
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'note' => 'nullable|string|max:1000',
            'attachment' => 'nullable|string',
            'idempotency_key' => 'nullable|string|max:120',
            'allocations' => 'required|array',
            'allocations.*' => 'numeric|min:0',
        ]);

        $attachmentPath = null;
        if ($request->attachment) {
            $basename = basename($request->attachment);
            $attachmentPath = \Illuminate\Support\Facades\Storage::path('temp/dropzone/' . $basename);
        }

        try {
            $service->storeMultiPayment(
                (int) $startingTransaction->customer_id,
                array_merge(
                    $request->only(['reference', 'date', 'payment_method_id', 'note', 'allocations', 'idempotency_key']),
                    ['attachment' => $attachmentPath]
                ),
                (int) auth()->id()
            );

            toast('Pembayaran POS Global berhasil disimpan!', 'success');
            return redirect()->route('pos.global-payments.index');
        } finally {
            if ($attachmentPath && file_exists($attachmentPath)) {
                @unlink($attachmentPath);
            }
        }
    }

    /**
     * Reprint transaction receipt with global authorization and originating business context.
     */
    public function receiptReprint(Request $request, $transaction_id, PosReceiptService $receiptService, PosSettlementProjectionService $projectionService)
    {
        abort_if(Gate::denies('posPayments.global.access') || Gate::denies('pos.receipts.reprint'), 403);

        $transaction = PosTransaction::with(['setting', 'completedCheckout'])->findOrFail($transaction_id);

        // Must be the same eligibility boundary as the global register: a completed
        // transaction with a posted checkout and at least one resolvable Sale. A direct
        // URL must not be able to reprint a receipt for a structurally incomplete or
        // non-financial transaction just because its lifecycle status is COMPLETED.
        $isEligible = $projectionService->baseEligibleQuery()
            ->where('pos_transactions.id', $transaction->id)
            ->exists();

        if (!$isEligible) {
            abort(422, 'Hanya transaksi POS yang selesai dan memenuhi syarat yang dapat dicetak struknya.');
        }

        $settingId = (int) $transaction->setting_id;

        // Log the reprint
        $receiptService->logTransactionPrint(
            $settingId,
            $transaction->id,
            (int) auth()->id(),
            PosReceiptPrintLog::TYPE_REPRINT
        );

        $receiptData = $receiptService->getTransactionReceiptData($transaction);

        return view('pos::receipt', compact('receiptData'));
    }
}
