<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();
        
        // Give permissions so user can see notifications
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Test Role']);
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'notifications.access']));
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);
        $this->user->givePermissionTo('notifications.access');

        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id, 'user_settings' => collect([$this->setting])]);
    }

    public function test_index_shows_paginated_notifications()
    {
        Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'test',
            'type' => 'test',
            'title' => 'Test Title',
            'message' => 'Test Msg',
            'fingerprint' => 'fp1',
        ]);

        $response = $this->get(route('notifications.index'));

        $response->assertStatus(200);
        $response->assertSee('Test Title');
        $response->assertSee('Test Msg');
        $response->assertSee($this->setting->company_name);
    }

    public function test_read_redirects_and_marks_read()
    {
        $notification = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'test',
            'type' => 'test',
            'title' => 'Test Title',
            'message' => 'Test Msg',
            'fingerprint' => 'fp1',
            'action_url' => '/test-url',
        ]);

        $response = $this->get(route('notifications.read', $notification->id));

        $response->assertRedirect('/test-url');
        
        $notification->refresh();
        $this->assertNotNull($notification->read_at);
    }

    public function test_read_switches_session_business_if_different_and_user_has_access()
    {
        $otherSetting = Setting::factory()->create();
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Test Role']);
        $this->user->settings()->attach($otherSetting->id, ['role_id' => $role->id]);

        $notification = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $otherSetting->id,
            'category' => 'test',
            'type' => 'test',
            'title' => 'Test Title',
            'message' => 'Test Msg',
            'fingerprint' => 'fp2',
            'action_url' => '/test-url-2',
        ]);

        $response = $this->get(route('notifications.read', $notification->id));

        $response->assertRedirect('/test-url-2');
        $this->assertEquals($otherSetting->id, session('setting_id'));
    }

    public function test_read_returns_403_if_user_no_longer_has_access_to_business()
    {
        $otherSetting = Setting::factory()->create();

        $notification = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $otherSetting->id,
            'category' => 'test',
            'type' => 'test',
            'title' => 'Test Title',
            'message' => 'Test Msg',
            'fingerprint' => 'fp3',
            'action_url' => '/test-url-3',
        ]);

        $response = $this->get(route('notifications.read', $notification->id));

        $response->assertStatus(403);
        $this->assertEquals($this->setting->id, session('setting_id'));
    }

    public function test_mark_all_read()
    {
        Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'test',
            'type' => 'test',
            'title' => 'T1',
            'message' => 'M1',
            'fingerprint' => 'fp1',
        ]);

        $response = $this->post(route('notifications.markAllRead'));

        $response->assertRedirect();
        
        $this->assertEquals(0, Notification::unread()->count());
    }

    public function test_read_redirects_on_lan_host()
    {
        $notification = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'test',
            'type' => 'test',
            'title' => 'LAN Test',
            'message' => 'LAN Msg',
            'fingerprint' => 'lan-fp',
            'action_url' => '/purchases/42?tab=items',
        ]);

        $response = $this->get("http://192.168.1.50:8000/notifications/{$notification->id}/read");

        $response->assertRedirect('http://192.168.1.50:8000/purchases/42?tab=items');
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_read_redirects_on_cloudflare_host()
    {
        $notification = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'test',
            'type' => 'test',
            'title' => 'CF Test',
            'message' => 'CF Msg',
            'fingerprint' => 'cf-fp',
            'action_url' => '/sale-returns/7',
        ]);

        $response = $this->get("https://erp.tigasaudara.com/notifications/{$notification->id}/read");

        $response->assertRedirect('https://erp.tigasaudara.com/sale-returns/7');
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_read_falls_back_to_notification_index_on_unsafe_stored_destination()
    {
        $notification = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'test',
            'type' => 'test',
            'title' => 'Evil Test',
            'message' => 'Evil Msg',
            'fingerprint' => 'evil-fp',
            'action_url' => 'https://evil-phishing.com/steal-creds',
        ]);

        $response = $this->get(route('notifications.read', $notification->id));

        $response->assertRedirect(route('notifications.index'));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_read_falls_back_to_notification_index_on_protocol_relative_url()
    {
        $notification = Notification::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'category' => 'test',
            'type' => 'test',
            'title' => 'Proto Rel Test',
            'message' => 'Proto Rel Msg',
            'fingerprint' => 'proto-fp',
            'action_url' => '//evil.com/hack',
        ]);

        $response = $this->get(route('notifications.read', $notification->id));

        $response->assertRedirect(route('notifications.index'));
        $this->assertNotNull($notification->fresh()->read_at);
    }
}
