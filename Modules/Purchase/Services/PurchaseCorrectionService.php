<?php

namespace Modules\Purchase\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseCorrection;
use Modules\Purchase\Entities\PurchaseCorrectionToken;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;

class PurchaseCorrectionService
{
    public function correct(
        Purchase $purchase,
        User $actor,
        string $reason,
        array $lineCorrections = [],
        ?float $globalDiscount = null,
        ?float $shipping = null,
        ?int $selectedPaymentId = null,
        ?string $confirmationToken = null,
    ): array {
        if (!in_array($purchase->status, [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY])) {
            return ['success' => false, 'error' => 'Purchase is not eligible for correction'];
        }

        // Count active payments to check if token is required
        $activePayments = PurchasePayment::where('purchase_id', $purchase->id)
            ->where('status', PurchasePayment::STATUS_ACTIVE)
            ->get();

        if ($activePayments->count() > 1 && !$confirmationToken) {
            return ['success' => false, 'error' => 'Confirmation token is required for purchases with multiple active payments'];
        }

        return DB::transaction(function () use (
            $purchase,
            $actor,
            $reason,
            $lineCorrections,
            $globalDiscount,
            $shipping,
            $selectedPaymentId,
            $confirmationToken,
            $activePayments,
        ) {
            $purchase = Purchase::lockForUpdate()->find($purchase->id);
            $activePayments = PurchasePayment::lockForUpdate()
                ->where('purchase_id', $purchase->id)
                ->where('status', PurchasePayment::STATUS_ACTIVE)
                ->get();

            $tokenRecord = null;

            // Validate confirmation token if multiple active payments exist
            if ($activePayments->count() > 1 && $confirmationToken) {
                $tokenValidation = $this->validateConfirmationToken(
                    purchase: $purchase,
                    actor: $actor,
                    tokenString: $confirmationToken,
                    selectedPaymentId: $selectedPaymentId,
                    lineCorrections: $lineCorrections,
                    globalDiscount: $globalDiscount,
                    shipping: $shipping,
                );

                if (!$tokenValidation['valid']) {
                    return ['success' => false, 'error' => $tokenValidation['error']];
                }

                $tokenRecord = $tokenValidation['token_record'] ?? null;
            }

            $fieldCorrections = [];
            $oldHeaderValues = [
                'discount_amount' => $purchase->discount_amount,
                'shipping_amount' => $purchase->shipping_amount,
                'total_amount' => $purchase->total_amount,
            ];

            // Validate and apply line corrections
            $detailIds = collect($lineCorrections)->pluck('detail_id')->toArray();

            // Check for duplicates
            if (count($detailIds) !== count(array_unique($detailIds))) {
                return ['success' => false, 'error' => 'Duplicate detail IDs in correction'];
            }

            foreach ($lineCorrections as $correction) {
                $detailId = $correction['detail_id'];

                $detail = PurchaseDetail::lockForUpdate()
                    ->where('id', $detailId)
                    ->where('purchase_id', $purchase->id)
                    ->first();

                if (!$detail) {
                    return ['success' => false, 'error' => "Detail ID {$detailId} not found in this purchase"];
                }

                $beforeValues = [
                    'unit_price' => $detail->unit_price,
                    'product_discount_amount' => $detail->product_discount_amount ?? 0,
                ];

                $updateData = [];

                if (isset($correction['unit_price'])) {
                    $updateData['unit_price'] = $correction['unit_price'];
                    $updateData['price'] = $correction['unit_price'];  // Ensure price is in sync
                    $fieldCorrections[$detail->id]['unit_price'] = [
                        'before' => $beforeValues['unit_price'],
                        'after' => $correction['unit_price'],
                    ];
                }

                if (isset($correction['product_discount_amount'])) {
                    $updateData['product_discount_amount'] = $correction['product_discount_amount'];
                    $fieldCorrections[$detail->id]['product_discount_amount'] = [
                        'before' => $beforeValues['product_discount_amount'],
                        'after' => $correction['product_discount_amount'],
                    ];
                }

                if (!empty($updateData)) {
                    $detail->update($updateData);
                }
            }

            // Reload details from the locked purchase instance
            // Note: must reload since details were updated above
            $purchase->load('purchaseDetails');

            // Ensure each detail is refreshed from the DB (not from in-memory cache)
            foreach ($purchase->purchaseDetails as $detail) {
                $detail->refresh();
            }

            // Apply header corrections
            if ($globalDiscount !== null) {
                $purchase->discount_amount = $globalDiscount;
                $fieldCorrections['header']['discount_amount'] = [
                    'before' => $oldHeaderValues['discount_amount'],
                    'after' => $globalDiscount,
                ];
            }

            if ($shipping !== null) {
                $purchase->shipping_amount = $shipping;
                $fieldCorrections['header']['shipping_amount'] = [
                    'before' => $oldHeaderValues['shipping_amount'],
                    'after' => $shipping,
                ];
            }

            // Normalize using fresh detail data (as arrays for clarity)
            $detailArrays = $purchase->purchaseDetails
                ->map(fn ($detail) => $detail->toArray())
                ->values()
                ->toArray();

            // Get the setting to determine PKP status from canonical source
            $setting = $purchase->setting ?? \Modules\Setting\Entities\Setting::find($purchase->setting_id);
            $isPkp = $setting?->is_pkp ?? false;

            $normalizer = app(PurchaseNormalizer::class);
            $normalizedData = $normalizer->normalize(
                header: [
                    'discount_amount' => $purchase->discount_amount,
                    'discount_percentage' => $purchase->discount_percentage ?? 0,
                    'shipping_amount' => $purchase->shipping_amount,
                    'tax_id' => $purchase->tax_id,
                    'tax_percentage' => $purchase->tax_percentage ?? 0,
                ],
                detailInputs: $detailArrays,
                isPkp: $isPkp,
            );

            // Apply normalized detail values back to existing detail rows
            $details = $purchase->purchaseDetails->toArray();
            foreach ($normalizedData['details'] as $idx => $normalizedDetail) {
                if (isset($details[$idx])) {
                    $detailData = $details[$idx];
                    $detailId = $detailData['id'];
                    PurchaseDetail::where('id', $detailId)->update([
                        'unit_price' => $normalizedDetail['unit_price'],
                        'price' => $normalizedDetail['price'],
                        'product_discount_type' => $normalizedDetail['product_discount_type'],
                        'product_discount_amount' => $normalizedDetail['product_discount_amount'],
                        'sub_total' => $normalizedDetail['sub_total'],
                        'product_tax_amount' => $normalizedDetail['product_tax_amount'],
                        'tax_id' => $normalizedDetail['tax_id'],
                    ]);
                }
            }

            Purchase::where('id', $purchase->id)->update([
                'total_amount' => $normalizedData['header']['total_amount'],
                'due_amount' => $normalizedData['header']['due_amount'],
                'discount_amount' => $normalizedData['header']['discount_amount'],
                'discount_percentage' => $normalizedData['header']['discount_percentage'],
                'shipping_amount' => $normalizedData['header']['shipping_amount'],
                'tax_id' => $normalizedData['header']['tax_id'],
                'tax_percentage' => $normalizedData['header']['tax_percentage'],
                'tax_amount' => $normalizedData['header']['tax_amount'],
            ]);

            $correctedTotal = $normalizedData['header']['total_amount'];

            // Handle payment reconciliation
            $paymentBeforeAfter = null;
            if ($activePayments->count() === 0) {
                // No active payments: recalculate due amount
                $purchase->paid_amount = 0;
                $purchase->due_amount = $correctedTotal;
            } elseif ($activePayments->count() === 1) {
                // Exactly one payment: update to corrected total
                $payment = $activePayments->first();
                $paymentBeforeAfter = [
                    'payment_id' => $payment->id,
                    'before' => $payment->amount,
                    'after' => $correctedTotal,
                ];
                $payment->amount = $correctedTotal;
                $payment->save();

                $purchase->paid_amount = $payment->amount;
                $purchase->due_amount = 0;
            } else {
                // Multiple payments: require selection
                if (!$selectedPaymentId) {
                    return ['success' => false, 'error' => 'Multiple active payments require selection'];
                }

                $selectedPayment = $activePayments->firstWhere('id', $selectedPaymentId);
                if (!$selectedPayment) {
                    return ['success' => false, 'error' => 'Selected payment not found or not active'];
                }

                $delta = $correctedTotal - $oldHeaderValues['total_amount'];
                $newAmount = $selectedPayment->amount + $delta;

                if ($newAmount < 0) {
                    return ['success' => false, 'error' => 'Correction would result in negative payment'];
                }

                // Check for overpayment
                $nonSelectedTotal = $activePayments
                    ->where('id', '!=', $selectedPaymentId)
                    ->sum('amount');

                if ($nonSelectedTotal + $newAmount > $correctedTotal) {
                    return ['success' => false, 'error' => 'Correction would result in overpayment'];
                }

                $paymentBeforeAfter = [
                    'payment_id' => $selectedPayment->id,
                    'before' => $selectedPayment->amount,
                    'after' => $newAmount,
                ];
                $selectedPayment->amount = $newAmount;
                $selectedPayment->save();

                $purchase->paid_amount = PurchasePayment::where('purchase_id', $purchase->id)
                    ->where('status', PurchasePayment::STATUS_ACTIVE)
                    ->sum('amount');
                $purchase->due_amount = max(0, $correctedTotal - $purchase->paid_amount);
            }

            $purchase->payment_status = $this->resolvePaymentStatus($purchase);
            $purchase->save();

            // Create correction audit record
            $correction = PurchaseCorrection::create([
                'setting_id' => $purchase->setting_id,
                'purchase_id' => $purchase->id,
                'actor_user_id' => $actor->id,
                'reason' => $reason,
                'field_corrections' => $fieldCorrections,
                'payment_before_after' => $paymentBeforeAfter,
            ]);

            // Consume token if present (after successful persistence)
            if ($tokenRecord) {
                $tokenRecord->consume();
            }

            return [
                'success' => true,
                'correction_id' => $correction->id,
            ];
        });
    }

    private function resolvePaymentStatus(Purchase $purchase): string
    {
        if ($purchase->paid_amount >= $purchase->total_amount) {
            return 'PAID';
        }

        if ($purchase->paid_amount > 0) {
            return 'PARTIAL';
        }

        return 'UNPAID';
    }

    private function validateConfirmationToken(
        Purchase $purchase,
        User $actor,
        string $tokenString,
        ?int $selectedPaymentId,
        array $lineCorrections,
        ?float $globalDiscount,
        ?float $shipping,
    ): array {
        $tokenRecord = PurchaseCorrectionToken::where('token', $tokenString)->first();

        if (!$tokenRecord) {
            return [
                'valid' => false,
                'error' => 'Confirmation token not found',
            ];
        }

        if (!$tokenRecord->isValid(session()->getId(), $actor->id)) {
            if ($tokenRecord->consumed_at !== null) {
                return [
                    'valid' => false,
                    'error' => 'Confirmation token has already been consumed',
                ];
            }

            if ($tokenRecord->expires_at && $tokenRecord->expires_at->isPast()) {
                return [
                    'valid' => false,
                    'error' => 'Confirmation token has expired',
                ];
            }

            if ($tokenRecord->session_id !== session()->getId()) {
                return [
                    'valid' => false,
                    'error' => 'Confirmation token session mismatch',
                ];
            }

            if ($tokenRecord->user_id !== $actor->id) {
                return [
                    'valid' => false,
                    'error' => 'Confirmation token user mismatch',
                ];
            }

            return [
                'valid' => false,
                'error' => 'Confirmation token is invalid',
            ];
        }

        // Validate token context
        if ($tokenRecord->purchase_id !== $purchase->id) {
            return [
                'valid' => false,
                'error' => 'Confirmation token purchase mismatch',
            ];
        }

        if ($tokenRecord->selected_payment_id !== $selectedPaymentId) {
            return [
                'valid' => false,
                'error' => 'Confirmation token selected payment mismatch',
            ];
        }

        if ($tokenRecord->source_document_total != $purchase->total_amount) {
            return [
                'valid' => false,
                'error' => 'Confirmation token source document total mismatch (document may have been modified)',
            ];
        }

        // Validate payload hash
        $payloadHash = $this->hashCorrectionPayload(
            lineCorrections: $lineCorrections,
            globalDiscount: $globalDiscount,
            shipping: $shipping,
        );

        if ($tokenRecord->correction_payload_hash !== $payloadHash) {
            return [
                'valid' => false,
                'error' => 'Confirmation token payload mismatch (correction values may have changed)',
            ];
        }

        return [
            'valid' => true,
            'token_record' => $tokenRecord,
        ];
    }

    private function hashCorrectionPayload(
        array $lineCorrections,
        ?float $globalDiscount,
        ?float $shipping,
    ): string {
        $payload = [
            'line_corrections' => $lineCorrections,
            'global_discount' => $globalDiscount,
            'shipping' => $shipping,
        ];

        return hash('sha256', json_encode($payload));
    }
}
