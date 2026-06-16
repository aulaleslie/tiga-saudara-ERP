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
}
