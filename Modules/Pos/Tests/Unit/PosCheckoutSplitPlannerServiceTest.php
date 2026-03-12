<?php

namespace Modules\Pos\Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
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
}
