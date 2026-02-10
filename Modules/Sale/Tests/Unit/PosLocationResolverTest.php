<?php

namespace Modules\Sale\Tests\Unit;

use App\Support\PosLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Tests\TestCase;

class PosLocationResolverTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        \Modules\Currency\Entities\Currency::create([
            'currency_name'      => 'Rupiah',
            'code'               => 'IDR',
            'symbol'             => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator'  => ',',
        ]);
        
        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => \Modules\Currency\Entities\Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);
        
        session(['setting_id' => $this->setting->id]);
    }

    public function test_resolves_all_assigned_locations_ordered_by_position(): void
    {
        // Create locations with different positions
        $loc1 = Location::create(['name' => 'Loc 1', 'setting_id' => $this->setting->id]);
        $loc2 = Location::create(['name' => 'Loc 2', 'setting_id' => $this->setting->id]);
        $loc3 = Location::create(['name' => 'Loc 3', 'setting_id' => $this->setting->id]);

        // Assign them to the setting with specific positions
        // Note: is_pos is removed, all assigned locations are valid
        $this->assignLocation($loc3, 1);
        $this->assignLocation($loc1, 2);
        $this->assignLocation($loc2, 3);

        $resolvedIds = PosLocationResolver::resolveLocationIds($this->setting->id);

        $this->assertCount(3, $resolvedIds);
        $this->assertEquals([$loc3->id, $loc1->id, $loc2->id], $resolvedIds->toArray());
    }

    public function test_resolves_primary_location_id_as_first_in_sequence(): void
    {
        $loc1 = Location::create(['name' => 'Loc 1', 'setting_id' => $this->setting->id]);
        $loc2 = Location::create(['name' => 'Loc 2', 'setting_id' => $this->setting->id]);

        // Loc 2 is first
        $this->assignLocation($loc2, 1);
        $this->assignLocation($loc1, 2);

        $primaryId = PosLocationResolver::resolveId($this->setting->id);

        $this->assertEquals($loc2->id, $primaryId);
    }



    public function test_respects_session_assignment_override(): void
    {
        $loc1 = Location::create(['name' => 'Loc 1', 'setting_id' => $this->setting->id]);
        $assignment = $this->assignLocation($loc1, 1);

        // Manually set session assignment
        PosLocationResolver::setActiveAssignment($assignment->id);
        
        // This should force the resolver to use the setting from the assignment
        // (In this matching case it's same setting, but key path is session check)
        $ids = PosLocationResolver::resolveLocationIds(); // No arg, relies on session
        
        $this->assertNotEmpty($ids);
        $this->assertEquals(session('setting_id'), $this->setting->id);
    }

    public function test_cache_usage_and_invalidation(): void
    {
        $loc1 = Location::create(['name' => 'Loc 1', 'setting_id' => $this->setting->id]);
        $this->assignLocation($loc1, 1);

        // First call populates cache
        PosLocationResolver::resolveLocationIds($this->setting->id);
        
        $cacheKey = "pos_location_ids_{$this->setting->id}";
        $this->assertTrue(Cache::has($cacheKey));

        // Add new location - model events should clear cache
        $loc2 = Location::create(['name' => 'Loc 2', 'setting_id' => $this->setting->id]);
        $this->assignLocation($loc2, 2);

        $this->assertFalse(Cache::has($cacheKey), 'Cache should be invalidated after new assignment');

        $ids = PosLocationResolver::resolveLocationIds($this->setting->id);
        $this->assertCount(2, $ids);
    }

    private function assignLocation(Location $location, int $position)
    {
        // Use updateOrCreate since Location model auto-creates an assignment on boot
        return SettingSaleLocation::updateOrCreate(
            ['location_id' => $location->id],
            [
                'setting_id' => $this->setting->id,
                'position' => $position,
            ]
        );
    }
}
