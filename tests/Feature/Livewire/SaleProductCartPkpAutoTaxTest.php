<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class SaleProductCartPkpAutoTaxTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

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

        $this->product = Product::create([
            'product_name' => 'Taxed Sale Product',
            'product_code' => 'SALE-TAX-001',
            'product_quantity' => 20,
            'setting_id' => $setting->id,
            'product_cost' => 1000,
            'product_price' => 1110,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $setting->id,
            'sale_price' => 1110,
            'tier_1_price' => 1110,
            'tier_2_price' => 1110,
        ]);

        Cart::instance('sale')->destroy();
    }

    public function test_pkp_sale_product_add_uses_default_tax_and_populates_dpp_immediately(): void
    {
        $defaultTax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
            'is_default' => true,
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => $this->product->product_quantity,
                'product_unit' => $this->product->product_unit,
                'display_name' => $this->product->product_name . ' | ' . $this->product->product_code,
            ]);

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertSame($defaultTax->id, (int) $cartItem->options->product_tax);
        $this->assertTrue((float) $cartItem->options->sub_total > (float) $cartItem->options->sub_total_before_tax);
        $this->assertTrue(
            ((float) $cartItem->options->sub_total - (float) $cartItem->options->sub_total_before_tax) > 0
        );
    }
}
