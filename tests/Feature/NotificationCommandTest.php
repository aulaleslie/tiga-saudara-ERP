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
        Permission::firstOrCreate(['name' => 'purchases.edit']);
        Permission::firstOrCreate(['name' => 'notifications.lowStock']);
    }

    public function test_sync_repairs_missing_notifications_and_is_idempotent()
    {
        $setting = Setting::factory()->create();
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $user->assignRole($role);

        // Create a purchase needing approval
        $purchase = Purchase::factory()->create([
            'setting_id' => $setting->id,
            'status' => 'WAITING_APPROVAL'
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
        
        $purchase = Purchase::factory()->create([
            'setting_id' => $setting->id,
            'status' => 'APPROVED' // No longer needs approval
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
