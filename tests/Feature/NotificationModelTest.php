<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class NotificationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_notification_row_with_all_fields()
    {
        $user = User::factory()->create();
        $setting = Setting::factory()->create();
        $location = Location::factory()->create();
        $source = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $user->id,
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'category' => 'stock',
            'type' => 'low_stock',
            'title' => 'Low Stock Alert',
            'message' => 'Product X is low on stock',
            'source_type' => User::class,
            'source_id' => $source->id,
            'fingerprint' => "test:global:{$source->id}:user:{$user->id}",
            'action_url' => '/users/' . $source->id,
            'metadata' => ['key' => 'value'],
            'read_at' => Carbon::now(),
            'resolved_at' => Carbon::now(),
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'user_id' => $user->id,
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'category' => 'stock',
            'type' => 'low_stock',
            'title' => 'Low Stock Alert',
            'message' => 'Product X is low on stock',
            'source_type' => User::class,
            'source_id' => $source->id,
            'fingerprint' => "test:global:{$source->id}:user:{$user->id}",
            'action_url' => '/users/' . $source->id,
        ]);
        
        $notification->refresh();
        $this->assertEquals(['key' => 'value'], $notification->metadata);
        $this->assertInstanceOf(Carbon::class, $notification->read_at);
        $this->assertInstanceOf(Carbon::class, $notification->resolved_at);
    }

    public function test_notification_relationships()
    {
        $user = User::factory()->create();
        $setting = Setting::factory()->create();
        $location = Location::factory()->create();
        $source = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $user->id,
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'category' => 'stock',
            'type' => 'low_stock',
            'title' => 'Title',
            'message' => 'Message',
            'source_type' => User::class,
            'source_id' => $source->id,
            'fingerprint' => 'test-fingerprint',
        ]);

        $this->assertTrue($notification->user->is($user));
        $this->assertTrue($notification->setting->is($setting));
        $this->assertTrue($notification->location->is($location));
        $this->assertTrue($notification->source->is($source));
    }

    public function test_notification_scopes()
    {
        $user = User::factory()->create();
        $setting = Setting::factory()->create();

        $baseData = [
            'user_id' => $user->id,
            'setting_id' => $setting->id,
            'category' => 'test',
            'type' => 'test',
            'title' => 'Title',
            'message' => 'Message',
        ];

        Notification::create(array_merge($baseData, [
            'fingerprint' => 'unread-unresolved',
            'read_at' => null,
            'resolved_at' => null,
        ]));

        Notification::create(array_merge($baseData, [
            'fingerprint' => 'read-unresolved',
            'read_at' => now(),
            'resolved_at' => null,
        ]));

        Notification::create(array_merge($baseData, [
            'fingerprint' => 'unread-resolved',
            'read_at' => null,
            'resolved_at' => now(),
        ]));

        $this->assertEquals(2, Notification::unread()->count());
        $this->assertEquals(1, Notification::read()->count());
        $this->assertEquals(2, Notification::unresolved()->count());
    }
}
