<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class CrossBusinessPriceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->permission = Permission::firstOrCreate(['name' => 'products.manage_cross_business_prices', 'guard_name' => 'web']);
        $accessPermission = Permission::firstOrCreate(['name' => 'products.access', 'guard_name' => 'web']);
        
        $this->authorizedUser = User::factory()->create();
        $this->authorizedUser->givePermissionTo($this->permission);
        $this->authorizedUser->givePermissionTo($accessPermission);
        
        $this->unauthorizedUser = User::factory()->create();
        $this->unauthorizedUser->givePermissionTo($accessPermission);
        
        $role = Role::firstOrCreate(['name' => 'test-role']);
        
        Setting::truncate();
        $setting = Setting::factory()->create(['id' => 1, 'company_name' => 'Business A']);
        
        $this->authorizedUser->settings()->attach($setting->id, ['role_id' => $role->id]);
        $this->unauthorizedUser->settings()->attach($setting->id, ['role_id' => $role->id]);
        
        $unit = \Modules\Setting\Entities\Unit::firstOrCreate(['name' => 'Unit Test', 'short_name' => 'UT']);
        $this->product = app(\Modules\Product\Services\ProductCreator::class)->create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'base_unit_id' => $unit->id,
            'is_purchased' => 1,
            'is_sold' => 1,
            'stock_managed' => 1,
        ]);
    }

    public function test_authorized_user_can_access_cross_business_price_page()
    {
        $response = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => 1])
            ->get(route('products.cross-business-prices.edit', $this->product));
            
        $response->assertOk();
    }

    public function test_unauthorized_user_cannot_access_cross_business_price_page()
    {
        $response = $this->actingAs($this->unauthorizedUser)
            ->withSession(['setting_id' => 1])
            ->get(route('products.cross-business-prices.edit', $this->product));
            
        $response->assertForbidden();
    }
    
    public function test_unauthorized_user_cannot_save_cross_business_prices()
    {
        $response = $this->actingAs($this->unauthorizedUser)
            ->withSession(['setting_id' => 1])
            ->put(route('products.cross-business-prices.update', $this->product), [
                'prices' => []
            ]);
            
        $response->assertForbidden();
    }
    
    public function test_sensitive_price_data_exposure_requires_permission()
    {
        $route = route('products.cross-business-prices.edit', $this->product);
        
        // Authorized user sees the action
        $responseAuth = $this->actingAs($this->authorizedUser)
            ->withSession(['setting_id' => 1])
            ->getJson(route('products.index'), ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            
        $productAuth = collect($responseAuth->json('data'))->firstWhere('id', $this->product->id);
        $this->assertStringContainsString($route, $productAuth['action']);
        
        // Unauthorized user does not see the action
        $responseUnauth = $this->actingAs($this->unauthorizedUser)
            ->withSession(['setting_id' => 1])
            ->getJson(route('products.index'), ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            
        $productUnauth = collect($responseUnauth->json('data'))->firstWhere('id', $this->product->id);
        $this->assertStringNotContainsString($route, $productUnauth['action']);
    }
}
