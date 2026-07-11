<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class NormalizeProductPurchasePricesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;
    private Setting $primarySetting;
    private Setting $secondarySetting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->primarySetting = $this->createSetting('primary');
        $this->secondarySetting = $this->createSetting('secondary');
    }

    private function createSetting(string $suffix): Setting
    {
        return Setting::create([
            'company_name' => 'Company ' . $suffix,
            'company_email' => $suffix . '@company.example',
            'company_phone' => '0800000000',
            'site_logo' => null,
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'left',
            'notification_email' => $suffix . '@notify.example',
            'footer_text' => 'Footer',
            'company_address' => 'Some Address',
        ]);
    }

    public function test_dry_run_does_not_modify_database()
    {
        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'setting_id' => $this->primarySetting->id,
            'stock_managed' => true,
            'product_quantity' => 0,
            'serial_number_required' => false,
            'product_stock_alert' => 0,
            'product_cost' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'profit_percentage' => 0,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'is_sold' => 1,
            'sale_price' => 0,
            'product_price' => 0,
            'last_purchase_price' => 0,
            'average_purchase_price' => 0,
        ]);

        $supplier = \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_phone' => '1234567890',
            'supplier_email' => 'supplier@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->primarySetting->id,
        ]);

        $purchase = Purchase::create([
            'date' => now()->subDay(),
            'due_date' => now(),
            'setting_id' => $this->primarySetting->id,
            'status' => Purchase::STATUS_RECEIVED,
            'supplier_id' => $supplier->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 150000,
            'due_amount' => 0,
            'paid_amount' => 150000,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'is_tax_included' => true,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'price' => 15000,
            'unit_price' => 15000,
            'sub_total' => 150000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $this->artisan('product:normalize-purchase-prices')
             ->expectsOutputToContain('Dry run complete. Run with --write to apply.')
             ->assertExitCode(0);

        $this->assertDatabaseMissing('product_prices', [
            'product_id' => $product->id,
        ]);
    }

    private function createProduct(array $attributes = [])
    {
        return Product::create(array_merge([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-' . uniqid(),
            'setting_id' => $this->primarySetting->id,
            'stock_managed' => true,
            'product_quantity' => 0,
            'serial_number_required' => false,
            'product_stock_alert' => 0,
            'product_cost' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'profit_percentage' => 0,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'is_sold' => 1,
            'sale_price' => 0,
            'product_price' => 0,
            'last_purchase_price' => 0,
            'average_purchase_price' => 0,
        ], $attributes));
    }

    private function createSupplier()
    {
        return \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Test Supplier ' . uniqid(),
            'supplier_phone' => '1234567890',
            'supplier_email' => 'supplier' . uniqid() . '@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->primarySetting->id,
        ]);
    }

    private function createPurchase(array $attributes = [])
    {
        $purchase = Purchase::create(array_merge([
            'date' => now()->subDay(),
            'due_date' => now(),
            'setting_id' => $this->primarySetting->id,
            'status' => Purchase::STATUS_RECEIVED,
            'supplier_id' => $this->createSupplier()->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 0,
            'due_amount' => 0,
            'paid_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'is_tax_included' => true,
        ], $attributes));

        if (isset($attributes['setting_id'])) {
            $purchase->setting_id = $attributes['setting_id'];
            $purchase->saveQuietly();
        }

        return $purchase;
    }

    private function createPurchaseDetail($purchase, $product, array $attributes = [])
    {
        $quantity = $attributes['quantity'] ?? 10;
        $price = $attributes['price'] ?? 1000;
        $subTotal = $attributes['sub_total'] ?? ($quantity * $price);

        return PurchaseDetail::create(array_merge([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => $quantity,
            'price' => $price,
            'unit_price' => $price,
            'sub_total' => $subTotal,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ], $attributes));
    }

    public function test_normalization_uses_approved_received_note_quantities_when_available()
    {
        $product = $this->createProduct();
        $purchase = $this->createPurchase();

        $detail = $this->createPurchaseDetail($purchase, $product, [
            'quantity' => 10, // ordered 10
            'price' => 15000,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => 1,
            'location_id' => 1,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail->id,
            'quantity_received' => 8, // actually received 8
        ]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $prices = ProductPrice::where('product_id', $product->id)->get();
        $this->assertCount(2, $prices); // for primary and secondary setting

        foreach ($prices as $price) {
            $this->assertEquals(15000, $price->average_purchase_price);
            $this->assertEquals(15000, $price->last_purchase_price);
        }
    }

    public function test_normalization_uses_purchase_detail_quantity_when_no_approved_received_notes()
    {
        $product = $this->createProduct();
        $purchase = $this->createPurchase();

        $detail = $this->createPurchaseDetail($purchase, $product, [
            'quantity' => 10,
            'price' => 20000,
        ]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $prices = ProductPrice::where('product_id', $product->id)->get();
        $this->assertCount(2, $prices);

        foreach ($prices as $price) {
            $this->assertEquals(20000, $price->average_purchase_price);
        }
    }

    public function test_exclusion_rules_for_ineligible_purchases_and_products()
    {
        $stockManagedProduct = $this->createProduct(['stock_managed' => true]);
        $nonStockManagedProduct = $this->createProduct(['stock_managed' => false]);

        // Archived purchase
        $archivedPurchase = $this->createPurchase(['archived_at' => now()]);
        $this->createPurchaseDetail($archivedPurchase, $stockManagedProduct, ['price' => 1000]);

        // Draft purchase
        $draftPurchase = $this->createPurchase(['status' => Purchase::STATUS_DRAFTED]);
        $this->createPurchaseDetail($draftPurchase, $stockManagedProduct, ['price' => 1000]);

        // Non-stock managed product in valid purchase
        $validPurchase = $this->createPurchase();
        $this->createPurchaseDetail($validPurchase, $nonStockManagedProduct, ['price' => 1000]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $this->assertDatabaseCount('product_prices', 0);
    }

    public function test_weighted_average_and_latest_price_calculations()
    {
        $product = $this->createProduct();

        $purchase1 = $this->createPurchase(['date' => now()->subDays(3)]);
        $this->createPurchaseDetail($purchase1, $product, [
            'quantity' => 10,
            'price' => 100,
        ]);

        $purchase2 = $this->createPurchase(['date' => now()->subDays(1)]);
        $this->createPurchaseDetail($purchase2, $product, [
            'quantity' => 20,
            'price' => 200,
        ]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $prices = ProductPrice::where('product_id', $product->id)->get();
        $this->assertCount(2, $prices);

        // (10*100 + 20*200) / 30 = (1000 + 4000) / 30 = 5000 / 30 = 166.67
        foreach ($prices as $price) {
            $this->assertEquals(166.67, $price->average_purchase_price);
            $this->assertEquals(200, $price->last_purchase_price);
        }
    }

    public function test_existing_rows_preserve_sales_fields_while_updating_purchase_prices()
    {
        $product = $this->createProduct();
        $tax1 = \Modules\Setting\Entities\Tax::create(['name' => 'Tax 1', 'value' => 10]);
        $tax2 = \Modules\Setting\Entities\Tax::create(['name' => 'Tax 2', 'value' => 5]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->primarySetting->id,
            'sale_price' => 500,
            'tier_1_price' => 480,
            'tier_2_price' => 450,
            'purchase_tax_id' => $tax1->id,
            'sale_tax_id' => $tax2->id,
            'average_purchase_price' => 50,
            'last_purchase_price' => 50,
        ]);

        $purchase = $this->createPurchase();
        $this->createPurchaseDetail($purchase, $product, [
            'quantity' => 10,
            'price' => 150,
        ]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->primarySetting->id)
            ->first();

        $this->assertEquals(150, $price->average_purchase_price);
        $this->assertEquals(150, $price->last_purchase_price);
        $this->assertEquals(500, $price->sale_price);
        $this->assertEquals(480, $price->tier_1_price);
        $this->assertEquals(450, $price->tier_2_price);
        $this->assertEquals($tax1->id, $price->purchase_tax_id);
        $this->assertEquals($tax2->id, $price->sale_tax_id);
    }

    public function test_missing_rows_copy_template_or_default_to_zero()
    {
        $product = $this->createProduct();
        $tax1 = \Modules\Setting\Entities\Tax::create(['name' => 'Tax 1', 'value' => 10]);
        $tax2 = \Modules\Setting\Entities\Tax::create(['name' => 'Tax 2', 'value' => 5]);

        // Create an existing row to act as template
        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->primarySetting->id,
            'sale_price' => 1000,
            'tier_1_price' => 900,
            'tier_2_price' => 0, // Should fallback to sale_price
            'purchase_tax_id' => $tax1->id,
            'sale_tax_id' => $tax2->id,
            'average_purchase_price' => 50,
            'last_purchase_price' => 50,
        ]);

        $purchase = $this->createPurchase();
        $this->createPurchaseDetail($purchase, $product, [
            'quantity' => 10,
            'price' => 200,
        ]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $secondaryPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->secondarySetting->id)
            ->first();

        $this->assertEquals(200, $secondaryPrice->average_purchase_price);
        $this->assertEquals(200, $secondaryPrice->last_purchase_price);

        // Copied from primary setting
        $this->assertEquals(1000, $secondaryPrice->sale_price);
        $this->assertEquals(900, $secondaryPrice->tier_1_price);
        $this->assertEquals(1000, $secondaryPrice->tier_2_price); // 0 fell back to 1000
        $this->assertEquals($tax1->id, $secondaryPrice->purchase_tax_id);
        $this->assertEquals($tax2->id, $secondaryPrice->sale_tax_id);
    }

    public function test_normalization_handles_received_partially_purchases()
    {
        $product = $this->createProduct();
        $purchase = $this->createPurchase(['status' => Purchase::STATUS_RECEIVED_PARTIALLY]);

        $this->createPurchaseDetail($purchase, $product, [
            'quantity' => 10,
            'price' => 20000,
        ]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)->first();
        $this->assertEquals(20000, $price->average_purchase_price);
    }

    public function test_normalization_skips_products_with_zero_eligible_quantity()
    {
        $product = $this->createProduct();
        $purchase = $this->createPurchase();

        $this->createPurchaseDetail($purchase, $product, [
            'quantity' => 0,
            'price' => 20000,
        ]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])
             ->expectsOutputToContain('Products Skipped: 1')
             ->assertExitCode(0);

        $this->assertDatabaseCount('product_prices', 0);
    }

    public function test_command_is_idempotent_on_second_run()
    {
        $product = $this->createProduct();
        $purchase = $this->createPurchase();
        $this->createPurchaseDetail($purchase, $product, [
            'quantity' => 10,
            'price' => 1000,
        ]);

        // First run creates rows
        $this->artisan('product:normalize-purchase-prices', ['--write' => true])
             ->expectsOutputToContain('Rows Created: 2')
             ->expectsOutputToContain('Rows Updated: 0')
             ->assertExitCode(0);

        // Second run updates nothing
        $this->artisan('product:normalize-purchase-prices', ['--write' => true])
             ->expectsOutputToContain('Rows Created: 0')
             ->expectsOutputToContain('Rows Updated: 0')
             ->expectsOutputToContain('Rows Unchanged: 2')
             ->assertExitCode(0);
    }

    public function test_latest_price_precedence_uses_approved_at_when_available()
    {
        $product = $this->createProduct();

        // Older purchase, but approved later
        $purchase1 = $this->createPurchase(['date' => now()->subDays(5)]);
        $detail1 = $this->createPurchaseDetail($purchase1, $product, ['quantity' => 10, 'price' => 100]);
        $rn1 = ReceivedNote::create(['po_id' => $purchase1->id, 'date' => now()->subDays(4), 'status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()->subDay(), 'approved_by' => 1, 'location_id' => 1]);
        ReceivedNoteDetail::create(['received_note_id' => $rn1->id, 'po_detail_id' => $detail1->id, 'quantity_received' => 10]);

        // Newer purchase, but approved earlier
        $purchase2 = $this->createPurchase(['date' => now()->subDays(3)]);
        $detail2 = $this->createPurchaseDetail($purchase2, $product, ['quantity' => 10, 'price' => 200]);
        $rn2 = ReceivedNote::create(['po_id' => $purchase2->id, 'date' => now()->subDays(3), 'status' => ReceivedNote::STATUS_APPROVED, 'approved_at' => now()->subDays(2), 'approved_by' => 1, 'location_id' => 1]);
        ReceivedNoteDetail::create(['received_note_id' => $rn2->id, 'po_detail_id' => $detail2->id, 'quantity_received' => 10]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)->first();

        // The one approved later (rn1) has a unit cost of 100, which should be the last_purchase_price
        $this->assertEquals(100, $price->last_purchase_price);
        $this->assertEquals(150, $price->average_purchase_price);
    }

    public function test_missing_rows_default_to_zero_when_no_template_exists()
    {
        $product = $this->createProduct();
        $purchase = $this->createPurchase();
        $this->createPurchaseDetail($purchase, $product, ['quantity' => 10, 'price' => 100]);

        // No template row exists initially

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $prices = ProductPrice::where('product_id', $product->id)->get();
        $this->assertCount(2, $prices);

        foreach ($prices as $price) {
            $this->assertEquals(100, $price->last_purchase_price);
            $this->assertEquals(100, $price->average_purchase_price);
            $this->assertEquals(0, $price->sale_price);
            $this->assertEquals(0, $price->tier_1_price);
            $this->assertEquals(0, $price->tier_2_price);
            $this->assertNull($price->purchase_tax_id);
            $this->assertNull($price->sale_tax_id);
        }
    }

    public function test_normalization_calculates_dpp_from_subtotal_and_tax()
    {
        $product = $this->createProduct();
        $purchase = $this->createPurchase();

        $this->createPurchaseDetail($purchase, $product, [
            'quantity' => 10,
            'price' => 11000,
            'unit_price' => 11000,
            'sub_total' => 110000,
            'product_tax_amount' => 10000, // 100,000 DPP + 10,000 tax
        ]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $price = ProductPrice::where('product_id', $product->id)->first();
        // DPP = (110000 - 10000) / 10 = 10000
        $this->assertEquals(10000, $price->average_purchase_price);
        $this->assertEquals(10000, $price->last_purchase_price);
    }

    public function test_normalization_isolates_buckets_for_special_companies_and_rest()
    {
        $product = $this->createProduct();

        $tigaNusa = $this->createSetting('tiga_nusa');
        $tigaNusa->company_name = 'CV TIGA NUSA COMPUTER';
        $tigaNusa->saveQuietly();

        $topIt = $this->createSetting('top_it');
        $topIt->company_name = 'CV TOP IT INTERNUSA';
        $topIt->saveQuietly();

        $other1 = $this->createSetting('other1'); // REST
        $other2 = $this->createSetting('other2'); // REST

        $purchase1 = $this->createPurchase(['setting_id' => $tigaNusa->id]);
        $this->createPurchaseDetail($purchase1, $product, ['quantity' => 10, 'price' => 100]);

        $purchase2 = $this->createPurchase(['setting_id' => $topIt->id]);
        $this->createPurchaseDetail($purchase2, $product, ['quantity' => 10, 'price' => 200]);

        $purchase3 = $this->createPurchase(['setting_id' => $other1->id]);
        $this->createPurchaseDetail($purchase3, $product, ['quantity' => 10, 'price' => 300]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $tigaNusaPrice = ProductPrice::where('product_id', $product->id)->where('setting_id', $tigaNusa->id)->first();
        $this->assertEquals(100, $tigaNusaPrice->average_purchase_price);

        $topItPrice = ProductPrice::where('product_id', $product->id)->where('setting_id', $topIt->id)->first();
        $this->assertEquals(200, $topItPrice->average_purchase_price);

        $other1Price = ProductPrice::where('product_id', $product->id)->where('setting_id', $other1->id)->first();
        $this->assertEquals(300, $other1Price->average_purchase_price);

        $other2Price = ProductPrice::where('product_id', $product->id)->where('setting_id', $other2->id)->first();
        $this->assertEquals(300, $other2Price->average_purchase_price);
    }

    public function test_special_companies_fall_back_to_rest_bucket_when_empty()
    {
        $product = $this->createProduct();

        $tigaNusa = $this->createSetting('tiga_nusa');
        $tigaNusa->company_name = 'CV TIGA NUSA COMPUTER';
        $tigaNusa->saveQuietly();

        $other1 = $this->createSetting('other1');

        $purchase3 = $this->createPurchase(['setting_id' => $other1->id]);
        $this->createPurchaseDetail($purchase3, $product, ['quantity' => 10, 'price' => 300]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $tigaNusaPrice = ProductPrice::where('product_id', $product->id)->where('setting_id', $tigaNusa->id)->first();
        $this->assertEquals(300, $tigaNusaPrice->average_purchase_price);

        $other1Price = ProductPrice::where('product_id', $product->id)->where('setting_id', $other1->id)->first();
        $this->assertEquals(300, $other1Price->average_purchase_price);
    }

    public function test_normalization_skips_row_creation_if_no_bucket_and_no_fallback()
    {
        $product = $this->createProduct();

        $tigaNusa = $this->createSetting('tiga_nusa');
        $tigaNusa->company_name = 'CV TIGA NUSA COMPUTER';
        $tigaNusa->saveQuietly();

        $other1 = $this->createSetting('other1');

        $purchase = $this->createPurchase(['setting_id' => $tigaNusa->id]);
        $this->createPurchaseDetail($purchase, $product, ['quantity' => 10, 'price' => 100]);

        $this->artisan('product:normalize-purchase-prices', ['--write' => true])->assertExitCode(0);

        $tigaNusaPrice = ProductPrice::where('product_id', $product->id)->where('setting_id', $tigaNusa->id)->first();
        $this->assertEquals(100, $tigaNusaPrice->average_purchase_price);

        $other1Price = ProductPrice::where('product_id', $product->id)->where('setting_id', $other1->id)->first();
        $this->assertNull($other1Price);
    }
}
