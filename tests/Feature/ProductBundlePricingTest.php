<?php

namespace Tests\Feature;

use App\Livewire\Product\BundleTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductBundlePricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn() => true);

        $user = User::factory()->create();
        $this->actingAs($user);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'TestCo',
            'company_email' => 'test@example.com',
            'company_phone' => '12345',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'company_address' => 'Addr',
            'footer_text' => 'Footer',
            'is_pkp' => true,
        ]);

        Session::put('setting_id', $this->setting->id);

        $this->parentProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'PARENT_PRODUCT',
            'product_code' => 'P1',
            'product_unit' => 'pc',
            'product_cost' => 50000.00,
            'product_price' => 100000.00,
            'product_quantity' => 10,
        ]);
        ProductPrice::create([
            'product_id' => $this->parentProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 100000.00,
        ]);

        $this->itemProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'ITEM_PRODUCT',
            'product_code' => 'I1',
            'product_unit' => 'pc',
            'product_cost' => 10000.00,
            'product_price' => 25000.00,
            'product_quantity' => 10,
        ]);
        ProductPrice::create([
            'product_id' => $this->itemProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 25000.00,
        ]);
    }

    public function test_bundle_create_persists_bundle_sale_price()
    {
        // Add feature test for bundle create defaulting and persisting Harga Jual Paket
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), [
            'name' => 'Test Bundle',
            'bundle_sale_price' => 95000.00,
            'price' => 5000.00, // Pass legacy explicitly to ensure it is NOT stored
            'items' => [
                [
                    'product_id' => $this->itemProduct->id,
                    'quantity' => 1,
                    'informational_item_price' => 20000.00,
                    'price' => 1000.00, // Legacy
                ]
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.show', $this->parentProduct->id));

        $bundle = ProductBundle::withoutGlobalScopes()->where('name', 'TEST BUNDLE')->first();
        $this->assertNotNull($bundle);
        
        $this->assertEquals(95000.00, $bundle->bundle_sale_price);
        $this->assertNull($bundle->price); // Untouched legacy

        $item = $bundle->items()->first();
        $this->assertEquals(20000.00, $item->informational_item_price);
        $this->assertNull($item->price); // Untouched legacy
    }

    public function test_livewire_bundle_table_defaults_informational_price()
    {
        // Add feature or Livewire test for item selection defaulting and persisting Harga Informasi Item
        Livewire::test(BundleTable::class, ['productId' => $this->parentProduct->id])
            ->call('addItem')
            ->call('updateProductRow', [
                'index' => 'item_5',
                'product' => $this->itemProduct->toArray()
            ])
            // Just verifying it doesn't crash and we can test the state manually but rowKeys are dynamic.
            // A safer approach:
            ->set('rowKeys.0', 'item_5') // manually overwrite the first rowkey
            ->call('updateProductRow', [
                'index' => 'item_5',
                'product' => $this->itemProduct->toArray()
            ])
            ->assertSet('items.0.product_id', $this->itemProduct->id)
            ->assertSet('items.0.informational_item_price', 25000.00); // from setup sale_price
    }

    public function test_surfaces_do_not_expose_legacy_harga_paket()
    {
        // create view
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.bundle.create', $this->parentProduct->id));
        $response->assertOk();
        $response->assertSee('bundle_sale_price');
        $response->assertDontSee('name="price"', false);

        // create a bundle for edit & product detail tests
        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Existing Bundle',
            'bundle_sale_price' => 95000.00,
            'price' => 3000.00 // Legacy value shouldn't be rendered
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $this->itemProduct->id,
            'quantity' => 2,
            'informational_item_price' => 25000.00,
            'price' => 1500.00 // Legacy shouldn't be rendered
        ]);

        // edit view
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.bundle.edit', [$this->parentProduct->id, $bundle->id]));
        $response->assertOk();
        $response->assertSee('bundle_sale_price');
        $response->assertDontSee('name="price"', false);

        // product detail surface
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.show', $this->parentProduct->id));
        $response->assertOk();
        // The display logic: text-muted for bracketed price
        // Legacy bundle price 3000.00 shouldn't exist, we expect 95000.00
        $response->assertSee('95.000,00');
        // Item informational price = 25000.00
        $response->assertSee('25.000,00');
        // It shouldn't display the legacy ones 3.000,00 and 1.500,00
        $response->assertDontSee('3.000,00');
        $response->assertDontSee('1.500,00');
    }
}
