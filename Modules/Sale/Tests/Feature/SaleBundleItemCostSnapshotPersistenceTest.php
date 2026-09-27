<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleBundleItemCostSnapshotPersistenceTest extends TestCase
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

    protected function makeSetting(bool $isPkp = false): Setting
    {
        return Setting::create([
            'company_name' => 'Setting ' . uniqid(),
            'company_email' => uniqid() . '@test.com',
            'company_phone' => '1',
            'notification_email' => uniqid() . '@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => $isPkp,
        ]);
    }

    protected function makeSaleWithDetail(Setting $setting): array
    {
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

        return [$sale, $saleDetail, $product];
    }

    public function test_bundle_item_persists_full_cost_snapshot_with_decimal_precision(): void
    {
        $setting = $this->makeSetting(true);
        [$sale, $saleDetail, $product] = $this->makeSaleWithDetail($setting);

        $item = SaleBundleItem::create([
            'sale_detail_id' => $saleDetail->id,
            'sale_id' => $sale->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $product->id,
            'name' => 'Component',
            'price' => 100,
            'quantity' => 3,
            'sub_total' => 300,
            'cost_unit_snapshot' => 123.456789,
            'cost_total_snapshot' => 370.37,
            'cost_snapshot_source' => 'OWNER_AVERAGE',
            'cost_snapshot_setting_id' => $setting->id,
            'cost_snapshot_setting_is_pkp' => true,
            'cost_snapshot_at' => now(),
        ]);

        $item->refresh();

        $this->assertSame('123.456789', (string) $item->cost_unit_snapshot);
        $this->assertSame('370.37', (string) $item->cost_total_snapshot);
        $this->assertSame('OWNER_AVERAGE', $item->cost_snapshot_source);
        $this->assertSame($setting->id, $item->cost_snapshot_setting_id);
        $this->assertTrue($item->cost_snapshot_setting_is_pkp);
        $this->assertNotNull($item->cost_snapshot_at);
        $this->assertTrue($item->costSnapshotSetting->is($setting));
    }

    public function test_legacy_bundle_item_rows_without_cost_snapshot_remain_null(): void
    {
        $setting = $this->makeSetting();
        [$sale, $saleDetail, $product] = $this->makeSaleWithDetail($setting);

        $item = SaleBundleItem::create([
            'sale_detail_id' => $saleDetail->id,
            'sale_id' => $sale->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $product->id,
            'name' => 'Legacy Component',
            'price' => 100,
            'quantity' => 1,
            'sub_total' => 100,
        ]);

        $item->refresh();

        $this->assertNull($item->cost_unit_snapshot);
        $this->assertNull($item->cost_total_snapshot);
        $this->assertNull($item->cost_snapshot_source);
        $this->assertNull($item->cost_snapshot_setting_id);
        $this->assertNull($item->cost_snapshot_setting_is_pkp);
        $this->assertNull($item->cost_snapshot_at);
    }

    public function test_bundle_item_cost_snapshot_setting_relation_can_differ_from_selling_setting(): void
    {
        $setting = $this->makeSetting();
        [$sale, $saleDetail, $product] = $this->makeSaleWithDetail($setting);

        $sourceSetting = $this->makeSetting(true);

        $item = SaleBundleItem::create([
            'sale_detail_id' => $saleDetail->id,
            'sale_id' => $sale->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $product->id,
            'name' => 'Component',
            'price' => 100,
            'quantity' => 1,
            'sub_total' => 100,
            'cost_unit_snapshot' => 50,
            'cost_total_snapshot' => 50,
            'cost_snapshot_source' => 'OWNER_AVERAGE_FALLBACK',
            'cost_snapshot_setting_id' => $sourceSetting->id,
            'cost_snapshot_setting_is_pkp' => true,
            'cost_snapshot_at' => now(),
        ]);

        $item->refresh();

        $this->assertSame($sourceSetting->id, $item->cost_snapshot_setting_id);
        $this->assertTrue($item->costSnapshotSetting->is($sourceSetting));
    }
}
