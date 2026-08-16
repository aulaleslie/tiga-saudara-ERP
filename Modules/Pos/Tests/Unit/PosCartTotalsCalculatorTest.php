<?php

namespace Modules\Pos\Tests\Unit;

use Modules\Pos\Services\PosCartTotalsCalculator;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class PosCartTotalsCalculatorTest extends TestCase
{
    public function test_line_discount_percentage_and_fixed_are_capped(): void
    {
        $calculator = new PosCartTotalsCalculator();

        $snapshot = $calculator->calculate(
            lines: [
                [
                    'line_id' => 21,
                    'qty' => 1,
                    'unit_price' => 100.00,
                    'line_discount_type' => 'fixed',
                    'line_discount_value' => 150.00,
                    'tax_rate' => 0,
                    'tax_id' => null,
                ],
                [
                    'line_id' => 22,
                    'qty' => 1,
                    'unit_price' => 100.00,
                    'line_discount_type' => 'percentage',
                    'line_discount_value' => 150.00,
                    'tax_rate' => 0,
                    'tax_id' => null,
                ],
            ],
            billDiscount: [
                'type' => 'fixed',
                'value' => 0,
            ],
            isPkp: true
        );

        $this->assertSame(200.0, $snapshot['totals']['discount_total']);
        $this->assertSame(0.0, $snapshot['totals']['subtotal']);
        $this->assertSame(0.0, $snapshot['totals']['grand_total']);
        $this->assertSame(100.0, $snapshot['lines'][0]['line_discount_amount']);
        $this->assertSame(100.0, $snapshot['lines'][1]['line_discount_amount']);
    }

    public function test_bill_discount_proration_uses_deterministic_remainder_distribution(): void
    {
        $calculator = new PosCartTotalsCalculator();

        $snapshot = $calculator->calculate(
            lines: [
                [
                    'line_id' => 1,
                    'qty' => 1,
                    'unit_price' => 100.00,
                    'line_discount_type' => 'fixed',
                    'line_discount_value' => 0,
                    'tax_rate' => 0,
                    'tax_id' => null,
                ],
                [
                    'line_id' => 2,
                    'qty' => 1,
                    'unit_price' => 100.00,
                    'line_discount_type' => 'fixed',
                    'line_discount_value' => 0,
                    'tax_rate' => 0,
                    'tax_id' => null,
                ],
            ],
            billDiscount: [
                'type' => 'fixed',
                'value' => 0.01,
            ],
            isPkp: true
        );

        $this->assertSame(0.01, $snapshot['lines'][0]['bill_discount_amount']);
        $this->assertSame(0.0, $snapshot['lines'][1]['bill_discount_amount']);
        $this->assertSame(0.01, $snapshot['totals']['bill_discount_total']);
        $this->assertSame(199.99, $snapshot['totals']['subtotal']);
    }

    public function test_tax_included_extraction_uses_discounted_gross_base(): void
    {
        $calculator = new PosCartTotalsCalculator();

        $snapshot = $calculator->calculate(
            lines: [
                [
                    'line_id' => 7,
                    'qty' => 2,
                    'unit_price' => 100.00,
                    'line_discount_type' => 'fixed',
                    'line_discount_value' => 10.00,
                    'tax_rate' => 11,
                    'tax_id' => 1,
                ],
            ],
            billDiscount: [
                'type' => 'fixed',
                'value' => 0,
            ],
            isPkp: true
        );

        $this->assertSame(190.0, $snapshot['totals']['subtotal']);
        $this->assertSame(19.0, $snapshot['totals']['tax_total']);
        $this->assertSame(190.0, $snapshot['totals']['grand_total']);
        $this->assertSame($snapshot['totals']['subtotal'], $snapshot['totals']['grand_total']);
    }

    public function test_result_is_stable_regardless_of_input_line_order(): void
    {
        $calculator = new PosCartTotalsCalculator();

        $lines = [
            [
                'line_id' => 9,
                'qty' => 1,
                'unit_price' => 135.00,
                'line_discount_type' => 'fixed',
                'line_discount_value' => 10.00,
                'tax_rate' => 11,
                'tax_id' => 1,
            ],
            [
                'line_id' => 3,
                'qty' => 2,
                'unit_price' => 75.00,
                'line_discount_type' => 'percentage',
                'line_discount_value' => 10.00,
                'tax_rate' => 11,
                'tax_id' => 1,
            ],
        ];

        $forward = $calculator->calculate(
            lines: $lines,
            billDiscount: ['type' => 'fixed', 'value' => 10.00],
            isPkp: true
        );

        $reverse = $calculator->calculate(
            lines: array_reverse($lines),
            billDiscount: ['type' => 'fixed', 'value' => 10.00],
            isPkp: true
        );

        $this->assertSame($forward['totals'], $reverse['totals']);
        $this->assertSame($forward['lines'], $reverse['lines']);
    }

    public function test_authoritative_line_total_is_used_when_present(): void
    {
        $calculator = new PosCartTotalsCalculator();

        $snapshot = $calculator->calculate(
            lines: [
                [
                    'line_id' => 1,
                    'qty' => 3,
                    'unit_price' => 33333.33,
                    'price_source' => 'LINE_TOTAL_OVERRIDE',
                    'line_total' => 10000000, // 100,000.00 minor units
                    'line_discount_type' => 'fixed',
                    'line_discount_value' => 0,
                    'tax_rate' => 0,
                    'tax_id' => null,
                ],
            ],
            billDiscount: [
                'type' => 'fixed',
                'value' => 0,
            ],
            isPkp: true
        );

        $this->assertSame(100000.0, $snapshot['lines'][0]['line_gross']);
        $this->assertSame(100000.0, $snapshot['lines'][0]['line_subtotal']);
        $this->assertSame(100000.0, $snapshot['totals']['subtotal']);
        $this->assertSame(100000.0, $snapshot['totals']['grand_total']);
    }

    public function test_line_total_override_percentage_discount_reversal_arithmetic(): void
    {
        $calculator = new PosCartTotalsCalculator();

        // 10,000 minor units requested with 10% discount:
        // gross = 10000 / (1 - 0.1) = 11111 minor units (111.11)
        // discount = 11111 - 10000 = 1111 minor units (11.11)
        // line_total = 10000 minor units (100.00)
        $snapshot = $calculator->calculate(
            lines: [
                [
                    'line_id' => 1,
                    'qty' => 1,
                    'unit_price' => 100.0,
                    'price_source' => 'LINE_TOTAL_OVERRIDE',
                    'line_total' => 1000000, // 10,000.00 in minor units
                    'line_discount_type' => 'percentage',
                    'line_discount_value' => 10.0,
                    'tax_rate' => 0,
                    'tax_id' => null,
                ],
            ],
            billDiscount: ['type' => 'fixed', 'value' => 0],
            isPkp: false
        );

        $this->assertSame(10000.0, $snapshot['lines'][0]['line_total']);
        $this->assertSame(10000.0, $snapshot['lines'][0]['line_subtotal']);
        $this->assertSame(11111.11, $snapshot['lines'][0]['line_gross']);
        $this->assertSame(1111.11, $snapshot['lines'][0]['line_discount_amount']);
        $this->assertSame(10000.0, $snapshot['totals']['grand_total']);
    }
}
