<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseProductCartCrossBusinessPriceLinkTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;
    protected Setting $setting;
    protected User $authorizedUser;
    protected User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create currency
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        // Create setting
        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);
        session(['setting_id' => $this->setting->id]);

        // Create product
        $this->product = Product::create([
            'product_name' => 'Link Test Product',
            'product_code' => 'LINK-001',
            'product_quantity' => 10,
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 1200,
            'product_unit' => 'pcs',
        ]);

        // Clear cart
        Cart::instance('purchase')->destroy();

        // Setup users and permissions
        $permission = Permission::firstOrCreate(['name' => 'products.manage_cross_business_prices']);
        
        $this->authorizedUser = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Admin']);
        $role->givePermissionTo($permission);
        $this->authorizedUser->assignRole($role);

        $this->unauthorizedUser = User::factory()->create();
    }

    public function test_renders_cross_business_price_link_for_authorized_user()
    {
        $this->actingAs($this->authorizedUser);

        $this->seedCartRow();

        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->assertSeeHtml(route('products.cross-business-prices.edit', $this->product->id))
            ->assertSeeHtml('Manage Cross-Business Prices');
    }

    public function test_hides_cross_business_price_link_for_unauthorized_user()
    {
        $this->actingAs($this->unauthorizedUser);

        $this->seedCartRow();

        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->assertDontSeeHtml(route('products.cross-business-prices.edit', $this->product->id))
            ->assertSeeHtml('<strong>' . $this->product->product_name . '</strong>');
    }

    private function seedCartRow(): void
    {
        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 1000,
            'weight' => 1,
            'options' => [
                'sub_total' => 1000,
                'sub_total_before_tax' => 1000,
                'product_tax_amount' => 0,
                'code' => $this->product->product_code,
                'stock' => $this->product->product_quantity,
                'unit' => $this->product->product_unit,
                'unit_price' => 1000,
                'product_tax' => null,
                'product_discount' => 0,
                'product_discount_input' => 0,
                'product_discount_type' => 'fixed',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ],
        ]);
    }
}
