<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class BackfillSalesCostSnapshotsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        $this->supplier = \Modules\People\Entities\Supplier::forceCreate(['setting_id' => $this->setting->id, 'supplier_name' => 'Test Supplier', 'supplier_phone' => '12345', 'supplier_email' => 'sup@test.com', 'city' => 'City', 'country' => 'Country', 'address' => 'Address']);
        $this->customer = \Modules\People\Entities\Customer::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Test Customer', 'customer_phone' => '12345', 'customer_email' => 'cus@test.com', 'city' => 'City', 'country' => 'Country', 'address' => 'Address']);
    }

    public function test_dry_run_performs_no_writes()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        $purchase = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $sale = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetail = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertNull($saleDetail->fresh()->cost_unit_snapshot);
    }

    public function test_write_updates_imported_snapshots()
    {
        // Verify that backfill recalculates imported snapshots (without backfill source marker)
        // using replayed historical averages, not the import-time current average
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        $purchase = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $sale = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        // Simulating imported snapshot without source marker (imported sales don't have cost_snapshot_source set initially)
        $saleDetail1 = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);
        // Simulating manually set/imported snapshot without backfill source
        $saleDetail2 = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0, 'cost_unit_snapshot' => 8000, 'cost_snapshot_source' => null]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true])
            ->assertExitCode(0);

        // Both should be updated with replayed historical average (10000)
        $this->assertEquals(10000, $saleDetail1->fresh()->cost_unit_snapshot);
        $this->assertEquals(10000, $saleDetail2->fresh()->cost_unit_snapshot); // Updated from 8000
        // saleDetail2 uses BACKFILL_FUTURE_PURCHASE because after sale 1, stock is depleted
        // and it falls back to the earliest available purchase (still 10000)
        $this->assertNotNull($saleDetail2->fresh()->cost_snapshot_source);
        $this->assertTrue(str_starts_with($saleDetail2->fresh()->cost_snapshot_source, 'BACKFILL_'));
    }

    public function test_force_recomputes_existing_snapshots()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        $purchase = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $sale = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetail = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0, 'cost_unit_snapshot' => 8000]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertEquals(10000, $saleDetail->fresh()->cost_unit_snapshot);
    }

    public function test_idempotent_reruns()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        $purchase = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $sale = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetail = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true]);
        $this->assertEquals(10000, $saleDetail->fresh()->cost_unit_snapshot);

        // Run again
        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true])
            ->expectsOutputToContain('| skipped')
            ->expectsOutputToContain('1')
            ->assertExitCode(0);
    }

    public function test_future_purchase_fallback()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        $sale = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']); // Sale is earlier!
        $saleDetail = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $purchase = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-05', 'due_date' => '2023-01-05', 'payment_status' => 'Paid', 'payment_method' => 'Cash']); // Future purchase!
        PurchaseDetail::forceCreate(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true]);

        $fresh = $saleDetail->fresh();
        $this->assertEquals(10000, $fresh->cost_unit_snapshot);
        $this->assertEquals('BACKFILL_FUTURE_PURCHASE', $fresh->cost_snapshot_source);
    }

    public function test_no_purchase_fallback()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        $sale = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetail = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true]);

        $fresh = $saleDetail->fresh();
        $this->assertEquals(0, $fresh->cost_unit_snapshot);
        $this->assertEquals('BACKFILL_ZERO_FALLBACK', $fresh->cost_snapshot_source);
    }

    public function test_negative_stock_warning()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        $purchase = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Purchase return of 15 quantity! Negative stock.
        $pr = PurchaseReturn::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-01-02']);
        PurchaseReturnDetail::forceCreate(['purchase_return_id' => $pr->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 15, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 150000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true])
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['scanned', '0'],
                    ['fillable', '0'],
                    ['updated', '0'],
                    ['unchanged', '0'],
                    ['skipped', '0'],
                    ['missing_product_price', '0'],
                    ['negative_stock', '1'],
                    ['archived_skipped', '0'],
                    ['future_purchase_fallback', '0'],
                    ['no_purchase_fallback', '0'],
                    ['non_stock_zero', '0'],
                ]
            );
    }

    public function test_archived_rejected_document_exclusion()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        $purchase = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Pending', 'total_amount' => 100000, 'paid_amount' => 0, 'due_amount' => 100000, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Unpaid', 'payment_method' => 'Cash']); // Not completed!
        PurchaseDetail::forceCreate(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $sale = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Pending', 'total_amount' => 150000, 'paid_amount' => 0, 'due_amount' => 150000, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Unpaid', 'payment_method' => 'Cash']); // Not completed!
        $saleDetail = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true])
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['scanned', '0'],
                    ['fillable', '0'],
                    ['updated', '0'],
                    ['unchanged', '0'],
                    ['skipped', '0'],
                    ['missing_product_price', '0'],
                    ['negative_stock', '0'],
                    ['archived_skipped', '0'],
                    ['future_purchase_fallback', '0'],
                    ['no_purchase_fallback', '0'],
                    ['non_stock_zero', '0'],
                ]
            );
    }

    public function test_non_stock_zero_fallback()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => false]);

        $sale = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetail = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true]);

        $fresh = $saleDetail->fresh();
        $this->assertEquals(0, $fresh->cost_unit_snapshot);
        $this->assertEquals('NON_STOCK_ZERO', $fresh->cost_snapshot_source);
    }
}
