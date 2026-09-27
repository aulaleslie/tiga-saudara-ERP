<?php

declare(strict_types=1);

namespace Modules\Setting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Livewire\LocationSearchDropdown;
use Tests\TestCase;

class LocationSearchDropdownScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $settingA;
    private Setting $settingB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->settingA = Setting::factory()->create(['company_name' => 'Business A']);
        $this->settingB = Setting::factory()->create(['company_name' => 'Business B']);

        session(['setting_id' => $this->settingA->id]);
    }

    /** @test */
    public function default_behavior_matches_the_original_tenant_scoped_lookup_for_existing_callers()
    {
        $active = Location::factory()->create(['setting_id' => $this->settingA->id, 'is_active' => true]);
        $otherTenant = Location::factory()->create(['setting_id' => $this->settingB->id, 'is_active' => true]);

        // The component's original behavior always scoped to the
        // selected/session tenant, so the default (tenantScoped=true,
        // crossBusiness=false) must preserve that for existing callers
        // (breakage, adjustment product tables) that pass no new options.
        $component = Livewire::actingAs($this->user)
            ->test(LocationSearchDropdown::class, [
                'selectedSettingId' => $this->settingA->id,
            ]);

        $ids = collect($component->get('options'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($otherTenant->id), 'default lookup must remain tenant-scoped to preserve existing behavior');
    }

    /** @test */
    public function tenant_scoped_search_only_returns_active_locations_owned_by_the_tenant()
    {
        $ownActive = Location::factory()->create(['setting_id' => $this->settingA->id, 'is_active' => true]);
        $ownInactive = Location::factory()->create(['setting_id' => $this->settingA->id, 'is_active' => false]);
        $otherTenant = Location::factory()->create(['setting_id' => $this->settingB->id, 'is_active' => true]);

        $component = Livewire::actingAs($this->user)
            ->test(LocationSearchDropdown::class, [
                'selectedSettingId' => $this->settingA->id,
                'tenantScoped' => true,
            ]);

        $ids = collect($component->get('options'))->pluck('id');

        $this->assertTrue($ids->contains($ownActive->id));
        $this->assertFalse($ids->contains($ownInactive->id));
        $this->assertFalse($ids->contains($otherTenant->id));
    }

    /** @test */
    public function cross_business_search_includes_business_aware_labels()
    {
        $locationA = Location::factory()->create([
            'setting_id' => $this->settingA->id,
            'name' => 'Warehouse',
            'is_active' => true,
        ]);
        $locationB = Location::factory()->create([
            'setting_id' => $this->settingB->id,
            'name' => 'Warehouse',
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(LocationSearchDropdown::class, [
                'crossBusiness' => true,
            ]);

        $options = collect($component->get('options'));

        $labelA = $options->firstWhere('id', $locationA->id)['name'] ?? null;
        $labelB = $options->firstWhere('id', $locationB->id)['name'] ?? null;

        $this->assertStringContainsStringIgnoringCase('Business A', $labelA);
        $this->assertStringContainsStringIgnoringCase('Business B', $labelB);
        $this->assertNotEquals($labelA, $labelB);
    }

    /** @test */
    public function excluded_location_ids_are_removed_from_results_and_rejected_on_selection()
    {
        $origin = Location::factory()->create(['setting_id' => $this->settingA->id, 'is_active' => true]);
        $other = Location::factory()->create(['setting_id' => $this->settingA->id, 'is_active' => true]);

        $component = Livewire::actingAs($this->user)
            ->test(LocationSearchDropdown::class, [
                'selectedSettingId' => $this->settingA->id,
                'excludedLocationIds' => [$origin->id],
            ]);

        $ids = collect($component->get('options'))->pluck('id');
        $this->assertFalse($ids->contains($origin->id));
        $this->assertTrue($ids->contains($other->id));

        // A crafted attempt to select the excluded ID directly is rejected.
        $component->set('selected', $origin->id);
        $component->assertSet('selected', null);
    }

    /** @test */
    public function crafted_inactive_selection_is_rejected_even_when_tenant_scoped()
    {
        $inactive = Location::factory()->create(['setting_id' => $this->settingA->id, 'is_active' => false]);

        $component = Livewire::actingAs($this->user)
            ->test(LocationSearchDropdown::class, [
                'selectedSettingId' => $this->settingA->id,
                'tenantScoped' => true,
            ]);

        $component->set('selected', $inactive->id);
        $component->assertSet('selected', null);
    }

    /** @test */
    public function crafted_out_of_tenant_selection_is_rejected_when_tenant_scoped()
    {
        $foreign = Location::factory()->create(['setting_id' => $this->settingB->id, 'is_active' => true]);

        $component = Livewire::actingAs($this->user)
            ->test(LocationSearchDropdown::class, [
                'selectedSettingId' => $this->settingA->id,
                'tenantScoped' => true,
            ]);

        $component->set('selected', $foreign->id);
        $component->assertSet('selected', null);
    }

    /** @test */
    public function set_selected_location_event_also_rejects_out_of_scope_ids()
    {
        $foreign = Location::factory()->create(['setting_id' => $this->settingB->id, 'is_active' => true]);
        $excluded = Location::factory()->create(['setting_id' => $this->settingA->id, 'is_active' => true]);

        $component = Livewire::actingAs($this->user)
            ->test(LocationSearchDropdown::class, [
                'selectedSettingId' => $this->settingA->id,
                'tenantScoped' => true,
                'excludedLocationIds' => [$excluded->id],
            ]);

        // The setSelectedLocation event previously bypassed scope validation
        // entirely; it must reject the same out-of-scope IDs as select().
        $component->call('handleSetSelectedLocation', $foreign->id);
        $component->assertSet('selected', null);

        $component->call('handleSetSelectedLocation', $excluded->id);
        $component->assertSet('selected', null);
    }
}
