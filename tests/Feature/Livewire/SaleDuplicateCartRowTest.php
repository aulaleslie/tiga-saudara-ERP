<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\ProductCart;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleDuplicateCartRowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '123456789',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);
    }

    /** @test */
    public function selecting_the_same_product_twice_creates_two_distinct_cart_rows()
    {
        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'product_price' => 100,
            'product_quantity' => 10,
            'product_unit' => 'pcs',
            'product_cost' => 0,
            'setting_id' => 1,
        ]);

        $payload = [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_price' => $product->product_price,
            'product_quantity' => $product->product_quantity,
            'product_unit' => 'pcs',
        ];

        Cart::instance('sale')->destroy();

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('productSelected', $payload)
            ->call('productSelected', $payload)
            ->assertCount('quantity', 2);

        $this->assertEquals(2, Cart::instance('sale')->count());
        $this->assertEquals(2, Cart::instance('sale')->content()->count());
    }
    /** @test */
    public function selecting_the_same_bundle_twice_creates_two_distinct_cart_rows()
    {
        $product = Product::create([
            'product_name' => 'Parent Product',
            'product_code' => 'PARENT001',
            'product_price' => 1000,
            'product_quantity' => 10,
            'product_unit' => 'pcs',
            'product_cost' => 0,
            'setting_id' => 1,
        ]);

        $bundleProduct = Product::create([
            'product_name' => 'Bundle Item',
            'product_code' => 'BUNDLE001',
            'product_price' => 0,
            'product_quantity' => 100,
            'product_unit' => 'pcs',
            'product_cost' => 0,
            'setting_id' => 1,
        ]);

        $bundle = \Modules\Product\Entities\ProductBundle::create([
            'parent_product_id' => $product->id,
            'name' => 'Test Bundle',
            'price' => 50,
            'setting_id' => 1,
        ]);

        \Modules\Product\Entities\ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $bundleProduct->id,
            'quantity' => 1,
        ]);

        $payload = [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_price' => $product->product_price,
            'product_quantity' => $product->product_quantity,
            'product_unit' => 'pcs',
        ];

        Cart::instance('sale')->destroy();

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('pendingProduct', $payload)
            ->call('confirmBundleSelection', $bundle->id)
            ->set('pendingProduct', $payload)
            ->call('confirmBundleSelection', $bundle->id);

        $this->assertEquals(2, Cart::instance('sale')->count());
        $this->assertEquals(2, Cart::instance('sale')->content()->count());
    }
}
