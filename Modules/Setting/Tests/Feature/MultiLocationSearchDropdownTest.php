<?php

declare(strict_types=1);

namespace Modules\Setting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Livewire\MultiLocationSearchDropdown;
use Tests\TestCase;

class MultiLocationSearchDropdownTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $settingA;
    private Setting $settingB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->settingA = Setting::factory()->create(['company_name' => 'Business Alpha', 'is_pkp' => true]);
        $this->settingB = Setting::factory()->create(['company_name' => 'Business Beta', 'is_pkp' => false]);

        session(['setting_id' => $this->settingA->id]);
    }

    /** @test */
    public function it_lists_active_standard_locations_across_all_businesses(): void
    {
        $locA = Location::factory()->create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang Pusat Alpha',
            'is_active' => true,
            'is_consignment' => false,
        ]);
        $locB = Location::factory()->create([
            'setting_id' => $this->settingB->id,
            'name' => 'Gudang Cabang Beta',
            'is_active' => true,
            'is_consignment' => false,
        ]);
        $inactive = Location::factory()->create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang Tutup',
            'is_active' => false,
            'is_consignment' => false,
        ]);
        $consignment = Location::factory()->create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang Konsinyasi',
            'is_active' => true,
            'is_consignment' => true,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(MultiLocationSearchDropdown::class);

        $options = collect($component->get('options'));
        $optionIds = $options->pluck('id')->all();

        // Cross-business active standard locations should be present
        $this->assertContains($locA->id, $optionIds);
        $this->assertContains($locB->id, $optionIds);

        // Inactive and consignment should NOT be present
        $this->assertNotContains($inactive->id, $optionIds);
        $this->assertNotContains($consignment->id, $optionIds);

        // Labels should be business-aware
        $labelA = $options->firstWhere('id', $locA->id)['name'];
        $labelB = $options->firstWhere('id', $locB->id)['name'];
        $this->assertStringContainsStringIgnoringCase('Business Alpha', $labelA);
        $this->assertStringContainsStringIgnoringCase('Business Beta', $labelB);
    }

    /** @test */
    public function it_filters_options_by_search_query(): void
    {
        Location::factory()->create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang Utama',
            'is_active' => true,
            'is_consignment' => false,
        ]);
        Location::factory()->create([
            'setting_id' => $this->settingB->id,
            'name' => 'Toko Retail',
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(MultiLocationSearchDropdown::class)
            ->set('search', 'Retail');

        $filtered = collect($component->get('filteredOptions'));
        $this->assertCount(1, $filtered);
        $this->assertStringContainsStringIgnoringCase('Toko Retail', $filtered->first()['name']);
    }

    /** @test */
    public function it_can_add_and_remove_locations(): void
    {
        $locA = Location::factory()->create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang A',
            'is_active' => true,
            'is_consignment' => false,
        ]);
        $locB = Location::factory()->create([
            'setting_id' => $this->settingB->id,
            'name' => 'Gudang B',
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(MultiLocationSearchDropdown::class)
            ->call('addLocation', $locA->id)
            ->assertDispatched('locationsSelected')
            ->call('addLocation', $locB->id);

        $this->assertEquals([$locA->id, $locB->id], $component->get('selected'));

        // Removing locA
        $component->call('removeLocation', $locA->id);
        $this->assertEquals([$locB->id], $component->get('selected'));
    }

    /** @test */
    public function it_rejects_duplicate_location_selection(): void
    {
        $loc = Location::factory()->create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang Unik',
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(MultiLocationSearchDropdown::class, ['selected' => [$loc->id]])
            ->call('addLocation', $loc->id)
            ->assertDispatched('location-dropdown-selection-rejected');

        $this->assertEquals([$loc->id], $component->get('selected'));
    }

    /** @test */
    public function it_rejects_inactive_location_selection(): void
    {
        $inactive = Location::factory()->create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang Nonaktif',
            'is_active' => false,
            'is_consignment' => false,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(MultiLocationSearchDropdown::class)
            ->call('addLocation', $inactive->id)
            ->assertDispatched('location-dropdown-selection-rejected');

        $this->assertEmpty($component->get('selected'));
    }

    /** @test */
    public function it_rejects_consignment_location_selection(): void
    {
        $consignment = Location::factory()->create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang Konsinyasi',
            'is_active' => true,
            'is_consignment' => true,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(MultiLocationSearchDropdown::class)
            ->call('addLocation', $consignment->id)
            ->assertDispatched('location-dropdown-selection-rejected');

        $this->assertEmpty($component->get('selected'));
    }

    /** @test */
    public function it_supports_initial_selected_locations(): void
    {
        $locA = Location::factory()->create([
            'setting_id' => $this->settingA->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);
        $locB = Location::factory()->create([
            'setting_id' => $this->settingB->id,
            'is_active' => true,
            'is_consignment' => false,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(MultiLocationSearchDropdown::class, [
                'selected' => [$locA->id, $locB->id],
            ]);

        $this->assertEquals([$locA->id, $locB->id], $component->get('selected'));

        // Filtered options should exclude already selected
        $filtered = collect($component->get('filteredOptions'))->pluck('id')->all();
        $this->assertNotContains($locA->id, $filtered);
        $this->assertNotContains($locB->id, $filtered);
    }
}
