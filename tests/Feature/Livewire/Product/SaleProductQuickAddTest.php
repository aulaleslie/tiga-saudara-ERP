<?php

namespace Tests\Feature\Livewire\Product;

use App\Livewire\Modules\Product\Modals\ProductQuickAddModal;
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
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class SaleProductQuickAddTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Unit $unit;
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
            'company_name' => 'Sale Quick Add',
            'company_email' => 'sale-quick-add@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
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

    public function test_sales_quick_add_requires_sales_price_and_forces_sellable_context(): void
    {
        Livewire::test(ProductQuickAddModal::class)
            ->call('openModal', ['context' => 'sale'])
            ->assertSet('context', 'sale')
            ->assertSet('is_sold', true)
            ->set('product_name', 'Missing Sale Price Product')
            ->set('base_unit_id', $this->unit->id)
            ->set('purchase_price', 90000)
            ->call('save')
            ->assertHasErrors(['sale_price']);
    }

    public function test_sales_quick_add_defaults_tier_prices_and_dispatches_sales_ready_payload(): void
    {
        Livewire::test(ProductQuickAddModal::class)
            ->call('openModal', ['context' => 'sale'])
            ->set('product_name', 'Sales Quick Add Product')
            ->set('base_unit_id', $this->unit->id)
            ->set('purchase_price', 90000)
            ->set('sale_price', 125000)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('productSelected');

        $price = ProductPrice::query()->where('setting_id', $this->setting->id)->latest('id')->firstOrFail();

        $this->assertSame('125000.00', $price->sale_price);
        $this->assertSame('125000.00', $price->tier_1_price);
        $this->assertSame('125000.00', $price->tier_2_price);
    }

    public function test_sales_cart_uses_quick_added_product_price_metadata_and_reprices_after_customer_selection(): void
    {
        Livewire::test(ProductQuickAddModal::class)
            ->call('openModal', ['context' => 'sale'])
            ->set('product_name', 'Quick Add Cart Product')
            ->set('base_unit_id', $this->unit->id)
            ->set('purchase_price', 100000)
            ->set('sale_price', 150000)
            ->set('tier_1_price', 135000)
            ->set('tier_2_price', 130000)
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->latest('id')->firstOrFail();
        $customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
            'tier' => 'RESELLER',
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $product->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_quantity' => (int) $product->product_quantity,
                'product_unit' => $product->baseUnit?->name ?? 'PCS',
            ])
            ->call('customerSelected', [
                'id' => $customer->id,
                'tier' => 'RESELLER',
                'payment_term_id' => $customer->payment_term_id,
            ]);

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertSame(130000.0, (float) $cartItem->price);
        $this->assertSame(130000.0, (float) $cartItem->options->unit_price);
        $this->assertSame(150000.0, (float) $cartItem->options->sale_price);
        $this->assertSame(135000.0, (float) $cartItem->options->tier_1_price);
        $this->assertSame(130000.0, (float) $cartItem->options->tier_2_price);
    }
}
