<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Tax;
use Modules\Currency\Entities\Currency;
use App\Support\SalesReturn\SaleReturnEligibilityService;
use Tests\TestCase;

class SaleStandaloneBundleRowTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected Category $category;
    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(function () {
            return true;
        });

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Test Co',
            'company_email' => 'company@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        Session::put('setting_id', $this->setting->id);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '0800000000',
            'setting_id' => $this->setting->id,
        ]);

        $user = \App\Models\User::factory()->create();

        $this->category = Category::create([
            'category_code' => 'CAT001',
            'category_name' => 'Category',
            'setting_id' => $this->setting->id,
            'created_by' => $user->id,
        ]);
    }

    /** @test */
    public function it_resolves_tax_context_from_standalone_bundle_item_when_parent_is_missing()
    {
        $tax = Tax::create(['name' => 'VAT 11%', 'value' => 11]);
        $sale = Sale::create([
            'date' => now(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1110,
            'paid_amount' => 0,
            'due_amount' => 1110,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);
        
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Standalone Product',
            'product_code' => 'SP001',
            'product_quantity' => 10,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'PCS',
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'stock_managed' => true,
        ]);

        $bundleItem = SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => null,
            'product_id' => $product->id,
            'bundle_id' => 0,
            'bundle_item_id' => 0,
            'name' => 'Standalone Piece',
            'quantity' => 1,
            'price' => 1000,
            'sub_total' => 1110,
            'tax_id' => $tax->id,
            'tax_amount' => 110,
        ]);

        $this->assertEquals($tax->id, $bundleItem->inherited_tax_id);
        $this->assertEquals(110, $bundleItem->resolved_tax_amount);
        $this->assertNotNull($bundleItem->line_group_key);
        $this->assertStringContainsString('standalone-', $bundleItem->line_group_key);
    }

    /** @test */
    public function it_includes_standalone_bundle_items_in_return_eligibility_summary()
    {
        $tax = Tax::create(['name' => 'VAT 11%', 'value' => 11]);
        $sale = Sale::create([
            'date' => now(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2220,
            'paid_amount' => 0,
            'due_amount' => 2220,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Test Component',
            'product_code' => 'TC001',
            'product_quantity' => 10,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'PCS',
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'stock_managed' => true,
        ]);

        // Create standalone bundle item
        $bundleItem = SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => null,
            'product_id' => $product->id,
            'bundle_id' => 0,
            'bundle_item_id' => 0,
            'name' => 'Standalone Piece',
            'quantity' => 2,
            'price' => 1000,
            'sub_total' => 2220,
            'tax_id' => $tax->id,
            'tax_amount' => 220,
        ]);

        // Create approved dispatch
        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'status' => Dispatch::STATUS_APPROVED,
            'dispatch_date' => now(),
        ]);

        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 2,
            'tax_id' => $tax->id,
        ]);

        $service = new SaleReturnEligibilityService();
        $summary = $service->summariseSale($sale);

        $this->assertEquals(1, $summary['returnable_lines']);
        $row = $summary['rows']->first();

        $this->assertEquals($product->id, $row['product_id']);
        $this->assertEquals(1000, $row['unit_price']); // Pulled from bundleItem->price fallback
        $this->assertEquals('Standalone Bundle Component', $row['bundle_context'][0]['bundle_name']);
        $this->assertNull($row['sale_detail_id']);
    }
}
