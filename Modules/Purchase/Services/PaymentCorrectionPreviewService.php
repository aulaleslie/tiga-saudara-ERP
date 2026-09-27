<?php

namespace Modules\Purchase\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Entities\PurchaseCorrectionToken;
use Modules\Purchase\Services\PurchaseNormalizer;

/**
 * Preview service for payment corrections during received-purchase correction.
 * Calculates and validates payment adjustments before confirmation.
 */
class PaymentCorrectionPreviewService
{
    public function __construct(
        private PurchaseNormalizer $normalizer,
    ) {
    }

    /**
     * Preview payment corrections for a given correction payload.
     *
     * @return array{
     *     success: bool,
     *     error?: string,
     *     corrected_total?: float,
     *     current_total?: float,
     *     total_delta?: float,
     *     active_payment_count?: int,
     *     selected_payment?: array|null,
     *     non_selected_active_total?: float,
     *     resulting_paid_amount?: float,
     *     resulting_due_amount?: float,
     *     resulting_status?: string,
     *     validation_errors?: array<string>,
     *     confirmation_token?: string
     * }
     */
    public function previewPaymentCorrection(
        Purchase $purchase,
        array $lineCorrections,
        ?float $globalDiscount = null,
        ?float $shipping = null,
        ?int $selectedPaymentId = null,
    ): array {
        // First, calculate what the corrected total would be
        $detailArrays = $purchase->purchaseDetails
            ->map(fn ($detail) => $detail->toArray())
            ->values()
            ->toArray();

        // Apply corrections to detail arrays
        foreach ($lineCorrections as $correction) {
            $detailId = $correction['detail_id'];
            foreach ($detailArrays as &$detail) {
                if ($detail['id'] === $detailId) {
                    if (isset($correction['unit_price'])) {
                        $detail['unit_price'] = $correction['unit_price'];
                        $detail['price'] = $correction['unit_price'];
                    }
                    if (isset($correction['product_discount_amount'])) {
                        $detail['product_discount_amount'] = $correction['product_discount_amount'];
                    }
                    break;
                }
            }
        }

        $setting = $purchase->setting ?? \Modules\Setting\Entities\Setting::find($purchase->setting_id);
        $isPkp = $setting?->is_pkp ?? false;

        $correctedHeader = [
            'discount_amount' => $globalDiscount ?? $purchase->discount_amount,
            'discount_percentage' => $purchase->discount_percentage ?? 0,
            'shipping_amount' => $shipping ?? $purchase->shipping_amount,
            'tax_id' => $purchase->tax_id,
            'tax_percentage' => $purchase->tax_percentage ?? 0,
        ];

        $normalizedData = $this->normalizer->normalize(
            header: $correctedHeader,
            detailInputs: $detailArrays,
            isPkp: $isPkp,
        );

        $correctedTotal = $normalizedData['header']['total_amount'];
        $currentTotal = $purchase->total_amount;
        $delta = $correctedTotal - $currentTotal;

        // Get active payments
        $activePayments = PurchasePayment::where('purchase_id', $purchase->id)
            ->where('status', PurchasePayment::STATUS_ACTIVE)
            ->get();

        $activePaymentCount = $activePayments->count();

        // Validate based on payment count
        if ($activePaymentCount === 0) {
            // No active payments: simple case
            return [
                'success' => true,
                'corrected_total' => $correctedTotal,
                'current_total' => $currentTotal,
                'total_delta' => $delta,
                'active_payment_count' => 0,
                'selected_payment' => null,
                'non_selected_active_total' => 0,
                'resulting_paid_amount' => 0,
                'resulting_due_amount' => $correctedTotal,
                'resulting_status' => 'UNPAID',
            ];
        } elseif ($activePaymentCount === 1) {
            // Single active payment: update to corrected total
            $payment = $activePayments->first();

            return [
                'success' => true,
                'corrected_total' => $correctedTotal,
                'current_total' => $currentTotal,
                'total_delta' => $delta,
                'active_payment_count' => 1,
                'selected_payment' => [
                    'id' => $payment->id,
                    'current_amount' => $payment->amount,
                    'corrected_amount' => $correctedTotal,
                ],
                'non_selected_active_total' => 0,
                'resulting_paid_amount' => $correctedTotal,
                'resulting_due_amount' => 0,
                'resulting_status' => 'PAID',
            ];
        } else {
            // Multiple active payments: require selection
            if (!$selectedPaymentId) {
                return [
                    'success' => false,
                    'error' => 'Multiple active payments require selection',
                    'active_payment_count' => $activePaymentCount,
                    'corrected_total' => $correctedTotal,
                    'current_total' => $currentTotal,
                    'total_delta' => $delta,
                ];
            }

            $selectedPayment = $activePayments->firstWhere('id', $selectedPaymentId);
            if (!$selectedPayment) {
                return [
                    'success' => false,
                    'error' => 'Selected payment not found or not active',
                ];
            }

            $newAmount = $selectedPayment->amount + $delta;

            // Validate: no negative payments
            if ($newAmount < 0) {
                return [
                    'success' => false,
                    'error' => 'Correction would result in negative payment amount',
                    'validation_errors' => ['Selected payment would become negative'],
                ];
            }

            // Validate: no overpayment
            $nonSelectedTotal = $activePayments
                ->where('id', '!=', $selectedPaymentId)
                ->sum('amount');

            if ($nonSelectedTotal + $newAmount > $correctedTotal) {
                return [
                    'success' => false,
                    'error' => 'Correction would result in overpayment',
                    'validation_errors' => ['Total active payments would exceed corrected total'],
                ];
            }

            $resultingPaid = $newAmount + $nonSelectedTotal;
            $resultingDue = max(0, $correctedTotal - $resultingPaid);
            $resultingStatus = $resultingPaid >= $correctedTotal ? 'PAID' : ($resultingPaid > 0 ? 'PARTIAL' : 'UNPAID');

            // Generate and persist confirmation token
            $payloadHash = $this->hashCorrectionPayload(
                lineCorrections: $lineCorrections,
                globalDiscount: $globalDiscount,
                shipping: $shipping,
            );

            $userId = auth()->id();
            if (!$userId) {
                // For testing or cases where auth context is unavailable
                return [
                    'success' => false,
                    'error' => 'User not authenticated',
                ];
            }

            $token = PurchaseCorrectionToken::create([
                'token' => Str::uuid(),
                'purchase_id' => $purchase->id,
                'setting_id' => $purchase->setting_id,
                'user_id' => $userId,
                'session_id' => session()->getId(),
                'selected_payment_id' => $selectedPaymentId,
                'correction_payload_hash' => $payloadHash,
                'source_document_total' => $purchase->total_amount,
                'expires_at' => Carbon::now()->addHours(1),
            ]);

            return [
                'success' => true,
                'corrected_total' => $correctedTotal,
                'current_total' => $currentTotal,
                'total_delta' => $delta,
                'active_payment_count' => $activePaymentCount,
                'selected_payment' => [
                    'id' => $selectedPayment->id,
                    'current_amount' => $selectedPayment->amount,
                    'corrected_amount' => $newAmount,
                ],
                'non_selected_active_total' => $nonSelectedTotal,
                'resulting_paid_amount' => $resultingPaid,
                'resulting_due_amount' => $resultingDue,
                'resulting_status' => $resultingStatus,
                'confirmation_token' => $token->token->toString(),
            ];
        }
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
