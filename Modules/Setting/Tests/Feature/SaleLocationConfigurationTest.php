<?php

namespace Modules\Setting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleLocationConfigurationTest extends TestCase
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

    private function actingAsSuperAdminForSetting(Setting $setting): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        $this->actingAs($user)->withSession([
            'setting_id'    => $setting->id,
            'user_settings' => collect([$setting]),
        ]);

        return $user;
    }

    public function test_index_displays_current_and_available_locations(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $ownedLocation = Location::create([
            'name'       => 'CVTN 1',
            'setting_id' => $settingA->id,
        ]);

        $borrowable = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);

        // Manually create pivot entries to simulate Phase 1 backfill
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $ownedLocation->id, 'is_enabled' => true]);
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $borrowable->id, 'is_enabled' => false]);

        $response = $this->get(route('sales-location-configurations.index'));

        $response->assertOk();
        $response->assertSee('CVTN 1');
        $response->assertSee('TIT 1');
        $response->assertSee('Konfigurasi Lokasi Penjualan POS');
        $response->assertDontSee('Tambah Lokasi Penjualan POS');
        $response->assertDontSee('Tambahkan Lokasi');
        $response->assertSee('Milik Bisnis');
        $response->assertSee('Disabled');
    }

    public function test_default_enabled_state_on_toggle(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $borrowable = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);

        // Enable it
        $response = $this->patch(route('sales-location-configurations.toggle', $borrowable->id), [
            'is_enabled' => 1,
        ]);

        $response->assertRedirect(route('sales-location-configurations.index'));
        $this->assertTrue(SettingSaleLocation::where('location_id', $borrowable->id)
            ->where('setting_id', $settingA->id)
            ->where('is_enabled', true)
            ->exists());
    }

    public function test_disable_owned_location_is_blocked(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');

        $this->actingAsSuperAdminForSetting($settingA);

        $ownedLocation = Location::create([
            'name'       => 'CVTN 1',
            'setting_id' => $settingA->id,
        ]);
        
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $ownedLocation->id, 'is_enabled' => true]);

        $response = $this->patch(route('sales-location-configurations.toggle', $ownedLocation->id), [
            'is_enabled' => 0,
        ]);

        $response->assertRedirect(route('sales-location-configurations.index'));
        
        // Ensure it is still enabled
        $this->assertTrue(SettingSaleLocation::where('location_id', $ownedLocation->id)
            ->where('setting_id', $settingA->id)
            ->where('is_enabled', true)
            ->exists());
    }

    public function test_disable_and_reenable_borrowed_location(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $location = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);

        SettingSaleLocation::create([
            'location_id' => $location->id, 
            'setting_id' => $settingA->id,
            'is_enabled' => true,
        ]);

        // Disable
        $response = $this->patch(route('sales-location-configurations.toggle', $location->id), [
            'is_enabled' => 0,
        ]);

        $response->assertRedirect(route('sales-location-configurations.index'));
        $this->assertFalse(SettingSaleLocation::where('location_id', $location->id)->where('setting_id', $settingA->id)->value('is_enabled'));

        // Re-enable
        $response = $this->patch(route('sales-location-configurations.toggle', $location->id), [
            'is_enabled' => 1,
        ]);

        $response->assertRedirect(route('sales-location-configurations.index'));
        $this->assertTrue(SettingSaleLocation::where('location_id', $location->id)->where('setting_id', $settingA->id)->value('is_enabled'));
    }
}
