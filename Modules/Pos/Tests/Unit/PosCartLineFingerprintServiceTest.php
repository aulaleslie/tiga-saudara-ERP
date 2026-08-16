<?php

namespace Modules\Pos\Tests\Unit;

use Modules\Pos\Services\PosCartLineFingerprintService;
use Tests\TestCase;

class PosCartLineFingerprintServiceTest extends TestCase
{
    private PosCartLineFingerprintService $fingerprintService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fingerprintService = new PosCartLineFingerprintService();
    }

    public function test_fingerprint_is_stable_for_identical_line_inputs(): void
    {
        $line1 = [
            'line_id' => 1,
            'product_id' => 10,
            'qty' => 2,
            'unit_price' => 50000.0,
            'conversion_id' => null,
            'bundle_id' => null,
            'tax_id' => 1,
            'tax_rate' => 11.0,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0.0,
            'price_source' => 'BASE',
        ];

        $context = [
            'is_pkp' => true,
            'customer_id' => 5,
            'customer_tier' => 'tier_1',
        ];

        $fp1 = $this->fingerprintService->generateFingerprint($line1, $context);
        $fp2 = $this->fingerprintService->generateFingerprint($line1, $context);

        $this->assertSame($fp1, $fp2);
        $this->assertTrue($this->fingerprintService->fingerprintMatches($line1, $context, $fp1));
    }

    public function test_fingerprint_changes_when_any_pricing_input_drifts(): void
    {
        $baseLine = [
            'line_id' => 1,
            'product_id' => 10,
            'qty' => 2,
            'unit_price' => 50000.0,
            'conversion_id' => null,
            'bundle_id' => null,
            'tax_id' => 1,
            'tax_rate' => 11.0,
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0.0,
            'price_source' => 'BASE',
            'bundle_items' => [],
            'assigned_serials' => ['SN1', 'SN2'],
        ];

        $baseContext = [
            'is_pkp' => true,
            'customer_id' => 5,
            'customer_tier' => 'tier_1',
        ];

        $baseFp = $this->fingerprintService->generateFingerprint($baseLine, $baseContext);

        // Variations
        $variations = [
            'qty changed' => [array_merge($baseLine, ['qty' => 3]), $baseContext],
            'unit_price changed' => [array_merge($baseLine, ['unit_price' => 45000.0]), $baseContext],
            'conversion changed' => [array_merge($baseLine, ['conversion_id' => 2]), $baseContext],
            'bundle changed' => [array_merge($baseLine, ['bundle_id' => 4]), $baseContext],
            'tax changed' => [array_merge($baseLine, ['tax_id' => 2, 'tax_rate' => 12.0]), $baseContext],
            'discount value changed' => [array_merge($baseLine, ['line_discount_value' => 5000.0]), $baseContext],
            'discount type changed' => [array_merge($baseLine, ['line_discount_type' => 'percentage', 'line_discount_value' => 10.0]), $baseContext],
            'price source changed' => [array_merge($baseLine, ['price_source' => 'PACKED']), $baseContext],
            'serial changed' => [array_merge($baseLine, ['assigned_serials' => ['SN1', 'SN3']]), $baseContext],
            'customer changed' => [$baseLine, array_merge($baseContext, ['customer_id' => 6])],
            'customer tier changed' => [$baseLine, array_merge($baseContext, ['customer_tier' => 'tier_2'])],
            'pkp status changed' => [$baseLine, array_merge($baseContext, ['is_pkp' => false])],
            'bundle item composition changed' => [
                array_merge($baseLine, ['bundle_items' => [['bundle_item_id' => 1, 'product_id' => 101, 'quantity_per_bundle' => 1, 'informational_item_price' => 10000, 'stock_managed' => true, 'serial_number_required' => false]]]),
                $baseContext
            ],
            'bundle item quantity_per_bundle changed' => [
                array_merge($baseLine, ['bundle_items' => [['bundle_item_id' => 1, 'product_id' => 101, 'quantity_per_bundle' => 2, 'informational_item_price' => 10000, 'stock_managed' => true, 'serial_number_required' => false]]]),
                $baseContext
            ],
            'bundle item informational_item_price changed' => [
                array_merge($baseLine, ['bundle_items' => [['bundle_item_id' => 1, 'product_id' => 101, 'quantity_per_bundle' => 1, 'informational_item_price' => 15000, 'stock_managed' => true, 'serial_number_required' => false]]]),
                $baseContext
            ],
            'bundle item stock_managed changed' => [
                array_merge($baseLine, ['bundle_items' => [['bundle_item_id' => 1, 'product_id' => 101, 'quantity_per_bundle' => 1, 'informational_item_price' => 10000, 'stock_managed' => false, 'serial_number_required' => false]]]),
                $baseContext
            ],
            'bundle item serial_number_required changed' => [
                array_merge($baseLine, ['bundle_items' => [['bundle_item_id' => 1, 'product_id' => 101, 'quantity_per_bundle' => 1, 'informational_item_price' => 10000, 'stock_managed' => true, 'serial_number_required' => true]]]),
                $baseContext
            ],
        ];

        foreach ($variations as $scenario => [$line, $context]) {
            $mutatedFp = $this->fingerprintService->generateFingerprint($line, $context);
            $this->assertNotSame($baseFp, $mutatedFp, "Fingerprint should drift on {$scenario}");
            $this->assertFalse($this->fingerprintService->fingerprintMatches($line, $context, $baseFp));
        }
    }

    public function test_build_context_derives_canonical_customer_and_pkp_data(): void
    {
        $cart = [
            'selected_customer_id' => 42,
            'selected_customer_tier' => 'VIP',
        ];

        $context = $this->fingerprintService->buildContext(1, $cart);
        $this->assertSame(42, $context['customer_id']);
        $this->assertSame('VIP', $context['customer_tier']);
    }
}
