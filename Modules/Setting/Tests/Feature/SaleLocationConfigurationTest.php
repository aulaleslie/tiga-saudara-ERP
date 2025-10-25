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
            'user_settings' => [$setting],
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
        $response->assertSee('Konfigurasi Gudang Penjualan');
        $this->assertEquals($settingA->id, $ownedLocation->saleAssignment->setting_id);
        $this->assertEquals($settingB->id, $borrowable->saleAssignment->setting_id);
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
        $this->assertEquals($settingA->id, $borrowable->fresh()->saleAssignment->setting_id);
        $this->assertFalse($borrowable->fresh()->saleAssignment->is_pos);
    }

    public function test_cannot_attach_location_already_borrowed(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');
        $settingC = $this->createSetting('CV Gabungan');

        $this->actingAsSuperAdminForSetting($settingA);

        $location = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);

        SettingSaleLocation::where('location_id', $location->id)->update(['setting_id' => $settingC->id]);

        $response = $this->post(route('sales-location-configurations.store'), [
            'location_id' => $location->id,
        ]);

        $response->assertRedirect(route('sales-location-configurations.index'));
        $this->assertEquals($settingC->id, $location->fresh()->saleAssignment->setting_id);
    }

    public function test_destroy_returns_location_to_owner(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $location = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);

        $location->saleAssignment()->update(['setting_id' => $settingA->id]);

        $response = $this->delete(route('sales-location-configurations.destroy', $location->id));

        $response->assertRedirect(route('sales-location-configurations.index'));
        $this->assertEquals($settingB->id, $location->fresh()->saleAssignment->setting_id);
        $this->assertFalse($location->fresh()->saleAssignment->is_pos);
    }

    public function test_can_toggle_pos_location_within_setting(): void
    {
        $setting = $this->createSetting('CV Tiga Nusa');
        $this->actingAsSuperAdminForSetting($setting);

        $primary = Location::create([
            'name'       => 'Gudang Utama',
            'setting_id' => $setting->id,
        ]);

        $secondary = Location::create([
            'name'       => 'Gudang Cabang',
            'setting_id' => $setting->id,
        ]);

        $this->patch(route('sales-location-configurations.update', $primary->id), [
            'is_pos' => true,
        ])->assertRedirect(route('sales-location-configurations.index'));

        $this->assertTrue($primary->fresh()->saleAssignment->is_pos);
        $this->assertFalse($secondary->fresh()->saleAssignment->is_pos);

        $this->patch(route('sales-location-configurations.update', $secondary->id), [
            'is_pos' => true,
        ])->assertRedirect(route('sales-location-configurations.index'));

        $this->assertFalse($primary->fresh()->saleAssignment->is_pos);
        $this->assertTrue($secondary->fresh()->saleAssignment->is_pos);

        $this->patch(route('sales-location-configurations.update', $secondary->id), [
            'is_pos' => false,
        ])->assertRedirect(route('sales-location-configurations.index'));

        $this->assertFalse($primary->fresh()->saleAssignment->is_pos);
        $this->assertFalse($secondary->fresh()->saleAssignment->is_pos);
    }

    public function test_toggle_pos_requires_edit_permission(): void
    {
        $setting = $this->createSetting('CV Tiga Nusa');

        $location = Location::create([
            'name'       => 'Gudang Utama',
            'setting_id' => $setting->id,
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)->withSession([
            'setting_id'    => $setting->id,
            'user_settings' => [$setting],
        ]);

        $this->patch(route('sales-location-configurations.update', $location->id), [
            'is_pos' => true,
        ])->assertStatus(403);
    }
}
