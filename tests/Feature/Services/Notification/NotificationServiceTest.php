<?php

namespace Tests\Feature\Services\Notification;

use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected NotificationService $service;
    protected User $user;
    protected Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new NotificationService();
        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();
    }

    public function test_write_creates_new_notification()
    {
        $notification = $this->service->write([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'stock',
            'type' => 'low_stock',
            'title' => 'Low Stock',
            'message' => 'Product is low',
            'fingerprint' => 'test-fingerprint',
            'source_type' => User::class,
            'source_id' => 1,
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'user_id' => $this->user->id,
            'fingerprint' => 'test-fingerprint',
        ]);
        
        $this->assertNull($notification->read_at);
        $this->assertNull($notification->resolved_at);
    }

    public function test_write_updates_existing_unresolved_notification_instead_of_duplicating()
    {
        $first = $this->service->write([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'stock',
            'type' => 'low_stock',
            'title' => 'Low Stock',
            'message' => 'Product is low',
            'fingerprint' => 'test-fingerprint',
        ]);

        $first->update(['read_at' => Carbon::now()]);

        $second = $this->service->write([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'stock',
            'type' => 'low_stock',
            'title' => 'Low Stock Updated',
            'message' => 'Product is very low',
            'fingerprint' => 'test-fingerprint',
        ]);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals('Low Stock Updated', $second->title);
        $this->assertEquals('Product is very low', $second->message);
        $this->assertNull($second->read_at); // Reset to unread

        $this->assertEquals(1, Notification::count());
    }

    public function test_write_creates_new_if_previous_was_resolved()
    {
        $first = $this->service->write([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'stock',
            'type' => 'low_stock',
            'title' => 'Low Stock',
            'message' => 'Product is low',
            'fingerprint' => 'test-fingerprint',
        ]);

        $first->update(['resolved_at' => Carbon::now()]);

        $second = $this->service->write([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'stock',
            'type' => 'low_stock',
            'title' => 'Low Stock',
            'message' => 'Product is low',
            'fingerprint' => 'test-fingerprint',
        ]);

        $this->assertNotEquals($first->id, $second->id);
        $this->assertEquals(2, Notification::count());
    }

    public function test_resolve_by_source()
    {
        $notification = $this->service->write([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'approval',
            'type' => 'purchase_approval',
            'title' => 'Approval Needed',
            'message' => 'Purchase PR-001',
            'fingerprint' => 'test-fingerprint',
            'source_type' => 'Modules\Purchase\Entities\Purchase',
            'source_id' => 10,
        ]);

        $affected = $this->service->resolveBySource('approval', 'Modules\Purchase\Entities\Purchase', 10);

        $this->assertEquals(1, $affected);
        $notification->refresh();
        $this->assertNotNull($notification->resolved_at);
    }

    public function test_mark_as_read()
    {
        $notification = $this->service->write([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'stock',
            'type' => 'low_stock',
            'title' => 'Low Stock',
            'message' => 'Product is low',
            'fingerprint' => 'test-fingerprint',
        ]);

        $result = $this->service->markAsRead($notification->id, $this->user->id);

        $this->assertTrue($result);
        $notification->refresh();
        $this->assertNotNull($notification->read_at);

        // Try marking again, should return false (already read)
        $result2 = $this->service->markAsRead($notification->id, $this->user->id);
        $this->assertFalse($result2);
    }

    public function test_mark_all_as_read()
    {
        $this->service->write([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'stock',
            'type' => 'low_stock',
            'title' => '1',
            'message' => '1',
            'fingerprint' => 'fp1',
        ]);

        $this->service->write([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'stock',
            'type' => 'low_stock',
            'title' => '2',
            'message' => '2',
            'fingerprint' => 'fp2',
        ]);

        $affected = $this->service->markAllAsRead($this->user->id);

        $this->assertEquals(2, $affected);
        $this->assertEquals(0, Notification::unread()->count());
    }
}
