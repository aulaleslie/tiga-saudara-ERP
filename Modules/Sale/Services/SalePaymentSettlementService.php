<?php

namespace Modules\Sale\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\CustomerCredit;
use Modules\SalesReturn\Entities\SalePaymentCreditApplication;
use Modules\Setting\Entities\PaymentMethod;

/**
 * Handles atomic settlement of a single sale payment with optional customer credit.
 *
 * This service encapsulates all authoritative validation and settlement logic:
 * - Locking the sale and credit (if used)
 * - Revalidating canonical live due and credit state inside the transaction
 * - Creating the payment and credit application
 * - Reconciling the sale
 *
 * The controller must validate request shape and format; this service
 * performs all authoritative business logic decisions.
 */
class SalePaymentSettlementService
{
    /**
     * Settle a single sale with optional customer credit.
     *
     * All validation and state mutation occurs inside a database transaction.
     * Any failure results in complete rollback.
     *
     * @param int $saleId
     * @param int $customerId
     * @param array $data Expected structure:
     *        - amount: float (cash payment amount)
     *        - date: date string
     *        - reference: string
     *        - payment_method_id: int
     *        - note: string|null
     *        - attachment: string|null (file path to temp file)
     *        - credit_customer_credit_id: int|null
     *        - credit_amount: float|null
     * @return SalePayment The created payment record
     * @throws ValidationException
     */
    public function settle($saleId, $customerId, array $data): SalePayment
    {
        return DB::transaction(function () use ($saleId, $customerId, $data) {
            // Normalize amounts
            $cashAmount = round((float) ($data['amount'] ?? 0), 2);
            $creditId = $data['credit_customer_credit_id'] ?? null;
            $creditAmount = round((float) ($data['credit_amount'] ?? 0), 2);

            // Lock and revalidate sale
            $sale = Sale::where('id', $saleId)
                ->lockForUpdate()
                ->first();

            if (!$sale || $sale->customer_id !== $customerId) {
                throw ValidationException::withMessages([
                    'sale_id' => 'Penjualan tidak ditemukan atau pelanggan tidak cocok.',
                ]);
            }

            // Calculate canonical live due after lock
            $currentLiveDue = round($sale->live_due_amount, 2);
            $totalApplied = round($cashAmount + $creditAmount, 2);

            if ($totalApplied <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Jumlah pembayaran atau kredit harus lebih dari 0.',
                ]);
            }

            if ($totalApplied > $currentLiveDue) {
                throw ValidationException::withMessages([
                    'amount' => 'Total pembayaran melebihi sisa tagihan saat ini.',
                ]);
            }

            // If using customer credit, lock and revalidate it inside the transaction
            $credit = null;
            if ($creditAmount > 0) {
                if (!$creditId) {
                    throw ValidationException::withMessages([
                        'credit_customer_credit_id' => 'ID kredit pelanggan diperlukan.',
                    ]);
                }

                $credit = CustomerCredit::where('id', $creditId)
                    ->lockForUpdate()
                    ->first();

                if (!$credit) {
                    throw ValidationException::withMessages([
                        'credit_customer_credit_id' => 'Kredit pelanggan tidak lagi tersedia.',
                    ]);
                }

                if (strtoupper($credit->status) !== 'OPEN') {
                    throw ValidationException::withMessages([
                        'credit_customer_credit_id' => 'Kredit pelanggan tidak dalam status terbuka.',
                    ]);
                }

                if ($credit->customer_id !== $customerId) {
                    throw ValidationException::withMessages([
                        'credit_customer_credit_id' => 'Kredit pelanggan tidak cocok dengan pelanggan penjualan.',
                    ]);
                }

                $currentRemaining = round((float) $credit->remaining_amount, 2);
                if ($creditAmount > $currentRemaining) {
                    throw ValidationException::withMessages([
                        'credit_amount' => 'Jumlah kredit melebihi saldo kredit yang tersedia sekarang.',
                    ]);
                }
            }

            // Resolve payment method
            $paymentMethod = PaymentMethod::find($data['payment_method_id']);
            if (!$paymentMethod) {
                throw ValidationException::withMessages([
                    'payment_method_id' => 'Metode pembayaran tidak ditemukan.',
                ]);
            }

            // Create the sale payment record
            $payment = SalePayment::create([
                'date'              => $data['date'],
                'reference'         => $data['reference'],
                'amount'            => $cashAmount,
                'note'              => $data['note'] ?? null,
                'sale_id'           => $saleId,
                'payment_method_id' => $data['payment_method_id'],
                'payment_method'    => $paymentMethod->name,
            ]);

            // If an attachment exists, add it to the payment's media collection
            if (!empty($data['attachment'])) {
                $payment->addMedia(Storage::path('temp/dropzone/' . $data['attachment']))
                    ->toMediaCollection('attachments');
            }

            // If using customer credit, create the application and update credit
            if ($creditAmount > 0 && $credit) {
                SalePaymentCreditApplication::create([
                    'sale_payment_id' => $payment->id,
                    'customer_credit_id' => $credit->id,
                    'amount' => $creditAmount,
                ]);

                $remaining = round((float) $credit->remaining_amount - $creditAmount, 2);
                $credit->update([
                    'remaining_amount' => max($remaining, 0),
                    'status' => $remaining <= 0 ? 'CLOSED' : 'OPEN',
                ]);
            }

            // Reconcile sale header using canonical settlement calculation
            $sale->reconcileFromActivePayments();

            return $payment;
        });
    }
}
