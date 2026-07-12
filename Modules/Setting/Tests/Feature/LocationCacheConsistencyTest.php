<?php

namespace Modules\Setting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Livewire\Modals\LocationQuickAddModal;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Livewire\Livewire;
use App\Support\SalesLocationResolver;

class LocationCacheConsistencyTest extends TestCase
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

    public function test_standard_create_warms_cache_and_asserts_visibility_and_exclusion()
    {
        $settingA = $this->createSetting('Owner Setting');
        $settingB = $this->createSetting('Unrelated Setting');

        $this->assertEmpty(SalesLocationResolver::resolveLocationIds($settingA->id));
        $this->assertEmpty(SalesLocationResolver::resolveLocationIds($settingB->id));

        $location = Location::create([
            'name' => 'New Standard Location',
            'setting_id' => $settingA->id,
        ]);

        $idsA = SalesLocationResolver::resolveLocationIds($settingA->id);
        $idsB = SalesLocationResolver::resolveLocationIds($settingB->id);

        $this->assertContains($location->id, $idsA->toArray());
        $this->assertNotContains($location->id, $idsB->toArray());
        
        $this->assertDatabaseMissing('setting_sale_locations', [
            'setting_id' => $settingB->id,
            'location_id' => $location->id,
        ]);
    }

    public function test_quick_add_cache_consistency()
    {
        $settingA = $this->createSetting('Owner Setting');
        $settingB = $this->createSetting('Unrelated Setting');
        
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['setting_id' => $settingA->id]);

        $this->assertEmpty(SalesLocationResolver::resolveLocationIds($settingA->id));
        $this->assertEmpty(SalesLocationResolver::resolveLocationIds($settingB->id));

        Livewire::test(LocationQuickAddModal::class)
            ->set('name', 'Quick Location')
            ->call('save')
            ->assertHasNoErrors();

        $location = Location::where('name', 'QUICK LOCATION')->first();
        $this->assertNotNull($location);

        $idsA = SalesLocationResolver::resolveLocationIds($settingA->id);
        $idsB = SalesLocationResolver::resolveLocationIds($settingB->id);

        $this->assertContains($location->id, $idsA->toArray());
        $this->assertNotContains($location->id, $idsB->toArray());
        
        $count = SettingSaleLocation::where('location_id', $location->id)->count();
        $this->assertEquals(1, $count);
    }
    
    public function test_atomic_failure_reverts_location_creation()
    {
        $settingA = $this->createSetting('Owner Setting');
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Super Admin']);
        $user->assignRole($role);
        $user->settings()->attach($settingA->id, ['role_id' => $role->id]);

        $this->actingAs($user)->withSession(['setting_id' => $settingA->id]);

        // Force an exception during SettingSaleLocation creation by listening to eloquent events
        \Modules\Setting\Entities\SettingSaleLocation::creating(function () {
            throw new \Exception('Simulated assignment failure');
        });

        // Test the HTTP controller
        try {
            $this->post(route('locations.store'), [
                'name' => 'Should Rollback',
            ]);
        } catch (\Exception $e) {
            $this->assertEquals('Simulated assignment failure', $e->getMessage());
        }

        // Assert location was rolled back
        $this->assertDatabaseMissing('locations', [
            'name' => 'Should Rollback',
        ]);
        
        // Test Livewire quick add
        try {
            Livewire::test(LocationQuickAddModal::class)
                ->set('name', 'Quick Rollback')
                ->call('save');
        } catch (\Exception $e) {
            $this->assertEquals('Simulated assignment failure', $e->getMessage());
        }

        $this->assertDatabaseMissing('locations', [
            'name' => 'Quick Rollback',
        ]);
    }
}
