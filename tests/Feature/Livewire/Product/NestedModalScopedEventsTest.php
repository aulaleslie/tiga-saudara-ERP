<?php

namespace Tests\Feature\Livewire\Product;

use App\Livewire\Modules\Product\Modals\ProductQuickAddModal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Brand;
use Modules\Product\Entities\Category;
use Modules\Product\Livewire\BrandSearchDropdown;
use Modules\Product\Livewire\CategorySearchDropdown;
use Modules\Product\Livewire\Modals\BrandQuickAddModal;
use Modules\Product\Livewire\Modals\CategoryQuickAddModal;
use Modules\Product\Livewire\TaxSearchDropdown;
use Modules\Product\Livewire\UnitSearchDropdown;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Livewire\Modals\TaxQuickAddModal;
use Modules\Setting\Livewire\Modals\UnitQuickAddModal;
use Tests\TestCase;

class NestedModalScopedEventsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    public function test_product_quick_add_modal_renders_scoped_nested_event_names(): void
    {
        Livewire::test(ProductQuickAddModal::class)
            ->assertSee('openNestedCategoryModal', false)
            ->assertSee('openNestedBrandModal', false)
            ->assertSee('openNestedTaxModal', false)
            ->assertSee('openNestedUnitModal', false);
    }

    public function test_page_level_tax_modal_still_opens_with_default_event(): void
    {
        Livewire::test(TaxQuickAddModal::class)
            ->assertSet('showModal', false)
            ->dispatch('openTaxModal')
            ->assertSet('showModal', true);
    }

    public function test_nested_quick_add_modals_only_respond_to_scoped_events(): void
    {
        $taxModal = Livewire::test(TaxQuickAddModal::class, ['listenEvent' => 'openNestedTaxModal']);
        $this->assertSame('openModal', $taxModal->instance()->getListeners()['openNestedTaxModal']);
        $this->assertArrayNotHasKey('openTaxModal', $taxModal->instance()->getListeners());

        $taxModal
            ->dispatch('openNestedTaxModal')
            ->assertSet('showModal', true);

        $categoryModal = Livewire::test(CategoryQuickAddModal::class, ['listenEvent' => 'openNestedCategoryModal']);
        $this->assertSame('openModal', $categoryModal->instance()->getListeners()['openNestedCategoryModal']);
        $this->assertArrayNotHasKey('openCategoryModal', $categoryModal->instance()->getListeners());

        $categoryModal
            ->dispatch('openNestedCategoryModal')
            ->assertSet('showModal', true);

        $brandModal = Livewire::test(BrandQuickAddModal::class, ['listenEvent' => 'openNestedBrandModal']);
        $this->assertSame('openModal', $brandModal->instance()->getListeners()['openNestedBrandModal']);
        $this->assertArrayNotHasKey('openBrandModal', $brandModal->instance()->getListeners());

        $brandModal
            ->dispatch('openNestedBrandModal')
            ->assertSet('showModal', true);

        $unitModal = Livewire::test(UnitQuickAddModal::class, ['listenEvent' => 'openNestedUnitModal']);
        $this->assertSame('openModal', $unitModal->instance()->getListeners()['openNestedUnitModal']);
        $this->assertArrayNotHasKey('openUnitModal', $unitModal->instance()->getListeners());

        $unitModal
            ->dispatch('openNestedUnitModal')
            ->assertSet('showModal', true);
    }

    public function test_search_dropdowns_render_custom_nested_modal_events(): void
    {
        Livewire::test(TaxSearchDropdown::class, [
            'allowCreate' => true,
            'modalEvent' => 'openNestedTaxModal',
        ])->assertSee('openNestedTaxModal', false);

        Livewire::test(CategorySearchDropdown::class, [
            'allowCreate' => true,
            'modalEvent' => 'openNestedCategoryModal',
        ])->assertSee('openNestedCategoryModal', false);

        Livewire::test(BrandSearchDropdown::class, [
            'allowCreate' => true,
            'modalEvent' => 'openNestedBrandModal',
        ])->assertSee('openNestedBrandModal', false);

        Livewire::test(UnitSearchDropdown::class, [
            'allowCreate' => true,
            'modalEvent' => 'openNestedUnitModal',
        ])
            ->call('openCreateModal')
            ->assertSet('awaitingCreated', true)
            ->assertDispatched('openNestedUnitModal');
    }

    public function test_nested_tax_modal_submission_creates_tax_and_refreshes_dropdown(): void
    {
        $instance = Livewire::test(TaxQuickAddModal::class, ['listenEvent' => 'openNestedTaxModal'])->instance();
        $instance->openModal();
        $instance->name = 'Nested VAT';
        $instance->value = 12;
        $instance->save();
        $this->assertFalse($instance->showModal);

        $tax = Tax::query()->where('name', 'NESTED VAT')->first();

        $this->assertNotNull($tax);

        Livewire::test(TaxSearchDropdown::class, [
            'name' => 'purchase_tax_id',
            'inputId' => 'purchase_tax_id',
            'allowCreate' => true,
            'modalEvent' => 'openNestedTaxModal',
        ])
            ->call('handleTaxCreated', $tax->id, $tax->name, $tax->value)
            ->assertSet('selected', $tax->id)
            ->assertSet('selectedLabel', 'NESTED VAT (12%)');
    }

    public function test_nested_category_modal_submission_creates_category_and_refreshes_dropdown(): void
    {
        $instance = Livewire::test(CategoryQuickAddModal::class, ['listenEvent' => 'openNestedCategoryModal'])->instance();
        $instance->openModal();
        $instance->category_name = 'Nested Category';
        $instance->save();
        $this->assertFalse($instance->showModal);

        $category = Category::query()->where('category_name', 'NESTED CATEGORY')->first();

        $this->assertNotNull($category);

        Livewire::test(CategorySearchDropdown::class, [
            'name' => 'category_id',
            'allowCreate' => true,
            'modalEvent' => 'openNestedCategoryModal',
        ])
            ->call('handleCategoryCreated', [
                'id' => $category->id,
                'name' => $category->category_name,
                'display_name' => $category->category_name,
                'category_name' => $category->category_name,
                'parent_id' => $category->parent_id,
                'parent_name' => null,
            ])
            ->assertSet('selected', $category->id)
            ->assertSet('selectedLabel', 'NESTED CATEGORY');
    }

    public function test_nested_brand_modal_submission_creates_brand_and_refreshes_dropdown(): void
    {
        $instance = Livewire::test(BrandQuickAddModal::class, ['listenEvent' => 'openNestedBrandModal'])->instance();
        $instance->openModal();
        $instance->name = 'Nested Brand';
        $instance->description = 'Nested modal brand';
        $instance->save();
        $this->assertFalse($instance->showModal);

        $brand = Brand::query()->where('name', 'NESTED BRAND')->first();

        $this->assertNotNull($brand);

        Livewire::test(BrandSearchDropdown::class, [
            'name' => 'brand_id',
            'allowCreate' => true,
            'modalEvent' => 'openNestedBrandModal',
        ])
            ->call('handleBrandCreated', [
                'id' => $brand->id,
                'name' => $brand->name,
            ])
            ->assertSet('selected', $brand->id)
            ->assertSet('selectedLabel', 'NESTED BRAND');
    }

    public function test_nested_unit_modal_submission_creates_unit_and_refreshes_dropdown(): void
    {
        $instance = Livewire::test(UnitQuickAddModal::class, ['listenEvent' => 'openNestedUnitModal'])->instance();
        $instance->openModal();
        $instance->name = 'Nested Unit';
        $instance->short_name = 'NU';
        $instance->save();
        $this->assertFalse($instance->showModal);

        $unit = Unit::query()->where('name', 'NESTED UNIT')->first();

        $this->assertNotNull($unit);

        Livewire::test(UnitSearchDropdown::class, [
            'name' => 'base_unit_id',
            'allowCreate' => true,
            'modalEvent' => 'openNestedUnitModal',
        ])
            ->set('awaitingCreated', true)
            ->call('handleUnitCreated', [
                'id' => $unit->id,
                'name' => $unit->name,
            ])
            ->assertSet('selected', $unit->id)
            ->assertSet('selectedLabel', 'NESTED UNIT')
            ->assertSet('awaitingCreated', false);
    }
}
