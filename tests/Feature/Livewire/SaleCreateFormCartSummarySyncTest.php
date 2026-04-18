<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\CreateForm;
use App\Livewire\Sale\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class SaleCreateFormCartSummarySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_pkp_sale_defaults_tax_included_to_true(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => 'PKP Sales Co',
            'company_email' => 'pkp-sales@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        session(['setting_id' => $setting->id]);

        Livewire::test(CreateForm::class, ['idempotencyToken' => (string) Str::uuid()])
            ->assertSet('isPkp', true)
            ->assertSet('is_tax_included', true);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->assertSet('isPkp', true)
            ->assertSet('is_tax_included', true);
    }

    public function test_new_non_pkp_sale_defaults_tax_included_to_false(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => 'Non PKP Sales Co',
            'company_email' => 'non-pkp-sales@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        session(['setting_id' => $setting->id]);

        Livewire::test(CreateForm::class, ['idempotencyToken' => (string) Str::uuid()])
            ->assertSet('isPkp', false)
            ->assertSet('is_tax_included', false);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->assertSet('isPkp', false)
            ->assertSet('is_tax_included', false);
    }

    public function test_sale_create_persists_cart_summary_state_from_product_cart_events(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => 'PKP Sales Co',
            'company_email' => 'pkp-sales@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        session(['setting_id' => $setting->id]);

        $paymentTerm = PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
        ]);

        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
            'payment_term_id' => $paymentTerm->id,
        ]);

        $tax = Tax::create([
            'name' => 'PPN 10',
            'value' => 10,
            'is_default' => true,
        ]);

        $product = Product::create([
            'product_name' => 'Summary Sync Product',
            'product_code' => 'SALE-SUM-001',
            'product_quantity' => 100,
            'setting_id' => $setting->id,
            'product_cost' => 50,
            'product_price' => 110,
            'product_unit' => 'pcs',
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'SALE-SUM-LINE-1',
            'name' => $product->product_name,
            'qty' => 1,
            'price' => 110,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 110.00,
                'sub_total_before_tax' => 100.00,
                'code' => $product->product_code,
                'stock' => $product->product_quantity,
                'unit' => $product->product_unit,
                'product_tax' => $tax->id,
                'unit_price' => 110.00,
                'bundle_items' => [],
            ],
        ]);

        Livewire::test(CreateForm::class, ['idempotencyToken' => (string) Str::uuid()])
            ->set('customerId', $customer->id)
            ->set('paymentTermId', $paymentTerm->id)
            ->set('date', now()->format('Y-m-d'))
            ->set('dueDate', now()->format('Y-m-d'))
            ->call('handleGlobalDiscountTypeUpdated', 'percentage')
            ->call('handleGlobalDiscountUpdated', 10)
            ->call('handleShippingUpdated', 5)
            ->call('handleTaxIncludedUpdated', true)
            ->call('submit');

        $sale = Sale::latest()->first();

        $this->assertNotNull($sale);
        $this->assertTrue((bool) $sale->is_tax_included);
        $this->assertEqualsWithDelta(10.0, (float) $sale->tax_amount, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $sale->discount_percentage, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $sale->discount_amount, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $sale->shipping_amount, 0.0001);
        $this->assertEqualsWithDelta(104.0, (float) $sale->total_amount, 0.0001);
    }
}
