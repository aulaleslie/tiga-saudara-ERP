<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\OperationalProfitLossReportService;
use App\Services\Reports\SaleHppAggregateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Canonical fixture for openspec/changes/harden-product-bundle-hpp Section 5:
 * a bundle Sale whose parent + component HPP combine to a net HPP of 4,530,000
 * against 5,550,000 DPP, yielding a gross profit of 1,020,000.
 */
class SaleHppAggregateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();

        $currency = Currency::factory()->create(['code' => 'IDR']);
        $this->setting = Setting::factory()->create(['default_currency_id' => $currency->id]);
        session(['setting_id' => $this->setting->id]);

        $category = Category::forceCreate([
            'category_name' => 'Cat',
            'category_code' => 'C1',
            'setting_id' => $this->setting->id,
            'created_by' => 1,
        ]);

        $this->product = Product::forceCreate([
            'product_name' => 'Prod',
            'product_code' => 'P1',
            'product_price' => 10000,
            'product_cost' => 5000,
            'category_id' => $category->id,
            'product_quantity' => 10,
            'product_unit' => 'pcs',
            'product_stock_alert' => 1,
            'setting_id' => $this->setting->id,
        ]);

        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
    }

    protected function createCanonicalBundleSale(): Sale
    {
        $sale = Sale::forceCreate([
            'setting_id' => $this->setting->id,
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 5550000,
            'paid_amount' => 5550000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'date' => '2023-05-15',
            'reference' => 'S' . uniqid(),
            'customer_name' => 'C',
            'discount_amount' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
        ]);

        // Parent: DPP 5,550,000, unit cost 3,500 * qty 1000 = 3,500,000 gross parent HPP.
        $saleDetail = SaleDetails::forceCreate([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => 'Prod',
            'product_code' => 'P1',
            'quantity' => 1000,
            'price' => 5550,
            'unit_price' => 5550,
            'sub_total' => 5550000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 3500,
        ]);

        // Two components summing to 1,030,000 gross component HPP:
        // 500 * 1000 = 500,000, and 530 * 1000 = 530,000.
        SaleBundleItem::create([
            'sale_detail_id' => $saleDetail->id,
            'sale_id' => $sale->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $this->product->id,
            'name' => 'Comp A',
            'price' => 0,
            'quantity' => 1000,
            'sub_total' => 0,
            'cost_unit_snapshot' => 500,
            'cost_total_snapshot' => 500000,
        ]);

        SaleBundleItem::create([
            'sale_detail_id' => $saleDetail->id,
            'sale_id' => $sale->id,
            'bundle_id' => 1,
            'bundle_item_id' => 2,
            'product_id' => $this->product->id,
            'name' => 'Comp B',
            'price' => 0,
            'quantity' => 1000,
            'sub_total' => 0,
            'cost_unit_snapshot' => 530,
            'cost_total_snapshot' => 530000,
        ]);

        return $sale;
    }

    public function test_canonical_fixture_yields_4_530_000_hpp_and_1_020_000_gross_profit(): void
    {
        $this->createCanonicalBundleSale();

        $service = new OperationalProfitLossReportService();
        $report = $service->generate([$this->setting->id], '2023-05-01', '2023-05-31');

        $this->assertEquals(5550000, $report->penjualan);
        $this->assertEquals(4530000, $report->bebanPokokPendapatan);
        $this->assertEquals(1020000, $report->penjualan - $report->bebanPokokPendapatan);
    }

    public function test_multiple_components_on_one_parent_are_summed_independently_without_row_multiplication(): void
    {
        $sale = $this->createCanonicalBundleSale();

        $aggregate = new SaleHppAggregateService();
        $totals = $aggregate->totals([$this->setting->id], '2023-05-01', '2023-05-31');

        // Two component rows must sum, not multiply the parent's own contribution.
        $this->assertEquals(4530000, $totals->hpp);
        $this->assertEquals(5550000, $totals->dpp);

        // Same result must hold in the per-sale view used by operational movements.
        $perSale = $aggregate->perSale([$this->setting->id], '2023-05-01', '2023-05-31');
        $this->assertEquals(4530000, (float) $perSale->get($sale->id)->net_hpp);
        $this->assertEquals(5550000, (float) $perSale->get($sale->id)->dpp);
    }

    public function test_component_only_pos_group_parent_row_contributes_zero_hpp(): void
    {
        $sale = Sale::forceCreate([
            'setting_id' => $this->setting->id,
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'date' => '2023-05-15',
            'reference' => 'S' . uniqid(),
            'customer_name' => 'C',
            'discount_amount' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
        ]);

        // Component-only group placeholder: parent qty 0, cost snapshot 0.
        $saleDetail = SaleDetails::forceCreate([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => 'Prod',
            'product_code' => 'P1',
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 0,
            'cost_total_snapshot' => 0,
        ]);

        SaleBundleItem::create([
            'sale_detail_id' => $saleDetail->id,
            'sale_id' => $sale->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => $this->product->id,
            'name' => 'Comp',
            'price' => 0,
            'quantity' => 5,
            'sub_total' => 100000,
            'cost_unit_snapshot' => 800,
            'cost_total_snapshot' => 4000,
        ]);

        $aggregate = new SaleHppAggregateService();
        $totals = $aggregate->totals([$this->setting->id], '2023-05-01', '2023-05-31');

        // Only the component's 4,000 contributes; the placeholder parent row contributes zero.
        $this->assertEquals(4000, $totals->hpp);
    }

    public function test_setting_and_date_scope_excludes_out_of_range_sales(): void
    {
        $this->createCanonicalBundleSale();

        $otherSetting = Setting::factory()->create(['default_currency_id' => $this->setting->default_currency_id]);

        $aggregate = new SaleHppAggregateService();

        $outOfScopeSetting = $aggregate->totals([$otherSetting->id], '2023-05-01', '2023-05-31');
        $this->assertEquals(0, $outOfScopeSetting->hpp);

        $outOfScopeDate = $aggregate->totals([$this->setting->id], '2023-06-01', '2023-06-30');
        $this->assertEquals(0, $outOfScopeDate->hpp);
    }
}
