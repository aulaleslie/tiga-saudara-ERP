<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
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
    private Setting $perdana;
    private Setting $regular1;
    private Setting $regular2;
    private Unit $unit;
    private $supplierId;

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

        $this->perdana = Setting::factory()->create([
            'company_name' => 'Perdana',
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
        Location::factory()->create(['setting_id' => $this->perdana->id]);
        Location::factory()->create(['setting_id' => $this->regular1->id]);
        Location::factory()->create(['setting_id' => $this->regular2->id]);

        $this->unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);

        // Create a supplier for testing purchases
        $supplier = Supplier::factory()->create(['setting_id' => $this->tigaNusa->id]);
        $this->supplierId = $supplier->id;
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

    public function test_perdana_baseline_fills_all_uninitialized_businesses()
    {
        $product = $this->createProduct('PERDANA-BASELINE-TEST', true, $this->perdana);
        $perdanaSale = $this->createSale($this->perdana);
        $this->createSaleDetail($perdanaSale, $product, 50000);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->topIt->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $tigaPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();
        $topItPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->topIt->id)
            ->first();
        $regularPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->regular1->id)
            ->first();

        $this->assertEquals(50000, $tigaPrice->average_purchase_price);
        $this->assertEquals(50000, $topItPrice->average_purchase_price);
        $this->assertEquals(50000, $regularPrice->average_purchase_price);
    }

    public function test_top_it_becomes_baseline_when_perdana_unavailable()
    {
        $product = $this->createProduct('TOP-IT-BASELINE-TEST', true, $this->topIt);
        $topItSale = $this->createSale($this->topIt);
        $this->createSaleDetail($topItSale, $product, 45000);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $tigaPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();
        $regularPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->regular1->id)
            ->first();

        $this->assertEquals(45000, $tigaPrice->average_purchase_price);
        $this->assertEquals(45000, $regularPrice->average_purchase_price);
    }

    public function test_tiga_nusa_becomes_baseline_when_perdana_and_top_it_unavailable()
    {
        $product = $this->createProduct('TIGA-BASELINE-TEST', true, $this->tigaNusa);
        $tigaSale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($tigaSale, $product, 40000);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->topIt->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $topItPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->topIt->id)
            ->first();
        $regularPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->regular1->id)
            ->first();

        $this->assertEquals(40000, $topItPrice->average_purchase_price);
        $this->assertEquals(40000, $regularPrice->average_purchase_price);
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

        $this->assertNotNull($price);
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

        $this->assertNotNull($price);
        $this->assertEquals(50000, $price->average_purchase_price);
    }

    public function test_special_company_retains_own_latest_hpp()
    {
        $product = $this->createProduct('SPECIAL-OVERLAY-TEST', true, $this->perdana);
        $perdanaSale = $this->createSale($this->perdana, now()->subDays(10));
        $this->createSaleDetail($perdanaSale, $product, 30000);

        $topItSale = $this->createSale($this->topIt, now()->subDays(2));
        $this->createSaleDetail($topItSale, $product, 50000);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->topIt->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $topItPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->topIt->id)
            ->first();
        $regularPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->regular1->id)
            ->first();

        $this->assertEquals(50000, $topItPrice->average_purchase_price);
        $this->assertEquals(30000, $regularPrice->average_purchase_price);
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

        $this->assertNotNull($tigaPrice);
        $this->assertEquals(50000, $tigaPrice->average_purchase_price);
    }

    public function test_special_setting_fallback_to_perdana()
    {
        $product = $this->createProduct('FALLBACK-TEST', true, $this->tigaNusa);
        $perdanaSale = $this->createSale($this->perdana);

        $this->createSaleDetail($perdanaSale, $product, 30000);

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

    public function test_non_special_settings_use_perdana_hpp()
    {
        $product = $this->createProduct('PERDANA-HPP-TEST', true, $this->regular1);
        $perdanaSale = $this->createSale($this->perdana);

        $this->createSaleDetail($perdanaSale, $product, 40000);

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

    public function test_creates_missing_product_price_row_without_purchase_candidate()
    {
        $product = $this->createProduct('CREATE-ROW-HPP-ONLY-TEST', true, $this->perdana);
        $sale = $this->createSale($this->perdana);
        $this->createSaleDetail($sale, $product, 45000);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
            'sale_price' => 100000,
            'tier_1_price' => 90000,
            'tier_2_price' => 80000,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $newPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();

        $this->assertNotNull($newPrice);
        $this->assertEquals(45000, $newPrice->average_purchase_price);
        $this->assertEquals(100000, $newPrice->sale_price);
        $this->assertEquals(90000, $newPrice->tier_1_price);
        $this->assertEquals(80000, $newPrice->tier_2_price);
    }

    public function test_preserves_existing_price_metadata_when_filling_zero_average()
    {
        $product = $this->createProduct('PRESERVE-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);

        $this->createSaleDetail($sale, $product, 55000);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 0,
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

    public function test_product_without_hpp_baseline_remains_unresolved()
    {
        $product = $this->createProduct('NO-HPP-BASELINE-TEST', true, $this->tigaNusa);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 25000,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
            'average_purchase_price' => 0,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $tigaPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();

        $regularPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->regular1->id)
            ->first();

        $this->assertEquals(25000, $tigaPrice->average_purchase_price);
        $this->assertEquals(0, $regularPrice->average_purchase_price);
    }

    public function test_special_setting_falls_back_to_perdana_hpp()
    {
        $product = $this->createProduct('SPECIAL-FALLBACK-HPP-TEST', true, $this->topIt);
        $perdanaSale = $this->createSale($this->perdana);
        $this->createSaleDetail($perdanaSale, $product, 50000);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->topIt->id)
            ->first();

        $this->assertNotNull($price);
        $this->assertEquals(50000, $price->average_purchase_price);
    }

    public function test_non_special_business_cannot_default_hpp()
    {
        $product = $this->createProduct('NO-ARBITRARY-DEFAULT-TEST', true, $this->regular1);

        $regular2Sale = $this->createSale($this->regular2);
        $this->createSaleDetail($regular2Sale, $product, 60000);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->regular1->id)
            ->first();

        $this->assertNull($price);
    }

    public function test_repeated_write_is_idempotent()
    {
        $product = $this->createProduct('IDEMPOTENT-TEST', true, $this->perdana);
        $sale = $this->createSale($this->perdana);
        $this->createSaleDetail($sale, $product, 50000);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price1 = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->regular1->id)
            ->first();
        $timestamp1 = $price1->updated_at;

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price1->refresh();
        $this->assertEquals($timestamp1->timestamp, $price1->updated_at->timestamp);
    }

    private function createPurchase(Setting $setting, $date = null): Purchase
    {
        $purchaseDate = $date ?? now();
        return Purchase::create([
            'setting_id' => $setting->id,
            'date' => $purchaseDate,
            'due_date' => $purchaseDate->copy()->addDays(30),
            'reference' => 'PO-' . uniqid(),
            'supplier_id' => $this->supplierId,
            'status' => 'RECEIVED',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Bank Transfer',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
        ]);
    }

    private function createPurchaseDetail(Purchase $purchase, Product $product, $quantity = 1, $subTotal = 50000, $discount = 0): PurchaseDetail
    {
        return PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $subTotal / $quantity,
            'unit_price' => $subTotal / $quantity,
            'sub_total' => $subTotal,
            'product_discount_amount' => $discount,
            'product_tax_amount' => 0,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
        ]);
    }

    private function createReceivedNote(Purchase $purchase, $status = 'APPROVED', $approvedAt = null): ReceivedNote
    {
        return ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => $purchase->date,
            'location_id' => Location::where('setting_id', $purchase->setting_id)->first()->id,
            'status' => $status,
            'approved_at' => $approvedAt ?? now(),
            'approved_by' => 1,
        ]);
    }

    public function test_positive_non_source_average_preserved()
    {
        $product = $this->createProduct('PRESERVE-POSITIVE-TEST', true, $this->regular1);
        $perdanaSale = $this->createSale($this->perdana);
        $this->createSaleDetail($perdanaSale, $product, 40000);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
            'average_purchase_price' => 35000,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $this->assertEquals(35000, $existingPrice->average_purchase_price);
    }

    public function test_already_correct_row_not_written_on_repeated_run()
    {
        $product = $this->createProduct('ALREADY-CORRECT-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($sale, $product, 50000);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 50000,
        ]);

        $originalUpdatedAt = $existingPrice->updated_at;

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $this->assertEquals(50000, $existingPrice->average_purchase_price);
        $this->assertEquals($originalUpdatedAt->timestamp, $existingPrice->updated_at->timestamp);
    }

    public function test_missing_top_it_row_uses_top_it_hpp_not_baseline()
    {
        $product = $this->createProduct('MISSING-TOP-IT-ROW-TEST', true, $this->perdana);

        $perdanaSale = $this->createSale($this->perdana);
        $this->createSaleDetail($perdanaSale, $product, 10000);

        $topItSale = $this->createSale($this->topIt);
        $this->createSaleDetail($topItSale, $product, 12000);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $topItPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->topIt->id)
            ->first();

        $this->assertNotNull($topItPrice);
        $this->assertEquals(12000, $topItPrice->average_purchase_price);
    }

    public function test_missing_tiga_nusa_row_uses_tiga_nusa_hpp_not_baseline()
    {
        $product = $this->createProduct('MISSING-TIGA-NUSA-ROW-TEST', true, $this->perdana);

        $perdanaSale = $this->createSale($this->perdana);
        $this->createSaleDetail($perdanaSale, $product, 10000);

        $tigaSale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($tigaSale, $product, 15000);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $tigaPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();

        $this->assertNotNull($tigaPrice);
        $this->assertEquals(15000, $tigaPrice->average_purchase_price);
    }
}
