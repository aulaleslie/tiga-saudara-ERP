<?php

namespace Tests\Feature\Services\Notification;

use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Notification\PermissionResolver;
use App\Services\Notification\StockNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StockNotificationService $stockService;
    protected Setting $setting1;
    protected Setting $setting2;
    protected User $manager1;
    protected User $manager2;
    protected Location $location1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockService = new StockNotificationService(
            app(NotificationService::class),
            app(PermissionResolver::class)
        );

        $this->setting1 = Setting::factory()->create();
        $this->setting2 = Setting::factory()->create();

        $this->location1 = Location::factory()->create(['setting_id' => $this->setting1->id]);

        $permission = Permission::firstOrCreate(['name' => 'notifications.lowStock', 'guard_name' => 'web']);
        
        $role1 = Role::firstOrCreate(['name' => 'Manager 1', 'guard_name' => 'web']);
        $role1->givePermissionTo($permission);

        $role2 = Role::firstOrCreate(['name' => 'Manager 2', 'guard_name' => 'web']);
        $role2->givePermissionTo($permission);

        $this->manager1 = User::factory()->create(['is_active' => 1]);
        $this->manager1->settings()->attach($this->setting1->id, ['role_id' => $role1->id]);

        $this->manager2 = User::factory()->create(['is_active' => 1]);
        $this->manager2->settings()->attach($this->setting2->id, ['role_id' => $role2->id]);
    }

    public function test_global_stock_threshold_crossing_creates_notification_for_correct_recipients()
    {
        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Test Item',
            'product_code' => 'TEST-1',
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 10,
            'product_quantity' => 5, // currently low
        ]);

        $this->stockService->checkGlobalStock($product, 15, 5); // Crossed from 15 to 5

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->manager1->id,
            'setting_id' => $this->setting1->id,
            'category' => 'stock',
            'type' => 'global_low_stock',
            'source_type' => Product::class,
            'source_id' => $product->id,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->manager2->id, // Wrong setting
        ]);
    }

    public function test_global_stock_already_low_does_not_create_duplicate()
    {
        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Test Item 2',
            'product_code' => 'TEST-2',
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 10,
            'product_quantity' => 5,
        ]);

        // First crossing
        $this->stockService->checkGlobalStock($product, 15, 5);

        $this->assertEquals(1, Notification::where('user_id', $this->manager1->id)->count());

        // Further decrease, does not cross threshold (previous also <= alert)
        $product->update(['product_quantity' => 2]);
        $this->stockService->checkGlobalStock($product, 5, 2);

        // Still only 1 notification
        $this->assertEquals(1, Notification::where('user_id', $this->manager1->id)->count());
    }

    public function test_global_stock_rising_above_threshold_resolves_notification()
    {
        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Test Item 3',
            'product_code' => 'TEST-3',
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 10,
            'product_quantity' => 5,
        ]);

        $this->stockService->checkGlobalStock($product, 15, 5);

        $this->assertEquals(1, Notification::unresolved()->count());

        $product->update(['product_quantity' => 12]);
        $this->stockService->checkGlobalStock($product, 5, 12);

        $this->assertEquals(0, Notification::unresolved()->count());
    }

    public function test_location_stock_threshold_crossing()
    {
        $product = Product::create([
            'setting_id' => $this->setting1->id,
            'product_name' => 'Test Item 4',
            'product_code' => 'TEST-4',
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 10,
            'product_quantity' => 20, // Global is fine
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location1->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $this->stockService->checkLocationStock($stock, 15, 5);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->manager1->id,
            'location_id' => $this->location1->id,
            'category' => 'stock',
            'type' => 'location_low_stock',
        ]);
    }
}
