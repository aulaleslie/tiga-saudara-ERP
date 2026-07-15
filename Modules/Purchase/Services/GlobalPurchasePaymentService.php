<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Validation\ValidationException;

class GlobalPurchasePaymentService
{
    /**
     * Store multiple payments for a single supplier atomically.
     *
     * @param int $supplierId
     * @param array $data Expected structure:
     *        - allocations: [purchase_id => amount]
     *        - reference: string
     *        - date: date
     *        - payment_method_id: int
     *        - note: string (optional)
     *        - attachment: string (file name from dropzone) (optional)
     * @return array Array of created PurchasePayment instances
     * @throws \Exception
     */
    public function storeMultiPayment($supplierId, array $data)
    {
        return DB::transaction(function () use ($supplierId, $data) {
            $allocations = $data['allocations'] ?? [];
            if (empty($allocations)) {
                throw ValidationException::withMessages(['allocations' => 'Tidak ada alokasi yang diberikan.']);
            }
            
            ksort($allocations); // Prevent deadlocks by ordering IDs

            $createdPayments = [];
            
            // Pre-validate attachment exists
            $attachmentPath = null;
            if (!empty($data['attachment'])) {
                $basename = basename($data['attachment']);
                $attachmentPath = \Illuminate\Support\Facades\Storage::path('temp/dropzone/' . $basename);
                if (!file_exists($attachmentPath)) {
                    throw ValidationException::withMessages(['attachment' => 'File lampiran tidak ditemukan atau tidak valid.']);
                }
            }

            foreach ($allocations as $purchaseId => $amount) {
                // Ensure amount is strictly positive
                if ($amount <= 0) {
                    continue;
                }

                // Load purchase across all settings, lock for update
                $purchase = Purchase::where('id', $purchaseId)
                    ->where('supplier_id', $supplierId)
                    ->where('status', Purchase::STATUS_RECEIVED)
                    ->whereNull('archived_at')
                    ->lockForUpdate()
                    ->first();

                if (!$purchase) {
                    throw ValidationException::withMessages(['allocations' => "Pembelian dengan ID {$purchaseId} tidak ditemukan atau pemasok tidak cocok."]);
                }

                // Check balance constraint dynamically
                $liveDueAmount = $purchase->live_due_amount;
                
                if ($amount > $liveDueAmount) {
                    throw ValidationException::withMessages(['allocations' => "Alokasi untuk Pembelian {$purchase->reference} melebihi sisa tagihan saat ini."]);
                }

                // Create payment
                // The current tenant setting shouldn't matter since the form bypasses it, 
                // but PurchasePayment creation might rely on it. We'll set it explicitly.
                $paymentMethod = \Modules\Setting\Entities\PaymentMethod::find($data['payment_method_id']);
                
                $payment = PurchasePayment::create([
                    'purchase_id' => $purchase->id,
                    'amount' => $amount,
                    'date' => $data['date'],
                    'reference' => $data['reference'],
                    'payment_method_id' => $data['payment_method_id'],
                    'payment_method' => $paymentMethod ? $paymentMethod->name : 'Unknown',
                    'note' => $data['note'] ?? null,
                ]);

                // We'll handle attachment replication later (Phase 6)
                if (!empty($data['attachment'])) {
                    // For now just note it in $data so the controller/service can do Phase 6
                    $payment->_temporary_attachment = $data['attachment'];
                }

                // Update purchase balances based on active payments
                // For safety we recalculate the live due amount again AFTER this payment.
                // But since we just added an uncommitted payment in memory, we can manually add $amount to active payments
                $newActivePayments = $purchase->getEffectivePaidAmount();
                $newDueAmount = $purchase->total_amount - $newActivePayments;
                $newStatus = ($newDueAmount <= 0) ? 'Paid' : 'Partial';

                $purchase->update([
                    'paid_amount' => $newActivePayments,
                    'due_amount' => $newDueAmount,
                    'payment_status' => $newStatus
                ]);

                $createdPayments[] = $payment;
            }

            if (empty($createdPayments)) {
                throw ValidationException::withMessages(['allocations' => 'Tidak ada alokasi positif yang valid untuk diproses.']);
            }

            // Attach media
            if ($attachmentPath && count($createdPayments) > 0) {
                try {
                    foreach ($createdPayments as $index => $payment) {
                        $adder = $payment->addMedia($attachmentPath);
                        $adder->preservingOriginal()->toMediaCollection('attachments');
                    }
                    @unlink($attachmentPath);
                } catch (\Exception $e) {
                    foreach ($createdPayments as $payment) {
                        $payment->clearMediaCollection('attachments');
                    }
                    throw $e;
                }
            }

            return $createdPayments;
        });
    }
}
