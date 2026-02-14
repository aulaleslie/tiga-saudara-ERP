<?php

namespace Tests\Feature\Setting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Livewire\Modals\TaxQuickAddModal;
use Tests\TestCase;

class TaxQuickAddModalDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_add_can_set_default_and_unset_previous_default(): void
    {
        $oldDefault = Tax::create([
            'name' => 'OLD VAT',
            'value' => 10,
            'is_default' => true,
        ]);

        Livewire::test(TaxQuickAddModal::class)
            ->call('openModal')
            ->set('name', 'NEW VAT')
            ->set('value', 11)
            ->set('is_default', true)
            ->call('save')
            ->assertSet('showModal', false);

        $newDefault = Tax::query()->where('name', 'NEW VAT')->first();

        $this->assertNotNull($newDefault);
        $this->assertTrue((bool) $newDefault->is_default);
        $this->assertFalse((bool) $oldDefault->fresh()->is_default);
        $this->assertSame(1, Tax::query()->where('is_default', true)->count());
    }
}
