<?php

namespace Tests\Feature;

use App\Models\Notification;
use Modules\Setting\Entities\Setting;
use Modules\Purchase\Entities\Purchase;
use Modules\Product\Entities\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class NotificationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Create permissions
        Permission::firstOrCreate(['name' => 'purchases.approval']);
        Permission::firstOrCreate(['name' => 'purchases.update']);
        Permission::firstOrCreate(['name' => 'notifications.lowStock']);
    }

    public function test_sync_repairs_missing_notifications_and_is_idempotent()
    {
        $setting = Setting::factory()->create();
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $user->assignRole($role);

        // Create a supplier
        $supplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $setting->id,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '1234567890',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address'
        ]);

        // Create a purchase needing approval
        $purchase = Purchase::create([
            'setting_id' => $setting->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'reference' => 'PR-TEST',
            'supplier_id' => $supplier->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'WAITING_APPROVAL',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
        ]);

        // Run sync
        Artisan::call('notifications:sync');

        $this->assertDatabaseHas('notifications', [
            'source_type' => Purchase::class,
            'source_id' => $purchase->id,
            'category' => 'approval',
        ]);

        $count = Notification::count();

        // Run sync again to test idempotency
        Artisan::call('notifications:sync');

        $this->assertEquals($count, Notification::count());
    }

    public function test_sync_resolves_stale_notifications()
    {
        $setting = Setting::factory()->create();
        
        $supplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $setting->id,
            'supplier_name' => 'Test Supplier 2',
            'supplier_email' => 'test2@example.com',
            'supplier_phone' => '1234567891',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address'
        ]);

        $purchase = Purchase::create([
            'setting_id' => $setting->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'reference' => 'PR-TEST',
            'supplier_id' => $supplier->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'APPROVED', // No longer needs approval
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => '',
        ]);

        // Manually create a notification that should be resolved
        $notification = Notification::create([
            'user_id' => 1,
            'setting_id' => $setting->id,
            'category' => 'approval',
            'type' => 'document_approval',
            'title' => 'Test',
            'message' => 'Test',
            'action_url' => '#',
            'source_type' => Purchase::class,
            'source_id' => $purchase->id,
            'fingerprint' => 'test-fingerprint',
        ]);

        $this->assertNull($notification->resolved_at);

        Artisan::call('notifications:sync');

        $notification->refresh();
        $this->assertNotNull($notification->resolved_at);
    }


    public function test_sync_uses_sellable_stock_for_low_stock_notifications()
    {
        $setting = Setting::factory()->create();
        $user = User::factory()->create(['is_active' => 1]);
        $permission = Permission::firstOrCreate(['name' => 'notifications.lowStock', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Low Stock Manager', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        $location = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $setting->id]);

        $globalProduct = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Global Kabel',
            'product_code' => 'GLOBAL-LOW',
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 5,
            'product_quantity' => 12,
            'broken_quantity' => 8,
            'stock_managed' => true,
        ]);

        $locationProduct = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Lokasi Kabel',
            'product_code' => 'LOCATION-LOW',
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 5,
            'product_quantity' => 20,
            'broken_quantity' => 0,
            'stock_managed' => true,
        ]);

        $stock = \Modules\Product\Entities\ProductStock::create([
            'product_id' => $locationProduct->id,
            'location_id' => $location->id,
            'quantity' => 9,
            'quantity_non_tax' => 9,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 6,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 6,
        ]);

        Artisan::call('notifications:sync');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'global_low_stock',
            'source_type' => Product::class,
            'source_id' => $globalProduct->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'location_low_stock',
            'source_type' => \Modules\Product\Entities\ProductStock::class,
            'source_id' => $stock->id,
        ]);

        $globalNotification = Notification::where('source_id', $globalProduct->id)
            ->where('type', 'global_low_stock')
            ->first();
        $locationNotification = Notification::where('source_id', $stock->id)
            ->where('type', 'location_low_stock')
            ->first();

        $this->assertStringContainsString('(4 / 5)', $globalNotification->message);
        $this->assertEquals(4, $globalNotification->metadata['current_quantity']);
        $this->assertStringContainsString('(3 / 5)', $locationNotification->message);
        $this->assertEquals(3, $locationNotification->metadata['current_quantity']);
    }

    public function test_sync_resolves_stock_notification_when_sellable_stock_recovers()
    {
        $setting = Setting::factory()->create();
        $user = User::factory()->create(['is_active' => 1]);
        $location = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $setting->id]);
        $product = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Recovery Kabel',
            'product_code' => 'RECOVERY-LOW',
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 5,
            'product_quantity' => 20,
            'broken_quantity' => 0,
            'stock_managed' => true,
        ]);
        $stock = \Modules\Product\Entities\ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 12,
            'quantity_non_tax' => 12,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 8,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 8,
        ]);
        $notification = Notification::create([
            'user_id' => $user->id,
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'category' => 'stock',
            'type' => 'location_low_stock',
            'title' => 'Test',
            'message' => 'Test',
            'action_url' => '#',
            'source_type' => \Modules\Product\Entities\ProductStock::class,
            'source_id' => $stock->id,
            'fingerprint' => 'stock:location:recovery-test',
        ]);

        Artisan::call('notifications:sync');
        $this->assertNull($notification->fresh()->resolved_at);

        $stock->update(['broken_quantity' => 4, 'broken_quantity_non_tax' => 4]);

        Artisan::call('notifications:sync');

        $this->assertNotNull($notification->fresh()->resolved_at);
    }

    public function test_prune_cutoff_behavior()
    {
        $setting = Setting::factory()->create();

        // Old notification
        Notification::create([
            'user_id' => 1,
            'setting_id' => $setting->id,
            'category' => 'approval',
            'type' => 'document_approval',
            'title' => 'Test 1',
            'message' => 'Test',
            'action_url' => '#',
            'source_type' => 'App\Models\User',
            'source_id' => 1,
            'fingerprint' => 'test-1',
            'created_at' => now()->subDays(40),
        ]);

        // New notification
        Notification::create([
            'user_id' => 1,
            'setting_id' => $setting->id,
            'category' => 'approval',
            'type' => 'document_approval',
            'title' => 'Test 2',
            'message' => 'Test',
            'action_url' => '#',
            'source_type' => 'App\Models\User',
            'source_id' => 1,
            'fingerprint' => 'test-2',
            'created_at' => now()->subDays(10),
        ]);

        $this->assertEquals(2, Notification::count());

        Artisan::call('notifications:prune', ['--days' => 30]);

        $this->assertEquals(1, Notification::count());
        $this->assertDatabaseHas('notifications', ['title' => 'Test 2']);
        $this->assertDatabaseMissing('notifications', ['title' => 'Test 1']);
    }
}
