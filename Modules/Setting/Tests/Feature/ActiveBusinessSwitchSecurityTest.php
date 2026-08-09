<?php

namespace Modules\Setting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActiveBusinessSwitchSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'currency_name'      => 'Rupiah',
            'code'               => 'IDR',
            'symbol'             => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator'  => ',',
        ]);
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name'            => $name,
            'company_email'           => strtolower(str_replace(' ', '', $name)) . '@example.com',
            'company_phone'           => '0800000000',
            'default_currency_id'     => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email'      => 'notify@example.com',
            'footer_text'             => 'Footer',
            'company_address'         => 'Address',
        ]);
    }

    private function createUserWithRole(Setting $setting, string $roleName = 'Staff'): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $user->assignRole($role);

        return $user;
    }

    /** @test */
    public function assigned_standard_user_can_switch_to_assigned_business(): void
    {
        $settingA = $this->createSetting('Business A');
        $settingB = $this->createSetting('Business B');

        // Create distinct roles for each business
        $roleA = Role::firstOrCreate(['name' => 'Business A Staff']);
        $roleB = Role::firstOrCreate(['name' => 'Business B Manager']);

        // Create user with Business A role
        $user = User::factory()->create();
        $user->assignRole($roleA);
        $user->settings()->attach($settingA->id, ['role_id' => $roleA->id]);
        $user->settings()->attach($settingB->id, ['role_id' => $roleB->id]);

        $this->actingAs($user)->withSession([
            'setting_id'    => $settingA->id,
            'user_settings' => collect([$settingA, $settingB]),
        ]);

        // Verify initial state: user has Business A role, not Business B role
        $this->assertTrue($user->hasRole($roleA->name));
        $this->assertFalse($user->hasRole($roleB->name));

        $response = $this->post(route('update.active.business'), ['setting_id' => $settingB->id], [
            'HTTP_REFERER' => 'http://localhost/sales',
        ]);

        $response->assertRedirect(route('home'));
        $this->assertEquals($settingB->id, session('setting_id'));
        $this->assertNotNull(cache()->get('settings_' . $settingB->id));

        // Refresh user to get updated roles from database
        $user->refresh();

        // Verify role synchronization: user now has Business B role, not Business A role
        $this->assertTrue($user->hasRole($roleB->name), 'User should have Business B Manager role');
        $this->assertFalse($user->hasRole($roleA->name), 'User should not have Business A Staff role');
    }

    /** @test */
    public function standard_user_cannot_switch_to_unassigned_business(): void
    {
        $settingA = $this->createSetting('Business A');
        $settingB = $this->createSetting('Business B');

        $user = $this->createUserWithRole($settingA, 'Staff');

        $this->actingAs($user)->withSession([
            'setting_id'    => $settingA->id,
            'user_settings' => collect([$settingA]),
        ]);

        $response = $this->post(route('update.active.business'), ['setting_id' => $settingB->id]);

        $response->assertForbidden();
        $this->assertEquals($settingA->id, session('setting_id'));
    }

    /** @test */
    public function user_cannot_switch_to_nonexistent_business(): void
    {
        $settingA = $this->createSetting('Business A');
        $user = $this->createUserWithRole($settingA, 'Staff');

        $this->actingAs($user)->withSession([
            'setting_id'    => $settingA->id,
            'user_settings' => collect([$settingA]),
        ]);

        $response = $this->post(route('update.active.business'), ['setting_id' => 99999]);

        $response->assertForbidden();
        $this->assertEquals($settingA->id, session('setting_id'));
    }

    /** @test */
    public function super_admin_can_switch_to_unassigned_business(): void
    {
        $settingA = $this->createSetting('Business A');
        $settingB = $this->createSetting('Business B');

        $user = $this->createSuperAdmin();
        // Explicitly do not assign settingB to this Super Admin

        $this->actingAs($user)->withSession([
            'setting_id'    => $settingA->id,
            'user_settings' => collect([$settingA, $settingB]),
        ]);

        $response = $this->post(route('update.active.business'), ['setting_id' => $settingB->id]);

        $response->assertRedirect(route('home'));
        $this->assertEquals($settingB->id, session('setting_id'));
    }

    /** @test */
    public function failed_switch_does_not_expose_business_existence(): void
    {
        $settingA = $this->createSetting('Business A');
        $settingB = $this->createSetting('Business B');

        $user = $this->createUserWithRole($settingA, 'Staff');

        $this->actingAs($user)->withSession([
            'setting_id'    => $settingA->id,
            'user_settings' => collect([$settingA]),
        ]);

        // Try to switch to an existing but inaccessible business
        $response1 = $this->post(route('update.active.business'), ['setting_id' => $settingB->id]);

        // Try to switch to a nonexistent business
        $response2 = $this->post(route('update.active.business'), ['setting_id' => 99999]);

        // Both should return the same 403 status
        $response1->assertForbidden();
        $response2->assertForbidden();

        // Neither should mutate the session
        $this->assertEquals($settingA->id, session('setting_id'));
    }
}
