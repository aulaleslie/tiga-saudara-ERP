<?php

namespace Modules\Pos\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Services\PosCheckoutSplitPlannerService;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class PosCheckoutSplitPlannerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_groups_by_source_and_tax_bucket_with_tax_fallback(): void
    {
        $defaultTax = Tax::query()->create([
            'name' => 'PPN 11',
            'value' => 11,
            'is_default' => true,
        ]);

        $planner = new PosCheckoutSplitPlannerService();
        $plan = $planner->plan([
            'setting_id' => 1,
            'cart_snapshot' => [
                'lines' => [
                    [
                        'line_id' => 1,
                        'product_id' => 99,
                        'product_name' => 'Split Product',
                        'product_code' => 'SP-001',
                        'qty' => 3,
                        'unit_price' => 100,
                        'tax_id' => 999,
                        'tax_rate' => 11,
                        'line_discount_type' => 'fixed',
                        'line_discount_value' => 0,
                        'line_discount_amount' => 30,
                        'bill_discount_amount' => 0,
                        'line_subtotal' => 270,
                        'serial_number_required' => false,
                        'assigned_serials' => [],
                    ],
                ],
            ],
            'allocations' => [
                [
                    [
                        'source_setting_id' => 1,
                        'source_location_id' => 10,
                        'allocated_qty' => 2,
                        'tax_bucket_used' => true,
                        'tax_policy_snapshot' => [
                            'source_is_pkp' => true,
                            'tax_id' => null,
                            'tax_name' => null,
                            'tax_rate' => 0,
                        ],
                    ],
                    [
                        'source_setting_id' => 2,
                        'source_location_id' => 20,
                        'allocated_qty' => 1,
                        'tax_bucket_used' => false,
                        'tax_policy_snapshot' => [
                            'source_is_pkp' => false,
                            'tax_id' => null,
                            'tax_name' => null,
                            'tax_rate' => 0,
                        ],
                    ],
                ],
            ],
        ]);

        $groups = $plan['groups'];

        $this->assertCount(2, $groups);
        $this->assertSame('1:10:TAX:' . $defaultTax->id, $groups[0]['split_key']);
        $this->assertSame('2:20:NON_TAX', $groups[1]['split_key']);

        $this->assertSame(180.0, $groups[0]['grand_total']);
        $this->assertSame(90.0, $groups[1]['grand_total']);
        $this->assertSame(20.0, $groups[0]['discount_total']);
        $this->assertSame(10.0, $groups[1]['discount_total']);
        $this->assertSame(18.0, $groups[0]['tax_total']);
        $this->assertSame(0.0, $groups[1]['tax_total']);
    }
}
