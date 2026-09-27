<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class FillAverageCostFromLastPurchasePriceCommandTest extends TestCase
{
    use RefreshDatabase;

    private Setting $tigaNusa;
    private Setting $topIt;
    private Setting $perdana;
    private Setting $regular1;
    private Setting $regular2;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        \Illuminate\Support\Facades\Gate::before(fn() => true);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->tigaNusa = Setting::factory()->create([
            'company_name' => 'CV TIGA NUSA COMPUTER',
            'default_currency_id' => $currency->id,
        ]);

        $this->topIt = Setting::factory()->create([
            'company_name' => 'CV TOP IT INTERNUSA',
            'default_currency_id' => $currency->id,
        ]);

        $this->perdana = Setting::factory()->create([
            'company_name' => 'Perdana',
            'default_currency_id' => $currency->id,
        ]);

        $this->regular1 = Setting::factory()->create([
            'company_name' => 'Regular 1',
            'default_currency_id' => $currency->id,
        ]);

        $this->regular2 = Setting::factory()->create([
            'company_name' => 'Regular 2',
            'default_currency_id' => $currency->id,
        ]);

        $this->unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
    }

    private function createProduct(Setting $setting): Product
    {
        static $codeCounter = 1;
        $code = 'TEST' . str_pad($codeCounter++, 4, '0', STR_PAD_LEFT);
        return Product::create([
            'product_name' => 'Test ' . $code,
            'product_code' => $code,
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'stock_managed' => true,
            'setting_id' => $setting->id,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
        ]);
    }

    private function createProductWithPrice(
        Setting $setting,
        ?float $average = 0,
        ?float $last = 0,
        ?Product $product = null
    ): ProductPrice {
        if (!$product) {
            $product = $this->createProduct($setting);
        }

        return ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'average_purchase_price' => $average,
            'last_purchase_price' => $last,
            'sale_price' => 0,
            'tier_1_price' => 0,
            'tier_2_price' => 0,
        ]);
    }

    public function test_dry_run_reports_prospective_changes_and_writes_nothing()
    {
        $price = $this->createProductWithPrice($this->regular1, 0, 5000);

        $this->artisan('product:fill-average-cost-from-last-purchase-price')
            ->expectsOutputToContain('Considered rows: 1')
            ->expectsOutputToContain('Filled (own last_purchase_price): 1')
            ->assertExitCode(0);

        $this->assertEquals(0, (float) $price->fresh()->average_purchase_price);
        $this->assertEquals(5000, (float) $price->fresh()->last_purchase_price);
    }

    public function test_write_mode_applies_changes()
    {
        $price = $this->createProductWithPrice($this->regular1, 0, 5000);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true])
            ->expectsOutputToContain('Considered rows: 1')
            ->expectsOutputToContain('Filled (own last_purchase_price): 1')
            ->assertExitCode(0);

        $this->assertEquals(5000, (float) $price->fresh()->average_purchase_price);
        $this->assertEquals(5000, (float) $price->fresh()->last_purchase_price);
    }

    public function test_null_and_zero_average_are_eligible_and_filled()
    {
        $p1 = $this->createProductWithPrice($this->regular1, null, 1000);
        $p2 = $this->createProductWithPrice($this->regular2, 0, 2000);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true]);

        $this->assertEquals(1000, (float) $p1->fresh()->average_purchase_price);
        $this->assertEquals(2000, (float) $p2->fresh()->average_purchase_price);
    }

    public function test_positive_average_is_left_unchanged()
    {
        $price = $this->createProductWithPrice($this->regular1, 500, 1000);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true])
            ->expectsOutputToContain('Unchanged (positive average): 1');

        $this->assertEquals(500, (float) $price->fresh()->average_purchase_price);
        $this->assertEquals(1000, (float) $price->fresh()->last_purchase_price);
    }

    public function test_own_last_purchase_price_wins_and_is_left_untouched()
    {
        $product = $this->createProduct($this->regular1);

        $target = $this->createProductWithPrice($this->regular1, 0, 500, $product);
        $donor = $this->createProductWithPrice($this->perdana, 0, 1000, $product);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true])
            ->expectsOutputToContain('Filled (own last_purchase_price): 2');

        $this->assertEquals(500, (float) $target->fresh()->average_purchase_price);
        $this->assertEquals(500, (float) $target->fresh()->last_purchase_price);
    }

    public function test_donor_fill_sets_both_fields_and_leaves_donor_untouched()
    {
        $product = $this->createProduct($this->regular1);

        $target = $this->createProductWithPrice($this->regular1, 0, 0, $product);
        $donor = $this->createProductWithPrice($this->perdana, 0, 1000, $product);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true])
            ->expectsOutputToContain('Filled (donor owner): 1')
            ->expectsOutputToContain('Filled (own last_purchase_price): 1');

        $this->assertEquals(1000, (float) $target->fresh()->average_purchase_price);
        $this->assertEquals(1000, (float) $target->fresh()->last_purchase_price);
        
        $this->assertEquals(1000, (float) $donor->fresh()->average_purchase_price);
        $this->assertEquals(1000, (float) $donor->fresh()->last_purchase_price);
    }

    public function test_donor_fill_handles_datasets_spanning_multiple_chunks()
    {
        $product = $this->createProduct($this->regular1);

        // We need > 100 settings to cross the chunk boundary (chunk size is 100).
        $settings = [];
        $currencyId = $this->regular1->default_currency_id;
        for ($i = 0; $i < 105; $i++) {
            $settings[] = Setting::factory()->create([
                'company_name' => 'Chunk Setting ' . $i,
                'default_currency_id' => $currencyId,
            ]);
        }

        // Create target rows in the first chunk (lower IDs)
        $targets = [];
        for ($i = 0; $i < 100; $i++) {
            $targets[] = $this->createProductWithPrice($settings[$i], 0, 0, $product);
        }

        // Create genuine donor in the second chunk (higher ID)
        $genuineDonor = $this->createProductWithPrice($settings[100], 0, 5555, $product);

        // Create a few more targets in the second chunk
        for ($i = 101; $i < 105; $i++) {
            $targets[] = $this->createProductWithPrice($settings[$i], 0, 0, $product);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        // First run
        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true])
            ->assertExitCode(0);

        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        $productPriceSelects = collect($queries)->filter(function ($query) {
            $sql = strtolower($query['query']);
            return str_contains($sql, 'select') && str_contains($sql, '"product_prices"');
        })->count();

        // 1 query for the pre-run snapshot, 2 queries for the chunks (105 rows chunked by 100).
        // The old code would perform 2 additional queries to reload siblings per chunk, yielding 4.
        $this->assertEquals(3, $productPriceSelects, 'Expected exactly 1 snapshot query and 2 chunk queries');

        // Assert all target rows received 5555 from the single genuine donor
        foreach ($targets as $target) {
            $this->assertEquals(5555, (float) $target->fresh()->average_purchase_price);
            $this->assertEquals(5555, (float) $target->fresh()->last_purchase_price);
        }

        // The genuine donor should be updated via own-fill
        $this->assertEquals(5555, (float) $genuineDonor->fresh()->average_purchase_price);
        $this->assertEquals(5555, (float) $genuineDonor->fresh()->last_purchase_price);
    }

    public function test_donor_ladder_order_perdana_topit_tiganusa()
    {
        $product = $this->createProduct($this->regular1);

        $target = $this->createProductWithPrice($this->regular1, 0, 0, $product);
        $tigaNusa = $this->createProductWithPrice($this->tigaNusa, 0, 10, $product);
        $topIt = $this->createProductWithPrice($this->topIt, 0, 20, $product);
        $perdana = $this->createProductWithPrice($this->perdana, 0, 30, $product);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true]);
        $this->assertEquals(30, (float) $target->fresh()->average_purchase_price);

        $target->fresh()->update(['average_purchase_price' => 0, 'last_purchase_price' => 0]);
        $perdana->fresh()->update(['last_purchase_price' => 0]);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true]);
        $this->assertEquals(20, (float) $target->fresh()->average_purchase_price);

        $target->fresh()->update(['average_purchase_price' => 0, 'last_purchase_price' => 0]);
        $topIt->fresh()->update(['last_purchase_price' => 0]);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true]);
        $this->assertEquals(10, (float) $target->fresh()->average_purchase_price);
    }

    public function test_unranked_donors_resolve_to_lower_setting_id()
    {
        $product = $this->createProduct($this->regular1);

        $target = $this->createProductWithPrice($this->perdana, 0, 0, $product);

        $this->assertTrue($this->regular1->id < $this->regular2->id);

        $donor1 = $this->createProductWithPrice($this->regular1, 0, 50, $product);
        $donor2 = $this->createProductWithPrice($this->regular2, 0, 100, $product);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true]);
        
        $this->assertEquals(50, (float) $target->fresh()->average_purchase_price);
    }

    public function test_row_with_no_donor_is_unresolved()
    {
        $product = $this->createProduct($this->regular1);

        $target = $this->createProductWithPrice($this->regular1, 0, 0, $product);
        $sibling = $this->createProductWithPrice($this->regular2, 0, 0, $product);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true])
            ->expectsOutputToContain('Unresolved (no donor): 2');

        $this->assertEquals(0, (float) $target->fresh()->average_purchase_price);
    }

    public function test_missing_product_prices_row_is_not_created()
    {
        $product = $this->createProduct($this->regular1);
        $this->createProductWithPrice($this->regular1, 0, 50, $product);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true]);

        $this->assertNull(ProductPrice::where('product_id', $product->id)->where('setting_id', $this->regular2->id)->first());
    }

    public function test_running_write_twice_is_idempotent()
    {
        $product = $this->createProduct($this->regular1);

        $target = $this->createProductWithPrice($this->regular1, 0, 0, $product);
        $donor = $this->createProductWithPrice($this->regular2, 0, 100, $product);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true])
            ->expectsOutputToContain('Filled (donor owner): 1')
            ->expectsOutputToContain('Filled (own last_purchase_price): 1');

        $this->assertEquals(100, (float) $target->fresh()->average_purchase_price);

        $this->artisan('product:fill-average-cost-from-last-purchase-price', ['--write' => true])
            ->expectsOutputToContain('Filled (donor owner): 0')
            ->expectsOutputToContain('Filled (own last_purchase_price): 0')
            ->expectsOutputToContain('Unchanged (positive average): 2');
    }
}
