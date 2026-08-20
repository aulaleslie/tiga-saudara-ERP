<?php

namespace Modules\Pos\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Pos\Services\PosCheckoutSplitPlannerService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PosCheckoutSplitPlannerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Setting 1',
            'company_email' => 's1@example.com',
            'company_phone' => '0811',
            'company_address' => 'Addr 1',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 's1@example.com',
            'footer_text' => 'F1',
            'document_prefix' => 'D1',
            'purchase_prefix_document' => 'P1',
            'sale_prefix_document' => 'S1',
            'pos_enabled' => true,
            'is_pkp' => true,
        ]);
        Setting::firstOrCreate(['id' => 2], [
            'company_name' => 'Setting 2',
            'company_email' => 's2@example.com',
            'company_phone' => '0812',
            'company_address' => 'Addr 2',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 's2@example.com',
            'footer_text' => 'F2',
            'document_prefix' => 'D2',
            'purchase_prefix_document' => 'P2',
            'sale_prefix_document' => 'S2',
            'pos_enabled' => true,
            'is_pkp' => false,
        ]);

        Location::firstOrCreate(['id' => 10], ['name' => 'Loc 10', 'setting_id' => 1]);
        Location::firstOrCreate(['id' => 11], ['name' => 'Loc 11', 'setting_id' => 1]);
        Location::firstOrCreate(['id' => 20], ['name' => 'Loc 20', 'setting_id' => 2]);
    }

    public function test_pkp_source_without_explicit_line_tax_uses_fallback_tax_bucket(): void
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
                        'qty' => 1,
                        'unit_price' => 100,
                        'tax_id' => null,
                        'tax_rate' => 0,
                        'line_discount_type' => 'fixed',
                        'line_discount_value' => 0,
                        'line_discount_amount' => 0,
                        'bill_discount_amount' => 0,
                        'line_subtotal' => 100,
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
                        'allocated_qty' => 1,
                        'tax_bucket_used' => false,
                        'tax_policy_snapshot' => [
                            'source_is_pkp' => true,
                            'tax_id' => null,
                            'tax_name' => null,
                            'tax_rate' => 0,
                        ],
                    ],
                ],
            ],
        ]);

        $groups = $plan['groups'];

        $this->assertCount(1, $groups);
        $this->assertSame('1:10:TAX:' . $defaultTax->id, $groups[0]['split_key']);
        $this->assertSame('TAX:' . $defaultTax->id, $groups[0]['tax_bucket']);
        $this->assertSame($defaultTax->id, $groups[0]['lines'][0]['tax_id']);
    }

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

    public function test_quantity_tax_allocation_without_line_tax_uses_fallback_tax_bucket(): void
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
                        'qty' => 1,
                        'unit_price' => 100,
                        'tax_id' => null,
                        'tax_rate' => 0,
                        'line_discount_type' => 'fixed',
                        'line_discount_value' => 0,
                        'line_discount_amount' => 0,
                        'bill_discount_amount' => 0,
                        'line_subtotal' => 100,
                        'serial_number_required' => false,
                        'assigned_serials' => [],
                    ],
                ],
            ],
            'allocations' => [
                [
                    [
                        'source_setting_id' => 2,
                        'source_location_id' => 20,
                        'allocated_qty' => 1,
                        'tax_bucket_used' => true,
                        'tax_policy_snapshot' => [
                            'source_is_pkp' => true,
                            'tax_id' => null,
                            'tax_name' => null,
                            'tax_rate' => 0,
                        ],
                    ],
                ],
            ],
        ]);

        $groups = $plan['groups'];

        $this->assertCount(1, $groups);
        $this->assertSame('2:20:TAX:' . $defaultTax->id, $groups[0]['split_key']);
        $this->assertSame('TAX:' . $defaultTax->id, $groups[0]['tax_bucket']);
        $this->assertSame($defaultTax->id, $groups[0]['lines'][0]['tax_id']);
    }

    public function test_non_pkp_source_allocation_stays_non_tax_even_if_tax_bucket_used_flag_is_set(): void
    {
        // The selling owner's PKP status alone determines customer-tax applicability.
        // A non-PKP source_is_pkp must never become taxable, even if a mis-tagged
        // allocation carries tax_bucket_used=true.
        Tax::query()->create([
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
                        'qty' => 1,
                        'unit_price' => 100,
                        'tax_id' => null,
                        'tax_rate' => 0,
                        'line_discount_type' => 'fixed',
                        'line_discount_value' => 0,
                        'line_discount_amount' => 0,
                        'bill_discount_amount' => 0,
                        'line_subtotal' => 100,
                        'serial_number_required' => false,
                        'assigned_serials' => [],
                    ],
                ],
            ],
            'allocations' => [
                [
                    [
                        'source_setting_id' => 2,
                        'source_location_id' => 20,
                        'allocated_qty' => 1,
                        'tax_bucket_used' => true,
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

        $this->assertCount(1, $groups);
        $this->assertSame('2:20:NON_TAX', $groups[0]['split_key']);
        $this->assertSame('NON_TAX', $groups[0]['tax_bucket']);
        $this->assertNull($groups[0]['lines'][0]['tax_id']);
    }

    public function test_non_pkp_allocation_without_quantity_tax_remains_non_tax(): void
    {
        Tax::query()->create([
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
                        'qty' => 1,
                        'unit_price' => 100,
                        'tax_id' => 999,
                        'tax_rate' => 11,
                        'line_discount_type' => 'fixed',
                        'line_discount_value' => 0,
                        'line_discount_amount' => 0,
                        'bill_discount_amount' => 0,
                        'line_subtotal' => 100,
                        'serial_number_required' => false,
                        'assigned_serials' => [],
                    ],
                ],
            ],
            'allocations' => [
                [
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

        $this->assertCount(1, $groups);
        $this->assertSame('2:20:NON_TAX', $groups[0]['split_key']);
        $this->assertSame('NON_TAX', $groups[0]['tax_bucket']);
        $this->assertNull($groups[0]['lines'][0]['tax_id']);
    }

    public function test_tax_required_allocation_throws_actionable_error_when_no_fallback_tax_exists(): void
    {
        Tax::query()->delete();
        $planner = new PosCheckoutSplitPlannerService();

        try {
            $planner->plan([
                'setting_id' => 1,
                'cart_snapshot' => [
                    'lines' => [
                        [
                            'line_id' => 1,
                            'product_id' => 99,
                            'product_name' => 'Split Product',
                            'product_code' => 'SP-001',
                            'qty' => 1,
                            'unit_price' => 100,
                            'tax_id' => null,
                            'tax_rate' => 0,
                            'line_discount_type' => 'fixed',
                            'line_discount_value' => 0,
                            'line_discount_amount' => 0,
                            'bill_discount_amount' => 0,
                            'line_subtotal' => 100,
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
                            'allocated_qty' => 1,
                            'tax_bucket_used' => true,
                            'tax_policy_snapshot' => [
                                'source_is_pkp' => true,
                                'tax_id' => null,
                                'tax_name' => null,
                                'tax_rate' => 0,
                            ],
                        ],
                    ],
                ],
            ]);

            $this->fail('Expected planner to throw when taxable allocation has no fallback tax.');
        } catch (PosCheckoutValidationException $exception) {
            $this->assertSame('TAX_POLICY_UNRESOLVED', $exception->errorCode());
            $this->assertStringContainsString('fallback tax', strtolower($exception->getMessage()));
        }
    }

    public function test_plan_uses_serial_tax_context_when_line_tax_is_null(): void
    {
        $currency = Currency::query()->create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::query()->create([
            'company_name' => 'Split Serial PKP',
            'company_email' => 'split-serial@example.com',
            'company_phone' => '0800',
            'company_address' => 'Address',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify-split-serial@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
            'is_pkp' => true,
        ]);

        $location = Location::query()->create([
            'name' => 'Split Serial Location',
            'setting_id' => $setting->id,
        ]);

        $tax = Tax::query()->create([
            'name' => 'PPN SERIAL 11',
            'value' => 11,
            'is_default' => true,
        ]);

        $user = User::factory()->create();
        $category = Category::query()->create([
            'category_code' => 'SERIAL-SPLIT-CAT',
            'category_name' => 'Serial Split Category',
            'setting_id' => $setting->id,
            'created_by' => $user->id,
        ]);
        $unit = Unit::query()->firstOrCreate([
            'name' => 'SERIAL SPLIT UNIT',
            'short_name' => 'SSU',
        ]);
        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Serial Split Product',
            'product_code' => 'SER-SPLIT-001',
            'barcode' => 'SER-SPLIT-BAR-001',
            'product_quantity' => 1,
            'product_cost' => 100,
            'product_price' => 100,
            'product_unit' => 'SSU',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => true,
        ]);

        ProductSerialNumber::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => 'SPLIT-SN-001',
            'tax_id' => $tax->id,
            'status' => 'ACTIVE',
        ]);

        $planner = new PosCheckoutSplitPlannerService();
        $plan = $planner->plan([
            'setting_id' => $setting->id,
            'cart_snapshot' => [
                'lines' => [
                    [
                        'line_id' => 1,
                        'product_id' => $product->id,
                        'product_name' => 'Serial Product',
                        'product_code' => 'SER-321',
                        'qty' => 1,
                        'unit_price' => 100,
                        'tax_id' => null,
                        'tax_rate' => 0,
                        'line_discount_type' => 'fixed',
                        'line_discount_value' => 0,
                        'line_discount_amount' => 0,
                        'bill_discount_amount' => 0,
                        'line_subtotal' => 100,
                        'serial_number_required' => true,
                        'assigned_serials' => ['SPLIT-SN-001'],
                    ],
                ],
            ],
            'allocations' => [],
        ]);

        $groups = $plan['groups'];
        $this->assertCount(1, $groups);
        $this->assertSame($setting->id . ':' . $location->id . ':TAX:' . $tax->id, $groups[0]['split_key']);
        $this->assertSame('TAX:' . $tax->id, $groups[0]['tax_bucket']);
        $this->assertSame($tax->id, $groups[0]['lines'][0]['tax_id']);
    }

    public function test_bundle_decomposition_throws_exception_on_negative_residual(): void
    {
        Tax::firstOrCreate(['name' => 'PPN 11'], ['value' => 11, 'is_default' => true]);

        $this->expectException(PosCheckoutValidationException::class);
        $this->expectExceptionMessage("Harga paket 'Super Bundle' tidak mencukupi untuk menutupi alokasi komponen.");

        $planner = new PosCheckoutSplitPlannerService();
        $planner->plan([
            'setting_id' => 1,
            'cart_snapshot' => [
                'lines' => [
                    [
                        'line_id' => 1,
                        'product_id' => 10,
                        'product_name' => 'Super Bundle',
                        'product_code' => 'BND-01',
                        'qty' => 1,
                        'unit_price' => 100, // bundle price 100
                        'line_subtotal' => 100,
                        'bundle_id' => 5,
                        'bundle_items' => [
                            [
                                'product_id' => 20,
                                'quantity' => 1,
                                'informational_item_price' => 120, // 120 > 100 -> negative residual
                                'stock_managed' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'allocations' => [
                '0_P' => [
                    [
                        'source_setting_id' => 1,
                        'source_location_id' => 10,
                        'allocated_qty' => 1,
                    ],
                ],
                '0_C_0' => [
                    [
                        'source_setting_id' => 1,
                        'source_location_id' => 10,
                        'allocated_qty' => 1,
                    ],
                ],
            ],
        ]);
    }

    public function test_bundle_uses_informational_item_price_without_falling_back_to_live_price(): void
    {
        Tax::firstOrCreate(['name' => 'PPN 11'], ['value' => 11, 'is_default' => true]);

        $planner = new PosCheckoutSplitPlannerService();
        $plan = $planner->plan([
            'setting_id' => 1,
            'cart_snapshot' => [
                'lines' => [
                    [
                        'line_id' => 1,
                        'product_id' => 10,
                        'product_name' => 'Super Bundle',
                        'product_code' => 'BND-01',
                        'qty' => 2,
                        'unit_price' => 500, // total subtotal = 1000
                        'line_subtotal' => 1000,
                        'bundle_id' => 5,
                        'bundle_items' => [
                            [
                                'product_id' => 20,
                                'quantity' => 1,
                                'informational_item_price' => 100, // total comp alloc = 2 * 100 = 200
                                'stock_managed' => true,
                            ],
                            [
                                'product_id' => 30,
                                'quantity' => 2,
                                'informational_item_price' => 0, // 0 informational price -> 0 allocation, no fallback!
                                'stock_managed' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'allocations' => [
                '0_P' => [
                    [
                        'source_setting_id' => 1,
                        'source_location_id' => 10,
                        'allocated_qty' => 2,
                    ],
                ],
                '0_C_0' => [
                    [
                        'source_setting_id' => 2,
                        'source_location_id' => 20,
                        'allocated_qty' => 2,
                    ],
                ],
                '0_C_1' => [
                    [
                        'source_setting_id' => 2,
                        'source_location_id' => 20,
                        'allocated_qty' => 4,
                    ],
                ],
            ],
        ]);

        $groups = $plan['groups'];
        // Group for setting 1 (Parent residual): 1000 - 200 - 0 = 800
        // Group for setting 2 (Child allocs): 200 + 0 = 200
        $this->assertCount(2, $groups);

        $parentGroup = collect($groups)->firstWhere('source_setting_id', 1);
        $childGroup = collect($groups)->firstWhere('source_setting_id', 2);

        $this->assertNotNull($parentGroup);
        $this->assertNotNull($childGroup);

        $this->assertEquals(800.0, (float) $parentGroup['grand_total']);
        $this->assertEquals(200.0, (float) $childGroup['grand_total']);
    }

    public function test_bundle_minor_unit_rounding_allocates_remainders_deterministically(): void
    {
        Tax::firstOrCreate(['name' => 'PPN 11'], ['value' => 11, 'is_default' => true]);

        $service = new PosCheckoutSplitPlannerService();

        // 3 units of parent split across 2 sources with uneven quantities (2 and 1)
        // Parent subtotal = 1000.01 (100,001 minor units)
        // Component allocation = 3 * 1 * 100.00 = 300.00 (30,000 minor units)
        // Parent residual = 700.01 (70,001 minor units)
        // Chunk 1 (qty 2 of 3): intdiv(70,001 * 2, 3) = 46,667 minor (rem 1)
        // Chunk 2 (qty 1 of 3): intdiv(70,001 * 1, 3) = 23,333 minor (rem 2)
        // Largest remainder is Chunk 2 (rem 2 > rem 1) => gets +1 minor unit: 23,334 minor (233.34)
        // Chunk 1 gets 46,667 minor (466.67)
        // Sum = 466.67 + 233.34 = 700.01 exactly!
        $plan = $service->plan([
            'setting_id' => 1,
            'source_location_ids' => [10, 11, 20],
            'cart_snapshot' => [
                'lines' => [
                    [
                        'line_id' => 1,
                        'product_id' => 10,
                        'product_name' => 'Bundle Parent',
                        'qty' => 3,
                        'unit_price' => 333.3366,
                        'line_subtotal' => 1000.01,
                        'line_discount_amount' => 0,
                        'tax_id' => null,
                        'stock_managed' => true,
                        'bundle_id' => 1,
                        'bundle_items' => [
                            [
                                'product_id' => 20,
                                'quantity' => 1,
                                'informational_item_price' => 100.00,
                                'stock_managed' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'allocations' => [
                '0_P' => [
                    [
                        'source_setting_id' => 1,
                        'source_location_id' => 10,
                        'allocated_qty' => 2,
                    ],
                    [
                        'source_setting_id' => 1,
                        'source_location_id' => 11,
                        'allocated_qty' => 1,
                    ],
                ],
                '0_C_0' => [
                    [
                        'source_setting_id' => 2,
                        'source_location_id' => 20,
                        'allocated_qty' => 3,
                    ],
                ],
            ],
        ]);

        $groups = $plan['groups'];
        $this->assertCount(3, $groups); // Loc 10, Loc 11, Loc 20

        $group10 = collect($groups)->firstWhere('source_location_id', 10);
        $group11 = collect($groups)->firstWhere('source_location_id', 11);
        $group20 = collect($groups)->firstWhere('source_location_id', 20);

        $this->assertNotNull($group10);
        $this->assertNotNull($group11);
        $this->assertNotNull($group20);

        // Parent residual shares
        $this->assertEquals(466.67, (float) $group10['grand_total']);
        $this->assertEquals(233.34, (float) $group11['grand_total']);
        // Component allocation
        $this->assertEquals(300.00, (float) $group20['grand_total']);

        // Aggregate reconciles exactly to 1000.01 with 0 minor unit loss
        $aggregateTotal = (float) $group10['grand_total'] + (float) $group11['grand_total'] + (float) $group20['grand_total'];
        $this->assertEquals(1000.01, round($aggregateTotal, 2));
    }

    public function test_rejects_allocation_when_source_setting_id_disagrees_with_location_setting(): void
    {
        $setting1 = Setting::find(1);
        $setting2 = Setting::find(2);

        // Location 50 belongs to Setting 2
        $loc = Location::create([
            'id' => 50,
            'name' => 'Loc 50',
            'setting_id' => $setting2->id,
        ]);

        $planner = new PosCheckoutSplitPlannerService();

        $this->expectException(PosCheckoutValidationException::class);
        $this->expectExceptionMessage('Ketidakcocokan kepemilikan');

        // Allocation falsely claims Setting 1 for Location 50 (which belongs to Setting 2)
        $planner->plan([
            'setting_id' => $setting1->id,
            'cart_snapshot' => [
                'lines' => [
                    [
                        'line_id' => 1,
                        'product_id' => 10,
                        'product_name' => 'Product Test',
                        'qty' => 1,
                        'unit_price' => 1000,
                        'line_subtotal' => 1000,
                        'stock_managed' => true,
                        'serial_number_required' => false,
                        'assigned_serials' => [],
                    ],
                ],
            ],
            'allocations' => [
                0 => [
                    [
                        'source_setting_id' => $setting1->id, // Mismatch!
                        'source_location_id' => $loc->id,
                        'allocated_qty' => 1,
                    ],
                ],
            ],
        ]);
    }

    public function test_rejects_stock_managed_bundle_component_with_missing_allocations(): void
    {
        $setting = Setting::create([
            'company_name' => 'Setting B',
            'company_email' => 'sb@example.com',
            'company_phone' => '0811',
            'company_address' => 'Addr B',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'sb@example.com',
            'footer_text' => 'FB',
            'document_prefix' => 'DB',
            'purchase_prefix_document' => 'PB',
            'sale_prefix_document' => 'SB',
            'pos_enabled' => true,
        ]);

        $loc = Location::create([
            'id' => 60,
            'name' => 'Loc 60',
            'setting_id' => $setting->id,
        ]);

        $planner = new PosCheckoutSplitPlannerService();

        $this->expectException(PosCheckoutValidationException::class);
        $this->expectExceptionMessage('Alokasi stok tidak ditemukan untuk komponen paket');

        $planner->plan([
            'setting_id' => $setting->id,
            'cart_snapshot' => [
                'lines' => [
                    [
                        'line_id' => 1,
                        'product_id' => 10,
                        'product_name' => 'Bundle Parent',
                        'qty' => 1,
                        'unit_price' => 1000,
                        'line_subtotal' => 1000,
                        'bundle_id' => 5,
                        'bundle_items' => [
                            [
                                'product_id' => 20,
                                'product_name' => 'Stock-Managed Component',
                                'quantity' => 1,
                                'stock_managed' => true,
                                'informational_item_price' => 200,
                            ],
                        ],
                        'stock_managed' => true,
                        'serial_number_required' => false,
                        'assigned_serials' => [],
                    ],
                ],
            ],
            'allocations' => [
                '0_P' => [
                    [
                        'source_setting_id' => $setting->id,
                        'source_location_id' => $loc->id,
                        'allocated_qty' => 1,
                    ],
                ],
                // Missing '0_C_0' allocation
            ],
        ]);
    }

    public function test_rejects_allocation_when_source_location_does_not_exist(): void
    {
        $planner = new PosCheckoutSplitPlannerService();

        $this->expectException(PosCheckoutValidationException::class);
        $this->expectExceptionMessage('Lokasi sumber #9999 tidak ditemukan');

        // Allocation with non-existent location ID 9999
        $planner->plan([
            'setting_id' => 1,
            'cart_snapshot' => [
                'lines' => [
                    [
                        'line_id' => 1,
                        'product_id' => 10,
                        'product_name' => 'Product Test',
                        'qty' => 1,
                        'unit_price' => 1000,
                        'line_subtotal' => 1000,
                        'stock_managed' => true,
                        'serial_number_required' => false,
                        'assigned_serials' => [],
                    ],
                ],
            ],
            'allocations' => [
                0 => [
                    [
                        'source_setting_id' => 1,
                        'source_location_id' => 9999, // Nonexistent location
                        'allocated_qty' => 1,
                    ],
                ],
            ],
        ]);
    }
}
