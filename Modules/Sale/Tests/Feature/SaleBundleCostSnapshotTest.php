<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Services\SaleService;
use Modules\Sale\Services\SalesCostSnapshotService;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleBundleCostSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;

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

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@test.com',
            'company_phone' => '1',
            'notification_email' => 'notify@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'cust@test.com',
            'customer_phone' => '1234',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);
    }

    protected function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'product_name' => 'Product ' . uniqid(),
            'product_code' => 'P-' . uniqid(),
            'product_quantity' => 100,
            'product_cost' => 0,
            'product_price' => 0,
            'setting_id' => $this->setting->id,
            'stock_managed' => true,
        ], $overrides));
    }

    protected function setAveragePrice(Product $product, $value): void
    {
        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 0,
            'average_purchase_price' => $value,
        ]);
    }

    protected function baseSaleData(): array
    {
        return [
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'setting_id' => $this->setting->id,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'is_tax_included' => false,
        ];
    }

    protected function cartItemWithBundle(Product $parent, int $parentQty, array $bundleItems, bool $parentStockManaged = false): array
    {
        return [
            'options' => [
                'product_id' => $parent->id,
                'unit_price' => 0,
                'product_discount' => 0,
                'bundle_items' => $bundleItems,
            ],
            'name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'qty' => $parentQty,
            'price' => 0,
        ];
    }

    public function test_non_stock_parent_with_stock_component_costs_only_the_component(): void
    {
        $parent = $this->makeProduct(['stock_managed' => false]);
        $component = $this->makeProduct(['stock_managed' => true]);
        $this->setAveragePrice($component, 500);

        $cartItem = $this->cartItemWithBundle($parent, 1, [[
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $component->id,
            'name' => $component->product_name,
            'quantity' => 3,
            'quantity_per_bundle' => 3,
        ]]);

        $sale = app(SaleService::class)->createSale($this->baseSaleData(), [$cartItem]);

        $saleDetail = $sale->fresh()->saleDetails()->with('bundleItems')->first();

        $this->assertEquals(0, (float) $saleDetail->cost_unit_snapshot);
        $this->assertEquals(0, (float) $saleDetail->cost_total_snapshot);
        $this->assertEquals(SalesCostSnapshotService::SOURCE_NON_STOCK_MANAGED, $saleDetail->cost_snapshot_source);

        $bundleItem = $saleDetail->bundleItems->first();
        $this->assertEquals(500, (float) $bundleItem->cost_unit_snapshot);
        $this->assertEquals(1500, (float) $bundleItem->cost_total_snapshot); // 500 * 3
        $this->assertEquals(SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE, $bundleItem->cost_snapshot_source);
        $this->assertEquals($this->setting->id, $bundleItem->cost_snapshot_setting_id);
    }

    public function test_stock_parent_alone_costs_only_the_parent(): void
    {
        $parent = $this->makeProduct(['stock_managed' => true]);
        $this->setAveragePrice($parent, 200);

        $cartItem = [
            'options' => [
                'product_id' => $parent->id,
                'unit_price' => 0,
                'product_discount' => 0,
            ],
            'name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'qty' => 4,
            'price' => 0,
        ];

        $sale = app(SaleService::class)->createSale($this->baseSaleData(), [$cartItem]);
        $saleDetail = $sale->fresh()->saleDetails()->first();

        $this->assertEquals(200, (float) $saleDetail->cost_unit_snapshot);
        $this->assertEquals(800, (float) $saleDetail->cost_total_snapshot); // 200 * 4
        $this->assertEquals(SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE, $saleDetail->cost_snapshot_source);
    }

    public function test_stock_parent_with_stock_and_non_stock_add_ons_costs_each_row_independently(): void
    {
        $parent = $this->makeProduct(['stock_managed' => true]);
        $this->setAveragePrice($parent, 1000);

        $stockAddOn = $this->makeProduct(['stock_managed' => true]);
        $this->setAveragePrice($stockAddOn, 50);

        $nonStockAddOn = $this->makeProduct(['stock_managed' => false]);

        $cartItem = $this->cartItemWithBundle($parent, 2, [
            [
                'bundle_id' => 1,
                'bundle_item_id' => 1,
                'product_id' => $stockAddOn->id,
                'name' => $stockAddOn->product_name,
                'quantity' => 2, // already expanded: 2 per bundle * 1 parent qty passthrough
                'quantity_per_bundle' => 1,
            ],
            [
                'bundle_id' => 1,
                'bundle_item_id' => 2,
                'product_id' => $nonStockAddOn->id,
                'name' => $nonStockAddOn->product_name,
                'quantity' => 2,
                'quantity_per_bundle' => 1,
            ],
        ]);

        $sale = app(SaleService::class)->createSale($this->baseSaleData(), [$cartItem]);
        $saleDetail = $sale->fresh()->saleDetails()->with('bundleItems')->first();

        $this->assertEquals(1000, (float) $saleDetail->cost_unit_snapshot);
        $this->assertEquals(2000, (float) $saleDetail->cost_total_snapshot); // 1000 * 2

        $stockItem = $saleDetail->bundleItems->firstWhere('product_id', $stockAddOn->id);
        $this->assertEquals(50, (float) $stockItem->cost_unit_snapshot);
        $this->assertEquals(100, (float) $stockItem->cost_total_snapshot); // 50 * 2

        $nonStockItem = $saleDetail->bundleItems->firstWhere('product_id', $nonStockAddOn->id);
        $this->assertEquals(0, (float) $nonStockItem->cost_unit_snapshot);
        $this->assertEquals(0, (float) $nonStockItem->cost_total_snapshot);
        $this->assertEquals(SalesCostSnapshotService::SOURCE_NON_STOCK_MANAGED, $nonStockItem->cost_snapshot_source);
    }

    public function test_multi_quantity_bundle_expansion_uses_already_expanded_component_quantity(): void
    {
        $parent = $this->makeProduct(['stock_managed' => false]);
        $component = $this->makeProduct(['stock_managed' => true]);
        $this->setAveragePrice($component, 25);

        // Parent line qty=5, per-bundle qty=2 -> caller already expands to 10.
        $cartItem = $this->cartItemWithBundle($parent, 5, [[
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $component->id,
            'name' => $component->product_name,
            'quantity' => 10,
            'quantity_per_bundle' => 2,
        ]]);

        $sale = app(SaleService::class)->createSale($this->baseSaleData(), [$cartItem]);
        $bundleItem = $sale->fresh()->saleDetails()->first()->bundleItems()->first();

        $this->assertEquals(10, $bundleItem->quantity);
        $this->assertEquals(25, (float) $bundleItem->cost_unit_snapshot);
        $this->assertEquals(250, (float) $bundleItem->cost_total_snapshot); // 25 * 10
    }

    public function test_missing_component_average_price_persists_zero_and_returns_warning(): void
    {
        $parent = $this->makeProduct(['stock_managed' => false]);
        $component = $this->makeProduct(['stock_managed' => true]);
        // No ProductPrice row at all for this setting.

        $cartItem = $this->cartItemWithBundle($parent, 1, [[
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $component->id,
            'name' => $component->product_name,
            'quantity' => 1,
            'quantity_per_bundle' => 1,
        ]]);

        $service = app(SaleService::class);
        $sale = $service->createSale($this->baseSaleData(), [$cartItem]);

        $bundleItem = $sale->fresh()->saleDetails()->first()->bundleItems()->first();

        $this->assertEquals(0, (float) $bundleItem->cost_unit_snapshot);
        $this->assertEquals(0, (float) $bundleItem->cost_total_snapshot);
        $this->assertEquals(SalesCostSnapshotService::SOURCE_MISSING_AVERAGE_PRICE, $bundleItem->cost_snapshot_source);

        $this->assertCount(1, $service->lastMissingCostWarnings);
        $this->assertSame($component->id, $service->lastMissingCostWarnings[0]['product_id']);
        $this->assertSame($bundleItem->id, $service->lastMissingCostWarnings[0]['sale_bundle_item_id']);
    }

    public function test_snapshot_remains_immutable_after_average_price_changes_post_sale(): void
    {
        $parent = $this->makeProduct(['stock_managed' => false]);
        $component = $this->makeProduct(['stock_managed' => true]);
        $priceRow = ProductPrice::create([
            'product_id' => $component->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 0,
            'average_purchase_price' => 300,
        ]);

        $cartItem = $this->cartItemWithBundle($parent, 1, [[
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $component->id,
            'name' => $component->product_name,
            'quantity' => 1,
            'quantity_per_bundle' => 1,
        ]]);

        $sale = app(SaleService::class)->createSale($this->baseSaleData(), [$cartItem]);
        $bundleItem = $sale->fresh()->saleDetails()->first()->bundleItems()->first();
        $this->assertEquals(300, (float) $bundleItem->cost_unit_snapshot);

        // Average price changes after the sale was persisted.
        $priceRow->update(['average_purchase_price' => 900]);

        $bundleItem->refresh();
        $this->assertEquals(300, (float) $bundleItem->cost_unit_snapshot);
        $this->assertEquals(300, (float) $bundleItem->cost_total_snapshot);
    }
}
