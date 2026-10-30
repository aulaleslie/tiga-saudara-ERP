<?php

namespace Modules\Pos\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Pos\Entities\PosKasBonCollection;
use Modules\Pos\Entities\PosReceiptPrintLog;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Services\PosKasBonReceivingSessionResolver;
use Modules\Pos\Services\PosKasBonReviewService;
use Modules\Pos\Services\PosKasBonSettlementService;
use Modules\Pos\Services\PosPaymentMethodSearchService;
use Modules\Pos\Services\PosReceiptService;
use Modules\Setting\Entities\PaymentMethod;

class PosKasBonCollectionController extends Controller
{
    public function __construct(
        protected PosKasBonReceivingSessionResolver $sessionResolver,
        protected PosKasBonReviewService $reviewService = new PosKasBonReviewService(),
        protected PosKasBonSettlementService $settlementService = new PosKasBonSettlementService(),
        protected PosReceiptService $receiptService = new PosReceiptService()
    ) {
    }

    /**
     * Get eligible non-debt payment methods for Kas Bon collection.
     */
    public function paymentMethods(Request $request, PosPaymentMethodSearchService $searchService): JsonResponse
    {
        $settingId = (int) session('setting_id');
        $session = $this->sessionResolver->resolve($request->user(), $settingId);

        $methods = $searchService->search($settingId);

        // Exclude Kas Bon / debt payment method
        $filteredMethods = array_values(array_filter($methods, function ($method) {
            $name = strtolower($method['name']);
            return !str_contains($name, 'kas bon') && !str_contains($name, 'kasbon') && !str_contains($name, 'piutang');
        }));

        return response()->json([
            'success' => true,
            'session_id' => $session->id,
            'methods' => $filteredMethods,
        ]);
    }

    /**
     * Outstanding Kas Bon candidates discovery for the active business.
     */
    public function candidates(Request $request): JsonResponse
    {
        $settingId = (int) session('setting_id');
        $session = $this->sessionResolver->resolve($request->user(), $settingId);

        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 15);
        $page = (int) $request->query('page', 1);

        $paginator = $this->reviewService->getCandidates($settingId, $search, $perPage, $page);

        return response()->json([
            'success' => true,
            'session_id' => $session->id,
            'terminal_id' => $session->terminal_id,
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * Current bill review for one POS transaction.
     */
    public function review(Request $request, $transaction_id): JsonResponse
    {
        $settingId = (int) session('setting_id');
        $session = $this->sessionResolver->resolve($request->user(), $settingId);
        $transaction = PosTransaction::where('setting_id', $settingId)->findOrFail($transaction_id);

        $reviewData = $this->reviewService->review($transaction);

        return response()->json([
            'success' => true,
            'session_id' => $session->id,
            'terminal_id' => $session->terminal_id,
            'data' => $reviewData,
        ]);
    }

    /**
     * Settle single transaction collection.
     */
    public function collect(Request $request, $transaction_id): JsonResponse
    {
        $settingId = (int) session('setting_id');

        // Check if an existing collection already exists with this idempotency key in this setting (Issue 5: idempotent retry after session closure)
        $idempotencyKey = $request->input('idempotency_key');
        if (!empty($idempotencyKey)) {
            $existing = PosKasBonCollection::where('idempotency_key', $idempotencyKey)
                ->where('setting_id', $settingId)
                ->first();

            if ($existing) {
                $collection = $this->settlementService->collect(
                    posTransactionId: (int) $transaction_id,
                    receivingSessionId: (int) $existing->pos_session_id,
                    actorUserId: (int) $request->user()->id,
                    fingerprint: (string) $request->input('fingerprint', ''),
                    amountToPay: (float) $request->input('amount_to_pay', 0),
                    methods: (array) $request->input('methods', []),
                    idempotencyKey: (string) $idempotencyKey,
                    note: $request->input('note')
                );

                return response()->json([
                    'success' => true,
                    'collection_id' => $collection->id,
                    'reference' => $collection->reference,
                    'amount_collected' => (float) $collection->amount_collected,
                    'tender_total' => (float) $collection->tender_total,
                    'change_total' => (float) $collection->change_total,
                    'remaining_debt' => (float) $collection->remaining_debt,
                    'receipt_url' => route('pos.kas-bon.collections.receipt', $collection->id),
                    'replayed' => true,
                    'data' => $collection,
                ]);
            }
        }

        $session = $this->sessionResolver->resolve($request->user(), $settingId);
        $transaction = PosTransaction::where('setting_id', $settingId)->findOrFail($transaction_id);

        $validated = $request->validate([
            'fingerprint' => 'required|string',
            'amount_to_pay' => 'required|numeric|min:0.01',
            'methods' => 'required|array|min:1',
            'methods.*.payment_method_id' => 'required|integer',
            'methods.*.amount' => 'required|numeric|min:0.01',
            'methods.*.tender_amount' => 'nullable|numeric',
            'methods.*.note' => 'nullable|string|max:255',
            'methods.*.reference' => 'nullable|string|max:255',
            'methods.*.attachment' => 'nullable|string|max:500',
            'idempotency_key' => 'required|string|max:100',
            'note' => 'nullable|string|max:1000',
        ]);

        $collection = $this->settlementService->collect(
            posTransactionId: $transaction->id,
            receivingSessionId: $session->id,
            actorUserId: $request->user()->id,
            fingerprint: $validated['fingerprint'],
            amountToPay: (float) $validated['amount_to_pay'],
            methods: $validated['methods'],
            idempotencyKey: $validated['idempotency_key'],
            note: $validated['note'] ?? null
        );

        return response()->json([
            'success' => true,
            'collection_id' => $collection->id,
            'reference' => $collection->reference,
            'amount_collected' => (float) $collection->amount_collected,
            'tender_total' => (float) $collection->tender_total,
            'change_total' => (float) $collection->change_total,
            'remaining_debt' => (float) $collection->remaining_debt,
            'receipt_url' => route('pos.kas-bon.collections.receipt', $collection->id),
            'replayed' => false,
            'data' => $collection,
        ]);
    }

    /**
     * Display printable collection receipt immediately after collection.
     */
    public function receipt(Request $request, $collection_id)
    {
        $settingId = (int) session('setting_id');
        $collection = PosKasBonCollection::with([
            'transaction.customer',
            'transaction.completedCheckout',
            'methods.paymentMethod',
            'allocations.sale',
            'session.cashier',
            'terminal',
            'user',
            'setting.currency',
        ])
        ->where('setting_id', $settingId)
        ->findOrFail($collection_id);

        // Determine if this collection receipt has already been printed
        $alreadyPrinted = PosReceiptPrintLog::where('setting_id', $settingId)
            ->where('pos_transaction_id', $collection->pos_transaction_id)
            ->get()
            ->contains(function ($log) use ($collection) {
                $meta = $log->metadata;
                if (is_string($meta)) {
                    $meta = json_decode($meta, true);
                }
                return is_array($meta)
                    && isset($meta['pos_kas_bon_collection_id'])
                    && (int) $meta['pos_kas_bon_collection_id'] === (int) $collection->id;
            });

        if ($alreadyPrinted) {
            abort_unless(
                $request->user()->can('pos.receipts.reprint'),
                403,
                'Anda tidak memiliki izin untuk mencetak ulang struk.'
            );
            $printType = PosReceiptPrintLog::TYPE_REPRINT;
            $isReprint = true;
        } else {
            $printType = PosReceiptPrintLog::TYPE_PRINT;
            $isReprint = false;
        }

        // Audit log print or reprint
        $log = $this->receiptService->logTransactionPrint(
            $settingId,
            $collection->pos_transaction_id,
            (int) $request->user()->id,
            $printType
        );
        $log->update([
            'metadata' => array_merge((array) ($log->metadata ?? []), [
                'pos_kas_bon_collection_id' => (int) $collection->id,
            ]),
        ]);

        $printHistory = $this->receiptService->getTransactionPrintHistory($collection->pos_transaction_id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'collection' => $collection,
                'print_history' => $printHistory,
            ]);
        }

        return view(
            'pos::receipts.kas-bon-collection',
            $this->buildCollectionReceiptViewData($collection, $isReprint, $printHistory)
        );
    }

    /**
     * Authorized reprinting of collection receipt.
     */
    public function receiptReprint(Request $request, $collection_id)
    {
        $settingId = (int) session('setting_id');
        $collection = PosKasBonCollection::with([
            'transaction.customer',
            'transaction.completedCheckout',
            'methods.paymentMethod',
            'allocations.sale',
            'session.cashier',
            'terminal',
            'user',
            'setting.currency',
        ])
        ->where('setting_id', $settingId)
        ->findOrFail($collection_id);

        abort_unless(
            $request->user()->can('pos.receipts.reprint'),
            403,
            'Anda tidak memiliki izin untuk mencetak ulang struk.'
        );

        // Audit log reprint
        $log = $this->receiptService->logTransactionPrint(
            $settingId,
            $collection->pos_transaction_id,
            (int) $request->user()->id,
            PosReceiptPrintLog::TYPE_REPRINT
        );
        $log->update([
            'metadata' => array_merge((array) ($log->metadata ?? []), [
                'pos_kas_bon_collection_id' => (int) $collection->id,
            ]),
        ]);

        $printHistory = $this->receiptService->getTransactionPrintHistory($collection->pos_transaction_id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'collection' => $collection,
                'print_history' => $printHistory,
            ]);
        }

        return view(
            'pos::receipts.kas-bon-collection',
            $this->buildCollectionReceiptViewData($collection, true, $printHistory)
        );
    }

    /**
     * Build view data for Kas Bon collection print and reprint receipts using
     * the collection's own business visibility setting and linked POS transaction note.
     */
    public function buildCollectionReceiptViewData(
        PosKasBonCollection $collection,
        bool $isReprint,
        array $printHistory
    ): array {
        $collectionSettingId = (int) ($collection->setting_id ?? 0);
        $collectionSetting = $collection->relationLoaded('setting')
            ? $collection->getRelation('setting')
            : null;

        $receiptVisibility = $this->receiptService->resolveReceiptVisibility(
            $collectionSettingId > 0 ? $collectionSettingId : null,
            $collectionSetting instanceof \Modules\Setting\Entities\Setting ? $collectionSetting : null
        );

        $linkedTransaction = $collection->relationLoaded('transaction')
            ? $collection->getRelation('transaction')
            : $collection->transaction;
        $transactionNote = ($linkedTransaction instanceof PosTransaction && trim((string) $linkedTransaction->note) !== '')
            ? (string) $linkedTransaction->note
            : null;

        return [
            'collection' => $collection,
            'isReprint' => $isReprint,
            'printHistory' => $printHistory,
            'receiptVisibility' => $receiptVisibility,
            'transactionNote' => $transactionNote,
        ];
    }
}
