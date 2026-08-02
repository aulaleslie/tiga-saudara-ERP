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

    public function test_imported_hpp_snapshot_skipped_in_backfill(): void
    {
        $product = Product::forceCreate([
            'setting_id' => $this->setting->id,
            'product_name' => 'HPP Test Product',
            'product_code' => uniqid(),
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 10,
            'product_cost' => 10000,
            'product_price' => 15000,
            'product_unit' => 'pc',
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ]);

        $purchase = Purchase::forceCreate([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => 'Supplier',
            'status' => 'Completed',
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'date' => '2023-01-01',
            'due_date' => '2023-01-01',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        PurchaseDetail::forceCreate([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => 'HPP Test Product',
            'product_code' => 'HPPTEST',
            'quantity' => 10,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 100000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
        ]);

        $sale = Sale::forceCreate([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Customer',
            'customer_id' => $this->customer->id,
            'status' => 'Completed',
            'total_amount' => 150000,
            'paid_amount' => 150000,
            'due_amount' => 0,
            'date' => '2023-01-02',
            'due_date' => '2023-01-02',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        // Create a sale detail with HPP_SNAPSHOT_IMPORT source marker and known cost
        $saleDetail = SaleDetails::forceCreate([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'HPP Test Product',
            'product_code' => 'HPPTEST',
            'quantity' => 5,
            'price' => 15000,
            'unit_price' => 15000,
            'sub_total' => 75000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
            'cost_unit_snapshot' => 8500,
            'cost_snapshot_source' => 'HPP_SNAPSHOT_IMPORT',
        ]);

        // Run backfill
        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true, '--force' => true])
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['scanned', '1'],
                    ['fillable', '0'],
                    ['updated', '0'],
                    ['unchanged', '0'],
                    ['skipped', '1'],
                    ['missing_product_price', '0'],
                    ['negative_stock', '0'],
                    ['archived_skipped', '0'],
                    ['future_purchase_fallback', '0'],
                    ['no_purchase_fallback', '0'],
                    ['non_stock_zero', '0'],
                    ['missing_receipt_data', '0'],
                    ['suspicious_unit_cost', '0'],
                ]
            )
            ->assertExitCode(0);

        // Cost snapshot should remain unchanged
        $saleDetail->refresh();
        $this->assertEquals(8500, $saleDetail->cost_unit_snapshot);
        $this->assertEquals('HPP_SNAPSHOT_IMPORT', $saleDetail->cost_snapshot_source);
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

    public function test_negative_stock_followed_by_purchase_resets_average()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        $purchase1 = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchase1->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Purchase return of 15 quantity! Negative stock of -5.
        $pr = PurchaseReturn::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-01-02']);
        PurchaseReturnDetail::forceCreate(['purchase_return_id' => $pr->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 15, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 150000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // A later purchase that should reseed the average from 0, ignoring the negative value
        $purchase2 = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 200000, 'paid_amount' => 200000, 'due_amount' => 0, 'date' => '2023-01-03', 'due_date' => '2023-01-03', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchase2->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 20000, 'unit_price' => 20000, 'sub_total' => 200000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Sale that should use the new average of 20000, not a poisoned value
        $sale = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-04', 'due_date' => '2023-01-04', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetail = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 25000, 'unit_price' => 25000, 'sub_total' => 125000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true]);

        $fresh = $saleDetail->fresh();
        $this->assertEquals(20000, $fresh->cost_unit_snapshot);
        $this->assertEquals('BACKFILL_RUNNING_AVERAGE', $fresh->cost_snapshot_source);
    }

    public function test_purchase_dpp_subtracts_discount_and_tax()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        // sub_total 100000, tax 5000, discount 10000 => line_cost = 85000, qty 10 => average 8500
        $purchase = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 5000, 'product_discount_amount' => 10000]);

        $sale = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetail = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true]);

        $fresh = $saleDetail->fresh();
        $this->assertEquals(8500, $fresh->cost_unit_snapshot);
    }

    public function test_receipt_prorated_purchase_uses_discounted_tax_exclusive_dpp()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        $purchase = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'RECEIVED PARTIALLY', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        // sub_total 100000, tax 5000, discount 15000 => line_cost 80000, qty 10.
        $pd = PurchaseDetail::forceCreate(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 5000, 'product_discount_amount' => 15000]);

        $rn = \Modules\Purchase\Entities\ReceivedNote::forceCreate(['po_id' => $purchase->id, 'date' => '2023-01-01', 'status' => 'APPROVED', 'approved_at' => '2023-01-01']);
        \Modules\Purchase\Entities\ReceivedNoteDetail::forceCreate(['received_note_id' => $rn->id, 'po_detail_id' => $pd->id, 'quantity_received' => 5]);

        $sale = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetail = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 3, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true]);

        // prorated cost = 80000 * (5/10) = 40000
        // unit cost = 40000 / 5 = 8000
        $this->assertEquals(8000, $saleDetail->fresh()->cost_unit_snapshot);
    }

    public function test_suspicious_unit_cost_handling()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        // Suspicious purchase cost: total is huge for 1 item
        $purchase = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 200000000, 'paid_amount' => 200000000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 1, 'price' => 200000000, 'unit_price' => 200000000, 'sub_total' => 200000000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $sale = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetail = SaleDetails::forceCreate(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 1, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 15000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true])
             ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['scanned', '1'],
                    ['fillable', '1'],
                    ['updated', '0'], // should not update due to guardrail
                    ['unchanged', '0'],
                    ['skipped', '0'],
                    ['missing_product_price', '0'],
                    ['negative_stock', '0'],
                    ['archived_skipped', '0'],
                    ['future_purchase_fallback', '0'],
                    ['no_purchase_fallback', '0'],
                    ['non_stock_zero', '0'],
                    ['missing_receipt_data', '0'],
                    ['suspicious_unit_cost', '1'],
                ]
            );

        $this->assertNull($saleDetail->fresh()->cost_unit_snapshot);
    }

    public function test_filtered_replay_semantics()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        // Purchase on Jan 1
        $purchase = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Sale on Jan 2 - shouldn't be counted in updated but stock is consumed
        $sale1 = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetail1 = SaleDetails::forceCreate(['sale_id' => $sale1->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Sale on Jan 3 - filtered to be in scope
        $sale2 = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-03', 'due_date' => '2023-01-03', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetail2 = SaleDetails::forceCreate(['sale_id' => $sale2->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 2, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 30000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Run with filter from Jan 3
        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true, '--start' => '2023-01-03'])
             ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['scanned', '1'], // only the in-scope sale is "scanned"
                    ['fillable', '1'], // only the in-scope sale is "fillable"
                    ['updated', '1'], // only one updated
                    ['unchanged', '0'],
                    ['skipped', '0'],
                    ['missing_product_price', '0'],
                    ['negative_stock', '0'],
                    ['archived_skipped', '0'],
                    ['future_purchase_fallback', '0'],
                    ['no_purchase_fallback', '0'],
                    ['non_stock_zero', '0'],
                    ['missing_receipt_data', '0'],
                    ['suspicious_unit_cost', '0'],
                ]
            );

        // saleDetail1 shouldn't have snapshot updated because it's out of scope
        $this->assertNull($saleDetail1->fresh()->cost_unit_snapshot);
        // saleDetail2 should have it updated
        $this->assertEquals(10000, $saleDetail2->fresh()->cost_unit_snapshot);
    }

    public function test_end_filtered_replay_ignores_future_events()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        // Purchase on Jan 1
        $purchase = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Sale on Jan 2 - IN SCOPE
        $sale1 = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        SaleDetails::forceCreate(['sale_id' => $sale1->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Future Purchase Return on Jan 4 (would cause negative stock warning if processed)
        $pr = \Modules\PurchasesReturn\Entities\PurchaseReturn::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-01-04']);
        \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::forceCreate(['purchase_return_id' => $pr->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 15, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 150000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Future Received Partially Purchase with NO receipts on Jan 5 (would cause missing receipt data warning if processed)
        $purchaseFuture = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'RECEIVED PARTIALLY', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-05', 'due_date' => '2023-01-05', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseFuture->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Run with filter up to Jan 3
        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true, '--end' => '2023-01-03'])
             ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['scanned', '1'], 
                    ['fillable', '1'], 
                    ['updated', '1'], 
                    ['unchanged', '0'],
                    ['skipped', '0'],
                    ['missing_product_price', '0'],
                    ['negative_stock', '0'], // Should be 0 because future return is ignored
                    ['archived_skipped', '0'],
                    ['future_purchase_fallback', '0'],
                    ['no_purchase_fallback', '0'],
                    ['non_stock_zero', '0'],
                    ['missing_receipt_data', '0'], // Should be 0 because future purchase is ignored
                    ['suspicious_unit_cost', '0'],
                ]
            );
    }

    public function test_end_filtered_replay_uses_valid_future_purchase_fallback()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test Product', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'product_stock_alert' => 1, 'stock_managed' => true]);

        // Sale on Jan 1 - IN SCOPE
        $sale1 = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Customer', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetail = SaleDetails::forceCreate(['sale_id' => $sale1->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 5, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 75000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Future Received Partially Purchase with NO receipts on Jan 5 (invalid fallback)
        $purchaseFutureInvalid = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'RECEIVED PARTIALLY', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-05', 'due_date' => '2023-01-05', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseFutureInvalid->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 20000, 'unit_price' => 20000, 'sub_total' => 200000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Future Valid Purchase on Jan 6
        $purchaseFutureValid = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'Supplier', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-06', 'due_date' => '2023-01-06', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseFutureValid->id, 'product_id' => $product->id, 'product_name' => 'Test Product', 'product_code' => 'TEST01', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Run with filter up to Jan 3. 
        // Jan 5 invalid purchase should be skipped for fallback.
        // Jan 6 valid purchase should be used.
        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true, '--end' => '2023-01-03']);

        $fresh = $saleDetail->fresh();
        $this->assertEquals(10000, $fresh->cost_unit_snapshot);
        $this->assertEquals('BACKFILL_FUTURE_PURCHASE', $fresh->cost_snapshot_source);
    }

    public function test_tiga_nusa_isolated_bucket()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'stock_managed' => true]);

        $purchaseRest = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S1', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseRest->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $tigaNusa = Setting::factory()->create(['company_name' => 'CV TIGA NUSA COMPUTER']);
        $purchaseTN = Purchase::forceCreate(['setting_id' => $tigaNusa->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S2', 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseTN->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 10, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 150000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $saleTN = Sale::forceCreate(['setting_id' => $tigaNusa->id, 'customer_name' => 'C', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-03', 'due_date' => '2023-01-03', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetailTN = SaleDetails::forceCreate(['sale_id' => $saleTN->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 5, 'price' => 20000, 'unit_price' => 20000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true]);
        $this->assertEquals(15000, $saleDetailTN->fresh()->cost_unit_snapshot);
    }

    public function test_top_it_isolated_bucket()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'stock_managed' => true]);

        $purchaseRest = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S1', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseRest->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $topIt = Setting::factory()->create(['company_name' => 'CV TOP IT INTERNUSA']);
        $purchaseTI = Purchase::forceCreate(['setting_id' => $topIt->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S2', 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseTI->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 10, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 150000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $saleTI = Sale::forceCreate(['setting_id' => $topIt->id, 'customer_name' => 'C', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-03', 'due_date' => '2023-01-03', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetailTI = SaleDetails::forceCreate(['sale_id' => $saleTI->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 5, 'price' => 20000, 'unit_price' => 20000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true]);
        $this->assertEquals(15000, $saleDetailTI->fresh()->cost_unit_snapshot);
    }

    public function test_rest_isolated_bucket_from_specials()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'stock_managed' => true]);

        // TN Purchase 15k
        $tigaNusa = Setting::factory()->create(['company_name' => 'CV TIGA NUSA COMPUTER']);
        $purchaseTN = Purchase::forceCreate(['setting_id' => $tigaNusa->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S2', 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseTN->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 10, 'price' => 15000, 'unit_price' => 15000, 'sub_total' => 150000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Rest Purchase 10k
        $purchaseRest = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S1', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseRest->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Rest Sale
        $saleRest = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'C', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-03', 'due_date' => '2023-01-03', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetailRest = SaleDetails::forceCreate(['sale_id' => $saleRest->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 5, 'price' => 20000, 'unit_price' => 20000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true]);
        // Rest should ignore TN's 15k purchase
        $this->assertEquals(10000, $saleDetailRest->fresh()->cost_unit_snapshot);
    }

    public function test_purchase_return_isolated()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'stock_managed' => true]);

        $purchaseRest = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S1', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseRest->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $tigaNusa = Setting::factory()->create(['company_name' => 'CV TIGA NUSA COMPUTER']);
        $purchaseTN = Purchase::forceCreate(['setting_id' => $tigaNusa->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S2', 'status' => 'Completed', 'total_amount' => 200000, 'paid_amount' => 200000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseTN->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 10, 'price' => 20000, 'unit_price' => 20000, 'sub_total' => 200000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Purchase return on TN bucket. This drains 10 qty from TN bucket.
        $prTN = PurchaseReturn::forceCreate(['setting_id' => $tigaNusa->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S2', 'status' => 'Completed', 'total_amount' => 200000, 'paid_amount' => 200000, 'due_amount' => 0, 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'date' => '2023-01-03']);
        PurchaseReturnDetail::forceCreate(['purchase_return_id' => $prTN->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 10, 'price' => 20000, 'unit_price' => 20000, 'sub_total' => 200000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

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
                    ['negative_stock', '0'], // Rest bucket is not negative! TN bucket is 0.
                    ['archived_skipped', '0'],
                    ['future_purchase_fallback', '0'],
                    ['no_purchase_fallback', '0'],
                    ['non_stock_zero', '0'],
                    ['missing_receipt_data', '0'],
                    ['suspicious_unit_cost', '0'],
                ]
            );
    }

    public function test_special_fallback_to_rest()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'stock_managed' => true]);

        // Rest Purchase 10k
        $purchaseRest = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S1', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseRest->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Tiga Nusa Sale (TN has no purchases)
        $tigaNusa = Setting::factory()->create(['company_name' => 'CV TIGA NUSA COMPUTER']);
        $saleTN = Sale::forceCreate(['setting_id' => $tigaNusa->id, 'customer_name' => 'C', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-03', 'due_date' => '2023-01-03', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetailTN = SaleDetails::forceCreate(['sale_id' => $saleTN->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 5, 'price' => 20000, 'unit_price' => 20000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true]);
        
        // TN falls back to Rest average
        $this->assertEquals(10000, $saleDetailTN->fresh()->cost_unit_snapshot);
        $this->assertEquals('BACKFILL_RUNNING_AVERAGE', $saleDetailTN->fresh()->cost_snapshot_source);
    }

    public function test_setting_write_filter_special()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'stock_managed' => true]);

        $tigaNusa = Setting::factory()->create(['company_name' => 'CV TIGA NUSA COMPUTER']);
        
        // Rest Purchase 10k
        $purchaseRest = Purchase::forceCreate(['setting_id' => $this->setting->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S1', 'status' => 'Completed', 'total_amount' => 100000, 'paid_amount' => 100000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseRest->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 10, 'price' => 10000, 'unit_price' => 10000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $saleRest = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'C', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetailRest = SaleDetails::forceCreate(['sale_id' => $saleRest->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 5, 'price' => 20000, 'unit_price' => 20000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // TN Sale (Falls back to Rest)
        $saleTN = Sale::forceCreate(['setting_id' => $tigaNusa->id, 'customer_name' => 'C', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-03', 'due_date' => '2023-01-03', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetailTN = SaleDetails::forceCreate(['sale_id' => $saleTN->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 5, 'price' => 20000, 'unit_price' => 20000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Run with setting=tigaNusa
        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true, '--setting' => $tigaNusa->id]);
        
        $this->assertNull($saleDetailRest->fresh()->cost_unit_snapshot);
        $this->assertEquals(10000, $saleDetailTN->fresh()->cost_unit_snapshot);
    }

    public function test_setting_write_filter_non_special()
    {
        $product = Product::forceCreate(['setting_id' => $this->setting->id, 'product_name' => 'Test', 'product_code' => uniqid(), 'product_barcode_symbology' => 'C128', 'product_quantity' => 10, 'product_cost' => 10000, 'product_price' => 15000, 'product_unit' => 'pc', 'stock_managed' => true]);

        $tigaNusa = Setting::factory()->create(['company_name' => 'CV TIGA NUSA COMPUTER']);
        $otherRest = Setting::factory()->create(['company_name' => 'Another Rest']);
        
        // Rest Purchase on otherRest: 12000
        $purchaseRest = Purchase::forceCreate(['setting_id' => $otherRest->id, 'supplier_id' => $this->supplier->id, 'supplier_name' => 'S1', 'status' => 'Completed', 'total_amount' => 120000, 'paid_amount' => 120000, 'due_amount' => 0, 'date' => '2023-01-01', 'due_date' => '2023-01-01', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        PurchaseDetail::forceCreate(['purchase_id' => $purchaseRest->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 10, 'price' => 12000, 'unit_price' => 12000, 'sub_total' => 120000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $saleRest = Sale::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'C', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-02', 'due_date' => '2023-01-02', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetailRest = SaleDetails::forceCreate(['sale_id' => $saleRest->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 5, 'price' => 20000, 'unit_price' => 20000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        $saleOtherRest = Sale::forceCreate(['setting_id' => $otherRest->id, 'customer_name' => 'C', 'customer_id' => $this->customer->id, 'status' => 'Completed', 'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0, 'date' => '2023-01-03', 'due_date' => '2023-01-03', 'payment_status' => 'Paid', 'payment_method' => 'Cash']);
        $saleDetailOtherRest = SaleDetails::forceCreate(['sale_id' => $saleOtherRest->id, 'product_id' => $product->id, 'product_name' => 'Test', 'product_code' => 'T', 'quantity' => 5, 'price' => 20000, 'unit_price' => 20000, 'sub_total' => 100000, 'product_tax_amount' => 0, 'product_discount_amount' => 0]);

        // Run with setting=this->setting
        $this->artisan('sales:backfill-cost-snapshots', ['--write' => true, '--setting' => $this->setting->id]);
        
        $this->assertEquals(12000, $saleDetailRest->fresh()->cost_unit_snapshot);
        $this->assertNull($saleDetailOtherRest->fresh()->cost_unit_snapshot);
    }
}
