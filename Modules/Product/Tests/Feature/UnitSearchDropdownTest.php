<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Livewire\UnitSearchDropdown;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class UnitSearchDropdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::factory()->create();
    }

    public function test_quick_add_button_is_unavailable_when_dropdown_is_disabled()
    {
        Livewire::test(UnitSearchDropdown::class, [
            'allowCreate' => true,
            'disabled' => true,
        ])
        ->assertDontSeeHtml('wire:click="openCreateModal"');
    }

    public function test_quick_add_button_is_available_when_dropdown_is_enabled()
    {
        Livewire::test(UnitSearchDropdown::class, [
            'allowCreate' => true,
            'disabled' => false,
        ])
        ->assertSeeHtml('wire:click="openCreateModal"');
    }

    public function test_dropdown_updates_hidden_input_value_on_selection()
    {
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Box',
            'short_name' => 'bx',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        Livewire::test(UnitSearchDropdown::class, [
            'name' => 'conversions[1][unit_id]',
            'selected' => null,
            'options' => [
                ['id' => $unit->id, 'name' => $unit->name],
            ],
        ])
            ->assertSeeHtml('name="conversions[1][unit_id]" value=""')
            ->call('select', $unit->id)
            ->assertSet('selected', $unit->id)
            ->assertSet('selectedLabel', $unit->name)
            ->assertSeeHtml('name="conversions[1][unit_id]" value="' . $unit->id . '"');
    }

    public function test_dropdown_name_is_reactive_and_preserves_child_selection_state()
    {
        $unit1 = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pc',
            'operator' => '*',
            'operation_value' => 1,
        ]);
        $unit2 = \Modules\Setting\Entities\Unit::create([
            'name' => 'Carton',
            'short_name' => 'ctn',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $dropdown = Livewire::test(UnitSearchDropdown::class, [
            'name' => 'conversions[2][unit_id]',
            'selected' => $unit1->id,
            'options' => [
                ['id' => $unit1->id, 'name' => $unit1->name],
                ['id' => $unit2->id, 'name' => $unit2->name],
            ],
        ]);

        $dropdown->assertSeeHtml('name="conversions[2][unit_id]" value="' . $unit1->id . '"');

        // Select a different unit in the child dropdown
        $dropdown->call('select', $unit2->id);
        $dropdown->assertSet('selected', $unit2->id);
        $dropdown->assertSeeHtml('name="conversions[2][unit_id]" value="' . $unit2->id . '"');

        // Simulate reactive prop update from parent compaction
        \Livewire\Features\SupportReactiveProps\SupportReactiveProps::$pendingChildParams[$dropdown->id()] = [
            'name' => 'conversions[1][unit_id]',
        ];

        // Hydrate child component with new reactive prop
        $dropdown->call('$refresh');
        $dropdown->assertSet('name', 'conversions[1][unit_id]');
        $dropdown->assertSet('selected', $unit2->id);
        $dropdown->assertSeeHtml('name="conversions[1][unit_id]" value="' . $unit2->id . '"');
    }
}
