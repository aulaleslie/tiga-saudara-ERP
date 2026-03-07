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

        $response = $this->get(route('sales-location-configurations.index'));

        $response->assertOk();
        $response->assertSee('CVTN 1');
        $response->assertSee('TIT 1');
        $response->assertSee('Konfigurasi Lokasi Penjualan POS');
        $response->assertDontSee('Jadikan POS');
        $response->assertDontSee('Nonaktifkan POS');
        $this->assertTrue(SettingSaleLocation::where('location_id', $ownedLocation->id)->where('setting_id', $settingA->id)->where('is_enabled', true)->exists());
        $this->assertTrue(SettingSaleLocation::where('location_id', $borrowable->id)->where('setting_id', $settingB->id)->where('is_enabled', true)->exists());
    }

    public function test_can_attach_location_from_other_setting(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $borrowable = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);

        $response = $this->post(route('sales-location-configurations.store'), [
            'location_id' => $borrowable->id,
        ]);

        $response->assertRedirect(route('sales-location-configurations.index'));
        $this->assertTrue(SettingSaleLocation::where('location_id', $borrowable->id)->where('setting_id', $settingA->id)->where('is_enabled', true)->exists());
    }

    public function test_can_attach_location_already_borrowed(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');
        $settingC = $this->createSetting('CV Gabungan');

        $this->actingAsSuperAdminForSetting($settingA);

        $location = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);

        SettingSaleLocation::updateOrCreate(
            ['location_id' => $location->id, 'setting_id' => $settingC->id],
            ['is_enabled' => true]
        );

        $response = $this->post(route('sales-location-configurations.store'), [
            'location_id' => $location->id,
        ]);

        $response->assertRedirect(route('sales-location-configurations.index'));
        $this->assertTrue(SettingSaleLocation::where('location_id', $location->id)->where('setting_id', $settingC->id)->where('is_enabled', true)->exists());
        $this->assertTrue(SettingSaleLocation::where('location_id', $location->id)->where('setting_id', $settingA->id)->where('is_enabled', true)->exists());
    }

    public function test_destroy_disables_location(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $location = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);

        SettingSaleLocation::updateOrCreate(
            ['location_id' => $location->id, 'setting_id' => $settingA->id],
            ['is_enabled' => true]
        );

        $response = $this->delete(route('sales-location-configurations.destroy', $location->id));

        $response->assertRedirect(route('sales-location-configurations.index'));
        $this->assertFalse(SettingSaleLocation::where('location_id', $location->id)->where('setting_id', $settingA->id)->value('is_enabled'));
    }



    public function test_latest_borrowed_location_is_enabled(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $firstLocation = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);

        $secondLocation = Location::create([
            'name'       => 'TIT 2',
            'setting_id' => $settingB->id,
        ]);

        $this->post(route('sales-location-configurations.store'), [
            'location_id' => $firstLocation->id,
        ])->assertRedirect(route('sales-location-configurations.index'));

        $this->post(route('sales-location-configurations.store'), [
            'location_id' => $secondLocation->id,
        ])->assertRedirect(route('sales-location-configurations.index'));

        $enabledLocations = SettingSaleLocation::query()
            ->where('setting_id', $settingA->id)
            ->where('is_enabled', true)
            ->pluck('location_id')
            ->all();

        $this->assertContains($firstLocation->id, $enabledLocations);
        $this->assertContains($secondLocation->id, $enabledLocations);
    }
}
