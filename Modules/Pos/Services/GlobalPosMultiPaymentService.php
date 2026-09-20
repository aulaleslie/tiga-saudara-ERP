<?php

namespace Modules\Pos\Services;

use App\Scopes\ArchivingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\GlobalPosPaymentAllocation;
use Modules\Pos\Entities\GlobalPosPaymentBatch;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Services\GlobalSalePaymentAttachmentReplicator;
use Modules\Setting\Entities\PaymentMethod;

class GlobalPosMultiPaymentService
{
    protected PosRemainingBalancePriorityPlanner $planner;
    protected PosReachableSalesResolver $resolver;
    protected PosSettlementProjectionService $projectionService;
    protected GlobalSalePaymentAttachmentReplicator $replicator;

    public function __construct(
        ?PosRemainingBalancePriorityPlanner $planner = null,
        ?PosReachableSalesResolver $resolver = null,
        ?PosSettlementProjectionService $projectionService = null,
        ?GlobalSalePaymentAttachmentReplicator $replicator = null
    ) {
        $this->planner = $planner ?? new PosRemainingBalancePriorityPlanner();
        $this->resolver = $resolver ?? new PosReachableSalesResolver();
        $this->projectionService = $projectionService ?? new PosSettlementProjectionService($this->resolver);
        $this->replicator = $replicator ?? new GlobalSalePaymentAttachmentReplicator();
    }

    /**
     * Preview child sale allocations for given POS allocations.
     *
     * @param int $customerId
     * @param array<int, float|int|string> $posAllocations (pos_transaction_id => amount)
     * @param int $paymentMethodId
     * @return array{
     *     transactions: array<int, array{
     *         pos_transaction_id: int,
     *         code: string,
     *         receipt_number: ?string,
     *         setting_name: string,
     *         setting_id: int,
     *         total_amount: float,
     *         effective_paid: float,
     *         live_due: float,
     *         requested_amount: float,
     *         planned_allocations: array<int, array{
     *             sale_id: int,
     *             sale_reference: string,
     *             setting_id: int,
     *             setting_name: string,
     *             total_amount: float,
     *             live_due: float,
     *             allocated_amount: float
     *         }>
     *     }>,
     *     total_requested: float,
     *     total_planned: float
     * }
     * @throws ValidationException
     */
    public function previewAllocations(int $customerId, array $posAllocations, int $paymentMethodId): array
    {
        $paymentMethod = PaymentMethod::find($paymentMethodId);
        if (!$paymentMethod) {
            throw ValidationException::withMessages([
                'payment_method_id' => 'Metode pembayaran tidak ditemukan.',
            ]);
        }
        $isCash = (bool) $paymentMethod->is_cash;

        $customer = Customer::find($customerId);
        if (!$customer) {
            throw ValidationException::withMessages([
                'customer_id' => 'Pelanggan tidak ditemukan.',
            ]);
        }

        $previewTransactions = [];
        $grandTotalRequested = 0.0;
        $grandTotalPlanned = 0.0;

        foreach ($posAllocations as $posTrxId => $amount) {
            $requestedAmount = round((float) $amount, 2);
            if ($requestedAmount < 0) {
                throw ValidationException::withMessages([
                    'allocations' => 'Jumlah alokasi pembayaran tidak boleh negatif.',
                ]);
            }

            $trx = PosTransaction::with(['completedCheckout', 'setting'])->find($posTrxId);
            if (!$trx) {
                throw ValidationException::withMessages([
                    'allocations' => "Transaksi POS #{$posTrxId} tidak ditemukan.",
                ]);
            }

            if ((int) $trx->customer_id !== (int) $customerId) {
                throw ValidationException::withMessages([
                    'allocations' => "Transaksi POS {$trx->code} bukan milik pelanggan yang dipilih.",
                ]);
            }

            if ($trx->status !== PosTransaction::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'allocations' => "Transaksi POS {$trx->code} belum selesai atau tidak valid.",
                ]);
            }

            $diagnostics = $this->resolver->resolveWithDiagnostics($trx);
            if (!$diagnostics['is_valid'] || $diagnostics['sales']->isEmpty()) {
                throw ValidationException::withMessages([
                    'allocations' => "Transaksi POS {$trx->code} memiliki mapping penjualan yang tidak konsisten atau rusak.",
                ]);
            }

            foreach ($diagnostics['sales'] as $sale) {
                $isEligible = $sale->archived_at === null && in_array($sale->status, [
                    Sale::STATUS_APPROVED,
                    Sale::STATUS_DISPATCHED_PARTIALLY,
                    Sale::STATUS_DISPATCHED,
                    Sale::STATUS_RETURNED_PARTIALLY,
                ], true);

                if (!$isEligible) {
                    throw ValidationException::withMessages([
                        'allocations' => "Penjualan {$sale->reference} untuk transaksi {$trx->code} tidak lagi memenuhi syarat untuk pembayaran baru.",
                    ]);
                }
            }

            $checkout = $trx->completedCheckout;
            $terminalSettingId = $checkout ? (int) $checkout->setting_id : (int) $trx->setting_id;

            $salesContext = [];
            foreach ($diagnostics['sales'] as $sale) {
                $salesContext[] = [
                    'sale_id' => $sale->id,
                    'setting_id' => (int) $sale->setting_id,
                    'live_due' => (float) $sale->live_due_amount,
                    'total_amount' => (float) $sale->total_amount,
                ];
            }

            $plan = $this->planner->plan([
                'amount' => $requestedAmount,
                'is_cash' => $isCash,
                'terminal_setting_id' => $terminalSettingId,
                'sales' => $salesContext,
            ]);

            $plannedAllocations = [];
            $salesById = $diagnostics['sales']->keyBy('id');

            foreach ($plan['allocations'] as $alloc) {
                $sale = $salesById->get($alloc['sale_id']);
                $plannedAllocations[] = [
                    'sale_id' => $alloc['sale_id'],
                    'sale_reference' => $sale ? $sale->reference : "SL-{$alloc['sale_id']}",
                    'setting_id' => $alloc['setting_id'],
                    'setting_name' => $sale && $sale->tenantSetting ? $sale->tenantSetting->company_name : "Cabang {$alloc['setting_id']}",
                    'total_amount' => $sale ? (float) $sale->total_amount : 0.0,
                    'live_due' => $alloc['live_due'],
                    'allocated_amount' => $alloc['allocated_amount'],
                ];
            }

            $projection = $this->projectionService->project($trx);

            $previewTransactions[] = [
                'pos_transaction_id' => $trx->id,
                'code' => $trx->code,
                'receipt_number' => $checkout ? $checkout->receipt_number : null,
                'setting_name' => $trx->setting ? $trx->setting->company_name : 'Pos',
                'setting_id' => (int) $trx->setting_id,
                'total_amount' => $projection['total_amount'],
                'effective_paid' => $projection['effective_paid'],
                'live_due' => $projection['live_due'],
                'requested_amount' => $requestedAmount,
                'planned_allocations' => $plannedAllocations,
            ];

            $grandTotalRequested += $requestedAmount;
            $grandTotalPlanned += $plan['total_allocated'];
        }

        return [
            'transactions' => $previewTransactions,
            'total_requested' => round($grandTotalRequested, 2),
            'total_planned' => round($grandTotalPlanned, 2),
        ];
    }

    /**
     * Store multi-POS payment atomically.
     *
     * @param int $customerId
     * @param array{
     *     allocations: array<int, float|int|string>,
     *     reference: string,
     *     date: string,
     *     payment_method_id: int,
     *     note?: ?string,
     *     attachment?: ?string,
     *     idempotency_key?: ?string
     * } $data
     * @param int|null $actorUserId
     * @return GlobalPosPaymentBatch
     * @throws \Exception
     */
    public function storeMultiPayment(int $customerId, array $data, ?int $actorUserId = null): GlobalPosPaymentBatch
    {
        $idempotencyKey = !empty($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : null;

        // Idempotency check: if batch exists, return it
        if ($idempotencyKey) {
            $existingBatch = GlobalPosPaymentBatch::where('idempotency_key', $idempotencyKey)
                ->with(['allocations.sale', 'allocations.posTransaction', 'allocations.salePayment'])
                ->first();

            if ($existingBatch) {
                return $existingBatch;
            }
        }

        $attachmentPath = null;
        try {
            if (!empty($data['attachment'])) {
                $attachmentPath = $data['attachment'];
                $this->replicator->validateSourcePath($attachmentPath);
            }

            $this->replicator->reset();

            $paymentMethod = PaymentMethod::find($data['payment_method_id'] ?? null);
            if (!$paymentMethod) {
                throw ValidationException::withMessages([
                    'payment_method_id' => 'Metode pembayaran tidak ditemukan.',
                ]);
            }
            $isCash = (bool) $paymentMethod->is_cash;

            $batch = DB::transaction(function () use ($customerId, $data, $actorUserId, $paymentMethod, $isCash, $attachmentPath, $idempotencyKey) {
                $rawAllocations = $data['allocations'] ?? [];
                if (empty($rawAllocations)) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Tidak ada alokasi pembayaran yang diberikan.',
                    ]);
                }

                $normalizedPosAllocations = [];
                foreach ($rawAllocations as $trxId => $amount) {
                    if (!is_numeric($amount) && $amount !== null && $amount !== '') {
                        throw ValidationException::withMessages([
                            'allocations' => 'Alokasi pembayaran harus berupa angka yang valid.',
                        ]);
                    }

                    $rounded = round((float) $amount, 2);
                    if ($rounded < 0) {
                        throw ValidationException::withMessages([
                            'allocations' => 'Alokasi pembayaran tidak boleh negatif.',
                        ]);
                    }

                    if ($rounded > 0) {
                        $normalizedPosAllocations[(int) $trxId] = $rounded;
                    }
                }

                if (empty($normalizedPosAllocations)) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Setidaknya satu transaksi POS dengan alokasi positif diperlukan.',
                    ]);
                }

                // Sort keys deterministically to prevent database deadlocks
                ksort($normalizedPosAllocations);

                $createdSalePayments = [];
                $allocationRecordsData = [];
                $batchTotalAmount = 0.0;

                // Process each POS transaction
                foreach ($normalizedPosAllocations as $trxId => $posAmount) {
                    // Lock POS transaction for update
                    $trx = PosTransaction::where('id', $trxId)
                        ->where('customer_id', $customerId)
                        ->lockForUpdate()
                        ->first();

                    if (!$trx) {
                        throw ValidationException::withMessages([
                            'allocations' => "Transaksi POS #{$trxId} tidak ditemukan atau pelanggan tidak cocok.",
                        ]);
                    }

                    if ($trx->status !== PosTransaction::STATUS_COMPLETED) {
                        throw ValidationException::withMessages([
                            'allocations' => "Transaksi POS {$trx->code} belum selesai atau tidak valid.",
                        ]);
                    }

                    $checkout = PosCheckout::where('pos_transaction_id', $trx->id)
                        ->orWhere('id', $trx->completed_checkout_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$checkout || $checkout->status !== PosCheckout::STATUS_POSTED) {
                        throw ValidationException::withMessages([
                            'allocations' => "Checkout untuk transaksi {$trx->code} tidak valid atau belum diposting.",
                        ]);
                    }

                    // Resolve child sales and lock them
                    $diagnostics = $this->resolver->resolveWithDiagnostics($trx);
                    if (!$diagnostics['is_valid'] || $diagnostics['sales']->isEmpty()) {
                        throw ValidationException::withMessages([
                            'allocations' => "Transaksi POS {$trx->code} memiliki mapping penjualan yang tidak konsisten.",
                        ]);
                    }

                    $saleIds = $diagnostics['sales']->pluck('id')->all();
                    sort($saleIds); // Deterministic ordering

                    // Lock reachable sales
                    $lockedSales = Sale::withoutGlobalScope(ArchivingScope::class)
                        ->with('tenantSetting')
                        ->whereIn('id', $saleIds)
                        ->where('customer_id', $customerId)
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    if ($lockedSales->count() !== count($saleIds)) {
                        throw ValidationException::withMessages([
                            'allocations' => "Beberapa penjualan untuk transaksi {$trx->code} tidak dapat dikunci atau pelanggan berubah.",
                        ]);
                    }

                    // Every reachable Sale must currently be eligible for new payments.
                    // Bypassing ArchivingScope above is only for reachability/locking; it must
                    // never allow a payment against an archived or lifecycle-ineligible Sale.
                    foreach ($lockedSales as $lockedSale) {
                        $isEligible = $lockedSale->archived_at === null && in_array($lockedSale->status, [
                            Sale::STATUS_APPROVED,
                            Sale::STATUS_DISPATCHED_PARTIALLY,
                            Sale::STATUS_DISPATCHED,
                            Sale::STATUS_RETURNED_PARTIALLY,
                        ], true);

                        if (!$isEligible) {
                            throw ValidationException::withMessages([
                                'allocations' => "Penjualan {$lockedSale->reference} untuk transaksi {$trx->code} tidak lagi memenuhi syarat untuk pembayaran baru.",
                            ]);
                        }
                    }

                    $terminalSettingId = (int) $checkout->setting_id;
                    $salesContext = [];
                    foreach ($diagnostics['sales'] as $sale) {
                        $lockedSale = $lockedSales->get($sale->id);
                        $salesContext[] = [
                            'sale_id' => $lockedSale->id,
                            'setting_id' => (int) $lockedSale->setting_id,
                            'live_due' => (float) $lockedSale->live_due_amount,
                            'total_amount' => (float) $lockedSale->total_amount,
                        ];
                    }

                    $plan = $this->planner->plan([
                        'amount' => $posAmount,
                        'is_cash' => $isCash,
                        'terminal_setting_id' => $terminalSettingId,
                        'sales' => $salesContext,
                    ]);

                    foreach ($plan['allocations'] as $plannedAlloc) {
                        $allocatedAmount = $plannedAlloc['allocated_amount'];
                        if ($allocatedAmount <= 0) {
                            continue;
                        }

                        $sale = $lockedSales->get($plannedAlloc['sale_id']);

                        // Validate live due constraint
                        $liveDue = round($sale->live_due_amount, 2);
                        if ($allocatedAmount > $liveDue) {
                            throw ValidationException::withMessages([
                                'allocations' => "Alokasi untuk Penjualan {$sale->reference} melebihi sisa tagihan saat ini ({$liveDue}).",
                            ]);
                        }

                        // Create SalePayment
                        $salePayment = SalePayment::create([
                            'sale_id' => $sale->id,
                            'amount' => $allocatedAmount,
                            'date' => $data['date'],
                            'reference' => $data['reference'],
                            'payment_method_id' => $data['payment_method_id'],
                            'payment_method' => $paymentMethod->name,
                            'note' => $data['note'] ?? null,
                            'status' => SalePayment::STATUS_ACTIVE,
                        ]);

                        // Reconcile Sale
                        $sale->reconcileFromActivePayments();

                        $createdSalePayments[] = $salePayment;

                        $allocationRecordsData[] = [
                            'pos_transaction_id' => $trx->id,
                            'sale_id' => $sale->id,
                            'sale_payment_id' => $salePayment->id,
                            'amount' => $allocatedAmount,
                        ];

                        $batchTotalAmount += $allocatedAmount;
                    }
                }

                if (empty($createdSalePayments)) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Tidak ada pembayaran yang dihasilkan dari alokasi.',
                    ]);
                }

                // Create GlobalPosPaymentBatch header
                $actorId = $actorUserId ?? (auth()->check() ? auth()->id() : 1);
                $batch = GlobalPosPaymentBatch::create([
                    'customer_id' => $customerId,
                    'user_id' => $actorId,
                    'date' => $data['date'],
                    'reference' => $data['reference'],
                    'payment_method_id' => $data['payment_method_id'],
                    'note' => $data['note'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'total_amount' => round($batchTotalAmount, 2),
                ]);

                // Create GlobalPosPaymentAllocation records
                foreach ($allocationRecordsData as $allocData) {
                    GlobalPosPaymentAllocation::create([
                        'global_pos_payment_batch_id' => $batch->id,
                        'pos_transaction_id' => $allocData['pos_transaction_id'],
                        'sale_id' => $allocData['sale_id'],
                        'sale_payment_id' => $allocData['sale_payment_id'],
                        'amount' => $allocData['amount'],
                    ]);
                }

                // Replicate attachment to all generated SalePayments
                $this->replicator->replicateToPayments($attachmentPath, $createdSalePayments);

                return $batch;
            });

            return $batch;
        } catch (\Exception $e) {
            $this->replicator->cleanupOnFailure();
            throw $e;
        }
    }
}
