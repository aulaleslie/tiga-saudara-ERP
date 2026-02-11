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
use Tests\TestCase;

class PurchaseDiscountFormattingTest extends TestCase
{
    use RefreshDatabase;

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
            'company_name' => 'Test Setting',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        session(['setting_id' => $setting->id]);
        Cart::instance('purchase')->destroy();
    }

    public function test_percentage_discount_input_persists_consistently_for_serial_and_non_serial_products(): void
    {
        $nonSerialProduct = Product::create([
            'product_name' => 'Non Serial Product',
            'product_code' => 'NS-001',
            'product_quantity' => 100,
            'setting_id' => (int) session('setting_id'),
            'product_cost' => 1000,
            'product_price' => 1200,
            'product_unit' => 'pcs',
            'serial_number_required' => false,
        ]);

        $serialProduct = Product::create([
            'product_name' => 'Serial Product',
            'product_code' => 'SR-001',
            'product_quantity' => 100,
            'setting_id' => (int) session('setting_id'),
            'product_cost' => 1000,
            'product_price' => 1200,
            'product_unit' => 'pcs',
            'serial_number_required' => true,
        ]);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);

        $component->call('productSelected', [
            'id' => $nonSerialProduct->id,
            'product_name' => $nonSerialProduct->product_name,
            'product_code' => $nonSerialProduct->product_code,
            'product_quantity' => 100,
            'product_unit' => 'pcs',
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
            'serial_number_required' => false,
        ]);

        $component->call('productSelected', [
            'id' => $serialProduct->id,
            'product_name' => $serialProduct->product_name,
            'product_code' => $serialProduct->product_code,
            'product_quantity' => 100,
            'product_unit' => 'pcs',
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
            'serial_number_required' => true,
        ]);

        $nonSerialCartItem = Cart::instance('purchase')->content()->firstWhere('id', $nonSerialProduct->id);
        $serialCartItem = Cart::instance('purchase')->content()->firstWhere('id', $serialProduct->id);

        $this->assertNotNull($nonSerialCartItem);
        $this->assertNotNull($serialCartItem);

        $component->call('setDiscountType', $nonSerialCartItem->rowId, (string) $nonSerialProduct->id, 'percentage');
        $component->set('item_discount.' . $nonSerialProduct->id, 10);
        $component->call('setProductDiscount', $nonSerialCartItem->rowId, (string) $nonSerialProduct->id);

        $component->call('setDiscountType', $serialCartItem->rowId, (string) $serialProduct->id, 'percentage');
        $component->set('item_discount.' . $serialProduct->id, 10);
        $component->call('setProductDiscount', $serialCartItem->rowId, (string) $serialProduct->id);

        $nonSerialCartItem = Cart::instance('purchase')->content()->firstWhere('id', $nonSerialProduct->id);
        $serialCartItem = Cart::instance('purchase')->content()->firstWhere('id', $serialProduct->id);

        $this->assertEquals(100.0, (float) $nonSerialCartItem->options->product_discount);
        $this->assertEquals(100.0, (float) $serialCartItem->options->product_discount);
        $this->assertEquals(10.0, (float) $nonSerialCartItem->options->product_discount_input);
        $this->assertEquals(10.0, (float) $serialCartItem->options->product_discount_input);

        // Simulate component remount (e.g., refresh/reopen): input must stay in percent, not converted amount.
        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->assertSet('discount_type.' . $nonSerialProduct->id, 'percentage')
            ->assertSet('discount_type.' . $serialProduct->id, 'percentage')
            ->assertSet('item_discount.' . $nonSerialProduct->id, 10.0)
            ->assertSet('item_discount.' . $serialProduct->id, 10.0);
    }
}

