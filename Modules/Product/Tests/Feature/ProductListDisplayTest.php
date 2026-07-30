<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductListDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->setting = Setting::factory()->create();
        $this->user = User::factory()->create();

        $role = Role::firstOrCreate(['name' => 'test-role']);
        $this->user->assignRole($role);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        Permission::findOrCreate('products.access', 'web');
        Permission::findOrCreate('products.view_prices', 'web');
        $this->user->givePermissionTo('products.access');

        $category = Category::firstOrCreate(
            ['category_code' => 'TEST-CAT'],
            ['category_name' => 'Test Category', 'created_by' => $this->user->id, 'setting_id' => $this->setting->id]
        );

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
            'product_quantity' => 0,
            'broken_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 0,
        ]);
    }

    /** @test */
    public function ajax_response_includes_all_five_price_fields_for_authorized_users()
    {
        Cache::flush();
        $this->user->forgetCachedPermissions();
        Permission::findOrCreate('products.view_prices', 'web');
        $this->user->givePermissionTo('products.view_prices');
        $this->user->forgetCachedPermissions();

        $this->product->prices()->create([
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'tier_1_price' => 8000,
            'tier_2_price' => 6000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5500,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('products.index'), [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ]);

        $response->assertSuccessful();

        $responseData = $response->json('data');
        $this->assertNotEmpty($responseData);

        $product = collect($responseData)->first();
        $this->assertArrayHasKey('last_purchase_price', $product);
        $this->assertArrayHasKey('average_purchase_price', $product);
        $this->assertArrayHasKey('sale_price', $product);
        $this->assertArrayHasKey('tier_1_price', $product);
        $this->assertArrayHasKey('tier_2_price', $product);

        $this->assertStringContainsString('5,000', $product['last_purchase_price']);
        $this->assertStringContainsString('5,500', $product['average_purchase_price']);
        $this->assertStringContainsString('10,000', $product['sale_price']);
        $this->assertStringContainsString('8,000', $product['tier_1_price']);
        $this->assertStringContainsString('6,000', $product['tier_2_price']);
    }

    /** @test */
    public function ajax_response_omits_all_five_price_fields_for_unauthorized_users()
    {
        Cache::flush();
        $this->user->forgetCachedPermissions();

        $this->product->prices()->create([
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'tier_1_price' => 8000,
            'tier_2_price' => 6000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5500,
        ]);

        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($this->user)->allows('products.view_prices'));
        $this->assertFalse($this->user->hasRole('Super Admin'));

        $response = $this->actingAs($this->user)
            ->getJson(route('products.index'), [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ]);

        $response->assertSuccessful();

        $responseData = $response->json('data');
        $this->assertNotEmpty($responseData);

        $product = collect($responseData)->first();

        // Display column keys must be absent
        $this->assertArrayNotHasKey('last_purchase_price', $product);
        $this->assertArrayNotHasKey('average_purchase_price', $product);
        $this->assertArrayNotHasKey('sale_price', $product);
        $this->assertArrayNotHasKey('tier_1_price', $product);
        $this->assertArrayNotHasKey('tier_2_price', $product);

        // Raw query alias keys must also be absent
        $this->assertArrayNotHasKey('pp_last_purchase_price', $product);
        $this->assertArrayNotHasKey('pp_average_purchase_price', $product);
        $this->assertArrayNotHasKey('pp_sale_price', $product);
        $this->assertArrayNotHasKey('pp_tier_1_price', $product);
        $this->assertArrayNotHasKey('pp_tier_2_price', $product);
    }

    /** @test */
    public function super_admin_receives_price_fields_without_explicit_permission()
    {
        Cache::flush();
        $this->user->forgetCachedPermissions();

        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin']);
        $this->user->assignRole($superAdminRole);
        $this->user->forgetCachedPermissions();

        $this->product->prices()->create([
            'setting_id' => $this->setting->id,
            'sale_price' => 10000,
            'tier_1_price' => 8000,
            'tier_2_price' => 6000,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5500,
        ]);

        $this->assertTrue($this->user->hasRole('Super Admin'));
        $this->assertFalse($this->user->hasPermissionTo('products.view_prices'));
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($this->user)->allows('products.view_prices'));

        $response = $this->actingAs($this->user)
            ->getJson(route('products.index'), [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ]);

        $response->assertSuccessful();

        $responseData = $response->json('data');
        $this->assertNotEmpty($responseData);

        $product = collect($responseData)->first();

        $this->assertArrayHasKey('last_purchase_price', $product);
        $this->assertArrayHasKey('average_purchase_price', $product);
        $this->assertArrayHasKey('sale_price', $product);
        $this->assertArrayHasKey('tier_1_price', $product);
        $this->assertArrayHasKey('tier_2_price', $product);

        $this->assertStringContainsString('5,000', $product['last_purchase_price']);
        $this->assertStringContainsString('5,500', $product['average_purchase_price']);
        $this->assertStringContainsString('10,000', $product['sale_price']);
        $this->assertStringContainsString('8,000', $product['tier_1_price']);
        $this->assertStringContainsString('6,000', $product['tier_2_price']);
    }

    /** @test */
    public function null_price_values_display_as_dash_for_authorized_users()
    {
        Cache::flush();
        $this->user->forgetCachedPermissions();
        Permission::findOrCreate('products.view_prices', 'web');
        $this->user->givePermissionTo('products.view_prices');
        $this->user->forgetCachedPermissions();

        $this->product->prices()->create([
            'setting_id' => $this->setting->id,
            'sale_price' => null,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => null,
            'average_purchase_price' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('products.index'), [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ]);

        $response->assertSuccessful();

        $responseData = $response->json('data');
        $product = collect($responseData)->first();

        $this->assertEquals('-', $product['last_purchase_price']);
        $this->assertEquals('-', $product['average_purchase_price']);
        $this->assertEquals('-', $product['sale_price']);
        $this->assertEquals('-', $product['tier_1_price']);
        $this->assertEquals('-', $product['tier_2_price']);
    }

    /** @test */
    public function missing_product_prices_row_displays_dashes_for_all_price_columns()
    {
        Cache::flush();
        $this->user->forgetCachedPermissions();
        Permission::findOrCreate('products.view_prices', 'web');
        $this->user->givePermissionTo('products.view_prices');
        $this->user->forgetCachedPermissions();

        $response = $this->actingAs($this->user)
            ->getJson(route('products.index'), [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ]);

        $response->assertSuccessful();

        $responseData = $response->json('data');
        $product = collect($responseData)->first();

        $this->assertEquals('-', $product['last_purchase_price']);
        $this->assertEquals('-', $product['average_purchase_price']);
        $this->assertEquals('-', $product['sale_price']);
        $this->assertEquals('-', $product['tier_1_price']);
        $this->assertEquals('-', $product['tier_2_price']);
    }

    /** @test */
    public function price_columns_visible_in_html_for_authorized_users()
    {
        Cache::flush();
        $this->user->forgetCachedPermissions();
        Permission::findOrCreate('products.view_prices', 'web');
        $this->user->givePermissionTo('products.view_prices');
        $this->user->forgetCachedPermissions();

        $response = $this->actingAs($this->user)
            ->get(route('products.index'));

        $response->assertSuccessful();
        $response->assertSee('Beli Akhir', false);
        $response->assertSee('Beli Rata²', false);
        $response->assertSee('Jual', false);
        $response->assertSee('Jual Partai', false);
        $response->assertSee('Jual Reseller', false);
    }

    /** @test */
    public function datatable_has_scroll_configuration()
    {
        Cache::flush();
        $this->user->forgetCachedPermissions();
        Permission::findOrCreate('products.view_prices', 'web');
        $this->user->givePermissionTo('products.view_prices');
        $this->user->forgetCachedPermissions();

        $response = $this->actingAs($this->user)
            ->get(route('products.index'));

        $response->assertSuccessful();

        $content = $response->getContent();
        $this->assertStringContainsString('scrollX', $content);
        $this->assertStringContainsString('scrollY', $content);
        $this->assertStringContainsString('70vh', $content);
        $this->assertStringContainsString('scrollCollapse', $content);
    }

    /** @test */
    public function frozen_column_css_is_present_in_response()
    {
        $response = $this->actingAs($this->user)
            ->get(route('products.index'));

        $response->assertSuccessful();

        $content = $response->getContent();
        $this->assertStringContainsString('position: sticky', $content);
        $this->assertStringContainsString('left: 0', $content);
        $this->assertStringContainsString('--image-col-width', $content);
        $this->assertStringContainsString('--code-col-left', $content);
        $this->assertStringContainsString('z-index: 1', $content);
        $this->assertStringContainsString('.dataTables_scrollHead table', $content);
    }

    /** @test */
    public function frozen_columns_have_opaque_background_color()
    {
        $response = $this->actingAs($this->user)
            ->get(route('products.index'));

        $response->assertSuccessful();

        $content = $response->getContent();
        $this->assertStringContainsString('background-color: #fff', $content);
        $this->assertStringContainsString('background-color: #1f2937', $content);
        $this->assertStringNotContainsString('background-color: inherit', $content);
    }

    /** @test */
    public function frozen_column_widths_are_consistent()
    {
        $response = $this->actingAs($this->user)
            ->get(route('products.index'));

        $response->assertSuccessful();

        $content = $response->getContent();
        $this->assertStringContainsString('--image-col-width: 80px', $content);
        $this->assertStringContainsString('width: var(--image-col-width)', $content);
        $this->assertStringContainsString('left: var(--code-col-left)', $content);
    }

    /** @test */
    public function product_prices_from_different_setting_not_displayed()
    {
        Cache::flush();
        $this->user->forgetCachedPermissions();
        Permission::findOrCreate('products.view_prices', 'web');
        $this->user->givePermissionTo('products.view_prices');
        $this->user->forgetCachedPermissions();

        $otherSetting = Setting::factory()->create();

        $this->product->prices()->create([
            'setting_id' => $otherSetting->id,
            'sale_price' => 999999,
            'tier_1_price' => 888888,
            'tier_2_price' => 777777,
            'last_purchase_price' => 111111,
            'average_purchase_price' => 222222,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('products.index'), [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ]);

        $response->assertSuccessful();

        $responseData = $response->json('data');
        $product = collect($responseData)->first();

        $this->assertEquals('-', $product['sale_price']);
        $this->assertEquals('-', $product['tier_1_price']);
        $this->assertEquals('-', $product['tier_2_price']);
        $this->assertEquals('-', $product['last_purchase_price']);
        $this->assertEquals('-', $product['average_purchase_price']);
    }
}
