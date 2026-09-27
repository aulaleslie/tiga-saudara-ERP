<?php

namespace Modules\SalesReturn\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleReturnDetailCostReversalPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);
    }

    protected function makeFixture(): array
    {
        $setting = Setting::create([
            'company_name' => 'Setting ' . uniqid(),
            'company_email' => uniqid() . '@test.com',
            'company_phone' => '1',
            'notification_email' => uniqid() . '@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        $customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => uniqid() . '@test.com',
            'customer_phone' => '1234',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $setting->id,
        ]);

        $product = Product::create([
            'product_name' => 'Component Product',
            'product_code' => 'COMP-01',
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'setting_id' => $setting->id,
        ]);

        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-' . uniqid(),
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Bundle Parent',
            'product_code' => 'BUNDLE-01',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $bundleItem = SaleBundleItem::create([
            'sale_detail_id' => $saleDetail->id,
            'sale_id' => $sale->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $product->id,
            'name' => 'Component',
            'price' => 100,
            'quantity' => 1,
            'sub_total' => 100,
        ]);

        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'SR-' . uniqid(),
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'setting_id' => $setting->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
        ]);

        return compact('setting', 'sale', 'saleDetail', 'bundleItem', 'saleReturn', 'product');
    }

    public function test_return_detail_persists_component_origin_cost_reversal_snapshot(): void
    {
        $fixture = $this->makeFixture();

        $detail = SaleReturnDetail::create([
            'sale_return_id' => $fixture['saleReturn']->id,
            'product_id' => $fixture['product']->id,
            'product_name' => 'Component',
            'product_code' => 'COMP-01',
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'component_sale_bundle_item_id' => $fixture['bundleItem']->id,
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM,
            'cost_unit_snapshot' => 123.456789,
            'cost_quantity' => 1,
            'cost_total_snapshot' => 123.46,
            'cost_snapshot_source' => 'OWNER_AVERAGE',
            'cost_snapshot_setting_id' => $fixture['setting']->id,
            'cost_snapshot_setting_is_pkp' => true,
            'cost_snapshot_at' => now(),
            'cost_effective_at' => now(),
        ]);

        $detail->refresh();

        $this->assertSame(SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM, $detail->cost_origin);
        $this->assertSame('123.456789', (string) $detail->cost_unit_snapshot);
        $this->assertSame('1.0000', (string) $detail->cost_quantity);
        $this->assertSame('123.46', (string) $detail->cost_total_snapshot);
        $this->assertTrue($detail->cost_snapshot_setting_is_pkp);
        $this->assertNotNull($detail->cost_effective_at);
        $this->assertTrue($detail->componentSaleBundleItem->is($fixture['bundleItem']));
        $this->assertTrue($detail->costSnapshotSetting->is($fixture['setting']));
    }

    public function test_return_detail_without_cost_reversal_metadata_remains_null(): void
    {
        $fixture = $this->makeFixture();

        $detail = SaleReturnDetail::create([
            'sale_return_id' => $fixture['saleReturn']->id,
            'product_id' => $fixture['product']->id,
            'product_name' => 'Parent',
            'product_code' => 'BUNDLE-01',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'sale_detail_id' => $fixture['saleDetail']->id,
        ]);

        $detail->refresh();

        $this->assertNull($detail->component_sale_bundle_item_id);
        $this->assertNull($detail->cost_origin);
        $this->assertNull($detail->cost_unit_snapshot);
        $this->assertNull($detail->cost_quantity);
        $this->assertNull($detail->cost_total_snapshot);
        $this->assertNull($detail->cost_effective_at);
    }

    public function test_return_detail_component_reference_resolves_the_originating_bundle_item(): void
    {
        $fixture = $this->makeFixture();

        $detail = SaleReturnDetail::create([
            'sale_return_id' => $fixture['saleReturn']->id,
            'product_id' => $fixture['product']->id,
            'product_name' => 'Component',
            'product_code' => 'COMP-01',
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'component_sale_bundle_item_id' => $fixture['bundleItem']->id,
            'cost_origin' => SaleReturnDetail::COST_ORIGIN_BUNDLE_ITEM,
        ]);

        $detail->refresh();

        $this->assertSame($fixture['bundleItem']->id, $detail->component_sale_bundle_item_id);
        $this->assertTrue($detail->componentSaleBundleItem->is($fixture['bundleItem']));
    }
}
