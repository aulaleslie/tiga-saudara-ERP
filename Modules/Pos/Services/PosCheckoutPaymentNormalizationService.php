<?php

namespace Modules\Pos\Services;

use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Setting\Entities\PaymentMethod;

class PosCheckoutPaymentNormalizationService
{
    /**
     * Normalize multi-payment payload to canonical form with deterministic ordering.
     *
     * @param  int  $settingId
     * @param  array<int, array{payment_method_id:int,amount_paid:float,reference?:string}>  $paymentsPayload
     * @return array{
     *     payments: array<int, array{
     *         payment_method_id: int,
     *         amount_minor_units: int,
     *         reference: ?string,
     *         is_cash: bool,
     *         requires_reference: bool,
     *         sequence_order: int,
     *         stage_order: ?int,
     *         edc_reference: ?string,
     *         payment_image_token: ?string
     *     }>,
     *     total_amount_minor_units: int,
     *     total_cash_minor_units: int
     * }
     */
    public function normalize(int $settingId, array $paymentsPayload): array
    {
        if (!$paymentsPayload) {
            throw new PosCheckoutValidationException('PAYMENT_INVALID', 'Payments array cannot be empty.');
        }

        $paymentMethods = $this->fetchPaymentMethods($settingId);
        $normalizedPayments = [];
        $totalAmountMinor = 0;
        $totalCashMinor = 0;

        foreach ($paymentsPayload as $index => $paymentData) {
            $paymentMethodId = (int) ($paymentData['payment_method_id'] ?? 0);
            $amountPaid = round((float) ($paymentData['amount_paid'] ?? 0), 2);
            $reference = isset($paymentData['reference']) ? trim((string) $paymentData['reference']) : null;
            $reference = $reference !== '' ? $reference : null;
            $stageOrder = isset($paymentData['stage_order']) ? (int) $paymentData['stage_order'] : null;
            $edcReference = isset($paymentData['edc_reference']) ? trim((string) $paymentData['edc_reference']) : null;
            $edcReference = $edcReference !== '' ? $edcReference : null;
            $paymentImageToken = isset($paymentData['payment_image_token']) ? trim((string) $paymentData['payment_image_token']) : null;
            $paymentImageToken = $paymentImageToken !== '' ? $paymentImageToken : null;

            // Validate amount
            if ($amountPaid <= 0) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', "Payment #{$index}: amount must be greater than zero.");
            }

            // Validate and fetch payment method
            if (!$paymentMethodId || $paymentMethodId <= 0) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', "Payment #{$index}: payment method is required.");
            }

            $paymentMethod = $paymentMethods[$paymentMethodId] ?? null;
            if (!$paymentMethod) {
                throw new PosCheckoutValidationException('PAYMENT_INVALID', "Payment #{$index}: payment method not found or not enabled for this setting.");
            }

            // Validate reference requirement
            if ($paymentMethod['requires_reference'] && (!$reference || $reference === '')) {
                throw new PosCheckoutValidationException(
                    'PAYMENT_INVALID',
                    "Payment #{$index}: payment method {$paymentMethod['name']} requires a reference number."
                );
            }

            $amountMinorUnits = (int) round($amountPaid * 100);
            $isCash = (bool) $paymentMethod['is_cash'];

            // Validate cash payment cannot have image token
            if ($isCash && $paymentImageToken !== null) {
                throw new PosCheckoutValidationException(
                    'PAYMENT_INVALID',
                    "Payment #{$index}: cash payment method cannot include a payment image."
                );
            }

            $normalizedPayments[] = [
                'payment_method_id' => $paymentMethodId,
                'amount_minor_units' => $amountMinorUnits,
                'reference' => $reference,
                'is_cash' => $isCash,
                'requires_reference' => (bool) $paymentMethod['requires_reference'],
                'sequence_order' => count($normalizedPayments), // Preserve input order initially
                'stage_order' => $stageOrder,
                'edc_reference' => $edcReference,
                'payment_image_token' => $paymentImageToken,
            ];

            $totalAmountMinor += $amountMinorUnits;
            if ($isCash) {
                $totalCashMinor += $amountMinorUnits;
            }
        }

        return [
            'payments' => $normalizedPayments,
            'total_amount_minor_units' => $totalAmountMinor,
            'total_cash_minor_units' => $totalCashMinor,
        ];
    }

    /**
     * Get canonical payload hash for idempotency.
     * Includes canonicalized payment composition.
     *
     * @param  array<int, array{
     *     payment_method_id:int,
     *     amount_minor_units:int,
     *     reference:?string
     * }>  $normalizedPayments
     * @return string
     */
    public function getCanonicalPaymentHash(array $normalizedPayments): string
    {
        // Create canonical representation with all idempotency-relevant fields
        // Order: method_id, amount, reference, edc_reference, stage_order, image_token
        $canonical = [];
        foreach ($normalizedPayments as $payment) {
            $canonical[] = [
                (int) $payment['payment_method_id'],
                (int) $payment['amount_minor_units'],
                $payment['reference'] ?? '',
                $payment['edc_reference'] ?? '',
                $payment['stage_order'] ?? 0,
                $payment['payment_image_token'] ?? '',
            ];
        }

        // Sort for deterministic ordering
        usort($canonical, function (array $a, array $b) {
            if ($a[0] !== $b[0]) return $a[0] <=> $b[0];  // method_id
            if ($a[1] !== $b[1]) return $a[1] <=> $b[1];  // amount
            if ($a[2] !== $b[2]) return strcmp($a[2], $b[2]);  // reference
            if ($a[3] !== $b[3]) return strcmp($a[3], $b[3]);  // edc_reference
            if ($a[4] !== $b[4]) return $a[4] <=> $b[4];  // stage_order
            return strcmp($a[5], $b[5]);  // image_token
        });

        // Use same JSON encoding flags as replay builder for consistency
        return hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    /**
     * Fetch enabled payment methods for a setting.
     *
     * @param  int  $settingId
     * @return array<int, array{id:int,name:string,is_cash:bool,requires_reference:bool}>
     */
    private function fetchPaymentMethods(int $settingId): array
    {
        $methods = PaymentMethod::query()
            ->join('setting_pos_payment_methods', 'payment_methods.id', '=', 'setting_pos_payment_methods.payment_method_id')
            ->where('setting_pos_payment_methods.setting_id', $settingId)
            ->where('setting_pos_payment_methods.is_enabled', true)
            ->select(
                'payment_methods.id',
                'payment_methods.name',
                'payment_methods.is_cash',
                'payment_methods.requires_reference'
            )
            ->get();

        $methodsById = [];
        foreach ($methods as $method) {
            $methodsById[(int) $method->id] = [
                'id' => (int) $method->id,
                'name' => (string) $method->name,
                'is_cash' => (bool) $method->is_cash,
                'requires_reference' => (bool) $method->requires_reference,
            ];
        }

        return $methodsById;
    }
}
