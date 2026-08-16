<?php

namespace Modules\Pos\Services;

use DomainException;
use Modules\Pos\Entities\PosActionApprovalRequest;

/**
 * Builds row-override approval payloads server-side.
 *
 * One builder serves both request creation and execution so the two paths
 * cannot interpret the same values differently. Nothing here is taken from the
 * client: source values, fingerprint, and customer context are all derived from
 * the authoritative cart.
 *
 * Source values are compared like with like — unit price against unit price,
 * final row total against final row total — and the current row total is
 * derived through the canonical totals calculator rather than `qty x unit_price`,
 * which is simply wrong whenever the row carries a discount.
 */
class PosRowOverrideApprovalPayloadBuilder
{
    public function __construct(
        private readonly PosCartTotalsCalculator $totalsCalculator,
        private readonly PosCartLineFingerprintService $fingerprintService
    ) {
    }

    /**
     * @param  array<string, mixed>  $cart
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function build(
        string $actionType,
        int $settingId,
        int $posSessionId,
        int $lineId,
        array $cart,
        array $line,
        int $requestedValueMinor,
        int $requesterId,
        ?string $reason = null
    ): array {
        $normalizedAction = strtoupper($actionType);

        if (! PosActionApprovalRequest::isRowOverrideAction($normalizedAction)) {
            throw new DomainException('Invalid row override action type.');
        }

        if ($requestedValueMinor < 0) {
            throw new DomainException('Nilai yang diminta tidak boleh negatif.');
        }

        $context = $this->fingerprintService->buildContext($settingId, $cart);

        $sourceUnitPriceMinor = $this->currentUnitPriceMinor($line);
        $sourceTotalMinor = $this->currentRowTotalMinor($settingId, $cart, $lineId, $context);

        // Compare like with like.
        $sourceValueMinor = $normalizedAction === PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE
            ? $sourceUnitPriceMinor
            : $sourceTotalMinor;

        $fingerprint = $this->fingerprintService->generateApprovalFingerprint(
            $line,
            $context,
            $normalizedAction,
            $requestedValueMinor,
            $posSessionId,
            $lineId,
            $requesterId
        );

        return [
            'action_type' => $normalizedAction,
            'value_kind' => $normalizedAction === PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE
                ? 'UNIT_PRICE'
                : 'ROW_TOTAL',
            'pos_session_id' => $posSessionId,
            'line_id' => $lineId,
            'product_id' => (int) ($line['product_id'] ?? 0),
            'product_name' => (string) ($line['product_name'] ?? ''),
            'qty' => (int) ($line['qty'] ?? 0),
            'requester_id' => $requesterId,
            'reason' => $reason,

            // Minor units throughout so the queue never compares a float to an int.
            'source_value_minor' => $sourceValueMinor,
            'requested_value_minor' => $requestedValueMinor,
            'delta_minor' => $requestedValueMinor - $sourceValueMinor,

            // Both source values are recorded for display context, regardless of
            // which one this action makes authoritative.
            'source_unit_price_minor' => $sourceUnitPriceMinor,
            'source_total_minor' => $sourceTotalMinor,

            // Action-specific aliases kept for execution-time comparison.
            'requested_unit_price_minor' => $normalizedAction === PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE
                ? $requestedValueMinor
                : null,
            'requested_total_minor' => $normalizedAction === PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE
                ? $requestedValueMinor
                : null,

            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * The authoritative current unit price, in minor units.
     *
     * @param  array<string, mixed>  $line
     */
    public function currentUnitPriceMinor(array $line): int
    {
        return (int) round(((float) ($line['unit_price'] ?? 0)) * 100);
    }

    /**
     * The current final row total, derived through the canonical totals
     * calculator with the cart's real discounts, taxes, quantity, and customer
     * context — never `qty x unit_price`.
     *
     * @param  array<string, mixed>  $cart
     * @param  array<string, mixed>  $context
     */
    public function currentRowTotalMinor(int $settingId, array $cart, int $lineId, array $context): int
    {
        $calculated = $this->totalsCalculator->calculate(
            array_values($cart['lines'] ?? []),
            [
                'type' => $cart['bill_discount_type'] ?? 'fixed',
                'value' => $cart['bill_discount_value'] ?? 0,
            ],
            ! empty($context['is_pkp'])
        );

        foreach ($calculated['lines'] as $calculatedLine) {
            if ((int) ($calculatedLine['line_id'] ?? 0) === $lineId) {
                // line_net_before_bill is the row's own authoritative total,
                // before bill-level allocation redistributes anything.
                $net = $calculatedLine['line_net_before_bill']
                    ?? $calculatedLine['line_total']
                    ?? 0;

                return (int) round(((float) $net) * 100);
            }
        }

        throw new DomainException('Baris keranjang tidak ditemukan.');
    }

    /**
     * Assert a submitted value exactly equals the approved value.
     *
     * A mismatch is rejected rather than silently replaced: substituting the
     * approved value would apply an amount the cashier did not just confirm and
     * would hide client/server divergence.
     *
     * @param  array<string, mixed>  $payload
     */
    public function assertSubmittedValueMatchesApproved(
        array $payload,
        string $actionType,
        int $submittedValueMinor
    ): void {
        $normalizedAction = strtoupper($actionType);

        $approved = $normalizedAction === PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE
            ? ($payload['requested_unit_price_minor'] ?? null)
            : ($payload['requested_total_minor'] ?? null);

        if ($approved === null || (int) $approved !== $submittedValueMinor) {
            throw new DomainException('REQUESTED_VALUE_MISMATCH');
        }
    }
}
