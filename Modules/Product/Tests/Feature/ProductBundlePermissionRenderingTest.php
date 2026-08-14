<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductBundlePermissionRenderingTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Product $parentProduct;
    private Product $componentProduct;
    private ProductBundle $bundle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Permission Co',
            'company_email' => 'perm@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'perm@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Test Lane',
            'is_pkp' => true,
        ]);

        // Create permissions
        foreach ([
            'products.show',
            'products.bundle.edit',
            'products.bundle.delete',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->parentProduct = Product::create([
            'product_name' => 'Parent Perm Product',
            'product_code' => 'PPP-01',
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 5000,
            'product_unit' => 'pcs',
        ]);

        $this->componentProduct = Product::create([
            'product_name' => 'Comp Perm Product',
            'product_code' => 'CPP-01',
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
            'product_cost' => 500,
            'product_price' => 2000,
            'product_unit' => 'pcs',
        ]);

        $this->bundle = ProductBundle::create([
            'parent_product_id' => $this->parentProduct->id,
            'setting_id' => $this->setting->id,
            'name' => 'Permission Test Bundle',
            'bundle_sale_price' => 5000,
            'is_active' => true,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $this->bundle->id,
            'product_id' => $this->componentProduct->id,
            'quantity' => 1,
            'informational_item_price' => 2000,
        ]);
    }

    private function createUserWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'ROLE-' . uniqid()]);
        $role->syncPermissions($permissions);
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        return $user;
    }

    /**
     * Test edit-only user sees "Ubah Paket" but does NOT see "Hapus Paket".
     */
    public function test_edit_only_user_sees_edit_link_and_not_delete_form(): void
    {
        $user = $this->createUserWithPermissions(['products.show', 'products.bundle.edit']);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.show', $this->parentProduct->id));

        $response->assertStatus(200);
        $response->assertSee('Ubah Paket');
        $response->assertDontSee('Hapus Paket');
    }

    /**
     * Test delete-only user sees "Hapus Paket" but does NOT see "Ubah Paket".
     */
    public function test_delete_only_user_sees_delete_form_and_not_edit_link(): void
    {
        $user = $this->createUserWithPermissions(['products.show', 'products.bundle.delete']);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $this->setting->id])
            ->get(route('products.show', $this->parentProduct->id));

        $response->assertStatus(200);
        $response->assertSee('Hapus Paket');
        $response->assertDontSee('Ubah Paket');
    }
}
