<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class SaleProductCartNonPkpNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;
    protected Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

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
            'company_name' => 'Non PKP Sales Cart',
            'company_email' => 'nonpkp-sales-cart@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        session(['setting_id' => $setting->id]);

        $this->product = Product::create([
            'product_name' => 'Non PKP Cart Product',
            'product_code' => 'SALE-CART-001',
            'product_quantity' => 20,
            'setting_id' => $setting->id,
            'product_cost' => 1000,
            'product_price' => 1110,
            'product_unit' => 'pcs',
        ]);

        $this->tax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
            'is_default' => true,
        ]);

        Cart::instance('sale')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('sale')->destroy();

        parent::tearDown();
    }

    public function test_non_pkp_sale_cart_recalculation_keeps_tax_excluded_state(): void
    {
        $row = Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 1110,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 1110,
                'sub_total_before_tax' => 1000,
                'product_tax_amount' => 110,
                'code' => $this->product->product_code,
                'stock' => 20,
                'unit' => 'pcs',
                'product_tax' => $this->tax->id,
                'unit_price' => 1110,
                'sale_price' => 1110,
                'tier_1_price' => 1110,
                'tier_2_price' => 1110,
            ],
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('quantity.' . $row->id, 2)
            ->call('updateQuantity', $row->rowId, $row->id)
            ->set('is_tax_included', true)
            ->call('handleTaxIncluded');

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertNull($cartItem->options->product_tax);
        $this->assertSame(0.0, (float) $cartItem->options->product_tax_amount);
        $this->assertSame(2220.0, (float) $cartItem->options->sub_total);
        $this->assertSame(2220.0, (float) $cartItem->options->sub_total_before_tax);
    }
}
