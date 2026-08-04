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

        $purchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $this->createPurchaseDetail($purchase, $product, 1, 48000, 0);
        $this->createReceivedNote($purchase);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();

        $this->assertNotNull($price);
        $this->assertEquals(50000, $price->average_purchase_price);
        $this->assertEquals(48000, $price->last_purchase_price);
    }

    public function test_same_date_tiebreak_by_sale_id_then_detail_id()
    {
        $product = $this->createProduct('TIEBREAK-TEST', true, $this->tigaNusa);
        $saleDate = now();

        $sale1 = $this->createSale($this->tigaNusa, $saleDate);
        $sale2 = $this->createSale($this->tigaNusa, $saleDate);

        $this->createSaleDetail($sale1, $product, 30000);
        $this->createSaleDetail($sale2, $product, 50000);

        $purchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $this->createPurchaseDetail($purchase, $product, 1, 48000, 0);
        $this->createReceivedNote($purchase);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();

        $this->assertNotNull($price);
        $this->assertEquals(50000, $price->average_purchase_price);
        $this->assertEquals(48000, $price->last_purchase_price);
    }

    public function test_special_setting_uses_own_bucket()
    {
        $product = $this->createProduct('SPECIAL-BUCKET-TEST', true, $this->tigaNusa);
        $tigaSale = $this->createSale($this->tigaNusa);
        $restSale = $this->createSale($this->regular1);

        $this->createSaleDetail($tigaSale, $product, 50000);
        $this->createSaleDetail($restSale, $product, 30000);

        $tigaPurchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $this->createPurchaseDetail($tigaPurchase, $product, 1, 48000, 0);
        $this->createReceivedNote($tigaPurchase);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $tigaPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)
            ->first();

        $this->assertNotNull($tigaPrice);
        $this->assertEquals(50000, $tigaPrice->average_purchase_price);
        $this->assertEquals(48000, $tigaPrice->last_purchase_price);
    }

    public function test_special_setting_fallback_to_perdana()
    {
        $product = $this->createProduct('FALLBACK-TEST', true, $this->tigaNusa);
        $perdanaSale = $this->createSale($this->perdana);

        $this->createSaleDetail($perdanaSale, $product, 30000);

        $perdanaPurchase = $this->createPurchase($this->perdana, now()->subDays(5));
        $this->createPurchaseDetail($perdanaPurchase, $product, 1, 28000, 0);
        $this->createReceivedNote($perdanaPurchase);

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
        $this->assertEquals(28000, $tigaPrice->last_purchase_price);
    }

    public function test_non_special_settings_use_perdana_hpp()
    {
        $product = $this->createProduct('PERDANA-HPP-TEST', true, $this->regular1);
        $perdanaSale = $this->createSale($this->perdana);

        $this->createSaleDetail($perdanaSale, $product, 40000);

        $perdanaPurchase = $this->createPurchase($this->perdana, now()->subDays(5));
        $this->createPurchaseDetail($perdanaPurchase, $product, 1, 38000, 0);
        $this->createReceivedNote($perdanaPurchase);

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
        $this->assertEquals(38000, $price1->last_purchase_price);
        $this->assertEquals(40000, $price2->average_purchase_price);
        $this->assertEquals(38000, $price2->last_purchase_price);
    }

    public function test_creates_missing_product_price_row()
    {
        $product = $this->createProduct('CREATE-ROW-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);

        $this->createSaleDetail($sale, $product, 45000);

        $purchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $this->createPurchaseDetail($purchase, $product, 1, 42000, 0);
        $this->createReceivedNote($purchase);

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
        $this->assertEquals(42000, $newPrice->last_purchase_price);
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

    public function test_received_purchase_sets_last_purchase_price_with_tax_inclusive_discount_excluded()
    {
        $product = $this->createProduct('LITERAL-PURCHASE-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($sale, $product, 50000);

        $purchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $this->createPurchaseDetail($purchase, $product, $quantity = 2, $subTotal = 100000, $discount = 10000);
        $this->createReceivedNote($purchase);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 40000,
            'last_purchase_price' => 0,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $this->assertEquals(50000, $existingPrice->average_purchase_price);
        $this->assertEquals(55000, $existingPrice->last_purchase_price);
    }

    public function test_latest_received_purchase_selected_by_approved_timestamp()
    {
        $product = $this->createProduct('APPROVED-TIME-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($sale, $product, 50000);

        $olderPurchase = $this->createPurchase($this->tigaNusa, now()->subDays(10));
        $this->createPurchaseDetail($olderPurchase, $product, 1, 30000, 0);
        $this->createReceivedNote($olderPurchase, 'APPROVED', now()->subDays(10));

        $newerPurchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $this->createPurchaseDetail($newerPurchase, $product, 1, 45000, 0);
        $this->createReceivedNote($newerPurchase, 'APPROVED', now()->subDays(1));

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 50000,
            'last_purchase_price' => 0,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $this->assertEquals(45000, $existingPrice->last_purchase_price);
    }

    public function test_ineligible_purchase_not_selected_as_last_price()
    {
        $product = $this->createProduct('INELIGIBLE-PURCHASE-TEST', true, $this->tigaNusa);

        $purchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $purchase->update(['status' => 'DRAFTED']);
        $this->createPurchaseDetail($purchase, $product, 1, 50000, 0);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 0,
            'last_purchase_price' => 25000,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $this->assertEquals(25000, $existingPrice->last_purchase_price);
    }

    public function test_own_purchase_takes_precedence_over_perdana()
    {
        $product = $this->createProduct('OWN-PRECEDENCE-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($sale, $product, 50000);

        $ownPurchase = $this->createPurchase($this->tigaNusa, now()->subDays(3));
        $this->createPurchaseDetail($ownPurchase, $product, 1, 45000, 0);
        $this->createReceivedNote($ownPurchase);

        $perdanaPurchase = $this->createPurchase($this->perdana, now()->subDays(2));
        $this->createPurchaseDetail($perdanaPurchase, $product, 1, 40000, 0);
        $this->createReceivedNote($perdanaPurchase);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 50000,
            'last_purchase_price' => 0,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $this->assertEquals(45000, $existingPrice->last_purchase_price);
    }

    public function test_perdana_supplies_missing_own_purchase()
    {
        $product = $this->createProduct('PERDANA-FALLBACK-TEST', true, $this->regular1);
        $sale = $this->createSale($this->perdana);
        $this->createSaleDetail($sale, $product, 50000);

        $perdanaPurchase = $this->createPurchase($this->perdana, now()->subDays(5));
        $this->createPurchaseDetail($perdanaPurchase, $product, 1, 35000, 0);
        $this->createReceivedNote($perdanaPurchase);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
            'average_purchase_price' => 50000,
            'last_purchase_price' => 0,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $this->assertEquals(35000, $existingPrice->last_purchase_price);
    }

    public function test_missing_literal_purchase_preserves_existing_price()
    {
        $product = $this->createProduct('NO-PURCHASE-TEST', true, $this->regular1);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
            'average_purchase_price' => 0,
            'last_purchase_price' => 28000,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $this->assertEquals(28000, $existingPrice->last_purchase_price);
    }

    public function test_missing_row_not_created_without_hpp_candidate()
    {
        $product = $this->createProduct('NO-ROW-CREATE-TEST', true, $this->regular1);

        $perdanaPurchase = $this->createPurchase($this->perdana, now()->subDays(5));
        $this->createPurchaseDetail($perdanaPurchase, $product, 1, 40000, 0);
        $this->createReceivedNote($perdanaPurchase);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->regular1->id)
            ->first();

        $this->assertNull($price);
    }

    public function test_special_setting_falls_back_to_perdana_hpp()
    {
        $product = $this->createProduct('SPECIAL-FALLBACK-HPP-TEST', true, $this->topIt);
        $perdanaSale = $this->createSale($this->perdana);
        $this->createSaleDetail($perdanaSale, $product, 50000);

        $perdanaPurchase = $this->createPurchase($this->perdana, now()->subDays(5));
        $this->createPurchaseDetail($perdanaPurchase, $product, 1, 40000, 0);
        $this->createReceivedNote($perdanaPurchase);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->topIt->id)
            ->first();

        $this->assertNotNull($price);
        $this->assertEquals(50000, $price->average_purchase_price);
        $this->assertEquals(40000, $price->last_purchase_price);
    }

    public function test_non_special_business_cannot_default_hpp()
    {
        $product = $this->createProduct('NO-ARBITRARY-DEFAULT-TEST', true, $this->regular1);

        $regular2Sale = $this->createSale($this->regular2);
        $this->createSaleDetail($regular2Sale, $product, 60000);

        $regular2Purchase = $this->createPurchase($this->regular2, now()->subDays(5));
        $this->createPurchaseDetail($regular2Purchase, $product, 1, 55000, 0);
        $this->createReceivedNote($regular2Purchase);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->regular1->id)
            ->first();

        $this->assertNull($price);
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

    public function test_fractional_purchase_quantity_is_eligible_and_produces_expected_price()
    {
        $product = $this->createProduct('FRACTIONAL-QTY-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($sale, $product, 50000);

        $purchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $this->createPurchaseDetail($purchase, $product, 0.5, 25000, 0);
        $this->createReceivedNote($purchase);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 50000,
            'last_purchase_price' => 0,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $expectedUnitPrice = (25000 + 0) / 0.5;
        $this->assertEquals(50000, $existingPrice->average_purchase_price);
        $this->assertEquals($expectedUnitPrice, $existingPrice->last_purchase_price);
    }

    public function test_partial_receipt_eligibility_is_line_specific()
    {
        $productA = $this->createProduct('PARTIAL-PRODUCT-A', true, $this->tigaNusa);
        $productB = $this->createProduct('PARTIAL-PRODUCT-B', true, $this->tigaNusa);

        $saleA = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($saleA, $productA, 40000);

        $saleB = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($saleB, $productB, 40000);

        $purchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $purchase->update(['status' => 'RECEIVED PARTIALLY']);

        $detailA = $this->createPurchaseDetail($purchase, $productA, 1, 35000, 0);
        $detailB = $this->createPurchaseDetail($purchase, $productB, 1, 35000, 0);

        $receivedNote = $this->createReceivedNote($purchase, 'APPROVED', now()->subDays(2));

        $this->createReceivedNoteDetail($receivedNote, $detailA, 1);

        $priceA = ProductPrice::create([
            'product_id' => $productA->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 40000,
            'last_purchase_price' => 0,
        ]);

        $priceB = ProductPrice::create([
            'product_id' => $productB->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 40000,
            'last_purchase_price' => 0,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $priceA->refresh();
        $priceB->refresh();

        $this->assertEquals(35000, $priceA->last_purchase_price);
        $this->assertEquals(0, $priceB->last_purchase_price);
    }

    public function test_multiple_approved_receipts_choose_latest_approved_at()
    {
        $product = $this->createProduct('MULTIPLE-RECEIPTS-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($sale, $product, 50000);

        $purchase = $this->createPurchase($this->tigaNusa, now()->subDays(10));
        $purchase->update(['status' => 'RECEIVED PARTIALLY']);

        $detail = $this->createPurchaseDetail($purchase, $product, 2, 100000, 0);

        $olderReceipt = $this->createReceivedNote($purchase, 'APPROVED', now()->subDays(8));
        $this->createReceivedNoteDetail($olderReceipt, $detail, 1);

        $newerReceipt = $this->createReceivedNote($purchase, 'APPROVED', now()->subDays(3));
        $this->createReceivedNoteDetail($newerReceipt, $detail, 1);

        $priceRow = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 50000,
            'last_purchase_price' => 0,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $priceRow->refresh();
        $expectedUnitPrice = (100000 + 0) / 2;
        $this->assertEquals($expectedUnitPrice, $priceRow->last_purchase_price);
    }

    public function test_existing_price_row_with_literal_purchase_but_no_hpp_candidate_remains_unchanged()
    {
        $product = $this->createProduct('NO-HPP-CANDIDATE-TEST', true, $this->regular1);

        $purchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $this->createPurchaseDetail($purchase, $product, 1, 40000, 0);
        $this->createReceivedNote($purchase);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->regular1->id,
            'average_purchase_price' => 0,
            'last_purchase_price' => 30000,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $this->assertEquals(0, $existingPrice->average_purchase_price);
        $this->assertEquals(30000, $existingPrice->last_purchase_price);
    }

    public function test_already_correct_row_is_reported_unchanged_and_not_written()
    {
        $product = $this->createProduct('ALREADY-CORRECT-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($sale, $product, 50000);

        $purchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $this->createPurchaseDetail($purchase, $product, 1, 48000, 0);
        $this->createReceivedNote($purchase);

        $existingPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 50000,
            'last_purchase_price' => 48000,
        ]);

        $originalUpdatedAt = $existingPrice->updated_at;

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $existingPrice->refresh();
        $this->assertEquals(50000, $existingPrice->average_purchase_price);
        $this->assertEquals(48000, $existingPrice->last_purchase_price);
        $this->assertEquals($originalUpdatedAt->timestamp, $existingPrice->updated_at->timestamp);
    }

    private function createReceivedNoteDetail($receivedNote, $purchaseDetail, $quantity)
    {
        return \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => $quantity,
        ]);
    }

    public function test_partial_receipt_older_id_newer_approval_timestamp_uses_newer_approval()
    {
        $product = $this->createProduct('PARTIAL-ID-VS-TIMESTAMP-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($sale, $product, 50000);

        $olderPurchase = $this->createPurchase($this->tigaNusa, now()->subDays(10));
        $olderPurchase->update(['status' => 'RECEIVED PARTIALLY']);
        $olderDetail = $this->createPurchaseDetail($olderPurchase, $product, 1, 40000, 0);

        $newerApprovalReceipt = $this->createReceivedNote($olderPurchase, 'APPROVED', now()->subDays(2));
        $this->createReceivedNoteDetail($newerApprovalReceipt, $olderDetail, 1);

        $olderPurchase2 = $this->createPurchase($this->tigaNusa, now()->subDays(10));
        $olderPurchase2->update(['status' => 'RECEIVED PARTIALLY']);
        $olderDetail2 = $this->createPurchaseDetail($olderPurchase2, $product, 1, 35000, 0);

        $olderApprovalReceipt = $this->createReceivedNote($olderPurchase2, 'APPROVED', now()->subDays(5));
        $this->createReceivedNoteDetail($olderApprovalReceipt, $olderDetail2, 1);

        $priceRow = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 50000,
            'last_purchase_price' => 0,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $priceRow->refresh();
        $this->assertEquals(40000, $priceRow->last_purchase_price);
    }

    public function test_partial_receipt_competing_purchases_same_product_chooses_latest_approval()
    {
        $product = $this->createProduct('COMPETING-PARTIALS-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($sale, $product, 50000);

        $olderPurchase = $this->createPurchase($this->tigaNusa, now()->subDays(10));
        $olderPurchase->update(['status' => 'RECEIVED PARTIALLY']);
        $olderDetail = $this->createPurchaseDetail($olderPurchase, $product, 1, 40000, 0);

        $olderReceipt = $this->createReceivedNote($olderPurchase, 'APPROVED', now()->subDays(8));
        $this->createReceivedNoteDetail($olderReceipt, $olderDetail, 1);

        $newerPurchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $newerPurchase->update(['status' => 'RECEIVED PARTIALLY']);
        $newerDetail = $this->createPurchaseDetail($newerPurchase, $product, 1, 45000, 0);

        $newerReceipt = $this->createReceivedNote($newerPurchase, 'APPROVED', now()->subDays(2));
        $this->createReceivedNoteDetail($newerReceipt, $newerDetail, 1);

        $priceRow = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 50000,
            'last_purchase_price' => 0,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $priceRow->refresh();
        $this->assertEquals(45000, $priceRow->last_purchase_price);
    }

    public function test_partial_receipt_zero_quantity_received_detail_is_ignored()
    {
        $product = $this->createProduct('ZERO-QTY-DETAIL-TEST', true, $this->tigaNusa);
        $sale = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($sale, $product, 50000);

        $purchase = $this->createPurchase($this->tigaNusa, now()->subDays(10));
        $purchase->update(['status' => 'RECEIVED PARTIALLY']);
        $detail = $this->createPurchaseDetail($purchase, $product, 2, 100000, 0);

        $zeroReceipt = $this->createReceivedNote($purchase, 'APPROVED', now()->subDays(1));
        $this->createReceivedNoteDetail($zeroReceipt, $detail, 0);

        $validReceipt = $this->createReceivedNote($purchase, 'APPROVED', now()->subDays(5));
        $this->createReceivedNoteDetail($validReceipt, $detail, 2);

        $priceRow = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 50000,
            'last_purchase_price' => 0,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $priceRow->refresh();
        $expectedUnitPrice = (100000 + 0) / 2;
        $this->assertEquals($expectedUnitPrice, $priceRow->last_purchase_price);
    }

    public function test_partial_receipt_line_specific_test_still_passes()
    {
        $productA = $this->createProduct('RETEST-PARTIAL-PRODUCT-A', true, $this->tigaNusa);
        $productB = $this->createProduct('RETEST-PARTIAL-PRODUCT-B', true, $this->tigaNusa);

        $saleA = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($saleA, $productA, 40000);

        $saleB = $this->createSale($this->tigaNusa);
        $this->createSaleDetail($saleB, $productB, 40000);

        $purchase = $this->createPurchase($this->tigaNusa, now()->subDays(5));
        $purchase->update(['status' => 'RECEIVED PARTIALLY']);

        $detailA = $this->createPurchaseDetail($purchase, $productA, 1, 35000, 0);
        $detailB = $this->createPurchaseDetail($purchase, $productB, 1, 35000, 0);

        $receivedNote = $this->createReceivedNote($purchase, 'APPROVED', now()->subDays(2));

        $this->createReceivedNoteDetail($receivedNote, $detailA, 1);

        $priceA = ProductPrice::create([
            'product_id' => $productA->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 40000,
            'last_purchase_price' => 0,
        ]);

        $priceB = ProductPrice::create([
            'product_id' => $productB->id,
            'setting_id' => $this->tigaNusa->id,
            'average_purchase_price' => 40000,
            'last_purchase_price' => 0,
        ]);

        $this->artisan('product:seed-average-cost-from-sales-hpp --write')
            ->assertExitCode(0);

        $priceA->refresh();
        $priceB->refresh();

        $this->assertEquals(35000, $priceA->last_purchase_price);
        $this->assertEquals(0, $priceB->last_purchase_price);
    }
}
