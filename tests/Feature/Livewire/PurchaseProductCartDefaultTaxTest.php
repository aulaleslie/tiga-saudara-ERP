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

class PurchaseProductCartDefaultTaxTest extends TestCase
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
            'product_name' => 'Taxable Product',
            'product_code' => 'TX-001',
            'product_quantity' => 20,
            'setting_id' => $setting->id,
            'product_cost' => 1000,
            'product_price' => 1200,
            'product_unit' => 'pcs',
        ]);

        Cart::instance('purchase')->destroy();
    }

    public function test_pkp_product_add_uses_default_tax_and_populates_tax_totals_immediately(): void
    {
        $defaultTax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
            'is_default' => true,
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
        $this->assertSame($defaultTax->id, (int) $cartItem->options->product_tax);
        $this->assertTrue((float) $cartItem->options->sub_total > (float) $cartItem->options->sub_total_before_tax);
        $this->assertTrue((float) $cartItem->options->tax_amount > 0);
    }
}
