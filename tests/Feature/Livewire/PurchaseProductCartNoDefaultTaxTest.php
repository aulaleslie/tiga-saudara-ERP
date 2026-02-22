<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class PurchaseProductCartNoDefaultTaxTest extends TestCase
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
            'company_name' => 'PKP Company',
            'company_email' => 'pkp@example.com',
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
            'product_name' => 'Non Default Tax Product',
            'product_code' => 'TX-002',
            'product_quantity' => 20,
            'setting_id' => $setting->id,
            'product_cost' => 1000,
            'product_price' => 1200,
            'product_unit' => 'pcs',
        ]);

        Cart::instance('purchase')->destroy();
    }

    public function test_pkp_product_add_autoselects_latest_tax_when_no_default_exists(): void
    {
        $olderTax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
            'is_default' => false,
        ]);

        $latestTax = Tax::create([
            'name' => 'PPN 12',
            'value' => 12,
            'is_default' => false,
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => $this->product->product_quantity,
                'product_unit' => $this->product->product_unit,
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
                'purchase_tax_id' => null,
            ]);

        $cartItem = Cart::instance('purchase')->content()->firstWhere('id', $this->product->id);

        $this->assertNotNull($cartItem);
        $this->assertNotSame($olderTax->id, (int) $cartItem->options->product_tax);
        $this->assertSame($latestTax->id, (int) $cartItem->options->product_tax);
        $this->assertTrue((float) $cartItem->options->sub_total > (float) $cartItem->options->sub_total_before_tax);
        $this->assertTrue((float) $cartItem->options->product_tax_amount > 0);
    }

    public function test_pkp_product_add_keeps_tax_null_when_no_tax_rows_exist(): void
    {
        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => $this->product->product_quantity,
                'product_unit' => $this->product->product_unit,
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
                'purchase_tax_id' => null,
            ]);

        $cartItem = Cart::instance('purchase')->content()->firstWhere('id', $this->product->id);

        $this->assertNotNull($cartItem);
        $this->assertNull($cartItem->options->product_tax);
        $this->assertEquals((float) $cartItem->options->sub_total_before_tax, (float) $cartItem->options->sub_total);
        $this->assertSame(0.0, (float) $cartItem->options->product_tax_amount);
    }
}
