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
use Modules\People\Livewire\CustomerSearchDropdown;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleCustomerTierPricingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected PaymentTerm $paymentTerm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Sales Tier Pricing',
            'company_email' => 'sales-tier-pricing@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $currency->id,
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

        session(['setting_id' => $this->setting->id]);

        Cart::instance('sale')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('sale')->destroy();

        parent::tearDown();
    }

    public function test_existing_customer_selection_reprices_existing_sale_cart_lines_using_setting_product_prices(): void
    {
        $product = $this->createProductWithPriceRow(
            product: [
                'product_name' => 'Tiered Cart Product',
                'product_code' => 'SALE-TIER-001',
                'product_quantity' => 20,
                'product_unit' => 'pcs',
                'product_price' => 999,
                'sale_price' => 999,
                'tier_1_price' => 999,
                'tier_2_price' => 999,
            ],
            prices: [
                'sale_price' => 150,
                'tier_1_price' => 120,
                'tier_2_price' => 110,
            ],
        );

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => 20,
                'product_unit' => 'pcs',
                'sale_price' => 999,
                'tier_1_price' => 999,
                'tier_2_price' => 999,
            ])
            ->call('customerSelected', [
                'id' => Customer::factory()->create([
                    'setting_id' => $this->setting->id,
                    'payment_term_id' => $this->paymentTerm->id,
                    'tier' => 'WHOLESALER',
                ])->id,
                'tier' => 'WHOLESALER',
                'payment_term_id' => $this->paymentTerm->id,
            ]);

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertSame(120.0, (float) $cartItem->price);
        $this->assertSame(120.0, (float) $cartItem->options->unit_price);
        $this->assertSame(120.0, (float) $cartItem->options->sub_total);
        $this->assertSame(150.0, (float) $cartItem->options->sale_price);
        $this->assertSame(120.0, (float) $cartItem->options->tier_1_price);
        $this->assertSame(110.0, (float) $cartItem->options->tier_2_price);
    }

    public function test_sales_edit_hydration_uses_setting_product_price_metadata_instead_of_legacy_product_columns(): void
    {
        $customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $product = $this->createProductWithPriceRow(
            product: [
                'product_name' => 'Edit Hydration Product',
                'product_code' => 'SALE-EDIT-001',
                'product_quantity' => 30,
                'product_unit' => 'pcs',
                'product_price' => 999,
                'sale_price' => 999,
                'tier_1_price' => 998,
                'tier_2_price' => 997,
            ],
            prices: [
                'sale_price' => 150,
                'tier_1_price' => 125,
                'tier_2_price' => 115,
            ],
        );

        $sale = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'reference' => 'SALE-EDIT-HYDRATION',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 175,
            'paid_amount' => 0,
            'due_amount' => 175,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 175,
            'unit_price' => 175,
            'sub_total' => 175,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        Livewire::test(EditForm::class, ['sale' => $sale]);

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertSame(175.0, (float) $cartItem->price);
        $this->assertSame(175.0, (float) $cartItem->options->unit_price);
        $this->assertSame(150.0, (float) $cartItem->options->sale_price);
        $this->assertSame(125.0, (float) $cartItem->options->tier_1_price);
        $this->assertSame(115.0, (float) $cartItem->options->tier_2_price);
    }

    public function test_customer_quick_add_selection_dispatches_the_same_customer_selected_event_path(): void
    {
        $customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
            'customer_name' => 'Quick Added Customer',
            'contact_name' => 'Quick Add',
            'tier' => 'RESELLER',
        ]);

        Livewire::test(CustomerSearchDropdown::class, [
            'name' => 'customer_id',
            'allowCreate' => true,
            'dispatchOnCreate' => true,
        ])
            ->call('handleCustomerCreated', $customer->toArray())
            ->assertSet('selected', $customer->id)
            ->assertDispatched('customerSelected', function ($event, $params) use ($customer) {
                $payload = $params[0] ?? [];

                return ($payload['id'] ?? null) === $customer->id
                    && ($payload['tier'] ?? null) === 'RESELLER'
                    && ($payload['payment_term_id'] ?? null) === $customer->payment_term_id;
            });
    }

    private function createProductWithPriceRow(array $product, array $prices): Product
    {
        $createdProduct = Product::create(array_merge([
            'setting_id' => $this->setting->id,
            'product_cost' => 50,
            'product_price' => 75,
            'product_quantity' => 10,
            'product_unit' => 'pcs',
        ], $product));

        ProductPrice::create(array_merge([
            'product_id' => $createdProduct->id,
            'setting_id' => $this->setting->id,
            'last_purchase_price' => 50,
            'average_purchase_price' => 50,
        ], $prices));

        return $createdProduct;
    }
}
