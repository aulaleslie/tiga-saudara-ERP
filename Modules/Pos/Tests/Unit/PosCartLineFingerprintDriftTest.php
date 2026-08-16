<?php

namespace Modules\Pos\Tests\Unit;

use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Services\PosCartLineFingerprintService;
use Tests\TestCase;

/**
 * Drift detection for the canonical line fingerprint.
 *
 * The line shape mirrors what PosCartService actually builds, including the
 * real bundle component shape, so these tests fail if the fingerprint stops
 * covering a field that can change the approved monetary outcome or the
 * fulfilment obligation.
 */
class PosCartLineFingerprintDriftTest extends TestCase
{
    private PosCartLineFingerprintService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PosCartLineFingerprintService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'line_id' => 7,
            'product_id' => 42,
            'product_name' => 'PRODUK UJI',
            'stock_managed' => true,
            'serial_number_required' => false,
            'assigned_serials' => [],
            'qty' => 3,
            'unit_price' => 15000.0,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0.0,
            'tax_id' => 1,
            'tax_rate' => 11.0,
            'price_source' => 'BASE',
            'conversion_id' => null,
            'conversion_unit_name' => null,
            'bundle_id' => null,
            'bundle_items' => [],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(array $overrides = []): array
    {
        return array_merge([
            'is_pkp' => true,
            'customer_id' => 5,
            'customer_tier' => 'TIER_1',
        ], $overrides);
    }

    private function fingerprintOf(array $lineOverrides = [], array $contextOverrides = []): string
    {
        return $this->service->generateFingerprint(
            $this->line($lineOverrides),
            $this->context($contextOverrides)
        );
    }

    public function test_identical_lines_fingerprint_identically(): void
    {
        $this->assertSame($this->fingerprintOf(), $this->fingerprintOf());
    }

    public function test_serial_order_does_not_change_the_fingerprint(): void
    {
        // Serials are a set, not a sequence; only membership matters.
        $ascending = $this->fingerprintOf(['assigned_serials' => ['SN-1', 'SN-2', 'SN-3']]);
        $shuffled = $this->fingerprintOf(['assigned_serials' => ['SN-3', 'SN-1', 'SN-2']]);

        $this->assertSame($ascending, $shuffled);
    }

    /**
     * @dataProvider driftingLineFields
     */
    public function test_line_field_drift_changes_the_fingerprint(string $label, array $overrides): void
    {
        $this->assertNotSame(
            $this->fingerprintOf(),
            $this->fingerprintOf($overrides),
            "Fingerprint ignored drift in {$label}."
        );
    }

    public static function driftingLineFields(): array
    {
        return [
            'quantity' => ['quantity', ['qty' => 4]],
            'unit price' => ['unit price', ['unit_price' => 15001.0]],
            'price source' => ['price source', ['price_source' => 'LINE_TOTAL_OVERRIDE']],
            'product identity' => ['product identity', ['product_id' => 43]],
            'line identity' => ['line identity', ['line_id' => 8]],
            'conversion identity' => ['conversion identity', ['conversion_id' => 2]],
            'conversion unit name' => ['conversion unit name', ['conversion_unit_name' => 'BOX']],
            'tax id' => ['tax id', ['tax_id' => 2]],
            'tax rate' => ['tax rate', ['tax_rate' => 12.0]],
            'discount type' => ['discount type', ['line_discount_type' => 'percentage']],
            'discount value' => ['discount value', ['line_discount_value' => 500.0]],
            'stock managed flag' => ['stock managed flag', ['stock_managed' => false]],
            'serial requirement' => ['serial requirement', ['serial_number_required' => true]],
            'assigned serials' => ['assigned serials', ['assigned_serials' => ['SN-9']]],
            'bundle identity' => ['bundle identity', ['bundle_id' => 3]],
        ];
    }

    /**
     * @dataProvider driftingContextFields
     */
    public function test_context_drift_changes_the_fingerprint(string $label, array $overrides): void
    {
        $this->assertNotSame(
            $this->fingerprintOf(),
            $this->fingerprintOf([], $overrides),
            "Fingerprint ignored drift in {$label}."
        );
    }

    public static function driftingContextFields(): array
    {
        return [
            'customer' => ['customer', ['customer_id' => 6]],
            'customer tier' => ['customer tier', ['customer_tier' => 'TIER_2']],
            'pkp status' => ['pkp status', ['is_pkp' => false]],
        ];
    }

    // ------------------------------------------------------ bundle drift

    /**
     * The real bundle component shape produced by PosCartService.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bundleItems(array $overrides = []): array
    {
        return [
            array_merge([
                'bundle_item_id' => 11,
                'product_id' => 101,
                'product_name' => 'KOMPONEN A',
                'quantity_per_bundle' => 2.0,
                'quantity' => 2.0,
                'stock_managed' => true,
                'serial_number_required' => false,
                'informational_item_price' => 5000.0,
            ], $overrides),
            [
                'bundle_item_id' => 12,
                'product_id' => 102,
                'product_name' => 'KOMPONEN B',
                'quantity_per_bundle' => 1.0,
                'quantity' => 1.0,
                'stock_managed' => false,
                'serial_number_required' => true,
                'informational_item_price' => 2500.0,
            ],
        ];
    }

    private function bundleFingerprint(array $componentOverrides = []): string
    {
        return $this->fingerprintOf([
            'bundle_id' => 9,
            'bundle_items' => $this->bundleItems($componentOverrides),
        ]);
    }

    public function test_identical_bundles_fingerprint_identically(): void
    {
        $this->assertSame($this->bundleFingerprint(), $this->bundleFingerprint());
    }

    public function test_bundle_component_order_does_not_change_the_fingerprint(): void
    {
        $forward = $this->bundleFingerprint();

        $reversed = $this->fingerprintOf([
            'bundle_id' => 9,
            'bundle_items' => array_reverse($this->bundleItems()),
        ]);

        $this->assertSame($forward, $reversed);
    }

    /**
     * @dataProvider driftingBundleComponentFields
     */
    public function test_bundle_component_drift_changes_the_fingerprint(string $label, array $overrides): void
    {
        $this->assertNotSame(
            $this->bundleFingerprint(),
            $this->bundleFingerprint($overrides),
            "Fingerprint ignored bundle component drift in {$label}."
        );
    }

    public static function driftingBundleComponentFields(): array
    {
        return [
            'bundle item id' => ['bundle item id', ['bundle_item_id' => 99]],
            'component product' => ['component product', ['product_id' => 999]],
            'quantity per bundle' => ['quantity per bundle', ['quantity_per_bundle' => 3.0]],
            'informational price' => ['informational price', ['informational_item_price' => 6000.0]],
            'stock managed classification' => ['stock managed classification', ['stock_managed' => false]],
            'serial required classification' => ['serial required classification', ['serial_number_required' => true]],
        ];
    }

    public function test_removing_a_bundle_component_changes_the_fingerprint(): void
    {
        $full = $this->bundleFingerprint();

        $partial = $this->fingerprintOf([
            'bundle_id' => 9,
            'bundle_items' => [$this->bundleItems()[0]],
        ]);

        $this->assertNotSame($full, $partial);
    }

    // ------------------------------------------------- approval binding

    public function test_approval_fingerprint_binds_the_action_type(): void
    {
        $line = $this->line();
        $context = $this->context();

        $unitPriceBound = $this->service->generateApprovalFingerprint(
            $line,
            $context,
            PosActionApprovalRequest::ACTION_LINE_UNIT_PRICE_OVERRIDE,
            1_000_000,
            3,
            7,
            11
        );

        $rowTotalBound = $this->service->generateApprovalFingerprint(
            $line,
            $context,
            PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
            1_000_000,
            3,
            7,
            11
        );

        $this->assertNotSame(
            $unitPriceBound,
            $rowTotalBound,
            'A unit-price approval fingerprint must not satisfy a row-total execution.'
        );
    }

    /**
     * @dataProvider driftingApprovalBindings
     */
    public function test_approval_fingerprint_binds_each_dimension(string $label, array $args): void
    {
        $baseline = $this->service->generateApprovalFingerprint(
            $this->line(),
            $this->context(),
            PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
            1_000_000,
            3,
            7,
            11
        );

        $drifted = $this->service->generateApprovalFingerprint(
            $this->line(),
            $this->context(),
            $args['action'] ?? PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
            $args['value'] ?? 1_000_000,
            $args['session'] ?? 3,
            $args['line'] ?? 7,
            $args['requester'] ?? 11
        );

        $this->assertNotSame($baseline, $drifted, "Approval fingerprint ignored {$label}.");
    }

    public static function driftingApprovalBindings(): array
    {
        return [
            'requested value' => ['requested value', ['value' => 1_000_001]],
            'pos session' => ['pos session', ['session' => 4]],
            'bound line' => ['bound line', ['line' => 8]],
            'requester' => ['requester', ['requester' => 12]],
        ];
    }

    public function test_approval_fingerprint_matcher_confirms_an_unchanged_line(): void
    {
        $this->assertTrue(
            $this->service->approvalFingerprintMatches(
                $this->line(),
                $this->context(),
                PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
                1_000_000,
                3,
                7,
                11,
                $this->service->generateApprovalFingerprint(
                    $this->line(),
                    $this->context(),
                    PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
                    1_000_000,
                    3,
                    7,
                    11
                )
            )
        );
    }

    public function test_approval_fingerprint_matcher_rejects_a_drifted_line(): void
    {
        $issued = $this->service->generateApprovalFingerprint(
            $this->line(),
            $this->context(),
            PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
            1_000_000,
            3,
            7,
            11
        );

        $this->assertFalse(
            $this->service->approvalFingerprintMatches(
                $this->line(['qty' => 4]),
                $this->context(),
                PosActionApprovalRequest::ACTION_LINE_TOTAL_OVERRIDE,
                1_000_000,
                3,
                7,
                11,
                $issued
            )
        );
    }
}
