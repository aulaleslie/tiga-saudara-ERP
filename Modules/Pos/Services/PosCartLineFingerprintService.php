<?php

namespace Modules\Pos\Services;

use Modules\Product\Services\BundleLifecycle\ProductBundleSnapshotMapper;
use Modules\Setting\Entities\Setting;

/**
 * Canonical fingerprint for one POS cart line.
 *
 * Both active row overrides share this service, but an approval payload binds
 * the fingerprint to its action type, exact requested value, session, line, and
 * requester — so a fingerprint can never be replayed across actions, values,
 * sessions, lines, or users.
 */
class PosCartLineFingerprintService
{
    public function __construct(
        private readonly ?ProductBundleSnapshotMapper $bundleSnapshotMapper = null
    ) {
    }

    /**
     * Build cart-level pricing context from authoritative cart state only.
     *
     * Client-submitted context is never accepted, and `customer_group` is not
     * used: tier pricing is driven by `selected_customer_tier`.
     *
     * @param  array<string, mixed>  $cart
     * @return array{is_pkp: bool, customer_id: int|null, customer_tier: string|null}
     */
    public function buildContext(int $settingId, array $cart): array
    {
        return [
            'is_pkp' => (bool) (Setting::query()->whereKey($settingId)->value('is_pkp') ?? false),
            'customer_id' => isset($cart['selected_customer_id']) && (int) $cart['selected_customer_id'] > 0
                ? (int) $cart['selected_customer_id']
                : null,
            'customer_tier' => isset($cart['selected_customer_tier']) && (string) $cart['selected_customer_tier'] !== ''
                ? (string) $cart['selected_customer_tier']
                : null,
        ];
    }

    /**
     * Fingerprint every input that can change the approved monetary outcome or
     * the fulfilment obligation of one line.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $context
     */
    public function generateFingerprint(array $line, array $context = []): string
    {
        return hash('sha256', http_build_query($this->components($line, $context)));
    }

    /**
     * Fingerprint bound to a specific approval.
     *
     * Binding action type and requested value means a unit-price approval's
     * fingerprint cannot satisfy a row-total execution, and an approval for one
     * amount cannot be reused for another.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $context
     */
    public function generateApprovalFingerprint(
        array $line,
        array $context,
        string $actionType,
        int $requestedValueMinor,
        int $posSessionId,
        int $lineId,
        int $requesterId
    ): string {
        $components = $this->components($line, $context) + [
            'action_type' => strtoupper($actionType),
            'requested_value_minor' => $requestedValueMinor,
            'pos_session_id' => $posSessionId,
            'bound_line_id' => $lineId,
            'requester_id' => $requesterId,
        ];

        return hash('sha256', http_build_query($components));
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $context
     */
    public function fingerprintMatches(array $line, array $context, string $fingerprint): bool
    {
        return hash_equals($this->generateFingerprint($line, $context), $fingerprint);
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $context
     */
    public function approvalFingerprintMatches(
        array $line,
        array $context,
        string $actionType,
        int $requestedValueMinor,
        int $posSessionId,
        int $lineId,
        int $requesterId,
        string $fingerprint
    ): bool {
        return hash_equals(
            $this->generateApprovalFingerprint(
                $line,
                $context,
                $actionType,
                $requestedValueMinor,
                $posSessionId,
                $lineId,
                $requesterId
            ),
            $fingerprint
        );
    }

    /**
     * The canonical line contract.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function components(array $line, array $context): array
    {
        $qty = (int) ($line['qty'] ?? 0);

        return [
            'line_id' => (int) ($line['line_id'] ?? 0),
            'product_id' => (int) ($line['product_id'] ?? 0),
            // The conversion factor is multiplied into qty when a line is
            // built, so conversion identity plus qty pins the effective amount.
            'qty' => $qty,
            'conversion_id' => isset($line['conversion_id']) ? (int) $line['conversion_id'] : 0,
            'conversion_unit_name' => (string) ($line['conversion_unit_name'] ?? ''),
            'unit_price_minor' => (int) round(((float) ($line['unit_price'] ?? 0)) * 100),
            'price_source' => (string) ($line['price_source'] ?? 'BASE'),
            'tax_id' => isset($line['tax_id']) ? (int) $line['tax_id'] : 0,
            'tax_rate_bp' => (int) round(((float) ($line['tax_rate'] ?? 0)) * 10000),
            'discount_type' => strtolower((string) ($line['line_discount_type'] ?? 'fixed')) === 'percentage'
                ? 'percentage'
                : 'fixed',
            'discount_value_minor' => (int) round(((float) ($line['line_discount_value'] ?? 0)) * 100),
            // Fulfilment obligations: a line whose serial or stock treatment
            // changed is not the line that was approved.
            'stock_managed' => ! empty($line['stock_managed']) ? 1 : 0,
            'serial_required' => ! empty($line['serial_number_required']) ? 1 : 0,
            'serials' => $this->canonicalSerials($line),
            'bundle_id' => isset($line['bundle_id']) ? (int) $line['bundle_id'] : 0,
            'bundle_items' => $this->canonicalBundleComponents($line, $qty),
            'customer_id' => (int) ($context['customer_id'] ?? 0),
            'customer_tier' => (string) ($context['customer_tier'] ?? ''),
            'is_pkp' => ! empty($context['is_pkp']) ? 1 : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function canonicalSerials(array $line): string
    {
        $serials = array_map('strval', (array) ($line['assigned_serials'] ?? []));
        sort($serials, SORT_STRING);

        return implode(',', $serials);
    }

    /**
     * Canonicalize bundle components through the shared snapshot mapper so the
     * fingerprint sees the same shape the rest of the system does.
     *
     * @param  array<string, mixed>  $line
     */
    private function canonicalBundleComponents(array $line, int $qty): string
    {
        $rawComponents = $line['bundle_items'] ?? [];

        if (empty($rawComponents) || ! is_array($rawComponents)) {
            return '';
        }

        $mapper = $this->bundleSnapshotMapper ?? app(ProductBundleSnapshotMapper::class);
        $canonical = $mapper->canonicalizeComponents($rawComponents, (float) max(1, $qty));

        $parts = [];

        foreach ($canonical as $item) {
            $parts[] = implode(':', [
                (int) ($item['bundle_item_id'] ?? 0),
                (int) ($item['product_id'] ?? 0),
                (string) round((float) ($item['quantity_per_bundle'] ?? 0), 4),
                (string) round((float) ($item['quantity'] ?? 0), 4),
                (int) round(((float) ($item['informational_item_price'] ?? 0)) * 100),
                ! empty($item['stock_managed']) ? 1 : 0,
                ! empty($item['serial_number_required']) ? 1 : 0,
            ]);
        }

        sort($parts, SORT_STRING);

        return implode(';', $parts);
    }
}
