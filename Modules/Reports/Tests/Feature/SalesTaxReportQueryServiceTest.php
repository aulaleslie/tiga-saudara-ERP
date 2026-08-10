<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use App\Services\Reports\SalesTaxReportQueryService;
use App\Services\Reports\SalesTaxReportFilterData;
use Carbon\Carbon;

class SalesTaxReportQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Tax $tax1;
    private Tax $tax2;
    private SalesTaxReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->setting = Setting::factory()->create();
        
        $this->tax1 = Tax::create(['name' => 'PPN 12%', 'value' => 11.0]);
        $this->tax2 = Tax::create(['name' => 'Pajak Lain', 'value' => 5.0]);

        $this->service = new SalesTaxReportQueryService();
    }

    private function createSale(array $attributes = []): Sale
    {
        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        return Sale::create(array_merge([
            'date' => Carbon::today()->format('Y-m-d'),
            'due_date' => Carbon::today()->format('Y-m-d'),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => Sale::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'shipping_amount' => 0,
        ], $attributes));
    }

    private function createPurchase(array $attributes = []): Purchase
    {
        $supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
        return Purchase::create(array_merge([
            'date' => Carbon::today()->format('Y-m-d'),
            'due_date' => Carbon::today()->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'status' => Purchase::STATUS_APPROVED,
            'setting_id' => $this->setting->id,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'shipping_amount' => 0,
        ], $attributes));
    }

    /** @test */
    public function it_aggregates_sales_and_purchases_by_tax()
    {
        $product1 = \Modules\Product\Entities\Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST',
            'product_price' => 100,
            'product_cost' => 100,
            'product_quantity' => 100,
            'product_stock_alert' => 10,
        ]);
        $product2 = \Modules\Product\Entities\Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product 2',
            'product_code' => 'TEST2',
            'product_price' => 100,
            'product_cost' => 100,
            'product_quantity' => 100,
            'product_stock_alert' => 10,
        ]);

        $sale = $this->createSale();
        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product1->id,
            'tax_id' => $this->tax1->id,
            'sub_total' => 700000,
            'product_tax_amount' => 63636.36,
            'quantity' => 1,
            'price' => 700000,
            'unit_price' => 700000,
            'product_discount_amount' => 0,
            'product_name' => 'Product A',
            'product_code' => 'A',
        ]);

        $purchase = $this->createPurchase();
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product2->id,
            'tax_id' => $this->tax1->id,
            'sub_total' => 2562424312.70,
            'product_tax_amount' => 253933940.90,
            'quantity' => 1,
            'price' => 2562424312.70,
            'unit_price' => 2562424312.70,
            'product_discount_type' => 'AMOUNT',
            'product_discount_amount' => 0,
            'product_name' => 'Product B',
            'product_code' => 'B',
        ]);

        // Verify data was created correctly
        $this->assertNotNull($saleDetail->id);
        $this->assertNotNull($purchaseDetail->id);
        $this->assertEquals($this->tax1->id, $purchaseDetail->tax_id);
        $this->assertEquals($this->tax1->id, $saleDetail->tax_id);
        $this->assertNotEquals(0, $purchaseDetail->product_tax_amount);

        $filter = new SalesTaxReportFilterData(
            startDate: Carbon::today()->format('Y-m-d'),
            endDate: Carbon::today()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $results = $this->service->build($filter);

        $this->assertCount(2, $results);

        $saleRow = $results->firstWhere('transaction_type', 'Penjualan');
        $this->assertNotNull($saleRow);
        $this->assertEquals($this->tax1->id, $saleRow->tax_id);
        $this->assertEquals('PPN 12%', $saleRow->tax_name);
        $this->assertEquals(11.0, $saleRow->tax_rate);
        $this->assertEquals(636363.64, round($saleRow->dpp, 2));
        $this->assertEquals(63636.36, round($saleRow->total_tax, 2));

        $purchaseRow = $results->firstWhere('transaction_type', 'Pembelian');
        $this->assertNotNull($purchaseRow);
        $this->assertEquals($this->tax1->id, $purchaseRow->tax_id);
        $this->assertEquals('PPN 12%', $purchaseRow->tax_name);
        $this->assertEquals(11.0, $purchaseRow->tax_rate);
        $this->assertEquals(2308490371.80, round($purchaseRow->dpp, 2));
        $this->assertEquals(253933940.90, round($purchaseRow->total_tax, 2));
    }

    /** @test */
    public function it_excludes_unapproved_and_deleted_records()
    {
        $product1 = \Modules\Product\Entities\Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST',
            'product_price' => 100,
            'product_cost' => 100,
            'product_quantity' => 100,
            'product_stock_alert' => 10,
        ]);

        $sale = $this->createSale(['status' => Sale::STATUS_DRAFTED]);
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product1->id,
            'tax_id' => $this->tax1->id,
            'sub_total' => 1000,
            'product_tax_amount' => 100,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'product_discount_amount' => 0,
            'product_name' => 'Product A',
            'product_code' => 'A',
        ]);

        $purchase = $this->createPurchase(['status' => Purchase::STATUS_REJECTED]);
        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product1->id,
            'tax_id' => $this->tax1->id,
            'sub_total' => 1000,
            'product_tax_amount' => 100,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'product_discount_type' => 'AMOUNT',
            'product_discount_amount' => 0,
            'product_name' => 'Product B',
            'product_code' => 'B',
        ]);

        $deletedSale = $this->createSale(['archived_at' => now()]);
        SaleDetails::create([
            'sale_id' => $deletedSale->id,
            'product_id' => $product1->id,
            'tax_id' => $this->tax1->id,
            'sub_total' => 1000,
            'product_tax_amount' => 100,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'product_discount_amount' => 0,
            'product_name' => 'Product C',
            'product_code' => 'C',
        ]);

        $filter = new SalesTaxReportFilterData(
            startDate: Carbon::today()->format('Y-m-d'),
            endDate: Carbon::today()->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $results = $this->service->build($filter);

        $this->assertCount(0, $results);
    }

    /** @test */
    public function it_filters_purchases_by_effective_reporting_date()
    {
        $product = \Modules\Product\Entities\Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST',
            'product_price' => 100,
            'product_cost' => 100,
            'product_quantity' => 100,
            'product_stock_alert' => 10,
        ]);

        $today = Carbon::today();
        $tomorrow = $today->clone()->addDay();
        $yesterday = $today->clone()->subDay();

        // Purchase with original date outside period but active reporting_date inside
        $purchaseWithReportingDateInside = $this->createPurchase([
            'status' => Purchase::STATUS_RECEIVED,
            'date' => $yesterday->format('Y-m-d'),
            'reporting_date' => $today->format('Y-m-d'),
        ]);
        PurchaseDetail::create([
            'purchase_id' => $purchaseWithReportingDateInside->id,
            'product_id' => $product->id,
            'tax_id' => $this->tax1->id,
            'sub_total' => 1000000,
            'product_tax_amount' => 100000,
            'quantity' => 1,
            'price' => 1000000,
            'unit_price' => 1000000,
            'product_discount_type' => 'AMOUNT',
            'product_discount_amount' => 0,
            'product_name' => 'Product A',
            'product_code' => 'A',
        ]);

        // Purchase with original date inside period but active reporting_date outside
        $purchaseWithReportingDateOutside = $this->createPurchase([
            'status' => Purchase::STATUS_RECEIVED,
            'date' => $today->format('Y-m-d'),
            'reporting_date' => $tomorrow->format('Y-m-d'),
        ]);
        PurchaseDetail::create([
            'purchase_id' => $purchaseWithReportingDateOutside->id,
            'product_id' => $product->id,
            'tax_id' => $this->tax2->id,
            'sub_total' => 2000000,
            'product_tax_amount' => 200000,
            'quantity' => 1,
            'price' => 2000000,
            'unit_price' => 2000000,
            'product_discount_type' => 'AMOUNT',
            'product_discount_amount' => 0,
            'product_name' => 'Product B',
            'product_code' => 'B',
        ]);

        $filter = new SalesTaxReportFilterData(
            startDate: $today->format('Y-m-d'),
            endDate: $today->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $results = $this->service->build($filter);

        $purchaseRows = $results->where('transaction_type', 'Pembelian');

        // Only the first purchase (with reporting_date inside) should be included
        $this->assertCount(1, $purchaseRows);

        // Verify the included purchase has the expected tax ID and aggregate values
        $includedRow = $purchaseRows->first();
        $this->assertEquals($this->tax1->id, $includedRow->tax_id);
        $this->assertEquals(900000, round($includedRow->dpp, 2));
        $this->assertEquals(100000, round($includedRow->total_tax, 2));

        // Verify the excluded purchase's tax is not in the results
        $excludedTaxRow = $results->firstWhere(function ($row) {
            return $row->transaction_type === 'Pembelian' && $row->tax_id === $this->tax2->id;
        });
        $this->assertNull($excludedTaxRow);
    }

    /** @test */
    public function it_filters_sales_only_by_sale_date()
    {
        $product = \Modules\Product\Entities\Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST',
            'product_price' => 100,
            'product_cost' => 100,
            'product_quantity' => 100,
            'product_stock_alert' => 10,
        ]);

        $today = Carbon::today();
        $tomorrow = $today->clone()->addDay();

        // Sale with date inside period (note: sales use only date, not reporting_date)
        $saleInside = $this->createSale([
            'date' => $today->format('Y-m-d'),
        ]);
        SaleDetails::create([
            'sale_id' => $saleInside->id,
            'product_id' => $product->id,
            'tax_id' => $this->tax1->id,
            'sub_total' => 1000,
            'product_tax_amount' => 100,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'product_discount_amount' => 0,
            'product_name' => 'Product A',
            'product_code' => 'A',
        ]);

        // Sale with date outside period
        $saleOutside = $this->createSale([
            'date' => $tomorrow->format('Y-m-d'),
        ]);
        SaleDetails::create([
            'sale_id' => $saleOutside->id,
            'product_id' => $product->id,
            'tax_id' => $this->tax1->id,
            'sub_total' => 1000,
            'product_tax_amount' => 100,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'product_discount_amount' => 0,
            'product_name' => 'Product B',
            'product_code' => 'B',
        ]);

        $filter = new SalesTaxReportFilterData(
            startDate: $today->format('Y-m-d'),
            endDate: $today->format('Y-m-d'),
            scopeSettingId: $this->setting->id
        );

        $results = $this->service->build($filter);

        $saleRows = $results->where('transaction_type', 'Penjualan');
        // Only the sale inside the period should be included
        $this->assertCount(1, $saleRows);
    }
}
