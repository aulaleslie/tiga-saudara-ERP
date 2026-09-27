<?php

namespace Modules\Sale\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Illuminate\Validation\ValidationException;

class GlobalSalePaymentService
{
    /**
     * Injected attachment replicator for media copy operations.
     *
     * @var GlobalSalePaymentAttachmentReplicator
     */
    protected GlobalSalePaymentAttachmentReplicator $replicator;

    /**
     * Create a new service instance.
     *
     * @param GlobalSalePaymentAttachmentReplicator|null $replicator
     */
    public function __construct(?GlobalSalePaymentAttachmentReplicator $replicator = null)
    {
        $this->replicator = $replicator ?? new GlobalSalePaymentAttachmentReplicator();
    }

    /**
     * Store multiple payments for a single customer atomically across all settings.
     *
     * @param int $customerId
     * @param array $data Expected structure:
     *        - allocations: [sale_id => amount]
     *        - reference: string
     *        - date: date
     *        - payment_method_id: int
     *        - note: string (optional)
     *        - attachment: string (file path to temp file) (optional)
     * @return array Array of created SalePayment instances
     * @throws \Exception
     */
    public function storeMultiPayment($customerId, array $data)
    {
        $attachmentPath = null;

        try {
            // Pre-validate attachment exists before transaction
            if (!empty($data['attachment'])) {
                $attachmentPath = $data['attachment'];
                $this->replicator->validateSourcePath($attachmentPath);
            }

            // Reset replicator state for this operation
            $this->replicator->reset();

            // Pre-resolve payment method before transaction
            $paymentMethod = \Modules\Setting\Entities\PaymentMethod::find($data['payment_method_id']);
            if (!$paymentMethod) {
                throw ValidationException::withMessages(['payment_method_id' => 'Metode pembayaran tidak ditemukan.']);
            }

            // Execute atomic transaction for all database operations and media copies
            $createdPayments = DB::transaction(function () use ($customerId, $data, $paymentMethod, $attachmentPath) {
                $allocations = $data['allocations'] ?? [];
                if (empty($allocations)) {
                    throw ValidationException::withMessages(['allocations' => 'Tidak ada alokasi yang diberikan.']);
                }

                // Normalize and validate all allocations to two decimal places
                $normalizedAllocations = [];
                foreach ($allocations as $saleId => $amount) {
                    // Validate numeric value
                    if (!is_numeric($amount) && $amount !== null && $amount !== '') {
                        throw ValidationException::withMessages(['allocations' => "Alokasi untuk penjualan tidak valid atau bukan angka."]);
                    }

                    $normalized = round((float) $amount, 2);

                    // Reject negative allocations
                    if ($normalized < 0) {
                        throw ValidationException::withMessages(['allocations' => "Alokasi untuk penjualan tidak boleh negatif."]);
                    }

                    // Only include positive allocations
                    if ($normalized > 0) {
                        $normalizedAllocations[$saleId] = $normalized;
                    }
                }

                if (empty($normalizedAllocations)) {
                    throw ValidationException::withMessages(['allocations' => 'Setidaknya satu alokasi positif diperlukan untuk diproses.']);
                }

                $allocations = $normalizedAllocations;
                ksort($allocations); // Prevent deadlocks by ordering IDs

                $createdPayments = [];

                // Process all allocations and create payments
                foreach ($allocations as $saleId => $amount) {
                    // Load sale across all settings, lock for update
                    $sale = Sale::where('id', $saleId)
                        ->where('customer_id', $customerId)
                        ->globalPaymentEligible()
                        ->whereNull('archived_at')
                        ->lockForUpdate()
                        ->first();

                    if (!$sale) {
                        throw ValidationException::withMessages(['allocations' => "Penjualan dengan ID {$saleId} tidak ditemukan, tidak memenuhi syarat, atau pelanggan tidak cocok."]);
                    }

                    // Check balance constraint dynamically against current live due
                    $liveDueAmount = round($sale->live_due_amount, 2);

                    if ($amount > $liveDueAmount) {
                        throw ValidationException::withMessages(['allocations' => "Alokasi untuk Penjualan {$sale->reference} melebihi sisa tagihan saat ini."]);
                    }

                    // Create payment with normalized amount
                    $payment = SalePayment::create([
                        'sale_id' => $sale->id,
                        'amount' => $amount,
                        'date' => $data['date'],
                        'reference' => $data['reference'],
                        'payment_method_id' => $data['payment_method_id'],
                        'payment_method' => $paymentMethod->name,
                        'note' => $data['note'] ?? null,
                        'status' => SalePayment::STATUS_ACTIVE,
                    ]);

                    // Reconcile sale header from canonical settlement totals
                    $sale->reconcileFromActivePayments();

                    $createdPayments[] = $payment;
                }

                if (empty($createdPayments)) {
                    throw ValidationException::withMessages(['allocations' => 'Tidak ada alokasi positif yang valid untuk diproses.']);
                }

                // Replicate attachment to all payments inside transaction
                // The replicator handles media copy, tracking, and rollback-time cleanup
                $this->replicator->replicateToPayments($attachmentPath, $createdPayments);

                return $createdPayments;
            });

            return $createdPayments;
        } catch (\Exception $e) {
            // Clean up any partially copied media files on failure
            // The replicator tracks all successful copies and cleans them up
            $this->replicator->cleanupOnFailure();

            // Re-throw the original exception
            throw $e;
        }
    }
}
