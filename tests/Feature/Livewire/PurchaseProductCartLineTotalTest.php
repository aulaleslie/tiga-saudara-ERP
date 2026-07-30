<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class PurchaseProductCartLineTotalTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Setting $pkpSetting;
    protected Product $product;
    protected Tax $tax11;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Non PKP Setting',
            'company_email' => 'non-pkp@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        $this->pkpSetting = Setting::create([
            'company_name' => 'PKP Setting',
            'company_email' => 'pkp@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'product_quantity' => 10,
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 1000,
            'product_unit' => 'pcs',
        ]);

        $this->tax11 = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
            'is_default' => true,
        ]);

        Cart::instance('purchase')->destroy();
    }

    public function test_reverse_calculate_line_total_non_pkp_no_discount()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 2)
            ->set('quantity.' . $this->product->id, 2)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('line_total.' . $this->product->id, 2500)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(1250, $cartItem->price);
        $this->assertEquals(2500, $cartItem->options->sub_total);
    }

    public function test_reverse_calculate_line_total_pkp_tax_excluded()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->pkpSetting->id)
            ->set('isPkp', true)
            ->set('is_tax_included', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 2)
            ->set('quantity.' . $this->product->id, 2)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('product_tax.' . $this->product->id, $this->tax11->id)
            ->call('updateTax', Cart::instance('purchase')->content()->first()->rowId, $this->product->id, $this->tax11->id);

        $livewire->set('line_total.' . $this->product->id, 3330) // base: 3000, qty 2 -> unit price 1500
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        // (3330 / 2) / 1.11 = 1500
        $this->assertEquals(1500, $cartItem->price);
        $this->assertEquals(3330, $cartItem->options->sub_total);
    }

    public function test_reverse_calculate_line_total_pkp_tax_included()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->pkpSetting->id)
            ->set('isPkp', true)
            ->set('is_tax_included', true)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 2)
            ->set('quantity.' . $this->product->id, 2)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('product_tax.' . $this->product->id, $this->tax11->id)
            ->call('updateTax', Cart::instance('purchase')->content()->first()->rowId, $this->product->id, $this->tax11->id);

        $livewire->set('line_total.' . $this->product->id, 3000) // tax included, so effective price = 1500, qty=2 -> 3000
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        // (3000 / 2) = 1500
        $this->assertEquals(1500, $cartItem->price);
        $this->assertEquals(3000, $cartItem->options->sub_total);
    }

    public function test_reverse_calculate_line_total_with_fixed_discount()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 2)
            ->set('quantity.' . $this->product->id, 2)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('discount_type.' . $this->product->id, 'fixed')
            ->set('item_discount.' . $this->product->id, 200) // per unit discount
            ->call('setProductDiscount', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        // original effective price = (1000 - 200) * 2 = 1600. Let's change line total to 2000.
        // effective price per unit = 1000. So base price = 1000 + 200 = 1200.
        $livewire->set('line_total.' . $this->product->id, 2000)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);
            
        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(1200, $cartItem->price);
        $this->assertEquals(200, $cartItem->options->product_discount);
        $this->assertEquals(2000, $cartItem->options->sub_total);
    }

    public function test_reverse_calculate_line_total_with_percentage_discount()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 2)
            ->set('quantity.' . $this->product->id, 2)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('discount_type.' . $this->product->id, 'percentage')
            ->set('item_discount.' . $this->product->id, 20) // 20%
            ->call('setProductDiscount', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        // original effective price = (1000 * 0.8) * 2 = 1600
        // Let's change line total to 2000.
        // effective price per unit = 1000. Base price = 1000 / (1 - 0.20) = 1250.
        $livewire->set('line_total.' . $this->product->id, 2000)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(1250, $cartItem->price);
        $this->assertEquals(250, $cartItem->options->product_discount);
        $this->assertEquals(2000, $cartItem->options->sub_total);
    }

    public function test_rejects_invalid_line_total_input()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('line_total.' . $this->product->id, -500)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(1000, $cartItem->price);
        $this->assertEquals(1000, $cartItem->options->sub_total);
    }

    public function test_rejects_line_total_update_with_100_percent_discount()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ]);

        $livewire->set('discount_type.' . $this->product->id, 'percentage')
            ->set('item_discount.' . $this->product->id, 100)
            ->call('setProductDiscount', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        // Subtotal should be 0 now.
        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(0, $cartItem->options->sub_total);

        // Try to update line total to a nonzero value
        $livewire->set('line_total.' . $this->product->id, 500)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(0, $cartItem->options->sub_total);
        $this->assertEquals(1000, $cartItem->price);
        $livewire->assertSee('Total baris lebih dari 0 tidak dimungkinkan dengan diskon 100%.');
    }
}
