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
}
