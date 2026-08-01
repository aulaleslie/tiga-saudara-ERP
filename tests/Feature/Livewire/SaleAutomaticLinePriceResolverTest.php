<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\EditForm;
use App\Livewire\Sale\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

class SaleAutomaticLinePriceResolverTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected PaymentTerm $paymentTerm;
    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting1 = Setting::create([
            'company_name' => 'Business 1',
            'company_email' => 'business1@example.com',
            'company_phone' => '111',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        $this->setting2 = Setting::create([
            'company_name' => 'Business 2',
            'company_email' => 'business2@example.com',
            'company_phone' => '222',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        $this->paymentTerm = PaymentTerm::query()->firstOrCreate(
            ['name' => 'Cash on Delivery'],
            ['longevity' => 0]
        );

        session(['setting_id' => $this->setting1->id]);
        Cart::instance('sale')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('sale')->destroy();
        parent::tearDown();
    }

    public function test_adding_product_with_automatic_pricing_uses_product_price_row(): void
    {
        $product = $this->createProductWithPriceRow(
            setting: $this->setting1,
            product: [
                'product_name' => 'Auto-priced Product',
                'product_code' => 'AUTO-001',
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ],
            prices: [
                'sale_price' => 50000,
                'tier_1_price' => 45000,
                'tier_2_price' => 48000,
            ],
        );

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

        $this->assertNotNull($cartItem);
        $this->assertSame('automatic', $cartItem->options->pricing_source);
        $this->assertSame(50000.0, (float) $cartItem->price);
        $this->assertSame(50000.0, (float) $cartItem->options->unit_price);
    }

    public function test_adding_product_with_no_effective_business_price_row_uses_zero(): void
    {
        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'No Price Product',
            'product_code' => 'NOPRICE-001',
            'product_quantity' => 50,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 50,
                'product_unit' => 'pcs',
            ]);

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertSame('automatic', $cartItem->options->pricing_source);
        $this->assertSame(0.0, (float) $cartItem->price);
        $this->assertSame(0.0, (float) $cartItem->options->unit_price);
    }

    public function test_manual_line_is_not_repriced_when_customer_changes(): void
    {
        $product = $this->createProductWithPriceRow(
            setting: $this->setting1,
            product: [
                'product_name' => 'Manual Price Product',
                'product_code' => 'MANUAL-001',
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ],
            prices: [
                'sale_price' => 50000,
                'tier_1_price' => 45000,
                'tier_2_price' => 48000,
            ],
        );

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

        Cart::instance('sale')->update($cartItem->rowId, [
            'options' => array_merge($cartItem->options->toArray(), [
                'pricing_source' => 'manual_unit_price',
            ]),
        ]);

        $customer = Customer::factory()->create([
            'setting_id' => $this->setting1->id,
            'payment_term_id' => $this->paymentTerm->id,
            'tier' => 'WHOLESALER',
        ]);

        $component->call('customerSelected', [
            'id' => $customer->id,
            'tier' => 'WHOLESALER',
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($updatedItem);
        $this->assertSame('manual_unit_price', $updatedItem->options->pricing_source);
        $this->assertSame(50000.0, (float) $updatedItem->price);
        $this->assertSame(50000.0, (float) $updatedItem->options->unit_price);
    }

    public function test_automatic_line_reprices_on_customer_change(): void
    {
        $product = $this->createProductWithPriceRow(
            setting: $this->setting1,
            product: [
                'product_name' => 'Tier Repriced Product',
                'product_code' => 'TIERREPRICE-001',
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ],
            prices: [
                'sale_price' => 50000,
                'tier_1_price' => 45000,
                'tier_2_price' => 48000,
            ],
        );

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        $customer = Customer::factory()->create([
            'setting_id' => $this->setting1->id,
            'payment_term_id' => $this->paymentTerm->id,
            'tier' => 'WHOLESALER',
        ]);

        $component->call('customerSelected', [
            'id' => $customer->id,
            'tier' => 'WHOLESALER',
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertSame('automatic', $cartItem->options->pricing_source);
        $this->assertSame(45000.0, (float) $cartItem->price);
        $this->assertSame(45000.0, (float) $cartItem->options->unit_price);
    }

    public function test_bundled_row_price_preserved_on_customer_change(): void
    {
        // This test verifies bundle handling - bundles stay at their manual price on tier change
        $product = $this->createProductWithPriceRow(
            setting: $this->setting1,
            product: [
                'product_name' => 'Bundle Parent',
                'product_code' => 'BUNDLE-PARENT-001',
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ],
            prices: [
                'sale_price' => 100000,
                'tier_1_price' => 90000,
                'tier_2_price' => 95000,
            ],
        );

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ]);

        // Manually set as bundled row
        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;

        Cart::instance('sale')->update($rowId, [
            'options' => array_merge($cartItem->options->toArray(), [
                'is_bundled_row' => true,
            ]),
        ]);

        $customer = Customer::factory()->create([
            'setting_id' => $this->setting1->id,
            'payment_term_id' => $this->paymentTerm->id,
            'tier' => 'WHOLESALER',
        ]);

        $component->call('customerSelected', [
            'id' => $customer->id,
            'tier' => 'WHOLESALER',
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($updatedItem);
        $this->assertSame(100000.0, (float) $updatedItem->price);
        $this->assertSame(100000.0, (float) $updatedItem->options->unit_price);
    }

    public function test_business_switch_reprices_automatic_non_bundled_lines(): void
    {
        $product = $this->createProductWithPriceRow(
            setting: $this->setting1,
            product: [
                'product_name' => 'Cross Business Product',
                'product_code' => 'CROSSBIZ-001',
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ],
            prices: [
                'sale_price' => 50000,
                'tier_1_price' => 45000,
                'tier_2_price' => 48000,
            ],
        );

        $this->createProductWithPriceRow(
            setting: $this->setting2,
            product: [
                'product_name' => 'Cross Business Product',
                'product_code' => 'CROSSBIZ-001',
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ],
            prices: [
                'sale_price' => 60000,
                'tier_1_price' => 55000,
                'tier_2_price' => 58000,
            ],
            existingProduct: $product,
        );

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
        $this->assertSame(50000.0, (float) $cartItem->price);

        $component->call('handleBusinessContextChanged', $this->setting2->id);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($updatedItem);
        $this->assertSame('automatic', $updatedItem->options->pricing_source);
        $this->assertSame(60000.0, (float) $updatedItem->price);
        $this->assertSame(60000.0, (float) $updatedItem->options->unit_price);
    }

    public function test_business_switch_with_missing_target_price_sets_zero_and_notifies(): void
    {
        $product = $this->createProductWithPriceRow(
            setting: $this->setting1,
            product: [
                'product_name' => 'Missing Target Price Product',
                'product_code' => 'MISSINGPRICE-001',
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ],
            prices: [
                'sale_price' => 50000,
                'tier_1_price' => 45000,
                'tier_2_price' => 48000,
            ],
        );

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ])
            ->call('handleBusinessContextChanged', $this->setting2->id);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($updatedItem);
        $this->assertSame('automatic', $updatedItem->options->pricing_source);
        $this->assertSame(0.0, (float) $updatedItem->price);
        $this->assertSame(0.0, (float) $updatedItem->options->unit_price);
    }

    public function test_business_switch_preserves_manual_prices(): void
    {
        $product = $this->createProductWithPriceRow(
            setting: $this->setting1,
            product: [
                'product_name' => 'Manual Business Product',
                'product_code' => 'MANUALBIZ-001',
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ],
            prices: [
                'sale_price' => 50000,
                'tier_1_price' => 45000,
                'tier_2_price' => 48000,
            ],
        );

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

        Cart::instance('sale')->update($cartItem->rowId, [
            'options' => array_merge($cartItem->options->toArray(), [
                'pricing_source' => 'manual_unit_price',
            ]),
        ]);

        $component->call('handleBusinessContextChanged', $this->setting2->id);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($updatedItem);
        $this->assertSame('manual_unit_price', $updatedItem->options->pricing_source);
        $this->assertSame(50000.0, (float) $updatedItem->price);
        $this->assertSame(50000.0, (float) $updatedItem->options->unit_price);
    }

    public function test_line_total_edit_sets_manual_line_total_source(): void
    {
        $product = $this->createProductWithPriceRow(
            setting: $this->setting1,
            product: [
                'product_name' => 'Line Total Product',
                'product_code' => 'LINETOTAL-001',
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ],
            prices: [
                'sale_price' => 50000,
                'tier_1_price' => 45000,
                'tier_2_price' => 48000,
            ],
        );

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

        $component->set('line_total.' . $id, 100000)
            ->call('updateLineTotal', $rowId, $id);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($updatedItem);
        $this->assertSame('manual_line_total', $updatedItem->options->pricing_source);
        $this->assertSame(100000.0, (float) $updatedItem->options->sub_total);
    }

    public function test_line_total_survives_customer_change(): void
    {
        $product = $this->createProductWithPriceRow(
            setting: $this->setting1,
            product: [
                'product_name' => 'Line Total Customer Product',
                'product_code' => 'LINETOTALCUST-001',
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ],
            prices: [
                'sale_price' => 50000,
                'tier_1_price' => 45000,
                'tier_2_price' => 48000,
            ],
        );

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

        $component->set('line_total.' . $id, 120000)
            ->call('updateLineTotal', $rowId, $id);

        $customer = Customer::factory()->create([
            'setting_id' => $this->setting1->id,
            'payment_term_id' => $this->paymentTerm->id,
            'tier' => 'WHOLESALER',
        ]);

        $component->call('customerSelected', [
            'id' => $customer->id,
            'tier' => 'WHOLESALER',
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $updatedItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($updatedItem);
        $this->assertSame('manual_line_total', $updatedItem->options->pricing_source);
        $this->assertSame(120000.0, (float) $updatedItem->options->sub_total);
    }

    public function test_edit_form_hydration_carries_automatic_pricing_source(): void
    {
        $customer = Customer::factory()->create([
            'setting_id' => $this->setting1->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $product = $this->createProductWithPriceRow(
            setting: $this->setting1,
            product: [
                'product_name' => 'Edit Hydration Product',
                'product_code' => 'EDITHYD-001',
                'product_quantity' => 100,
                'product_unit' => 'pcs',
            ],
            prices: [
                'sale_price' => 50000,
                'tier_1_price' => 45000,
                'tier_2_price' => 48000,
            ],
        );

        $sale = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'reference' => 'SALE-EDITHYD-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting1->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        Livewire::test(EditForm::class, ['sale' => $sale]);

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertSame('automatic', $cartItem->options->pricing_source);
    }

    private function createProductWithPriceRow(
        Setting $setting,
        array $product,
        array $prices,
        ?Product $existingProduct = null
    ): Product {
        if ($existingProduct) {
            $createdProduct = $existingProduct;
        } else {
            $createdProduct = Product::create(array_merge([
                'setting_id' => $setting->id,
                'product_cost' => 1000,
                'product_price' => 2000,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
            ], $product));
        }

        ProductPrice::create(array_merge([
            'product_id' => $createdProduct->id,
            'setting_id' => $setting->id,
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
        ], $prices));

        return $createdProduct;
    }
}
