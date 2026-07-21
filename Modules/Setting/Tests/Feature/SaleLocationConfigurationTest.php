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

    public function test_reorder_locations(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $loc1 = Location::create(['name' => 'Loc 1', 'setting_id' => $settingB->id]);
        $loc2 = Location::create(['name' => 'Loc 2', 'setting_id' => $settingB->id]);
        
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $loc1->id, 'position' => 1]);
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $loc2->id, 'position' => 2]);

        // Swap order
        $response = $this->put(route('sales-location-configurations.order'), [
            'location_ids' => [$loc2->id, $loc1->id],
        ]);

        $response->assertRedirect(route('sales-location-configurations.index'));
        
        $this->assertEquals(1, SettingSaleLocation::where('setting_id', $settingA->id)->where('location_id', $loc2->id)->value('position'));
        $this->assertEquals(2, SettingSaleLocation::where('setting_id', $settingA->id)->where('location_id', $loc1->id)->value('position'));
    }

    public function test_location_creation_busts_sales_location_resolver_cache(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $this->actingAsSuperAdminForSetting($settingA);

        // Pre-cache the sales locations (should be empty initially)
        $cachedIdsBefore = \App\Support\SalesLocationResolver::resolveLocationIds($settingA->id);
        $this->assertEmpty($cachedIdsBefore);

        // Create a new location (which should trigger cache clear via observer)
        $ownedLocation = Location::create([
            'name'       => 'CVTN 1',
            'setting_id' => $settingA->id,
        ]);

        // Fetch again from resolver. Without cache bust, it will return the cached empty array.
        $cachedIdsAfter = \App\Support\SalesLocationResolver::resolveLocationIds($settingA->id);
        
        $this->assertNotEmpty($cachedIdsAfter);
        $this->assertContains($ownedLocation->id, $cachedIdsAfter->toArray());
    }

    public function test_enabling_location_without_position_assigns_correct_max_position(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $borrowable1 = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);

        $borrowable2 = Location::create([
            'name'       => 'TIT 2',
            'setting_id' => $settingB->id,
        ]);

        // Scenario 1: First location, max position is 0 (null in DB). It should get position 1.
        $assignment1 = SettingSaleLocation::updateOrCreate(
            ['location_id' => $borrowable1->id, 'setting_id' => $settingA->id],
            ['is_enabled' => true]
        );

        $this->assertEquals(1, $assignment1->position);

        // Scenario 2: Second location, max position is now 1. It should get position 2.
        $assignment2 = SettingSaleLocation::updateOrCreate(
            ['location_id' => $borrowable2->id, 'setting_id' => $settingA->id],
            ['is_enabled' => true]
        );

        $this->assertEquals(2, $assignment2->position);
        
        // Scenario 3: Manually set a max position of 0 and test the ?: vs ?? fix.
        // Even if max position is 0, the next one should be 0 + 1 = 1.
        $assignment2->update(['position' => 0]);
        $assignment1->delete(); // clear out the other one so max is 0
        
        $borrowable3 = Location::create([
            'name'       => 'TIT 3',
            'setting_id' => $settingB->id,
        ]);
        
        $assignment3 = SettingSaleLocation::updateOrCreate(
            ['location_id' => $borrowable3->id, 'setting_id' => $settingA->id],
            ['is_enabled' => true]
        );

        $this->assertEquals(1, $assignment3->position);
    }
}
