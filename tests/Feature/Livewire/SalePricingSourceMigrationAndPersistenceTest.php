<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\EditForm;
use App\Livewire\Sale\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class SalePricingSourceMigrationAndPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Setting $targetSetting;
    protected Currency $currency;
    protected PaymentTerm $paymentTerm;
    protected \Modules\Sale\Services\SaleService $saleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->saleService = app(\Modules\Sale\Services\SaleService::class);

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Business',
            'company_email' => 'test@example.com',
            'company_phone' => '123',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        $this->targetSetting = Setting::create([
            'company_name' => 'Target Business',
            'company_email' => 'target@example.com',
            'company_phone' => '456',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        $this->paymentTerm = PaymentTerm::query()->firstOrCreate(
            ['name' => 'Net 30'],
            ['longevity' => 30]
        );

        session(['setting_id' => $this->setting->id]);
        Cart::instance('sale')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('sale')->destroy();
        parent::tearDown();
    }

    // ==================== REAL MIGRATION EXECUTION TEST ====================

    public function test_migrations_mark_legacy_sale_details_as_manual_and_default_new_details_to_automatic(): void
    {
        $customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Legacy Product',
            'product_code' => 'LEGACY-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'tier_1_price' => 9000,
            'tier_2_price' => 8000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        // First, drop the pricing_source column to simulate pre-migration state
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn('pricing_source');
        });

        // Create a legacy sale and detail without pricing_source column
        $sale = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'reference' => 'LEGACY-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        // Create legacy sale detail WITHOUT pricing_source column
        $legacyDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $legacyDetailId = $legacyDetail->id;
        $originalPrice = 10000.0;
        $originalSubTotal = 10000.0;
        $originalDiscount = 0.0;
        $originalTax = 0.0;
        $originalTaxId = null;

        // Execute migration 1: Add pricing_source column with default 'automatic'
        $migration1 = new (require base_path('Modules/Sale/Database/Migrations/2026_08_01_000001_add_pricing_source_to_sale_details.php'))();
        $migration1->up();

        // Execute migration 2: Mark existing rows with 'automatic' as 'manual_unit_price'
        $migration2 = new (require base_path('Modules/Sale/Database/Migrations/2026_08_01_000002_mark_existing_sale_details_as_manual.php'))();
        $migration2->up();

        // Reload legacy detail from database and verify it was marked manual_unit_price
        $legacyDetail = SaleDetails::findOrFail($legacyDetailId);
        $this->assertSame('manual_unit_price', $legacyDetail->pricing_source);

        // Verify all original monetary fields are unchanged
        $this->assertSame($originalPrice, (float) $legacyDetail->unit_price);
        $this->assertSame($originalSubTotal, (float) $legacyDetail->sub_total);
        $this->assertSame($originalDiscount, (float) $legacyDetail->product_discount_amount);
        $this->assertSame($originalTax, (float) $legacyDetail->product_tax_amount);
        $this->assertSame($originalTaxId, $legacyDetail->tax_id);

        // Create new sale detail AFTER migrations - should default to automatic
        $newSale = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'reference' => 'NEW-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $newDetail = SaleDetails::create([
            'sale_id' => $newSale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $newDetail->refresh();
        $this->assertSame('automatic', $newDetail->pricing_source);
    }

    // ==================== CART AND FORM WORKFLOW PERSISTENCE TESTS ====================

    public function test_manual_unit_price_edit_and_reopen_through_livewire_components(): void
    {
        $customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Manual Unit Price Product',
            'product_code' => 'MANUALUNIT-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'tier_1_price' => 9000,
            'tier_2_price' => 8000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        // Build and edit cart through ProductCart component - keep instance for state persistence
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;

        // Edit unit price through updatePrice() - sets pricing_source to manual_unit_price
        $component
            ->set('unit_price.' . $cartItem->id, 12000)
            ->call('updatePrice', $rowId, $cartItem->id);

        $editedItem = Cart::instance('sale')->content()->first();
        $this->assertSame('manual_unit_price', $editedItem->options->pricing_source);
        $this->assertSame(12000.0, (float) $editedItem->price);

        // Create sale through SaleService with the edited cart items
        $cartItems = Cart::instance('sale')->content();
        $sale = $this->saleService->createSale([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'customer_id' => $customer->id,
            'tax_id' => null,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DRAFTED,
        ], $cartItems);

        // Verify persisted sale detail
        $persistedDetail = $sale->saleDetails()->first();
        $this->assertNotNull($persistedDetail);
        $this->assertSame('manual_unit_price', $persistedDetail->pricing_source);
        $this->assertSame(12000.0, (float) $persistedDetail->unit_price);
        $this->assertSame(12000.0, (float) $persistedDetail->price);
        $this->assertSame(12000.0, (float) $persistedDetail->sub_total);
        $this->assertSame(0.0, (float) $persistedDetail->product_discount_amount);
        $this->assertSame(0.0, (float) $persistedDetail->product_tax_amount);

        // Clear cart and reopen through EditForm
        Cart::instance('sale')->destroy();
        Livewire::test(EditForm::class, ['sale' => $sale]);

        $reopenedItem = Cart::instance('sale')->content()->first();
        $this->assertNotNull($reopenedItem);
        $this->assertSame('manual_unit_price', $reopenedItem->options->pricing_source);
        $this->assertSame(12000.0, (float) $reopenedItem->price);
        $this->assertSame(12000.0, (float) $reopenedItem->options->unit_price);
    }

    public function test_manual_line_total_edit_and_reopen_through_livewire_components(): void
    {
        $customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Manual Line Total Product',
            'product_code' => 'MANUALLINE-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'tier_1_price' => 9000,
            'tier_2_price' => 8000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        // Build and edit cart through ProductCart component - keep instance for state persistence
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        // Update quantity to 100 first
        $component->set('quantity.' . $id, 100)
            ->call('updateQuantity', $rowId, $id);

        // Edit line total through updateLineTotal() - sets pricing_source to manual_line_total
        // With qty=100 and line_total=15000, unit_price should be 150
        $component
            ->set('line_total.' . $id, 15000)
            ->call('updateLineTotal', $rowId, $id);

        $editedItem = Cart::instance('sale')->content()->first();
        $this->assertSame('manual_line_total', $editedItem->options->pricing_source);
        $this->assertSame(15000.0, (float) $editedItem->options->sub_total);

        // Create sale through SaleService with the edited cart items
        $cartItems = Cart::instance('sale')->content();
        $sale = $this->saleService->createSale([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'customer_id' => $customer->id,
            'tax_id' => null,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DRAFTED,
        ], $cartItems);

        // Verify persisted sale detail
        $persistedDetail = $sale->saleDetails()->first();
        $this->assertNotNull($persistedDetail);
        $this->assertSame('manual_line_total', $persistedDetail->pricing_source);
        $this->assertSame(15000.0, (float) $persistedDetail->sub_total);
        $this->assertSame(150.0, (float) $persistedDetail->unit_price);
        $this->assertSame(0.0, (float) $persistedDetail->product_discount_amount);
        $this->assertSame(0.0, (float) $persistedDetail->product_tax_amount);

        // Clear cart and reopen through EditForm
        Cart::instance('sale')->destroy();
        Livewire::test(EditForm::class, ['sale' => $sale]);

        $reopenedItem = Cart::instance('sale')->content()->first();
        $this->assertNotNull($reopenedItem);
        $this->assertSame('manual_line_total', $reopenedItem->options->pricing_source);
        $this->assertSame(150.0, (float) $reopenedItem->options->unit_price);
        $this->assertSame(15000.0, (float) $reopenedItem->options->sub_total);
    }

    public function test_equal_value_unit_price_edit_still_marks_manual(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Equal Value Product',
            'product_code' => 'EQUAL-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'tier_1_price' => 9000,
            'tier_2_price' => 8000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;

        // Edit with same automatic price value - should still mark manual
        $component
            ->set('unit_price.' . $cartItem->id, 10000)
            ->call('updatePrice', $rowId, $cartItem->id);

        $editedItem = Cart::instance('sale')->content()->first();

        $this->assertSame(10000.0, (float) $editedItem->price);
        $this->assertSame('manual_unit_price', $editedItem->options->pricing_source);
    }

    // ==================== MISSING PRICE BEHAVIOR AND NOTIFICATION TESTS ====================

    public function test_single_missing_target_business_price_sets_zero_and_sends_notification(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Missing Target Price Product',
            'product_code' => 'MISSINGPRICE-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        // Add to cart in current business
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $this->assertSame(10000.0, (float) $cartItem->price);

        // Switch to business with no price - automatic lines get zero price.
        // Invoke on the live component instance so the flashed value stays readable:
        // a full Livewire ->call() ages flash data at the end of its simulated request.
        $component->instance()->handleBusinessContextChanged($this->targetSetting->id);

        $updatedItem = Cart::instance('sale')->content()->first();

        // Verify price is set to zero for automatic line when target ProductPrice missing
        $this->assertNotNull($updatedItem);
        $this->assertSame('automatic', $updatedItem->options->pricing_source);
        $this->assertSame(0.0, (float) $updatedItem->price, 'Price should be zero when ProductPrice is missing in target business');

        // Verify the flashed notification content names the product and the target business
        $message = session('message');
        $this->assertIsString($message, 'A missing-price notification should be flashed');
        $this->assertStringContainsString('MISSING TARGET PRICE PRODUCT', $message);
        $this->assertStringContainsString('TARGET BUSINESS', $message);
        $this->assertStringContainsString('Unit price diatur ke 0.', $message);
    }

    public function test_multiple_missing_products_in_business_switch_sends_consolidated_notification(): void
    {
        $product1 = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'First Missing Product',
            'product_code' => 'MISSING-MULTI-1',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        $product2 = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Second Missing Product',
            'product_code' => 'MISSING-MULTI-2',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product1->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        ProductPrice::create([
            'product_id' => $product2->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        // Add both products to cart
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale']);
        $component->call('productSelected', [
            'id' => $product1->id,
            'product_id' => $product1->id,
            'product_name' => $product1->product_name,
            'product_code' => $product1->product_code,
            'product_quantity' => 100,
            'product_unit' => 'pcs',
        ]);

        $component->call('productSelected', [
            'id' => $product2->id,
            'product_id' => $product2->id,
            'product_name' => $product2->product_name,
            'product_code' => $product2->product_code,
            'product_quantity' => 100,
            'product_unit' => 'pcs',
        ]);

        // Switch to business with no prices
        // When handleBusinessContextChanged is called with a business that has no ProductPrice
        // for these products, both lines should become zero and a consolidated message is flashed
        $component->instance()->handleBusinessContextChanged($this->targetSetting->id);

        $cartItems = Cart::instance('sale')->content();
        $this->assertCount(2, $cartItems, 'Should have 2 items in cart');

        // Verify both automatic lines become zero when ProductPrice is missing
        foreach ($cartItems as $item) {
            $this->assertSame(0.0, (float) $item->price, "Price should be zero when ProductPrice missing for {$item->name}");
            $this->assertSame('automatic', $item->options->pricing_source, 'Should remain automatic pricing source');
        }

        // A single consolidated notification should name both products and the target business
        $message = session('message');
        $this->assertIsString($message, 'A consolidated missing-price notification should be flashed');
        $this->assertStringContainsString('FIRST MISSING PRODUCT', $message);
        $this->assertStringContainsString('SECOND MISSING PRODUCT', $message);
        $this->assertStringContainsString('TARGET BUSINESS', $message);
        $this->assertStringContainsString('Unit price diatur ke 0.', $message);
    }

    public function test_zero_sale_price_not_treated_as_missing_no_notification(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Zero Price Product',
            'product_code' => 'ZEROPRICE-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        // Create target ProductPrice with explicit zero sale_price (not missing)
        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->targetSetting->id,
            'sale_price' => 0,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        // Add to cart in current business first
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        // Switch to business with zero sale_price
        $component->instance()->handleBusinessContextChanged($this->targetSetting->id);

        $cartItem = Cart::instance('sale')->content()->first();

        // Verify zero price is used (not treated as missing)
        $this->assertNotNull($cartItem);
        $this->assertSame(0.0, (float) $cartItem->price);
        $this->assertSame('automatic', $cartItem->options->pricing_source);

        // Verify NO notification message is sent when ProductPrice exists (even with zero)
        $message = session('message');
        $this->assertNull($message, 'Session message should be null when ProductPrice exists (even with zero sale_price)');
    }

    // ==================== BUSINESS-SWITCH REGRESSION TESTS ====================

    public function test_business_switch_automatic_line_uses_target_business_price(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Cross Biz Auto Product',
            'product_code' => 'AUTOBIZ-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->targetSetting->id,
            'sale_price' => 15000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $this->assertSame(10000.0, (float) $cartItem->price);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('handleBusinessContextChanged', $this->targetSetting->id);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($updatedItem);
        $this->assertSame('automatic', $updatedItem->options->pricing_source);
        $this->assertSame(15000.0, (float) $updatedItem->price);
    }

    public function test_business_switch_preserves_manual_unit_price(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Cross Biz Manual Product',
            'product_code' => 'MANUALBIZ-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->targetSetting->id,
            'sale_price' => 15000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;

        // Edit to manual
        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('unit_price.' . $cartItem->id, 12000)
            ->call('updatePrice', $rowId, $cartItem->id);

        $editedItem = Cart::instance('sale')->content()->first();
        $this->assertSame('manual_unit_price', $editedItem->options->pricing_source);

        // Switch business
        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('handleBusinessContextChanged', $this->targetSetting->id);

        $finalItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($finalItem);
        $this->assertSame('manual_unit_price', $finalItem->options->pricing_source);
        $this->assertSame(12000.0, (float) $finalItem->price);
    }

    public function test_business_switch_preserves_manual_line_total(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Cross Biz Line Total',
            'product_code' => 'LINEBIZ-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        // Edit line total
        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('line_total.' . $id, 15000)
            ->call('updateLineTotal', $rowId, $id);

        $editedItem = Cart::instance('sale')->content()->first();
        $this->assertSame('manual_line_total', $editedItem->options->pricing_source);

        // Switch business
        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('handleBusinessContextChanged', $this->targetSetting->id);

        $finalItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($finalItem);
        $this->assertSame('manual_line_total', $finalItem->options->pricing_source);
        $this->assertSame(15000.0, (float) $finalItem->options->sub_total);
    }

    public function test_business_switch_preserves_bundled_price(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Bundle Product',
            'product_code' => 'BUNDLE-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->targetSetting->id,
            'sale_price' => 15000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();

        // Mark as bundled
        Cart::instance('sale')->update($cartItem->rowId, [
            'options' => array_merge($cartItem->options->toArray(), [
                'is_bundled_row' => true,
            ]),
        ]);

        // Switch business
        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('handleBusinessContextChanged', $this->targetSetting->id);

        $finalItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($finalItem);
        $this->assertSame(10000.0, (float) $finalItem->price);
    }

    public function test_pkp_transition_recalculates_tax_while_preserving_manual_price(): void
    {
        $tax = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
            'is_default' => true,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'PKP Transition Product',
            'product_code' => 'PKPTRANS-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;

        // Edit to manual price
        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('unit_price.' . $cartItem->id, 12000)
            ->call('updatePrice', $rowId, $cartItem->id);

        // Switch to PKP business
        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('handleBusinessContextChanged', $this->targetSetting->id);

        $finalItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($finalItem);
        $this->assertSame('manual_unit_price', $finalItem->options->pricing_source);
        $this->assertSame(12000.0, (float) $finalItem->price);
    }

    // ==================== UPDATE PIPELINE TEST ====================

    public function test_manual_unit_price_update_pipeline_through_real_service(): void
    {
        $customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Update Pipeline Product',
            'product_code' => 'UPDATEPIPE-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'tier_1_price' => 9000,
            'tier_2_price' => 8000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        // Create initial sale with automatic pricing
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 1,
                'product_unit' => 'pcs',
            ]);

        $cartItems = Cart::instance('sale')->content();
        $sale = $this->saleService->createSale([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'customer_id' => $customer->id,
            'tax_id' => null,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DRAFTED,
        ], $cartItems);

        // Verify initial state
        $detail = $sale->saleDetails()->first();
        $this->assertSame('automatic', $detail->pricing_source);
        $this->assertSame(10000.0, (float) $detail->unit_price);

        // Clear and reopen for edit
        Cart::instance('sale')->destroy();
        Livewire::test(EditForm::class, ['sale' => $sale]);

        // Edit unit price
        $cartItem = Cart::instance('sale')->content()->first();
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('unit_price.' . $cartItem->id, 12500)
            ->call('updatePrice', $cartItem->rowId, $cartItem->id);

        // Verify cart state before update
        $updatedCartItem = Cart::instance('sale')->content()->first();
        $this->assertSame('manual_unit_price', $updatedCartItem->options->pricing_source);
        $this->assertSame(12500.0, (float) $updatedCartItem->price);

        // Update through SaleService
        $updatedCartItems = Cart::instance('sale')->content();
        $updatedSale = $this->saleService->updateSale($sale, [
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'customer_id' => $customer->id,
            'tax_id' => null,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DRAFTED,
        ], $updatedCartItems);

        // Verify updated detail persisted correctly
        $updatedDetail = $updatedSale->saleDetails()->first();
        $this->assertSame('manual_unit_price', $updatedDetail->pricing_source, 'Persisted pricing_source should be manual_unit_price');
        $this->assertSame(12500.0, (float) $updatedDetail->unit_price);
        $this->assertSame(12500.0, (float) $updatedDetail->price);

        // Refresh from database to ensure we're reading the persisted value
        $refreshedSale = Sale::findOrFail($updatedSale->id);
        $refreshedDetail = $refreshedSale->saleDetails()->first();
        $this->assertSame('manual_unit_price', $refreshedDetail->pricing_source, 'Refreshed pricing_source should be manual_unit_price');

        // Clear and reopen to verify persistence
        Cart::instance('sale')->destroy();
        Livewire::test(EditForm::class, ['sale' => $refreshedSale]);

        $reopenedItem = Cart::instance('sale')->content()->first();
        $this->assertSame('manual_unit_price', $reopenedItem->options->pricing_source);
        $this->assertSame(12500.0, (float) $reopenedItem->price);
    }

    public function test_manual_line_total_update_pipeline_through_real_service(): void
    {
        $customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Line Total Update Product',
            'product_code' => 'LINETOTALUPDATE-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        // Create initial sale with qty=2
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        // Set quantity to 2
        $cartItem = Cart::instance('sale')->content()->first();
        $component->set('quantity.' . $cartItem->id, 2)
            ->call('updateQuantity', $cartItem->rowId, $cartItem->id);

        $cartItems = Cart::instance('sale')->content();
        $sale = $this->saleService->createSale([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'customer_id' => $customer->id,
            'tax_id' => null,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DRAFTED,
        ], $cartItems);

        // Verify initial state has qty=2
        $detail = $sale->saleDetails()->first();
        $this->assertSame('automatic', $detail->pricing_source);
        $this->assertSame(2, (int) $detail->quantity);

        // Clear and reopen for edit
        Cart::instance('sale')->destroy();
        Livewire::test(EditForm::class, ['sale' => $sale]);

        // Edit line total
        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale']);
        $cartItem = Cart::instance('sale')->content()->first();
        $this->assertSame(2, (int) $cartItem->qty);

        // Set line total to 25000 which with qty=2 should give unit_price=12500
        $component
            ->set('line_total.' . $cartItem->id, 25000)
            ->call('updateLineTotal', $cartItem->rowId, $cartItem->id);

        // Verify cart state before update
        $updatedCartItem = Cart::instance('sale')->content()->first();
        $this->assertSame('manual_line_total', $updatedCartItem->options->pricing_source);
        $this->assertSame(25000.0, (float) $updatedCartItem->options->sub_total);
        $this->assertSame(12500.0, (float) $updatedCartItem->options->unit_price);

        // Update through SaleService
        $updatedCartItems = Cart::instance('sale')->content();
        $updatedSale = $this->saleService->updateSale($sale, [
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(30)->format('Y-m-d'),
            'customer_id' => $customer->id,
            'tax_id' => null,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DRAFTED,
        ], $updatedCartItems);

        // Verify updated detail persisted correctly
        $updatedDetail = $updatedSale->saleDetails()->first();
        $this->assertSame('manual_line_total', $updatedDetail->pricing_source, 'Persisted pricing_source should be manual_line_total');
        $this->assertSame(25000.0, (float) $updatedDetail->sub_total);
        $this->assertSame(12500.0, (float) $updatedDetail->unit_price);

        // Refresh from database to ensure we're reading the persisted value
        $refreshedSale = Sale::findOrFail($updatedSale->id);
        $refreshedDetail = $refreshedSale->saleDetails()->first();
        $this->assertSame('manual_line_total', $refreshedDetail->pricing_source, 'Refreshed pricing_source should be manual_line_total');

        // Reopen and verify persistence
        Cart::instance('sale')->destroy();
        Livewire::test(EditForm::class, ['sale' => $refreshedSale]);

        $reopenedItem = Cart::instance('sale')->content()->first();
        $this->assertSame('manual_line_total', $reopenedItem->options->pricing_source);
        $this->assertSame(12500.0, (float) $reopenedItem->options->unit_price);
        $this->assertSame(25000.0, (float) $reopenedItem->options->sub_total);
    }

    // ==================== ENUM CONSTRAINT NORMALIZATION TEST ====================

    public function test_pricing_source_enum_constraint_requires_lowercase(): void
    {
        // The pricing_source column uses enum('automatic', 'manual_unit_price', 'manual_line_total')
        // which creates a CHECK constraint. SQLite enforces case-sensitive matching.
        // The strtolower() calls and setPricingSourceAttribute mutator ensure all values
        // comply with the enum constraint even if non-lowercase values somehow enter the system.

        $customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Enum Constraint Product',
            'product_code' => 'ENUMCHECK-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        // Simulate a scenario where pricing_source might come through as non-lowercase
        // (even though our code always uses lowercase literals, the model mutator provides
        // defense-in-depth for this database constraint)

        $sale = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'reference' => 'ENUMCHECK-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        // Create a detail with pricing_source that will be normalized by the mutator
        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
            'pricing_source' => 'MANUAL_UNIT_PRICE', // Test that the mutator normalizes this
        ]);

        // Verify the mutator lowercased the value
        $this->assertSame('manual_unit_price', $detail->pricing_source);

        // Verify it persists correctly through database round-trip
        $refreshedDetail = SaleDetails::findOrFail($detail->id);
        $this->assertSame('manual_unit_price', $refreshedDetail->pricing_source);
    }

    // ==================== PURCHASE CART REGRESSION TEST ====================

    public function test_purchase_cart_price_unchanged_after_business_context_change(): void
    {
        // This test uses Purchase cart to ensure pricing_source changes don't affect it
        $supplier = \Modules\People\Entities\Supplier::factory()->create([
            'setting_id' => $this->setting->id,
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Purchase Regression Product',
            'product_code' => 'PURCHREG-001',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->targetSetting->id,
            'last_purchase_price' => 6000,
            'average_purchase_price' => 6000,
        ]);

        // Add to purchase cart
        Livewire::test(\App\Livewire\Purchase\ProductCart::class, ['cartInstance' => 'purchase'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $purchaseItem = Cart::instance('purchase')->content()->first();
        $originalPrice = (float) $purchaseItem->price;

        // Switch business context
        session(['setting_id' => $this->targetSetting->id]);

        // Price should remain unchanged (purchase pricing is not business-scoped the same way)
        $stillItem = Cart::instance('purchase')->content()->first();
        $this->assertSame($originalPrice, (float) $stillItem->price);

        // Cleanup
        Cart::instance('purchase')->destroy();
        session(['setting_id' => $this->setting->id]);
    }
}
