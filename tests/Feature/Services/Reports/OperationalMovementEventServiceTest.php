<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\OperationalGeneralLedgerBucketConfig;
use App\Services\Reports\OperationalMovementEventService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class OperationalMovementEventServiceTest extends TestCase
{
    use RefreshDatabase;

    protected OperationalMovementEventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OperationalMovementEventService();
    }

    private function createSetting()
    {
        return Setting::create([
            'company_name' => 'Test',
            'company_email' => 'test@test.com',
            'company_phone' => '123',
            'site_logo' => 'logo.png',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@test.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);
    }

    private function createProduct($settingId, $price)
    {
        return Product::create([
            'product_name' => 'Test',
            'product_code' => 'TEST' . rand(1, 1000),
            'product_price' => $price,
            'product_cost' => 0,
            'product_quantity' => 10,
            'setting_id' => $settingId,
            'product_unit' => 'PCS',
        ]);
    }

    private function createCustomer($settingId)
    {
        return \Modules\People\Entities\Customer::create([
            'customer_name' => 'John Doe',
            'customer_email' => 'john@test.com',
            'customer_phone' => '123',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $settingId,
        ]);
    }

    /** @test */
    public function it_uses_sale_detail_dpp_for_revenue_and_excludes_header_tax_and_shipping()
    {
        $setting = $this->createSetting();
        $product = $this->createProduct($setting->id, 1000);
        $customer = $this->createCustomer($setting->id);

        $sale = Sale::create([
            'setting_id' => $setting->id,
            'reference' => 'SL-001',
            'customer_id' => $customer->id,
            'customer_name' => 'John Doe',
            'date' => Carbon::now()->format('Y-m-d'),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'total_amount' => 2700,
            'tax_amount' => 500,
            'shipping_amount' => 200,
            'discount_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        // DPP = 1000 - 100 = 900
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'TEST',
            'price' => 1000,
            'unit_price' => 1000,
            'quantity' => 1,
            'sub_total' => 1000,
            'product_tax_amount' => 100,
            'product_discount_amount' => 0,
            'cost_unit_snapshot' => 600,
        ]);

        // DPP = 2000 - 0 = 2000
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'TEST',
            'price' => 1000,
            'unit_price' => 1000,
            'quantity' => 2,
            'sub_total' => 2000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
            'cost_unit_snapshot' => 600,
        ]);

        $events = collect($this->service->getMovementEvents($setting->id, Carbon::now()->format('Y-m-d')));

        // Expected Revenue = 900 + 2000 = 2900
        $revenueEvent = $events->firstWhere('bucket', OperationalGeneralLedgerBucketConfig::OPERATIONAL_REVENUE);
        $arEvent = $events->where('bucket', OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE)
                          ->where('sourceType', 'Penjualan')
                          ->first();

        $this->assertNotNull($revenueEvent, 'Revenue event should exist');
        $this->assertEquals(2900, $revenueEvent['credit']);
        $this->assertEquals(0, $revenueEvent['debit']);

        $this->assertNotNull($arEvent, 'AR event should exist');
        $this->assertEquals(2700, $arEvent['debit']); // AR is total_amount
        $this->assertEquals(0, $arEvent['credit']);
    }

    /** @test */
    public function it_creates_separate_revenue_reduction_for_global_sale_discount()
    {
        $setting = $this->createSetting();
        $product = $this->createProduct($setting->id, 1000);
        $customer = $this->createCustomer($setting->id);

        $sale = Sale::create([
            'setting_id' => $setting->id,
            'reference' => 'SL-002',
            'customer_id' => $customer->id,
            'customer_name' => 'John Doe',
            'date' => Carbon::now()->format('Y-m-d'),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'total_amount' => 850,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'discount_amount' => 150,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'TEST',
            'price' => 1000,
            'unit_price' => 1000,
            'quantity' => 1,
            'sub_total' => 1000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 50,
            'cost_unit_snapshot' => 600,
        ]);

        $events = collect($this->service->getMovementEvents($setting->id, Carbon::now()->format('Y-m-d')));

        // Expected Revenue = 1000
        $revenueEvent = $events->firstWhere('bucket', OperationalGeneralLedgerBucketConfig::OPERATIONAL_REVENUE);
        $this->assertEquals(1000, $revenueEvent['credit']);

        // Expected Discount Reduction (Dr) = 150
        $discountEvent = $events->where('bucket', OperationalGeneralLedgerBucketConfig::OPERATIONAL_REVENUE)
            ->where('debit', '>', 0)
            ->first();
            
        $this->assertNotNull($discountEvent, 'Discount reduction event should exist');
        $this->assertEquals(150, $discountEvent['debit']);
        $this->assertEquals(0, $discountEvent['credit']);

        // Expected AR = 850 (total_amount)
        $arEvent = $events->where('bucket', OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE)
                          ->where('sourceType', 'Penjualan')
                          ->first();
        $this->assertEquals(850, $arEvent['debit']);
    }

    /** @test */
    public function it_calculates_hpp_movement_from_sale_detail_cost_snapshots_treating_null_as_zero()
    {
        $setting = $this->createSetting();
        $product = $this->createProduct($setting->id, 1000);
        $customer = $this->createCustomer($setting->id);

        $sale = Sale::create([
            'setting_id' => $setting->id,
            'reference' => 'SL-003',
            'customer_id' => $customer->id,
            'customer_name' => 'John Doe',
            'date' => Carbon::now()->format('Y-m-d'),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'total_amount' => 3000,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'discount_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'payment_method' => 'Cash',
        ]);

        // Has cost snapshot
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'TEST',
            'price' => 1000,
            'unit_price' => 1000,
            'quantity' => 2,
            'sub_total' => 2000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
            'cost_unit_snapshot' => 600, // Cost = 1200
        ]);

        // Null cost snapshot
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'TEST',
            'price' => 1000,
            'unit_price' => 1000,
            'quantity' => 1,
            'sub_total' => 1000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
            'cost_unit_snapshot' => null, // Cost = 0
        ]);

        $events = collect($this->service->getMovementEvents($setting->id, Carbon::now()->format('Y-m-d')));

        // Expected HPP Cost (Dr) = 1200 + 0 = 1200
        $costEvent = $events->where('bucket', OperationalGeneralLedgerBucketConfig::OPERATIONAL_COST)
                            ->where('sourceType', 'Penjualan')
                            ->first();

        $this->assertNotNull($costEvent, 'HPP Cost event should exist');
        $this->assertEquals(1200, $costEvent['debit']);
        $this->assertEquals(0, $costEvent['credit']);
        
        $inventoryEvent = $events->where('bucket', OperationalGeneralLedgerBucketConfig::INVENTORY)
                                 ->where('sourceType', 'Penjualan')
                                 ->first();
                                 
        $this->assertNotNull($inventoryEvent, 'Inventory credit event should exist');
        $this->assertEquals(1200, $inventoryEvent['credit']);
        $this->assertEquals(0, $inventoryEvent['debit']);
    }
}
