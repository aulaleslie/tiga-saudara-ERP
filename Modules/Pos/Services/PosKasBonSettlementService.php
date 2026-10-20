<?php

namespace Modules\Pos\Services;

use App\Scopes\ArchivingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosKasBonCollection;
use Modules\Pos\Entities\PosKasBonCollectionAllocation;
use Modules\Pos\Entities\PosKasBonCollectionMethod;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTemporaryPaymentImage;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Services\GlobalSalePaymentAttachmentReplicator;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\SettingPosPaymentMethod;

class PosKasBonSettlementService
{
    public function __construct(
        protected PosRemainingBalancePriorityPlanner $planner = new PosRemainingBalancePriorityPlanner(),
        protected PosReachableSalesResolver $salesResolver = new PosReachableSalesResolver(),
        protected PosReceiptReprintProjectionService $reprintProjectionService = new PosReceiptReprintProjectionService(),
        protected PosChildSaleEligibilityService $eligibilityService = new PosChildSaleEligibilityService(),
        protected PosSessionExpectedCashCalculator $expectedCashCalculator = new PosSessionExpectedCashCalculator(),
        protected GlobalSalePaymentAttachmentReplicator $replicator = new GlobalSalePaymentAttachmentReplicator(),
        protected PosKasBonReviewService $reviewService = new PosKasBonReviewService()
    ) {
    }

    /**
     * Test seam for controlled interleaving concurrency tests.
     * Invoked immediately after the initial idempotency check passes.
     *
     * @var (\Closure(self): void)|null
     */
    public ?\Closure $afterInitialIdempotencyCheckHook = null;

    /**
     * Diagnostic flag to verify whether the last collect() call returned via
     * the recheck under the serialization lock rather than the initial check.
     */
    public bool $lastReturnedFromLockRecheck = false;

    public function getReplicator(): GlobalSalePaymentAttachmentReplicator
    {
        return $this->replicator;
    }

    /**
     * Compute canonical request hash for idempotency binding.
     * Uses stable upload tokens rather than temporary file paths so identical replays match across retries.
     *
     * @param int $posTransactionId
     * @param float $amountToPay
     * @param string $fingerprint
     * @param array $methods
     * @return string
     */
    public function computePayloadHash(int $posTransactionId, float $amountToPay, string $fingerprint, array $methods): string
    {
        $canonicalMethods = [];
        foreach ($methods as $m) {
            $token = !empty($m['attachment'])
                ? trim((string) $m['attachment'])
                : (!empty($m['attachment_token']) ? trim((string) $m['attachment_token']) : null);

            $note = isset($m['note']) ? trim((string) $m['note']) : null;
            if ($note === '') {
                $note = null;
            }

            $reference = isset($m['reference']) ? trim((string) $m['reference']) : null;
            if ($reference === '') {
                $reference = null;
            }

            $canonicalMethods[] = [
                'payment_method_id' => (int) ($m['payment_method_id'] ?? 0),
                'amount' => round((float) ($m['amount'] ?? 0), 2),
                'tender_amount' => isset($m['tender_amount']) && $m['tender_amount'] !== null && $m['tender_amount'] !== ''
                    ? round((float) $m['tender_amount'], 2)
                    : null,
                'note' => $note,
                'reference' => $reference,
                'attachment' => $token,
            ];
        }

        $canonicalPayload = [
            'pos_transaction_id' => $posTransactionId,
            'amount_to_pay' => round($amountToPay, 2),
            'fingerprint' => $fingerprint,
            'methods' => $canonicalMethods,
        ];

        return hash('sha256', json_encode($canonicalPayload));
    }

    /**
     * Atomically collect single Kas Bon transaction with mixed methods,
     * child Sale settlement, physical cash drawer updates, and durable idempotency.
     *
     * @param int $posTransactionId
     * @param int $receivingSessionId
     * @param int $actorUserId
     * @param string $fingerprint
     * @param float $amountToPay
     * @param array $methods
     * @param string $idempotencyKey
     * @param string|null $note
     * @return PosKasBonCollection
     * @throws ValidationException|\Exception
     */
    public function collect(
        int $posTransactionId,
        int $receivingSessionId,
        int $actorUserId,
        string $fingerprint,
        float $amountToPay,
        array $methods,
        string $idempotencyKey,
        ?string $note = null
    ): PosKasBonCollection {
        $this->lastReturnedFromLockRecheck = false;

        $amountToPay = round((float) $amountToPay, 2);
        if ($amountToPay <= 0.005) {
            throw ValidationException::withMessages([
                'amount' => 'Jumlah pembayaran harus lebih besar dari 0.',
            ]);
        }

        if (empty($methods)) {
            throw ValidationException::withMessages([
                'methods' => 'Setidaknya satu metode pembayaran harus ditentukan.',
            ]);
        }

        $idempotencyKey = trim($idempotencyKey);
        if (empty($idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Idempotency key wajib diisi.',
            ]);
        }

        // Compute stable request hash containing upload tokens before checking idempotency
        $payloadHash = $this->computePayloadHash($posTransactionId, $amountToPay, $fingerprint, $methods);

        // 1. Idempotency Check BEFORE checking session, temporary evidence, or method active status
        $existing = PosKasBonCollection::where('idempotency_key', $idempotencyKey)
            ->with(['methods', 'allocations.sale', 'allocations.salePayment', 'transaction', 'session'])
            ->first();

        if ($existing) {
            if ($existing->request_hash !== $payloadHash) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'Idempotency key telah digunakan dengan parameter permintaan yang berbeda.',
                ]);
            }

            return $existing;
        }

        if ($this->afterInitialIdempotencyCheckHook !== null) {
            $hook = $this->afterInitialIdempotencyCheckHook;
            $this->afterInitialIdempotencyCheckHook = null;
            $hook($this);
        }

        // 2. Validate receiving session exists and belongs to active business
        $session = PosSession::find($receivingSessionId);
        if (!$session) {
            throw ValidationException::withMessages([
                'session' => 'Sesi POS penerima tidak ditemukan.',
            ]);
        }
        $settingId = (int) $session->setting_id;

        // 3. Normalize methods & validate methods/evidence
        $validCartTokens = [
            'kas-bon-' . $posTransactionId,
            'pos-kas-bon-' . $posTransactionId,
        ];

        $normalizedMethods = [];
        $methodSum = 0.0;
        $tenderTotal = 0.0;
        $cashTenderTotal = 0.0;
        $changeTotal = 0.0;
        $tempImageIdsToClaim = [];

        foreach ($methods as $idx => $m) {
            $pmId = (int) ($m['payment_method_id'] ?? 0);
            $amount = round((float) ($m['amount'] ?? 0), 2);
            $methodNote = !empty($m['note']) ? trim((string) $m['note']) : null;
            $reference = !empty($m['reference']) ? trim((string) $m['reference']) : null;
            $attachment = !empty($m['attachment']) ? trim((string) $m['attachment']) : (!empty($m['attachment_token']) ? trim((string) $m['attachment_token']) : null);

            if ($amount <= 0.005) {
                throw ValidationException::withMessages([
                    "methods.{$idx}.amount" => 'Jumlah pembayaran per metode harus lebih besar dari 0.',
                ]);
            }

            $pm = PaymentMethod::find($pmId);
            if (!$pm || !$pm->is_active) {
                throw ValidationException::withMessages([
                    "methods.{$idx}.payment_method_id" => 'Metode pembayaran tidak valid atau tidak aktif.',
                ]);
            }

            // Enforce active-business POS enablement
            $isPosEnabled = SettingPosPaymentMethod::where('setting_id', $settingId)
                ->where('payment_method_id', $pmId)
                ->where('is_enabled', true)
                ->exists();

            if (!$isPosEnabled) {
                throw ValidationException::withMessages([
                    "methods.{$idx}.payment_method_id" => "Metode pembayaran {$pm->name} belum diaktifkan untuk POS pada cabang ini.",
                ]);
            }

            // Enforce required reference
            if ($pm->requires_reference && empty($reference)) {
                throw ValidationException::withMessages([
                    "methods.{$idx}.reference" => "Nomor referensi wajib diisi untuk metode {$pm->name}.",
                ]);
            }

            // Must not finish-as-Kas-Bon
            $pmNameLower = mb_strtolower($pm->name);
            if (
                str_contains($pmNameLower, 'kas bon')
                || str_contains($pmNameLower, 'utang')
                || str_contains($pmNameLower, 'piutang')
                || str_contains($pmNameLower, 'debt')
            ) {
                throw ValidationException::withMessages([
                    "methods.{$idx}.payment_method_id" => 'Metode pembayaran Kas Bon / Utang tidak dapat digunakan untuk pelunasan Kas Bon.',
                ]);
            }

            $isCash = (bool) $pm->is_cash;
            $tenderTotal += $amount;
            if ($isCash) {
                $cashTenderTotal += $amount;
            }

            $methodSum += $amount;

            // Resolve file path if attachment is given
            // Enforce session, collection, cashier, active, and non-expired ownership
            // (Atomic consumption check is enforced under row-level lock)
            $resolvedAttachment = null;
            if ($attachment) {
                $tempImage = PosTemporaryPaymentImage::where('token', $attachment)
                    ->where('setting_id', $settingId)
                    ->where('pos_session_id', $receivingSessionId)
                    ->where('cashier_id', $actorUserId)
                    ->whereIn('cart_token', $validCartTokens)
                    ->where('expires_at', '>', Carbon::now())
                    ->first();

                if (!$tempImage) {
                    throw ValidationException::withMessages([
                        "methods.{$idx}.attachment" => 'Lampiran bukti pembayaran tidak valid, bukan milik sesi/kasir/transaksi ini, sudah digunakan, atau sudah kedaluwarsa.',
                    ]);
                }

                if (!Storage::exists($tempImage->path)) {
                    throw ValidationException::withMessages([
                        "methods.{$idx}.attachment" => 'File bukti pembayaran hilang dari penyimpanan.',
                    ]);
                }

                $resolvedAttachment = Storage::path($tempImage->path);
                $tempImageIdsToClaim[] = $tempImage->id;
            }

            $normalizedMethods[] = [
                'payment_method_id' => $pmId,
                'payment_method_name' => $pm->name,
                'amount' => $amount,
                'tender_amount' => $amount,
                'reference' => $reference,
                'note' => $methodNote,
                'attachment' => $resolvedAttachment,
                'attachment_token' => $attachment,
            ];
        }

        if (abs($methodSum - $amountToPay) > 0.005) {
            throw ValidationException::withMessages([
                'methods' => 'Total rincian metode pembayaran (Rp ' . number_format($methodSum, 0, ',', '.') . ') tidak sama dengan jumlah bayar (Rp ' . number_format($amountToPay, 0, ',', '.') . ').',
            ]);
        }

        // 4. Pre-validate attachment paths
        foreach ($normalizedMethods as $m) {
            if (!empty($m['attachment'])) {
                $this->replicator->validateSourcePath($m['attachment']);
            }
        }
        $this->replicator->reset();

        // 5. Execute atomic database transaction
        try {
            return DB::transaction(function () use (
                $posTransactionId,
                $receivingSessionId,
                $actorUserId,
                $fingerprint,
                $amountToPay,
                $normalizedMethods,
                $idempotencyKey,
                $payloadHash,
                $tenderTotal,
                $cashTenderTotal,
                $changeTotal,
                $note,
                $tempImageIdsToClaim
            ) {
                // Lock 1: Receiving PosSession
                $session = PosSession::where('id', $receivingSessionId)
                    ->lockForUpdate()
                    ->first();

                if (!$session) {
                    throw ValidationException::withMessages([
                        'session' => 'Sesi POS penerima tidak ditemukan.',
                    ]);
                }

                if ($session->status !== PosSession::STATUS_OPEN) {
                    throw ValidationException::withMessages([
                        'session' => 'Sesi POS penerima sudah ditutup atau tidak aktif.',
                    ]);
                }

                if ((int) $session->cashier_user_id !== (int) $actorUserId) {
                    throw ValidationException::withMessages([
                        'session' => 'Sesi POS penerima bukan milik kasir yang sedang aktif.',
                    ]);
                }

                // Lock 2: PosTransaction (Serialization lock for this transaction)
                $trx = PosTransaction::where('id', $posTransactionId)
                    ->lockForUpdate()
                    ->first();

                if (!$trx) {
                    throw ValidationException::withMessages([
                        'transaction' => 'Transaksi POS tidak ditemukan.',
                    ]);
                }

                // Recheck committed idempotency state after acquiring serialization locks and before claiming evidence or validating fingerprint
                $committed = PosKasBonCollection::where('idempotency_key', $idempotencyKey)
                    ->with(['methods', 'allocations.sale', 'allocations.salePayment', 'transaction', 'session'])
                    ->first();

                if ($committed) {
                    if ($committed->request_hash !== $payloadHash) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => 'Idempotency key telah digunakan dengan parameter permintaan yang berbeda.',
                        ]);
                    }

                    $this->lastReturnedFromLockRecheck = true;

                    return $committed;
                }

                if ($trx->status !== PosTransaction::STATUS_COMPLETED) {
                    throw ValidationException::withMessages([
                        'transaction' => "Transaksi POS {$trx->code} belum selesai atau tidak valid.",
                    ]);
                }

                if ((int) $trx->setting_id !== (int) $session->setting_id) {
                    throw ValidationException::withMessages([
                        'transaction' => 'Transaksi POS tidak berada dalam unit usaha sesi aktif.',
                    ]);
                }

                // Claim evidence tokens atomically under row-level lock
                foreach ($tempImageIdsToClaim as $tempImgId) {
                    $lockedImg = PosTemporaryPaymentImage::where('id', $tempImgId)
                        ->whereNull('consumed_at')
                        ->lockForUpdate()
                        ->first();

                    if (!$lockedImg) {
                        throw ValidationException::withMessages([
                            'attachment' => 'Bukti pembayaran telah digunakan pada transaksi lain.',
                        ]);
                    }

                    $lockedImg->update([
                        'consumed_at' => Carbon::now(),
                    ]);
                }

                // Lock 3: PosCheckout
                $checkout = PosCheckout::where('pos_transaction_id', $trx->id)
                    ->orWhere('id', $trx->completed_checkout_id)
                    ->lockForUpdate()
                    ->first();

                if (!$checkout || $checkout->status !== PosCheckout::STATUS_POSTED) {
                    throw ValidationException::withMessages([
                        'transaction' => "Checkout untuk transaksi {$trx->code} belum diposting atau tidak valid.",
                    ]);
                }

                // Authoritative debt intent check
                $isDebtIntent = (bool) ($checkout->metadata['is_debt'] ?? false)
                    || ((float) ($checkout->debt_amount ?? 0) > 0.005)
                    || ((float) ($checkout->grand_total - $checkout->paid_total) > 0.005);

                if (!$isDebtIntent) {
                    throw ValidationException::withMessages([
                        'transaction' => "Transaksi POS {$trx->code} bukan merupakan transaksi Kas Bon.",
                    ]);
                }

                // Lock 4: Reachable child Sales in ascending ID order
                $diagnostics = $this->salesResolver->resolveWithDiagnostics($checkout);
                if (!$diagnostics['is_valid'] || $diagnostics['sales']->isEmpty()) {
                    throw ValidationException::withMessages([
                        'transaction' => "Pemetaan penjualan untuk transaksi {$trx->code} tidak konsisten atau rusak.",
                    ]);
                }

                $saleIds = $diagnostics['sales']->pluck('id')->all();
                sort($saleIds);

                $lockedSales = Sale::withoutGlobalScope(ArchivingScope::class)
                    ->with(['salePayments', 'tenantSetting'])
                    ->whereIn('id', $saleIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($lockedSales->count() !== count($saleIds)) {
                    throw ValidationException::withMessages([
                        'transaction' => "Gagal mengunci penjualan terkait untuk transaksi {$trx->code}.",
                    ]);
                }

                // Child Sale eligibility verification under lock
                foreach ($lockedSales as $lockedSale) {
                    if (!$this->eligibilityService->isEligible($lockedSale)) {
                        throw ValidationException::withMessages([
                            'transaction' => "Penjualan {$lockedSale->reference} tidak lagi memenuhi syarat untuk pembayaran baru.",
                        ]);
                    }
                }

                // 5. Recompute current projection & fingerprint under lock via reviewService
                $reviewData = $this->reviewService->review($trx);
                $recomputedFingerprint = $reviewData['fingerprint'];

                if ($recomputedFingerprint !== $fingerprint) {
                    throw ValidationException::withMessages([
                        'fingerprint' => 'Tagihan atau status transaksi telah berubah sejak peninjauan terakhir. Silakan tinjau ulang.',
                    ]);
                }

                $currentLiveDue = round((float) $reviewData['live_due'], 2);
                $currentTotal = round((float) $reviewData['current_total'], 2);
                $effectivePaid = round((float) $reviewData['effective_paid'], 2);
                $originalTotal = round((float) $checkout->grand_total, 2);

                if ($currentLiveDue <= 0.005) {
                    throw ValidationException::withMessages([
                        'transaction' => "Transaksi POS {$trx->code} sudah lunas (sisa utang Rp 0).",
                    ]);
                }

                if ($amountToPay > $currentLiveDue) {
                    throw ValidationException::withMessages([
                        'amount' => "Jumlah pembayaran (Rp " . number_format($amountToPay, 0, ',', '.') . ") melebihi sisa utang saat ini (Rp " . number_format($currentLiveDue, 0, ',', '.') . ").",
                    ]);
                }

                // 6. Generate collection reference
                $collectionRef = 'KBC-' . now()->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -4));
                $remainingDebtAfter = round($currentLiveDue - $amountToPay, 2);

                // 7. Persist PosKasBonCollection header
                $collection = PosKasBonCollection::create([
                    'pos_transaction_id' => $trx->id,
                    'setting_id' => $session->setting_id,
                    'pos_session_id' => $session->id,
                    'pos_terminal_id' => $session->terminal_id,
                    'user_id' => $actorUserId,
                    'reference' => $collectionRef,
                    'collected_at' => now(),
                    'amount' => $amountToPay,
                    'cash_tender_amount' => $cashTenderTotal,
                    'change_amount' => $changeTotal,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $payloadHash,
                    'review_snapshot' => [
                        'fingerprint' => $fingerprint,
                        'code' => $trx->code,
                        'receipt_number' => $checkout->receipt_number,
                        'customer' => [
                            'id' => $trx->customer_id,
                            'name' => $trx->customer?->customer_name ?? $checkout->customer?->customer_name,
                            'phone' => $trx->customer?->customer_phone ?? $checkout->customer?->customer_phone,
                        ],
                        'items' => $reviewData['lines'] ?? [],
                        'adjustments' => $reviewData['adjustments'] ?? [],
                        'original_total' => $originalTotal,
                        'current_total' => $currentTotal,
                        'effective_paid' => $effectivePaid,
                        'live_due' => $currentLiveDue,
                    ],
                    'receipt_snapshot' => [
                        'collection_reference' => $collectionRef,
                        'pos_reference' => $trx->code,
                        'receipt_number' => $checkout->receipt_number,
                        'amount_collected' => $amountToPay,
                        'tender_total' => $tenderTotal,
                        'change_total' => $changeTotal,
                        'remaining_debt' => $remainingDebtAfter,
                        'customer_name' => $trx->customer?->customer_name ?? $checkout->customer?->customer_name,
                        'cashier_name' => $session->cashier?->name ?? 'Kasir',
                        'terminal_name' => $session->terminal?->name,
                        'methods' => $normalizedMethods,
                    ],
                    'notes' => $note,
                ]);

                // 8. Allocation loop across methods and child Sales
                $saleBalances = [];
                foreach ($lockedSales as $s) {
                    $saleBalances[$s->id] = (float) $s->live_due_amount;
                }

                $terminalSettingId = (int) $checkout->setting_id;

                foreach ($normalizedMethods as $methodIdx => $m) {
                    $pmModel = PaymentMethod::findOrFail($m['payment_method_id']);
                    $isCash = (bool) $pmModel->is_cash;
                    $methodAmount = $m['amount'];
                    $methodTender = $methodAmount;
                    $methodChange = 0.0;

                    $collectionMethod = PosKasBonCollectionMethod::create([
                        'pos_kas_bon_collection_id' => $collection->id,
                        'payment_method_id' => $pmModel->id,
                        'payment_method_name' => $pmModel->name,
                        'amount' => $methodAmount,
                        'tender_amount' => $methodTender,
                        'change_amount' => $methodChange,
                        'sequence_order' => $methodIdx + 1,
                        'reference' => $m['reference'] ?? null,
                        'evidence_path' => $m['attachment'] ?? null,
                        'notes' => $m['note'] ?? null,
                    ]);

                    // Sales context for priority planner
                    $salesContext = [];
                    foreach ($lockedSales as $sale) {
                        if ($saleBalances[$sale->id] > 0.005) {
                            $salesContext[] = [
                                'sale_id' => $sale->id,
                                'setting_id' => (int) $sale->setting_id,
                                'live_due' => $saleBalances[$sale->id],
                                'total_amount' => (float) $sale->total_amount,
                            ];
                        }
                    }

                    $plan = $this->planner->plan([
                        'amount' => $methodAmount,
                        'is_cash' => $isCash,
                        'terminal_setting_id' => $terminalSettingId,
                        'sales' => $salesContext,
                    ]);

                    $methodSalePayments = [];

                    foreach ($plan['allocations'] as $plannedAlloc) {
                        $allocatedAmount = $plannedAlloc['allocated_amount'];
                        if ($allocatedAmount <= 0.005) {
                            continue;
                        }

                        $sale = $lockedSales->get($plannedAlloc['sale_id']);

                        if ($allocatedAmount > ($saleBalances[$sale->id] + 0.005)) {
                            throw ValidationException::withMessages([
                                'methods' => "Alokasi melebihi sisa tagihan penjualan {$sale->reference}.",
                            ]);
                        }

                        $saleBalances[$sale->id] = max(0.0, round($saleBalances[$sale->id] - $allocatedAmount, 2));

                        $spReference = !empty($m['reference'])
                            ? $m['reference']
                            : ('SP-' . $collection->reference . '-' . $sale->id . '-' . ($methodIdx + 1));

                        // Create ordinary SalePayment
                        $sp = SalePayment::create([
                            'sale_id' => $sale->id,
                            'amount' => $allocatedAmount,
                            'date' => now()->toDateString(),
                            'reference' => $spReference,
                            'payment_method_id' => $pmModel->id,
                            'payment_method' => $pmModel->name,
                            'note' => $m['note'] ?? $note ?? 'Pelunasan Kas Bon POS',
                            'status' => SalePayment::STATUS_ACTIVE,
                        ]);

                        // Reconcile child Sale from active payments
                        $sale->reconcileFromActivePayments();

                        $methodSalePayments[] = $sp;

                        // Create audit link allocation
                        PosKasBonCollectionAllocation::create([
                            'pos_kas_bon_collection_id' => $collection->id,
                            'pos_kas_bon_collection_method_id' => $collectionMethod->id,
                            'pos_transaction_id' => $trx->id,
                            'sale_id' => $sale->id,
                            'sale_payment_id' => $sp->id,
                            'amount' => $allocatedAmount,
                        ]);
                    }

                    // If method has attachment, replicate to this method's SalePayments
                    if (!empty($m['attachment'])) {
                        $this->replicator->replicateToPayments($m['attachment'], $methodSalePayments);
                    }
                }

                // 9. Physical cash events for receiving session
                // Cash tender event (inflow)
                if ($cashTenderTotal > 0.005) {
                    PosSessionCashEvent::create([
                        'setting_id' => $session->setting_id,
                        'pos_session_id' => $session->id,
                        'event_type' => PosSessionCashEvent::EVENT_KAS_BON_COLLECTION_IN,
                        'direction' => PosSessionCashEvent::DIRECTION_IN,
                        'amount' => $cashTenderTotal,
                        'reference_type' => PosKasBonCollection::class,
                        'reference_id' => $collection->id,
                        'performed_by' => $actorUserId,
                        'notes' => "Penerimaan Kas Bon ({$collection->reference})",
                        'occurred_at' => now(),
                    ]);
                }

                // Exactly-once collection change outflow (outflow)
                if ($changeTotal > 0.005) {
                    PosSessionCashEvent::create([
                        'setting_id' => $session->setting_id,
                        'pos_session_id' => $session->id,
                        'event_type' => PosSessionCashEvent::EVENT_CHANGE_OUT,
                        'direction' => PosSessionCashEvent::DIRECTION_OUT,
                        'amount' => $changeTotal,
                        'reference_type' => PosKasBonCollection::class,
                        'reference_id' => $collection->id,
                        'performed_by' => $actorUserId,
                        'notes' => "Kembalian Kas Bon ({$collection->reference})",
                        'occurred_at' => now(),
                    ]);
                }

                // 10. Refresh expected cash for receiving session
                $this->expectedCashCalculator->calculate($session->id);

                return $collection->load([
                    'methods',
                    'allocations.sale',
                    'allocations.salePayment',
                    'transaction',
                    'session',
                ]);
            });
        } catch (\Exception $e) {
            $this->replicator->cleanupOnFailure();
            throw $e;
        }
    }
}
