<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class BackfillSaleBundleItemCostSnapshotsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $supplier;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        $this->supplier = \Modules\People\Entities\Supplier::forceCreate(['setting_id' => $this->setting->id, 'supplier_name' => 'Test Supplier', 'supplier_phone' => '12345', 'supplier_email' => 'sup@test.com', 'city' => 'City', 'country' => 'Country', 'address' => 'Address']);
        $this->customer = \Modules\People\Entities\Customer::forceCreate(['setting_id' => $this->setting->id, 'customer_name' => 'Test Customer', 'customer_phone' => '12345', 'customer_email' => 'cus@test.com', 'city' => 'City', 'country' => 'Country', 'address' => 'Address']);
    }

    protected function createComponentProduct(): Product
    {
        return Product::forceCreate([
            'setting_id' => $this->setting->id,
            'product_name' => 'Component',
            'product_code' => uniqid(),
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 10,
            'product_cost' => 10000,
            'product_price' => 0,
            'product_unit' => 'pc',
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ]);
    }

    protected function createPurchase(Product $product, int $qty, int $unitPrice, string $date = '2023-01-01'): Purchase
    {
        $purchase = Purchase::forceCreate([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => 'Supplier',
            'status' => 'Completed',
            'total_amount' => $qty * $unitPrice,
            'paid_amount' => $qty * $unitPrice,
            'due_amount' => 0,
            'date' => $date,
            'due_date' => $date,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        PurchaseDetail::forceCreate([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => $qty,
            'price' => $unitPrice,
            'unit_price' => $unitPrice,
            'sub_total' => $qty * $unitPrice,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
        ]);

        return $purchase;
    }

    protected function createSaleWithBundleItem(Product $componentProduct, int $qty = 5, string $date = '2023-01-02'): array
    {
        $parentProduct = Product::forceCreate([
            'setting_id' => $this->setting->id,
            'product_name' => 'Parent',
            'product_code' => uniqid(),
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 10,
            'product_cost' => 0,
            'product_price' => 15000,
            'product_unit' => 'pc',
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ]);

        $sale = Sale::forceCreate([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Customer',
            'customer_id' => $this->customer->id,
            'status' => 'Completed',
            'total_amount' => 15000,
            'paid_amount' => 15000,
            'due_amount' => 0,
            'date' => $date,
            'due_date' => $date,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        $saleDetail = SaleDetails::forceCreate([
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id,
            'product_name' => 'Parent',
            'product_code' => 'PARENT',
            'quantity' => 1,
            'price' => 15000,
            'unit_price' => 15000,
            'sub_total' => 15000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
        ]);

        $bundleItem = SaleBundleItem::create([
            'sale_detail_id' => $saleDetail->id,
            'sale_id' => $sale->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $componentProduct->id,
            'name' => 'Component',
            'price' => 0,
            'quantity' => $qty,
            'sub_total' => 0,
        ]);

        return [$sale, $saleDetail, $bundleItem];
    }

    public function test_dry_run_performs_no_writes(): void
    {
        $component = $this->createComponentProduct();
        $this->createPurchase($component, 10, 10000);
        [, , $bundleItem] = $this->createSaleWithBundleItem($component);

        $this->artisan('sales:backfill-bundle-item-cost-snapshots')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertNull($bundleItem->fresh()->cost_unit_snapshot);
    }

    public function test_write_backfills_component_from_purchase_replay(): void
    {
        $component = $this->createComponentProduct();
        $this->createPurchase($component, 10, 10000);
        [, , $bundleItem] = $this->createSaleWithBundleItem($component, qty: 5);

        $this->artisan('sales:backfill-bundle-item-cost-snapshots', ['--write' => true])
            ->assertExitCode(0);

        $bundleItem = $bundleItem->fresh();
        $this->assertEquals(10000, (float) $bundleItem->cost_unit_snapshot);
        $this->assertEquals(50000, (float) $bundleItem->cost_total_snapshot); // 10000 * 5
        $this->assertNotNull($bundleItem->cost_snapshot_source);
        $this->assertTrue(str_starts_with($bundleItem->cost_snapshot_source, 'BACKFILL_'));
        $this->assertEquals($this->setting->id, $bundleItem->cost_snapshot_setting_id);
    }

    public function test_does_not_touch_sale_details_table(): void
    {
        $component = $this->createComponentProduct();
        $this->createPurchase($component, 10, 10000);
        [, $saleDetail] = $this->createSaleWithBundleItem($component);

        $this->artisan('sales:backfill-bundle-item-cost-snapshots', ['--write' => true])
            ->assertExitCode(0);

        // Parent detail is untouched: parent and component updates are reported separately.
        $this->assertNull($saleDetail->fresh()->cost_unit_snapshot);
    }

    public function test_existing_snapshot_skipped_without_force(): void
    {
        $component = $this->createComponentProduct();
        $this->createPurchase($component, 10, 10000);
        [, , $bundleItem] = $this->createSaleWithBundleItem($component);

        $bundleItem->update([
            'cost_unit_snapshot' => 999,
            'cost_snapshot_source' => 'MANUAL_OVERRIDE',
        ]);

        $this->artisan('sales:backfill-bundle-item-cost-snapshots', ['--write' => true])
            ->assertExitCode(0);

        $this->assertEquals(999, (float) $bundleItem->fresh()->cost_unit_snapshot);
    }

    public function test_force_recomputes_existing_snapshot(): void
    {
        $component = $this->createComponentProduct();
        $this->createPurchase($component, 10, 10000);
        [, , $bundleItem] = $this->createSaleWithBundleItem($component, qty: 5);

        $bundleItem->update([
            'cost_unit_snapshot' => 999,
            'cost_snapshot_source' => 'MANUAL_OVERRIDE',
        ]);

        $this->artisan('sales:backfill-bundle-item-cost-snapshots', ['--write' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertEquals(10000, (float) $bundleItem->fresh()->cost_unit_snapshot);
    }

    public function test_ambiguous_pos_split_lineage_is_skipped_and_reported(): void
    {
        $component = $this->createComponentProduct();
        $this->createPurchase($component, 10, 10000);
        [$sale, , $bundleItem] = $this->createSaleWithBundleItem($component, qty: 5);

        // Two dispatch_details for the SAME (sale_id, product_id, bundle_id) key,
        // at DIFFERENT owner settings: ambiguous lineage, must be skipped.
        $otherSetting = Setting::factory()->create();
        $locationA = Location::create(['name' => 'Loc A', 'setting_id' => $this->setting->id]);
        $locationB = Location::create(['name' => 'Loc B', 'setting_id' => $otherSetting->id]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $component->id,
            'bundle_id' => 1,
            'dispatched_quantity' => 3,
            'location_id' => $locationA->id,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $component->id,
            'bundle_id' => 1,
            'dispatched_quantity' => 2,
            'location_id' => $locationB->id,
        ]);

        $this->artisan('sales:backfill-bundle-item-cost-snapshots', ['--write' => true])
            ->assertExitCode(0);

        $bundleItem = $bundleItem->fresh();
        $this->assertNull($bundleItem->cost_unit_snapshot);
        $this->assertNull($bundleItem->cost_snapshot_source);
    }

    public function test_normal_sale_bundle_item_resolves_owner_from_sale_setting_when_no_dispatch_lineage(): void
    {
        $component = $this->createComponentProduct();
        $this->createPurchase($component, 10, 10000);
        [, , $bundleItem] = $this->createSaleWithBundleItem($component, qty: 5);

        // No dispatch_details rows at all: falls back to the Sale's own setting_id
        // (unambiguous for a single-owner Normal Sale).
        $this->artisan('sales:backfill-bundle-item-cost-snapshots', ['--write' => true])
            ->assertExitCode(0);

        $bundleItem = $bundleItem->fresh();
        $this->assertNotNull($bundleItem->cost_unit_snapshot);
        $this->assertEquals($this->setting->id, $bundleItem->cost_snapshot_setting_id);
    }

    public function test_idempotent_reruns_report_skipped_without_changing_values(): void
    {
        $component = $this->createComponentProduct();
        $this->createPurchase($component, 10, 10000);
        [, , $bundleItem] = $this->createSaleWithBundleItem($component, qty: 5);

        $this->artisan('sales:backfill-bundle-item-cost-snapshots', ['--write' => true]);
        $firstValue = $bundleItem->fresh()->cost_unit_snapshot;

        $this->artisan('sales:backfill-bundle-item-cost-snapshots', ['--write' => true])
            ->expectsOutputToContain('skipped_existing_snapshot')
            ->assertExitCode(0);

        $this->assertEquals($firstValue, $bundleItem->fresh()->cost_unit_snapshot);
    }
}
