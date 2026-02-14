<?php

namespace Tests\Feature\Livewire\Product;

use Livewire\Livewire;
use Modules\Product\Livewire\BrandSearchDropdown;
use Modules\Product\Livewire\CategorySearchDropdown;
use Modules\Product\Livewire\TaxSearchDropdown;
use Tests\TestCase;

class OptionalProductDropdownClearTest extends TestCase
{
    /** @test */
    public function category_dropdown_can_clear_selected_value(): void
    {
        Livewire::test(CategorySearchDropdown::class, [
            'name' => 'category_id',
            'selected' => 2,
            'clearable' => true,
            'options' => [
                ['id' => 1, 'name' => 'Laptop'],
                ['id' => 2, 'name' => 'Aksesoris'],
            ],
        ])
            ->assertSet('selected', 2)
            ->assertSet('selectedLabel', 'Aksesoris')
            ->call('clearSelection')
            ->assertSet('selected', null)
            ->assertSet('selectedLabel', null)
            ->assertSet('open', false)
            ->assertSet('search', '')
            ->assertSee('name="category_id" value=""', false);
    }

    /** @test */
    public function category_dropdown_select_still_updates_selection_state(): void
    {
        Livewire::test(CategorySearchDropdown::class, [
            'name' => 'category_id',
            'clearable' => true,
            'options' => [
                ['id' => 1, 'name' => 'Laptop'],
                ['id' => 2, 'name' => 'Aksesoris'],
            ],
        ])
            ->call('select', 1)
            ->assertSet('selected', 1)
            ->assertSet('selectedLabel', 'Laptop')
            ->assertSet('open', false)
            ->assertSet('search', '');
    }

    /** @test */
    public function brand_dropdown_clear_selection_resets_state_and_dispatches_event(): void
    {
        Livewire::test(BrandSearchDropdown::class, [
            'name' => 'brand_id',
            'selected' => 10,
            'clearable' => true,
            'dispatchTo' => 'modules.product.modals.product-quick-add-modal',
            'options' => [
                ['id' => 10, 'name' => 'Asus'],
                ['id' => 20, 'name' => 'Acer'],
            ],
        ])
            ->assertSet('selected', 10)
            ->assertSet('selectedLabel', 'Asus')
            ->call('clearSelection')
            ->assertSet('selected', null)
            ->assertSet('selectedLabel', null)
            ->assertSet('open', false)
            ->assertSet('search', '')
            ->assertDispatched('brandDropdownSelected')
            ->assertSee('name="brand_id" value=""', false);
    }

    /** @test */
    public function tax_dropdown_can_clear_selected_value_when_enabled(): void
    {
        Livewire::test(TaxSearchDropdown::class, [
            'name' => 'purchase_tax_id',
            'inputId' => 'purchase_tax_id',
            'selected' => 11,
            'clearable' => true,
            'disabled' => false,
            'dispatchTo' => 'modules.product.modals.product-quick-add-modal',
            'options' => [
                ['id' => 11, 'name' => 'PPN', 'value' => 11],
                ['id' => 0, 'name' => 'No Tax', 'value' => 0],
            ],
        ])
            ->assertSet('selected', 11)
            ->assertSet('selectedLabel', 'PPN (11%)')
            ->call('clearSelection')
            ->assertSet('selected', null)
            ->assertSet('selectedLabel', null)
            ->assertSet('open', false)
            ->assertSet('search', '')
            ->assertDispatched('taxDropdownSelected')
            ->assertSee('name="purchase_tax_id" id="purchase_tax_id" value=""', false);
    }

    /** @test */
    public function tax_dropdown_clear_selection_is_noop_when_disabled(): void
    {
        Livewire::test(TaxSearchDropdown::class, [
            'name' => 'sale_tax_id',
            'inputId' => 'sale_tax_id',
            'selected' => 11,
            'clearable' => true,
            'disabled' => true,
            'options' => [
                ['id' => 11, 'name' => 'PPN', 'value' => 11],
            ],
        ])
            ->assertSet('selected', 11)
            ->assertSet('selectedLabel', 'PPN (11%)')
            ->call('clearSelection')
            ->assertSet('selected', 11)
            ->assertSet('selectedLabel', 'PPN (11%)')
            ->assertSee('name="sale_tax_id" id="sale_tax_id" value="11"', false);
    }
}
