<?php

namespace Modules\Pos\Tests\Unit;

use Modules\Pos\Services\PosCartTotalsCalculator;
use Tests\TestCase;

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

    public function test_tax_excluded_calculation_uses_discounted_base(): void
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
        $this->assertSame(20.9, $snapshot['totals']['tax_total']);
        $this->assertSame(210.9, $snapshot['totals']['grand_total']);
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
}
