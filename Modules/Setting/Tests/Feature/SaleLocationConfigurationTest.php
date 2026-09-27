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
        // Location observer auto-creates assignment

        $borrowable = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);
        // Remove auto-created assignment for settingB
        SettingSaleLocation::where('setting_id', $settingB->id)->delete();

        // Manually create disabled pivot entry for settingA
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $borrowable->id, 'is_enabled' => false]);

        $response = $this->get(route('sales-location-configurations.index'));

        $response->assertOk();
        $response->assertSee('CVTN 1');
        $response->assertSee('TIT 1');
        $response->assertSee('Konfigurasi Lokasi Penjualan POS');
        $response->assertDontSee('Tambah Lokasi Penjualan POS');
        $response->assertDontSee('Tambahkan Lokasi');
        $response->assertSee('Milik Bisnis');
        $response->assertSee('Tidak Aktif');
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

    public function test_unassigned_and_disabled_foreign_locations_appear_outside_active_list(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $ownedLocation = Location::create([
            'name'       => 'CVTN 1',
            'setting_id' => $settingA->id,
        ]);
        // Location observer auto-creates assignment with is_enabled=true

        $unassignedForeign = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);

        $disabledForeign = Location::create([
            'name'       => 'TIT 2',
            'setting_id' => $settingB->id,
        ]);

        // Manually disable one of the foreign locations
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $disabledForeign->id, 'is_enabled' => false]);

        $response = $this->get(route('sales-location-configurations.index'));

        $response->assertOk();

        // Active locations should contain only the owned location
        $this->assertCount(1, $response->viewData('activeLocations'));
        $this->assertEquals($ownedLocation->id, $response->viewData('activeLocations')[0]->id);

        // Available locations should contain both unassigned and disabled foreign locations
        $this->assertCount(2, $response->viewData('availableLocations'));
        $availableIds = $response->viewData('availableLocations')->pluck('id')->toArray();
        $this->assertContains($unassignedForeign->id, $availableIds);
        $this->assertContains($disabledForeign->id, $availableIds);
    }

    public function test_enablement_appends_foreign_location_to_active_list(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $ownedLocation = Location::create([
            'name'       => 'CVTN 1',
            'setting_id' => $settingA->id,
        ]);
        // Location observer auto-creates assignment

        $foreignLocation = Location::create([
            'name'       => 'TIT 1',
            'setting_id' => $settingB->id,
        ]);
        // Location observer tries to create assignment for foreign location in $settingB, not $settingA
        // So we need to explicitly disable it for $settingA
        SettingSaleLocation::where('setting_id', $settingB->id)
            ->where('location_id', $foreignLocation->id)
            ->delete(); // Remove the auto-created assignment for the foreign setting

        // Enable the foreign location for settingA
        $response = $this->patch(route('sales-location-configurations.toggle', $foreignLocation->id), [
            'is_enabled' => 1,
        ]);

        $response->assertRedirect(route('sales-location-configurations.index'));

        $assignment = SettingSaleLocation::where('setting_id', $settingA->id)
            ->where('location_id', $foreignLocation->id)
            ->first();

        $this->assertTrue($assignment->is_enabled);
        $this->assertEquals(2, $assignment->position); // Should be appended after the max enabled position

        // Verify it now appears in active list
        $response = $this->get(route('sales-location-configurations.index'));
        $this->assertCount(2, $response->viewData('activeLocations'));
        $activeIds = $response->viewData('activeLocations')->pluck('id')->toArray();
        $this->assertContains($foreignLocation->id, $activeIds);
    }

    public function test_reorder_rejects_disabled_location(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $loc1 = Location::create(['name' => 'Loc 1', 'setting_id' => $settingB->id]);
        $loc2 = Location::create(['name' => 'Loc 2', 'setting_id' => $settingB->id]);

        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $loc1->id, 'is_enabled' => true, 'position' => 1]);
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $loc2->id, 'is_enabled' => false]);

        // Attempt reorder including disabled location
        $response = $this->put(route('sales-location-configurations.order'), [
            'location_ids' => [$loc2->id, $loc1->id],
        ]);

        $response->assertRedirect(route('sales-location-configurations.index'));

        // Positions should remain unchanged
        $this->assertEquals(1, SettingSaleLocation::where('setting_id', $settingA->id)->where('location_id', $loc1->id)->value('position'));
        $this->assertNull(SettingSaleLocation::where('setting_id', $settingA->id)->where('location_id', $loc2->id)->value('position'));
    }

    public function test_reorder_rejects_duplicate_location_id(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $loc1 = Location::create(['name' => 'Loc 1', 'setting_id' => $settingB->id]);
        $loc2 = Location::create(['name' => 'Loc 2', 'setting_id' => $settingB->id]);

        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $loc1->id, 'is_enabled' => true, 'position' => 1]);
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $loc2->id, 'is_enabled' => true, 'position' => 2]);

        // Attempt reorder with duplicate ID
        $response = $this->put(route('sales-location-configurations.order'), [
            'location_ids' => [$loc1->id, $loc1->id],
        ]);

        $response->assertRedirect(route('sales-location-configurations.index'));

        // Positions should remain unchanged
        $this->assertEquals(1, SettingSaleLocation::where('setting_id', $settingA->id)->where('location_id', $loc1->id)->value('position'));
        $this->assertEquals(2, SettingSaleLocation::where('setting_id', $settingA->id)->where('location_id', $loc2->id)->value('position'));
    }

    public function test_missing_owned_assignment_is_repaired(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');

        $this->actingAsSuperAdminForSetting($settingA);

        $ownedLocation1 = Location::create([
            'name'       => 'CVTN 1',
            'setting_id' => $settingA->id,
        ]);
        // Location observer auto-creates assignment

        $ownedLocation2 = Location::create([
            'name'       => 'CVTN 2',
            'setting_id' => $settingA->id,
        ]);
        // Location observer auto-creates assignment for this too

        // Delete the assignment for ownedLocation2 to simulate a missing assignment
        SettingSaleLocation::where('setting_id', $settingA->id)
            ->where('location_id', $ownedLocation2->id)
            ->delete();

        // Trigger repair
        SettingSaleLocation::repairMissingOwnedAssignments($settingA->id);

        // Both owned locations should now have enabled assignments
        $this->assertTrue(SettingSaleLocation::where('setting_id', $settingA->id)
            ->where('location_id', $ownedLocation1->id)
            ->where('is_enabled', true)
            ->exists());

        $this->assertTrue(SettingSaleLocation::where('setting_id', $settingA->id)
            ->where('location_id', $ownedLocation2->id)
            ->where('is_enabled', true)
            ->exists());

        // Verify both are in active list
        $response = $this->get(route('sales-location-configurations.index'));
        $this->assertCount(2, $response->viewData('activeLocations'));
    }

    public function test_reorder_invalidates_resolver_cache(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $loc1 = Location::create(['name' => 'Loc 1', 'setting_id' => $settingB->id]);
        $loc2 = Location::create(['name' => 'Loc 2', 'setting_id' => $settingB->id]);

        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $loc1->id, 'is_enabled' => true, 'position' => 1]);
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $loc2->id, 'is_enabled' => true, 'position' => 2]);

        // Prime the cache
        $cachedBefore = \App\Support\SalesLocationResolver::resolveLocationIds($settingA->id);
        $this->assertEquals([$loc1->id, $loc2->id], $cachedBefore->toArray());

        // Reorder
        $this->put(route('sales-location-configurations.order'), [
            'location_ids' => [$loc2->id, $loc1->id],
        ]);

        // Cache should be invalidated and return new order
        $cachedAfter = \App\Support\SalesLocationResolver::resolveLocationIds($settingA->id);
        $this->assertEquals([$loc2->id, $loc1->id], $cachedAfter->toArray());
    }

    public function test_disabled_assignment_position_not_considered_for_next_enabled_position(): void
    {
        $settingA = $this->createSetting('CV Tiga Nusa');
        $settingB = $this->createSetting('Top IT');

        $this->actingAsSuperAdminForSetting($settingA);

        $loc1 = Location::create(['name' => 'Loc 1', 'setting_id' => $settingB->id]);
        $loc2 = Location::create(['name' => 'Loc 2', 'setting_id' => $settingB->id]);
        $loc3 = Location::create(['name' => 'Loc 3', 'setting_id' => $settingB->id]);

        // Remove the auto-created assignments for the foreign setting
        SettingSaleLocation::where('setting_id', $settingB->id)->delete();

        // Create assignments for settingA
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $loc1->id, 'is_enabled' => true, 'position' => 1]);
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $loc2->id, 'is_enabled' => true, 'position' => 2]);

        // Disable and manually set a high position
        SettingSaleLocation::create(['setting_id' => $settingA->id, 'location_id' => $loc3->id, 'is_enabled' => false, 'position' => 100]);

        // Disable loc2
        SettingSaleLocation::where('setting_id', $settingA->id)->where('location_id', $loc2->id)->update(['is_enabled' => false]);

        // Now create a new enabled assignment - it should get position 3, not 101
        // After disabling loc2, only loc1 is enabled (position 1), so next should be 2
        // loc4 will auto-create in settingB, so remove that too
        $loc4 = Location::create(['name' => 'Loc 4', 'setting_id' => $settingB->id]);
        SettingSaleLocation::where('setting_id', $settingB->id)->where('location_id', $loc4->id)->delete();

        $assignment4 = SettingSaleLocation::updateOrCreate(
            ['location_id' => $loc4->id, 'setting_id' => $settingA->id],
            ['is_enabled' => true]
        );

        $this->assertEquals(2, $assignment4->position);
    }
}
