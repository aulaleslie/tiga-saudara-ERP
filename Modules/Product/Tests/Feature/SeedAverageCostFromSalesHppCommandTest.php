<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class SeedAverageCostFromSalesHppCommandTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    private Setting $tigaNusa;
    private Setting $topIt;
    private Setting $regular1;
    private Setting $regular2;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        \Illuminate\Support\Facades\Gate::before(fn() => true);

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->tigaNusa = Setting::factory()->create([
            'company_name' => 'CV TIGA NUSA COMPUTER',
            'default_currency_id' => $this->currency->id,
        ]);

        $this->topIt = Setting::factory()->create([
            'company_name' => 'CV TOP IT INTERNUSA',
            'default_currency_id' => $this->currency->id,
        ]);

        $this->regular1 = Setting::factory()->create([
            'company_name' => 'Regular Company 1',
            'default_currency_id' => $this->currency->id,
        ]);

        $this->regular2 = Setting::factory()->create([
            'company_name' => 'Regular Company 2',
            'default_currency_id' => $this->currency->id,
        ]);

        Location::factory()->create(['setting_id' => $this->tigaNusa->id]);
        Location::factory()->create(['setting_id' => $this->topIt->id]);
        Location::factory()->create(['setting_id' => $this->regular1->id]);
        Location::factory()->create(['setting_id' => $this->regular2->id]);

        $this->unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
    }

    private function createProduct(string $code, bool $stockManaged, Setting $setting): Product
    {
        return Product::create([
            'product_name' => 'Test ' . $code,
            'product_code' => $code,
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'stock_managed' => $stockManaged,
            'setting_id' => $setting->id,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
        ]);
    }

    private function createSale(Setting $setting, $date = null): Sale
    {
        return Sale::create([
            'setting_id' => $setting->id,
            'date' => $date ?? now(),
            'customer_name' => 'Test Customer',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);
    }

    private function createSaleDetail(Sale $sale, Product $product, $cost = 50000): SaleDetails
    {
        return SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $cost,
            'unit_price' => $cost,
            'sub_total' => $cost,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => $cost,
            'cost_total_snapshot' => $cost,
            'cost_snapshot_source' => 'HPP_SNAPSHOT_IMPORT',
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
        ]);
    }

    public function test_dry_run_does_not_mutate_product_prices()
    {
        $product = $this->createProduct('DRY-RUN-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa, now()->subDays(10));
        $this->createSaleDetail($sale, $product, 50000);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 10000,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $this->assertEquals(10000, $existingPrice->average_purchase_price);
    }

    public function test_ignores_non_imported_snapshots()
    {
        $product = $this->createProduct('NON-IMPORT-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 50000,
            'cost_total_snapshot' => 50000,
            'cost_snapshot_source' => 'SALES_COST_SERVICE',
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp')
            ->assertExitCode(0);
    }

    public function test_ignores_zero_cost_snapshots()
    {
        $product = $this->createProduct('ZERO-COST-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 0,
            'cost_total_snapshot' => 0,
            'cost_snapshot_source' => 'HPP_SNAPSHOT_IMPORT',
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp')
            ->assertExitCode(0);
    }

    public function test_ignores_non_stock_managed_products()
    {
        $product = $this->createProduct('NON-STOCK-TEST', false, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($sale, $product, 50000);

        $this->artisan('product:seed-average-cost-from-sales-hpp')
            ->assertExitCode(0);
    }

    public function test_selects_latest_candidate_by_sale_date()
    {
        $product = $this->createProduct('LATEST-DATE-TEST', true, $this->tigaNusa);
        $olderSale = $this->createSale($this->tigaNusa, now()->subDays(10));
        $newerSale = $this->createSale($this->tigaNusa, now()->subDays(1));

        $this->createSaleDetail($olderSale, $product, 30000);
        $this->createSaleDetail($newerSale, $product, 50000);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();

        $this->assertEquals(50000, $price->average_purchase_price);
    }

    public function test_same_date_tiebreak_by_sale_id_then_detail_id()
    {
        $product = $this->createProduct('TIEBREAK-TEST', true, $this->tigaNusa);
        $saleDate = now();

        $sale1 = $this->createSale($this->tigaNusa, $saleDate);
        $sale2 = $this->createSale($this->tigaNusa, $saleDate);

        $this->createSaleDetail($sale1, $product, 30000);
        $this->createSaleDetail($sale2, $product, 50000);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();

        $this->assertEquals(50000, $price->average_purchase_price);
    }

    public function test_special_setting_uses_own_bucket()
    {
        $product = $this->createProduct('SPECIAL-BUCKET-TEST', true, $this->tigaNusa);
        $tigaSale = $this->createSale($this->tigaNusa);
        $restSale = $this->createSale($this->regular1);

        $this->createSaleDetail($tigaSale, $product, 50000);
        $this->createSaleDetail($restSale, $product, 30000);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $tigaPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();

        $this->assertEquals(50000, $tigaPrice->average_purchase_price);
    }

    public function test_special_setting_fallback_to_rest_global()
    {
        $product = $this->createProduct('FALLBACK-TEST', true, $this->tigaNusa);
        $restSale = $this->createSale($this->regular1);

        $this->createSaleDetail($restSale, $product, 30000);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $tigaPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();

        $this->assertEquals(30000, $tigaPrice->average_purchase_price);
    }

    public function test_non_special_settings_share_rest_global()
    {
        $product = $this->createProduct('GLOBAL-SHARE-TEST', true, $this->regular1);
        $sale = $this->createSale($this->regular1);

        $this->createSaleDetail($sale, $product, 40000);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular2->id,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price1 = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->regular1->id)
            ->first();

        $price2 = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->regular2->id)
            ->first();

        $this->assertEquals(40000, $price1->average_purchase_price);
        $this->assertEquals(40000, $price2->average_purchase_price);
    }

    public function test_creates_missing_product_price_row()
    {
        $product = $this->createProduct('CREATE-ROW-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);

        $this->createSaleDetail($sale, $product, 45000);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
            'sale_price' => 100000,
            'last_purchase_price' => 40000,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $newPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();

        $this->assertNotNull($newPrice);
        $this->assertEquals(45000, $newPrice->average_purchase_price);
    }

    public function test_preserves_existing_price_metadata()
    {
        $product = $this->createProduct('PRESERVE-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);

        $this->createSaleDetail($sale, $product, 55000);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 40000,
            'last_purchase_price' => 38000,
            'sale_price' => 100000,
            'tier_1_price' => 90000,
            'tier_2_price' => 80000,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $this->assertEquals(55000, $existingPrice->average_purchase_price);
        $this->assertEquals(38000, $existingPrice->last_purchase_price);
        $this->assertEquals(100000, $existingPrice->sale_price);
        $this->assertEquals(90000, $existingPrice->tier_1_price);
        $this->assertEquals(80000, $existingPrice->tier_2_price);
    }

    public function test_products_without_candidates_remain_unchanged()
    {
        $product = $this->createProduct('NO-CANDIDATE-TEST', true, $this->tigaNusa);

        $price = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 25000,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price->refresh();
        $this->assertEquals(25000, $price->average_purchase_price);
    }
}
