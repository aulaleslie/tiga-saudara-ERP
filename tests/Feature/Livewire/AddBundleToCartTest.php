<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class AddBundleToCartTest extends TestCase
{
    use RefreshDatabase;

    public function test_bundle_add_uses_parent_unit_price_and_stores_bundle_price()
    {
        Gate::before(fn () => true);

        // Prepare user and session
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create minimal setting and put into session
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => 'TestCo',
            'company_email' => 'test@example.com',
            'company_phone' => '12345',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr',
            'is_pkp' => true,
        ]);

        Session::put('setting_id', $setting->id);

        $defaultTax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
            'is_default' => true,
        ]);

        // Create parent product
        $product = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'SAMSUNG GALAXY Z FOLD 6',
            'product_code' => 'SGZ6',
            'product_unit' => 'pc',
            'product_quantity' => 10,
            'product_cost' => 0,
            'product_price' => 5500000.00,
        ]);

        // Create per-setting product price (sale_price = 5,500,000)
        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => 5500000.00,
        ]);

        // Create a bundle for this product with bundle price 55,000
        $bundle = ProductBundle::create([
            'parent_product_id' => $product->id,
            'name' => 'FREE CHARGER',
            'price' => 55000.00,
        ]);

        // Add one item to bundle (use an existing or new product)
        $bundleItemProduct = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'CHARGER 60W',
            'product_code' => 'CH60',
            'product_unit' => 'pc',
            'product_quantity' => 100,
            'product_cost' => 0,
            'product_price' => 100000.00,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $bundleItemProduct->id,
            'quantity' => 1,
        ]);

        // Ensure cart is empty
        Cart::instance('sale')->destroy();

        // Mount ProductCart and simulate selecting/confirming bundle
        $productArray = [
            'id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_quantity' => $product->product_quantity,
            'product_unit' => $product->product_unit,
        ];

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('pendingProduct', $productArray)
            ->call('confirmBundleSelection', $bundle->id);

        $cartContent = Cart::instance('sale')->content();
        $this->assertCount(1, $cartContent);

        $row = $cartContent->first();

        // The displayed/row price should be the parent product unit price (5,500,000)
        $this->assertEquals(5500000.00, (float) $row->price);

        // options.bundle_price should hold the bundle amount (55,000)
        $this->assertEquals(55000.00, (float) ($row->options->bundle_price ?? 0));

        // options.sub_total should be parent + bundle = 5,555,000
        $this->assertEquals(5555000.00, (float) ($row->options->sub_total ?? 0));

        // PKP flow should auto-select tax and compute DPP immediately
        $this->assertSame($defaultTax->id, (int) ($row->options->product_tax ?? 0));
        $this->assertTrue((float) ($row->options->sub_total ?? 0) > (float) ($row->options->sub_total_before_tax ?? 0));
    }
}
